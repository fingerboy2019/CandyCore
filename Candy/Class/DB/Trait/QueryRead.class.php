<?php
/***
 * 只读查询执行
 *
 * 业务说明：total/select/find 及内部分页计数查询
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 10:18:01 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Core\Debug;
use Candy\Extend\DB\DbAsync;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait QueryRead {
	/**
	 * 添加受控投影表达式。
	 *
	 * 表达式必须由 DbExpression 构造，别名只允许使用普通标识符；外部值由表达式
	 * 编译器绑定，避免业务层为 INSERT...SELECT 拼接 CASE/常量 SQL。
	 *
	 * @param array<string,\Candy\Extend\DB\DbExpression> $expressions 别名到表达式的映射
	 * @return static 当前查询对象
	 */
	public function fieldExpressions(array $expressions): static
	{
		$normalized = [];
		foreach ($expressions as $alias => $expression) {
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$alias) !== 1) {
				throw new \InvalidArgumentException('投影表达式别名不合法');
			}
			if (!$expression instanceof \Candy\Extend\DB\DbExpression) {
				throw new \InvalidArgumentException('投影表达式必须使用 DbExpression');
			}
			$normalized[(string)$alias] = $expression;
		}

		$this->sql['field_expressions'] = $normalized;

		return $this;
	}

	/**
	 * 为下一次 select/find 添加排他行锁。
	 *
	 * @return static 当前查询对象
	 */
	public function lockForUpdate(): static
	{
		$this->sql['lock'] = 'update';
		return $this;
	}

	/**
	 * 为下一次 select/find 添加共享行锁。
	 *
	 * @return static 当前查询对象
	 */
	public function lockForShare(): static
	{
		$this->sql['lock'] = 'share';
		return $this;
	}

	/** @var bool 只读查询是否优先走异步队列 */
	protected bool $preferAsyncRead = false;

	/**
	 * 后续 select/find/total 优先异步入队（需 Db.async_read 开启）
	 *
	 * 入队后立即返回空结果，无回写；适合可丢结果的统计类查询。
	 *
	 * @param bool $on 是否启用
	 * @return static
	 */
	public function useAsyncRead(bool $on = true): static
	{
		$this->preferAsyncRead = $on;
		return $this;
	}

	/**
	 * 执行只读 SQL（可按配置异步入队）
	 *
	 * @param string $sql SQL
	 * @param string $method select|find|total
	 * @param array $bind 绑定参数
	 * @return mixed
	 */
	protected function runReadQuery(string $sql, string $method, array $bind = []): mixed
	{
		if ($this->preferAsyncRead) {
			return DbAsync::runRead($this, $sql, $method, $bind);
		}
		return $this->query($sql, $method, $bind);
	}
	/**
	 * 获取符合条件的记录总数，或同时返回分页列表
	 *
	 * 条件仅通过链式 where() 等设置；查询结束后自动重置 SQL 状态。
	 *
	 * @param int|null $offset 起始偏移（与 $length 同时传入时生效）
	 * @param int|null $length 返回条数；传入时返回 ['total'=>int, 'list'=>array]
	 * @return int|array{total:int,list:array}
	 * @example
	 * ```php
	 * $count = $db->where(['status' => 1])->total();
	 * $page = $db->where(['status' => 1])->order('id desc')->total(0, 10);
	 * ```
	 */
	public function total(?int $offset = null, ?int $length = null): int|array
	{
		$sqlSnapshot = $this->getSqlState();
		$count = $this->executeCount();

		if ($length !== null) {
			$this->setSqlState($sqlSnapshot);
			$this->sql['limit'] = [(int)($offset ?? 0), (int)$length];
			$list = $this->executeSelect([]);
			$this->resetSql();
			return ['total' => $count, 'list' => $list];
		}

		$this->resetSql();
		return $count;
	}

	/**
	 * 执行 COUNT 查询（不重置 SQL 状态）
	 */
	private function executeCount(): int
	{
		$cacheArgs = ['type' => 'total'];
		$cached = self::getDataCache($cacheArgs);
		if ($cached !== false) {
			return (int)$cached;
		}

		$join = self::compileJoin();

		$outerQualifier = empty($join['as'])
			? $this->quoteIdent($this->tabName)
			: rtrim((string)$join['as'], '.');
		$where = $this->compileWhere($this->sqlArgs('where'), $join['as'], ' WHERE ', '', $outerQualifier);
		$bind = array_merge($where['data'], $join['joinDate']);
		$whereSql = $where['where'] . $join['joinWhere'];

		listen('db_query', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $cacheArgs, 'data' => $bind]);
		$count = $this->runReadQuery(
			'SELECT COUNT(*) as count FROM ' . $this->quoteIdent($this->tabName) . (empty($join['as']) ? '' : ' AS ' . rtrim((string)$join['as'], '.')) . $join['join'] . $whereSql,
			'total',
			$bind
		);
		listen('db_after', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $cacheArgs, 'data' => $count]);

		self::setDataCache($cacheArgs, $count);
		return (int)$count;
	}

	/**
	 * 查询多条记录
	 * 
	 * 根据指定条件查询数据库中的记录，返回二维数组结果。
	 * 支持字段筛选、条件查询、排序、分组、分页等操作。
	 * 支持左关联查询和数据缓存。
	 * 
	 * @param mixed ...$args 查询条件，支持多种格式
	 * @return array 查询结果数组，二维数组格式
	 * @example
	 * ```php
	 * // 查询所有记录
	 * $list = $db->select();
	 * 
	 * // 查询指定条件的记录
	 * $list = $db->select(['status' => 1]);
	 * 
	 * // 使用连贯操作
	 * $list = $db->field('id,name,age')
	 *           ->where(['status' => 1])
	 *           ->order('id desc')
	 *           ->limit(10)
	 *           ->select();
	 * ```
	 */
	public function select(...$args): array
	{
		if (count($args) > 0) {
			$this->appendWhereClause('AND', $args);
		}

		$data = $this->executeSelect($args);
		$this->resetSql();
		return $data;
	}

	/**
	 * 编译当前链式查询为参数化 SELECT，不执行也不重置查询状态。
	 *
	 * 该入口供 Model 组合 UNION ALL 等派生查询使用；业务层仍通过 where、field、join
	 * 等链式 API 提供结构和值，禁止把业务 SQL 字符串直接传入组合器。
	 *
	 * @param array{order?:bool,limit?:bool,lock?:bool} $options 是否保留分支排序、分页与锁
	 * @return array{sql:string,data:array<int,mixed>,fields:string} SQL、绑定参数与投影字段
	 */
	public function compileSelect(array $options = []): array
	{
		$includeOrder = ($options['order'] ?? true) !== false;
		$includeLimit = ($options['limit'] ?? true) !== false;
		$includeLock = ($options['lock'] ?? true) !== false;

		$join = self::compileJoin();
		$fields = self::getFormatStr('fields', ' ', $this->sqlArgs('field'), $this->fieldList, $join['as']);
		$expressionFields = [];
		$expressionData = [];
		$quoteIdentifier = fn(string $column): string => $this->quoteIdent($column);
		foreach ((array)$this->sqlArgs('field_expressions') as $alias => $expression) {
			if (!$expression instanceof \Candy\Extend\DB\DbExpression) {
				throw new \LogicException('投影表达式状态已损坏');
			}
			$compiled = $expression->compile($quoteIdentifier, $this->dialect());
			$expressionFields[] = $compiled['sql'] . ' AS ' . $this->quoteIdent((string)$alias);
			array_push($expressionData, ...$compiled['data']);
		}
		if ($expressionFields !== []) {
			$fields .= ($fields === '' ? '' : ',') . implode(',', $expressionFields);
		}
		if (count($join['joinField']) > 0) {
			$fields .= ',' . implode(',', $join['joinField']);
		}

		$outerQualifier = empty($join['as'])
			? $this->quoteIdent($this->tabName)
			: rtrim((string)$join['as'], '.');
		$where = $this->compileWhere($this->sqlArgs('where'), $join['as'], ' WHERE ', '', $outerQualifier);
		$bind = array_merge($expressionData, $where['data'], $join['joinDate']);
		$whereSql = $where['where'] . $join['joinWhere'];

		$order = '';
		if ($includeOrder) {
			$order = $this->compileOrder(self::getFormatStr(
				'order',
				' ORDER BY ',
				$this->sqlArgs('order'),
				$this->implicitOrderDefaults(),
				$join['as']
			)) . $join['joinOrder'];
		}

		$group = $this->compileGroup(self::getFormatStr('group', ' GROUP BY ', $this->sqlArgs('group'), [], $join['as'])) . $join['joinGroup'];
		$having = $this->compileHaving(self::getFormatStr('having', ' HAVING ', $this->sqlArgs('having'), [], $join['as']));
		if (empty($having)) {
			$having = $join['joinHaving'];
		}

		$limit = '';
		if ($includeLimit && $this->sqlArgs('limit') !== '') {
			$limit = $this->compileLimit($this->sqlArgs('limit'));
		}

		$lock = '';
		if ($includeLock && $this->sqlArgs('lock') !== '') {
			$lock = $this->dialect()::lockClause((string)$this->sqlArgs('lock'));
		}

		$sql = 'SELECT ' . $fields . ' FROM ' . $this->quoteIdent($this->tabName)
			. (empty($join['as']) ? '' : ' AS ' . rtrim((string)$join['as'], '.'))
			. $join['join'] . $whereSql . $group . $having . $order . $limit . $lock;

		return ['sql' => $sql, 'data' => $bind, 'fields' => $fields];
	}

	/**
	 * 执行由多个链式 Model 组成的 UNION ALL 查询并统一分页。
	 *
	 * 每个分支先由 compileSelect() 生成参数化 SQL，外层只允许白名单字段与方向排序；
	 * 组合查询沿用当前连接、方言分页和异步只读设置。
	 *
	 * @param array<int,array{sql:string,data:array<int,mixed>}> $branches 已编译分支
	 * @param array<string,string>|array<int,array{0:string,1:string}> $order 外层排序
	 * @param int $offset 起始偏移
	 * @param int $limit 返回条数，0 表示不分页
	 * @param string $alias 派生表别名
	 * @return array 查询结果
	 */
	public function selectUnion(
		array $branches,
		array $order = [],
		int $offset = 0,
		int $limit = 0,
		string $alias = 'union_rows'
	): array {
		if ($branches === []) {
			return [];
		}
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
			throw new \InvalidArgumentException('UNION 派生表别名不合法');
		}

		$parts = [];
		$bind = [];
		foreach ($branches as $branch) {
			$sql = trim((string)($branch['sql'] ?? ''));
			if (preg_match('/^SELECT\b/i', $sql) !== 1) {
				throw new \InvalidArgumentException('UNION 分支必须是 SELECT');
			}
			$sql = rtrim($sql, " \t\r\n;");
			$parts[] = $sql;
			$bind = array_merge($bind, (array)($branch['data'] ?? []));
		}

		$orderSql = [];
		foreach ($order as $key => $value) {
			if (is_int($key)) {
				if (!is_array($value) || count($value) < 1) {
					throw new \InvalidArgumentException('UNION 排序必须是字段与方向数组');
				}
				$field = (string)$value[0];
				$direction = (string)($value[1] ?? 'ASC');
			} else {
				$field = (string)$key;
				$direction = (string)$value;
			}
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
				throw new \InvalidArgumentException('UNION 排序字段不合法');
			}
			$direction = strtoupper(trim($direction));
			if (!in_array($direction, ['ASC', 'DESC'], true)) {
				throw new \InvalidArgumentException('UNION 排序方向不合法');
			}
			$orderSql[] = $this->quoteIdent($field) . ' ' . $direction;
		}

		$offset = max(0, $offset);
		$limit = max(0, $limit);
		if ($limit === 0 && $offset > 0) {
			throw new \InvalidArgumentException('UNION 无分页条数时不能设置偏移');
		}

		$sql = 'SELECT * FROM (' . implode("\nUNION ALL\n", $parts) . ') AS ' . $this->quoteIdent($alias);
		if ($orderSql !== []) {
			$sql .= ' ORDER BY ' . implode(', ', $orderSql);
		}
		if ($limit > 0) {
			$sql .= $this->formatLimitSql($limit, $offset);
		}

		listen('db_query', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => ['order' => $order, 'offset' => $offset, 'limit' => $limit], 'data' => $bind]);
		$data = $this->runReadQuery($sql, 'select', $bind);
		listen('db_after', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => ['order' => $order, 'offset' => $offset, 'limit' => $limit], 'data' => $data]);

		return is_array($data) ? $data : [];
	}

	/**
	 * 执行 SELECT 查询（不重置 SQL 状态）
	 */
	private function executeSelect(array $cacheArgs): array
	{
		$cached = self::getDataCache($cacheArgs);
		if ($cached !== false && is_array($cached)) {
			return $cached;
		}

		$compiled = $this->compileSelect();
		$sql = $compiled['sql'];
		$bind = $compiled['data'];
		$fields = $compiled['fields'];

		$tojson = (bool)$this->sqlArgs('tojson');

		listen('db_query', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $cacheArgs, 'data' => $bind]);
		$data = $this->checkAttr($this->runReadQuery($sql, 'select', $bind), $fields);
		listen('db_after', ['tabName' => $this->tabName, 'method' => __METHOD__, 'args' => $cacheArgs, 'data' => $data]);

		$data = $tojson ? json($data) : $data;

		self::setDataCache($cacheArgs, $data);
		return $data;
	}

	/**
	 * 查询单条记录
	 * 
	 * 根据主键或条件查询单条记录，返回一维数组。
	 * 支持左关联查询和数据缓存。
	 * 
	 * @param string $pri 主键值，不传则使用where条件
	 * @return array|null 查询结果数组，一维数组格式，未找到则返回null
	 * @example
	 * ```php
	 * // 根据主键查询
	 * $data = $db->find(100);
	 * 
	 * // 使用where条件查询
	 * $data = $db->where(['name' => '张三'])->find();
	 * 
	 * // 结合字段筛选
	 * $data = $db->field('id,name,age')
	 *           ->where(['status' => 1])
	 *           ->find();
	 * ```
	 */
	public function find(int|string $pri = ''): ?array
	{
		// find() 始终隐式限制一条记录；写入状态后才能通过查询缓存资格检查。
		$this->sql['limit'] = [1, 0];

		//缓存
		$data = self::getDataCache([$pri]);
		if($data !== false) {
			$this->resetSql();
			return $data;
		}
		
		//左关联
		$join = self::compileJoin();
		
		//字段
		$fields = self::getFormatStr('fields', ' ', $this->sqlArgs('field'), $this->fieldList, $join['as']);
		//joinField
		if(count($join['joinField']) > 0)
			$fields .= ',' .implode(',', $join['joinField']);
		
		
		//where条件
		$stack = $this->sqlArgs('where');
		if (!is_array($stack)) {
			$stack = [];
		}
		if ($pri !== '') {
			$stack[] = ['logic' => 'AND', 'args' => [$pri]];
		}

		$outerQualifier = empty($join['as'])
			? $this->quoteIdent($this->tabName)
			: rtrim((string)$join['as'], '.');
		$where = $this->compileWhere($stack, $join['as'], ' WHERE ', '', $outerQualifier);
		$data = array_merge($where['data'], $join['joinDate']);
		$where = $where['where'] . $join['joinWhere'];
		
		//order
		$order = $this->compileOrder(self::getFormatStr(
			'order',
			' ORDER BY ',
			$this->sqlArgs('order'),
			$this->implicitOrderDefaults(),
			$join['as']
		)) . $join['joinOrder'];
		$lock = $this->sqlArgs('lock') !== '' ? $this->dialect()::lockClause((string)$this->sqlArgs('lock')) : '';
		$sql = 'SELECT '.$fields.' FROM '. $this->quoteIdent($this->tabName) . (empty($join['as']) ? '' : ' AS ' . rtrim((string)$join['as'], '.')) . $join['join'] . $where. $order . $this->formatLimitSql(1, 0) . $lock;
		
		//结果
		$tojson = $this->sqlArgs('tojson') ? true : false;
		
		//监听数据库查询前
		listen('db_query',['tabName'=>$this->tabName, 'method'=>__METHOD__, 'args'=>[$pri], 'data'=>$data]);
		$data = $this->checkAttr([$this->runReadQuery($sql,'find',$data)], $fields)[0];
		//监听数据库查询后
		listen('db_after',['tabName'=>$this->tabName, 'method'=>__METHOD__, 'args'=>[$pri], 'data'=>$data]);
		
		$data = $tojson ? json($data) : $data;
		
		//缓存结果
		self::setDataCache([$pri], $data);
		$this->resetSql();
		return $data;
	}

	/**
	 * 计算普通查询的隐式排序字段。
	 *
	 * 含 GROUP BY 或聚合表达式的查询若追加主键排序，会在 PostgreSQL 下触发
	 * 分组校验错误；这类查询没有显式 order 时应保持数据库自然顺序。
	 *
	 * @return list<string> 隐式排序字段
	 */
	private function implicitOrderDefaults(): array
	{
		$group = $this->sqlArgs('group');
		if ($group !== '' && $group !== []) {
			return [];
		}

		$field = $this->sqlArgs('field');
		$fieldText = is_array($field) ? implode(',', array_map('strval', $field)) : (string)$field;
		if (preg_match('/\b(?:COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT|JSON_AGG|ARRAY_AGG)\s*\(/i', $fieldText) === 1) {
			return [];
		}

		return [$this->fieldList['pri']];
	}

	/**
	 * 编译 exists 使用的参数化子查询。
	 *
	 * 子查询只保留 FROM、WHERE 与 JOIN 条件，不带排序、分页和锁，避免改变 EXISTS 语义。
	 *
	 * @param string|null $outerQualifier 外层表或别名的已引用限定符
	 * @return array{sql:string,data:array<int,mixed>} 子查询 SQL 与绑定参数
	 */
	public function compileSubquery(?string $outerQualifier = null): array
	{
		$join = self::compileJoin();
		$outerQualifier ??= '';
		$where = $this->compileWhere(
			$this->sqlArgs('where'),
			$join['as'],
			' WHERE ',
			'',
			$outerQualifier
		);
		$bind = array_merge($where['data'], $join['joinDate']);
		$whereSql = $where['where'] . $join['joinWhere'];

		return [
			'sql' => 'SELECT 1 FROM ' . $this->quoteIdent($this->tabName)
				. (empty($join['as']) ? '' : ' AS ' . rtrim((string)$join['as'], '.'))
				. $join['join'] . $whereSql,
			'data' => $bind,
		];
	}

}
