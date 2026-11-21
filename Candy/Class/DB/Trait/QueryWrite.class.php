<?php
/***
 * 写操作与删除
 *
 * 业务说明：insert/update/save/delete、setInc/setDec、truncate、conCat
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-23 08:40:32 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Core\Debug;
use Candy\Extend\DB\DbExpression;
use Candy\Extend\Validate\{Csrf, DbBridge};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait QueryWrite {
	/**
	 * 字段级写权限过滤（批量赋值保护）
	 *
	 * 规则优先级：
	 * ① $fillable 非空 → 白名单：只有列出的字段可写；
	 * ② $fillable 为空 → $readonly 黑名单：列出的字段被剔除；
	 * ③ 两者都空 → 不过滤（保持历史行为，兼容存量项目）。
	 *
	 * 自动时间戳字段（createTime / updateTime）永远放行 —— 它们由框架维护，
	 * 若被业务白名单挡掉，addTimeFields() 写入会被无声丢弃。
	 * update 场景额外挡 addtime（创建时间不允许被更新覆盖）。
	 *
	 * @param array  $data  待写入的数据（已过 check()）
	 * @param string $scene insert|update
	 * @return array 过滤后的数据
	 * @access protected
	 */
	protected function filterWritable(array $data, string $scene = 'insert'): array
	{
		if ($data === []) {
			return $data;
		}

		$fillable = is_array($this->fillable ?? null) ? $this->fillable : [];
		$readonly = is_array($this->readonly ?? null) ? $this->readonly : [];

		// 两者都空：不过滤，直接返回（兼容未声明白名单的存量 Model）
		if ($fillable === [] && $readonly === []) {
			return $data;
		}

		$fillable = array_map(fn ($v): string => strtolower((string) $v), $fillable);
		$readonly = array_map(fn ($v): string => strtolower((string) $v), $readonly);
		if ($scene === 'update') {
			$readonly[] = 'addtime';
		}

		// 自动时间戳字段永远放行
		$timeFields = [
			strtolower((string) ($this->createTime ?? 'AddTime')),
			strtolower((string) ($this->updateTime ?? 'UpTime')),
		];

		$out = [];
		$hitBusinessField = false;   // 是否有业务字段通过白名单
		foreach ($data as $key => $value) {
			$lower = strtolower((string) $key);

			if (in_array($lower, $timeFields, true)) {
				$out[$key] = $value;
				continue;
			}

			// ① 白名单优先
			if ($fillable !== []) {
				if (in_array($lower, $fillable, true)) {
					$out[$key] = $value;
					$hitBusinessField = true;
				}
				continue;
			}

			// ② 黑名单
			if (in_array($lower, $readonly, true)) {
				continue;
			}

			$out[$key] = $value;
			$hitBusinessField = true;
		}

		// 白名单模式下业务字段一个都没命中 → 整条拒绝。
		// 不能只留时间戳，否则会插进一条「只有 addtime/uptime 的空记录」。
		if ($fillable !== [] && !$hitBusinessField) {
			self::setMsg('所有字段均不在可写白名单内，操作已拒绝');

			return [];
		}

		return $out;
	}

	/**
	 * 插入单条记录
	 * 
	 * 向数据库中插入一条新记录，支持数据验证、数据过滤和自动填充。
	 * 自动处理时间戳字段（创建时间、更新时间）。
	 * 支持修改器处理数据。
	 * 
	 * @param array $array 要插入的数据数组，默认为$_POST
	 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
	 * @param int $validate 是否进行数据验证（1:验证，0:不验证）
	 * @return mixed 成功返回插入ID，失败返回false
	 * @example
	 * ```php
	 * // 插入一条记录
	 * $result = $db->insert(['name' => '张三', 'age' => 25, 'status' => 1]);
	 * 
	 * // 使用默认过滤但不验证
	 * $result = $db->insert(['name' => '张三', 'age' => 25], 1, 0);
	 * ```
	 */
	public function insert(array $array = [],int $filter = 1,int $validate = 0): int|false
	{
		if ($array === [] && isset($this->sql['data']) && is_array($this->sql['data']) && $this->sql['data'] !== []) {
			$array = $this->sql['data'];
		}
		if ($array === []) {
			self::setMsg('insert/add 必须传入数据数组');
			return false;
		}

		if($validate || (isset($array['token']) && !empty($array['token']))){
			$result = DbBridge::check($this, $array, 'add');
			$vali = $result['ok'];
			if (!$vali) {
				self::setMsg($result['message']);
				return false;
			}
		}else{
			$vali = true;	
		}
		
		if($vali){
			$array = $this->check($array, $filter, 'insert');

			// 字段级写权限：$fillable 白名单优先，否则 $readonly 黑名单
			$array = $this->filterWritable($array, 'insert');

			// 过滤后可能为空（字段全不在表内 / 全被白名单挡掉），
			// 此时不能拼 INSERT INTO t() VALUES ()，必须显式拒绝
			if ($array === []) {
				self::getMsg() === '' && self::setMsg('没有可写入的字段（检查字段名是否存在或是否在白名单内）');
				return false;
			}

			// 模型事件：beforeInsert（钩子可原地修改 $array，返回 false 中断插入）
			if (!$this->fireModelEvent('beforeInsert', $array)) {
				self::setMsg('beforeInsert 事件中断了插入操作');
				return false;
			}

			$cols = array_map(fn (string $k): string => $this->quoteIdent($k), array_keys($array));
			$sql = 'INSERT INTO ' . $this->tabName . '(' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($array), '?')) . ')';
			//删除对应表的缓存
			self::delDataCache();
			$result = $this->query($sql,'insert',array_values($array));
			$this->resetSql();

			// 模型事件：afterInsert（带上新主键，便于监听器取用）
			$afterPayload = $array;
			$afterPayload['__insertId'] = $result;
			$this->fireModelEvent('afterInsert', $afterPayload);

			return $result;
		}else{
			return false;
		}
	}

	/**
	 * 插入记录（insert方法的别名）
	 * 
	 * 与insert方法功能完全相同，用于向数据库中插入一条新记录。
	 * 
	 * @param array $array 要插入的数据数组，默认为$_POST
	 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
	 * @param int $validate 是否进行数据验证（1:验证，0:不验证）
	 * @return mixed 成功返回插入ID，失败返回false
	 * @see DBServer::insert()
	 * @example
	 * ```php
	 * // 插入一条记录
	 * $result = $db->add(['name' => '张三', 'age' => 25, 'status' => 1]);
	 * ```
	 */
	public function add(array $array = [],int $filter = 1,int $validate = 0)
	{
		return $this->insert($array, $filter, $validate);
	}

	/**
	 * 插入记录并按当前数据库方言可靠返回主键。
	 *
	 * PostgreSQL 使用 RETURNING，MySQL 保持驱动 insert_id 语义；不改变旧 insert/add 行为。
	 *
	 * @param array $array 插入数据
	 * @param string $pk 主键字段
	 * @param int $filter 是否按字段定义过滤
	 * @param int $validate 是否执行验证
	 * @return int|false 主键或失败
	 */
	public function insertGetId(array $array = [], string $pk = 'id', int $filter = 1, int $validate = 0): int|false
	{
		if ($array === [] && isset($this->sql['data']) && is_array($this->sql['data'])) { $array = $this->sql['data']; }
		if ($array === [] || $pk === '') { self::setMsg('insertGetId 必须传入数据和主键'); return false; }
		if ($validate || isset($array['token'])) {
			$result = DbBridge::check($this, $array, 'add');
			if (!$result['ok']) { self::setMsg($result['message']); return false; }
		}
		$array = $this->check($array, $filter, 'insert');
		$columns = array_keys($array);
		$sql = 'INSERT INTO ' . $this->tabName . '(' . implode(',', array_map(fn(string $column): string => $this->quoteIdent($column), $columns))
			. ') VALUES (' . implode(',', array_fill(0, count($array), '?')) . ')';
		$dialect = $this->dialect();
		$returning = $dialect::returningClause($pk);
		self::delDataCache();
		try {
			$result = $this->query($sql . $returning, $returning === '' ? 'insert' : 'insertgetid', array_values($array));
        } catch (\Throwable $exception) {
            throw $exception;
        }
		$this->resetSql();
		return is_int($result) ? $result : false;
	}

	/**
	 * 按唯一冲突键新增或更新记录。
	 *
	 * @param array $array 写入数据
	 * @param array<int,string> $conflictKeys 唯一冲突键
	 * @param array<int,string>|null $updateColumns 更新列，空数组表示冲突时不更新
	 * @param string $pk 返回主键
	 * @param int $filter 是否按字段定义过滤
	 * @return int|false 主键、影响结果或失败
	 */
	public function upsert(array $array, array $conflictKeys, ?array $updateColumns = null, string $pk = 'id', int $filter = 1): int|false
	{
		if ($array === [] || $conflictKeys === []) { self::setMsg('upsert 必须传入数据和冲突键'); return false; }
		$array = $this->check($array, $filter, 'insert');
		$columns = array_keys($array);
		foreach ($conflictKeys as $column) { if (!in_array($column, $columns, true)) { throw new \InvalidArgumentException('upsert 冲突键不在写入数据中'); } }
		$updateColumns ??= array_values(array_diff($columns, $conflictKeys, [$pk]));
		foreach ($updateColumns as $column) {
			if (!in_array($column, $columns, true)) {
				throw new \InvalidArgumentException('upsert 更新列不在写入数据中');
			}
		}
		$dialect = $this->dialect();
		$sql = 'INSERT INTO ' . $this->tabName . '(' . implode(',', array_map(fn(string $column): string => $this->quoteIdent($column), $columns))
			. ') VALUES (' . implode(',', array_fill(0, count($array), '?')) . ')'
			. $dialect::upsertClause($columns, $conflictKeys, $updateColumns);
		$returning = $dialect::returningClause($pk);
		self::delDataCache();
		$result = $this->query($sql . $returning, $returning === '' ? 'insert' : 'insertgetid', array_values($array));
		$this->resetSql();
		return is_int($result) ? $result : false;
	}

	/**
	 * 按唯一冲突键新增或使用安全表达式更新记录。
	 *
	 * 表达式只能引用当前模型字段或 DbExpression::excluded()，所有外部值均绑定；
	 * 适用于库存累加等必须保持唯一键并发原子性的写入。
	 *
	 * @param array<string,mixed> $array 插入数据
	 * @param array<int,string> $conflictKeys 唯一冲突键
	 * @param array<string,mixed> $expressions 冲突时的字段表达式
	 * @param string $pk 返回主键
	 * @param int $filter 是否按字段定义过滤
	 * @return int|false 主键或失败
	 */
	public function upsertExpressions(
		array $array,
		array $conflictKeys,
		array $expressions,
		string $pk = 'id',
		int $filter = 1
	): int|false
	{
		if ($array === [] || $conflictKeys === [] || $expressions === []) {
			self::setMsg('upsertExpressions 必须传入数据、冲突键和表达式');
			return false;
		}
		$array = $this->check($array, $filter, 'insert');
		$columns = array_keys($array);
		foreach ($conflictKeys as $column) {
			if (!in_array($column, $columns, true)) {
				throw new \InvalidArgumentException('upsertExpressions 冲突键不在写入数据中');
			}
		}

		$dialect = $this->dialect();
		$columnQualifier = str_contains(strtolower($dialect), 'pgsql') ? $this->tabName : null;
		$compiledExpressions = [];
		$expressionData = [];
		foreach ($expressions as $field => $expression) {
			$field = strtolower((string)$field);
			$this->assertExpressionColumn($field);
				$expression = $expression instanceof DbExpression
					? $expression
					: DbExpression::value($expression);
			foreach ($expression->columns() as $column) {
				$this->assertExpressionColumn($column, false);
			}
			$compiled = $expression->compile(
				fn(string $column): string => $this->quoteIdent($column),
				$dialect,
				$columnQualifier
			);
			$compiledExpressions[$field] = $compiled['sql'];
			$expressionData = array_merge($expressionData, $compiled['data']);
		}

		$sql = 'INSERT INTO ' . $this->tabName . '(' . implode(',', array_map(fn(string $column): string => $this->quoteIdent($column), $columns))
			. ') VALUES (' . implode(',', array_fill(0, count($array), '?')) . ')'
			. $dialect::upsertExpressionClause($columns, $conflictKeys, $compiledExpressions);
		$returning = $dialect::returningClause($pk);
		self::delDataCache();
		try {
			$result = $this->query(
				$sql . $returning,
				$returning === '' ? 'insert' : 'insertgetid',
				array_merge(array_values($array), $expressionData)
			);

			return is_int($result) ? $result : false;
		} finally {
			$this->resetSql();
		}
	}

	/**
	 * 使用单条多值 INSERT 批量新增记录。
	 *
	 * 方法会按数据库方言的绑定参数上限自动分批；同一批数据必须具有相同字段集合。
	 * 分批执行不隐式开启事务，需要全有或全无时由调用方使用 transaction() 包裹。
	 *
	 * @param array<int,array<string,mixed>>|array<string,mixed> $rows 写入行
	 * @param int $filter 是否按字段定义过滤
	 * @param int $validate 是否逐行执行验证
	 * @param int $chunkSize 单批最大行数
	 * @return int|false 受影响行数或失败
	 */
	public function insertAll(
		array $rows,
		int $filter = 1,
		int $validate = 0,
		int $chunkSize = 500
	): int|false
	{
		$normalized = $this->normalizeBatchRows($rows, $filter, $validate);
		if ($normalized === false) {
			return false;
		}
		return $this->executeBatchInsert($normalized, [], [], $chunkSize);
	}

	/**
	 * 将受控链式 SELECT 的结果批量插入当前表。
	 *
	 * 目标列、冲突列和更新列均只接受标识符；来源必须是同一连接方言的 Model
	 * 或 DBServer，并通过 compileSelect() 提供参数化投影、条件和绑定值。该入口
	 * 不接受业务层 SQL 字符串，写入后沿用普通 ORM 的缓存失效与查询状态重置。
	 *
	 * @param array<int,string> $columns 目标表写入列，顺序必须与来源 SELECT 投影一致
	 * @param object $source 已配置字段、条件和投影的来源查询对象
	 * @param array<int,string>|null $conflictKeys 冲突键；null 表示普通插入，空数组表示忽略所有冲突
	 * @param array<int,string> $updateColumns 冲突时更新列，空数组表示忽略冲突
	 * @return int|false 受影响行数或失败
	 */
	public function insertSelect(
		array $columns,
		object $source,
		?array $conflictKeys = null,
		array $updateColumns = []
	): int|false
	{
		$columns = $this->normalizeInsertSelectColumns($columns, 'insertSelect 目标列不能为空');
		$conflictKeys = $conflictKeys === null
			? null
			: $this->normalizeInsertSelectColumns($conflictKeys, 'insertSelect 冲突列不合法');
		$updateColumns = $this->normalizeInsertSelectColumns($updateColumns, 'insertSelect 更新列不合法');
		$this->assertBatchColumns($conflictKeys ?? [], $columns, 'insertSelect 冲突列不在目标列中');
		$this->assertBatchColumns($updateColumns, $columns, 'insertSelect 更新列不在目标列中');
		if ($conflictKeys === null && $updateColumns !== []) {
			throw new \InvalidArgumentException('insertSelect 普通插入不能提供更新列');
		}

		if (!method_exists($source, 'compileSelect')) {
			throw new \InvalidArgumentException('insertSelect 来源必须提供 compileSelect');
		}
		try {
			$sourceDialect = (string)$source->dialectClass();
		} catch (\Throwable $exception) {
			throw new \InvalidArgumentException('insertSelect 来源必须提供 dialectClass', 0, $exception);
		}
		if ($sourceDialect !== $this->dialectClass()) {
			throw new \InvalidArgumentException('insertSelect 来源与目标必须使用同一数据库方言');
		}

		$compiled = $source->compileSelect([
			'order' => false,
			'limit' => false,
			'lock' => false,
		]);
		$selectSql = trim((string)($compiled['sql'] ?? ''));
		if (preg_match('/^SELECT\b/i', $selectSql) !== 1 || str_contains($selectSql, ';')) {
			throw new \InvalidArgumentException('insertSelect 来源必须是单条 SELECT');
		}
		$bindings = $compiled['data'] ?? [];
		if (!is_array($bindings)) {
			throw new \InvalidArgumentException('insertSelect 来源绑定参数不合法');
		}

		$dialect = $this->dialect();
		$columnSql = implode(',', array_map(
			fn(string $column): string => $this->quoteIdent($column),
			$columns
		));
		$sql = 'INSERT INTO ' . $this->tabName . '(' . $columnSql . ') ' . $selectSql;
		if ($conflictKeys !== null) {
			$sql .= $dialect::upsertClause($columns, $conflictKeys, $updateColumns);
		}

		self::delDataCache();
		try {
			$result = $this->query($sql, 'update', $bindings);
			return is_int($result) ? $result : false;
		} finally {
			$this->resetSql();
		}
	}

	/**
	 * 使用单条多值 INSERT 批量新增或更新记录。
	 *
	 * PostgreSQL 生成 ON CONFLICT，MySQL 生成 ON DUPLICATE KEY；空更新列表示冲突时忽略。
	 * 分批执行不隐式开启事务，需要全有或全无时由调用方使用 transaction() 包裹。
	 *
	 * @param array<int,array<string,mixed>>|array<string,mixed> $rows 写入行
	 * @param array<int,string> $conflictKeys 唯一冲突键
	 * @param array<int,string>|null $updateColumns 更新列，null 表示自动排除冲突键和主键
	 * @param int $filter 是否按字段定义过滤
	 * @param int $chunkSize 单批最大行数
	 * @return int|false 受影响行数或失败
	 */
	public function upsertAll(
		array $rows,
		array $conflictKeys,
		?array $updateColumns = null,
		int $filter = 1,
		int $chunkSize = 500
	): int|false
	{
		if ($conflictKeys === []) {
			self::setMsg('upsertAll 冲突键不能为空');
			return false;
		}
		$normalized = $this->normalizeBatchRows($rows, $filter, 0);
		if ($normalized === false) {
			return false;
		}
		$columns = array_keys($normalized[0]);
		$this->assertBatchColumns($conflictKeys, $columns, 'upsertAll 冲突键不在写入数据中');
		$updateColumns ??= array_values(array_diff(
			$columns,
			$conflictKeys,
			[(string)($this->fieldList['pri'] ?? 'id')]
		));
		$this->assertBatchColumns($updateColumns, $columns, 'upsertAll 更新列不在写入数据中');
		return $this->executeBatchInsert(
			$normalized,
			$conflictKeys,
			$updateColumns,
			$chunkSize
		);
	}

	/**
	 * 过滤并统一批量写入行的字段顺序。
	 *
	 * @param array<int,array<string,mixed>>|array<string,mixed> $rows 原始写入行
	 * @param int $filter 是否按字段定义过滤
	 * @param int $validate 是否逐行执行验证
	 * @return array<int,array<string,mixed>>|false 规范化行或失败
	 */
	private function normalizeBatchRows(array $rows, int $filter, int $validate): array|false
	{
		if ($rows === []) {
			self::setMsg('批量写入数据不能为空');
			return false;
		}
		if (!array_is_list($rows)) {
			$rows = [$rows];
		}
		$normalized = [];
		$columns = [];
		foreach ($rows as $row) {
			if (!is_array($row) || $row === []) {
				self::setMsg('批量写入的每一行都必须是非空数组');
				return false;
			}
			if ($validate !== 0 || isset($row['token'])) {
				$validation = DbBridge::check($this, $row, 'add');
				if (!$validation['ok']) {
					self::setMsg($validation['message']);
					return false;
				}
			}
			$checked = $this->check($row, $filter, 'insert');
			if ($checked === []) {
				self::setMsg('批量写入行经过字段过滤后为空');
				return false;
			}
			if ($columns === []) {
				$columns = array_keys($checked);
			}
			if (array_diff($columns, array_keys($checked)) !== []
				|| array_diff(array_keys($checked), $columns) !== []) {
				self::setMsg('批量写入的字段集合必须一致');
				return false;
			}
			$ordered = [];
			foreach ($columns as $column) {
				$ordered[$column] = $checked[$column];
			}
			$normalized[] = $ordered;
		}
		return $normalized;
	}

	/**
	 * 执行分批多值 INSERT/UPSERT。
	 *
	 * @param array<int,array<string,mixed>> $rows 规范化写入行
	 * @param array<int,string> $conflictKeys 冲突键，空数组表示普通插入
	 * @param array<int,string> $updateColumns 更新列
	 * @param int $chunkSize 单批最大行数
	 * @return int|false 受影响行数或失败
	 */
	private function executeBatchInsert(
		array $rows,
		array $conflictKeys,
		array $updateColumns,
		int $chunkSize
	): int|false
	{
		$columns = array_keys($rows[0]);
		$dialect = $this->dialect();
		$parameterLimit = $dialect::maxBindParameters();
		$maxRowsByParameters = max(1, intdiv($parameterLimit, count($columns)));
		$chunkSize = max(1, min($chunkSize, $maxRowsByParameters));
		$columnSql = implode(',', array_map(
			fn(string $column): string => $this->quoteIdent($column),
			$columns
		));
		$affected = 0;
		self::delDataCache();
		try {
			foreach (array_chunk($rows, $chunkSize) as $chunk) {
				$placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
				$sql = 'INSERT INTO ' . $this->tabName . '(' . $columnSql . ') VALUES '
					. implode(',', array_fill(0, count($chunk), $placeholder));
				if ($conflictKeys !== []) {
					$sql .= $dialect::upsertClause($columns, $conflictKeys, $updateColumns);
				}
				$bindings = [];
				foreach ($chunk as $row) {
					array_push($bindings, ...array_values($row));
				}
				$result = $this->query($sql, 'update', $bindings);
				if (!is_int($result)) {
					return false;
				}
				$affected += $result;
			}
			return $affected;
		} finally {
			$this->resetSql();
		}
	}

	/**
	 * 校验列名都存在于批量写入字段集合。
	 *
	 * @param array<int,string> $requested 待校验列
	 * @param array<int,string> $columns 写入字段集合
	 * @param string $message 异常消息
	 * @return void
	 */
	private function assertBatchColumns(array $requested, array $columns, string $message): void
	{
		foreach ($requested as $column) {
			if (!in_array($column, $columns, true)) {
				throw new \InvalidArgumentException($message . '：' . $column);
			}
		}
	}

	/**
	 * 规范化 INSERT...SELECT 列名并拒绝重复标识符。
	 *
	 * @param array<int,string> $columns 原始列名
	 * @param string $emptyMessage 空数组错误消息
	 * @return array<int,string> 规范化列名
	 */
	private function normalizeInsertSelectColumns(array $columns, string $emptyMessage): array
	{
		if ($columns === []) {
			if ($emptyMessage === 'insertSelect 目标列不能为空') {
				throw new \InvalidArgumentException($emptyMessage);
			}
			return [];
		}

		$normalized = [];
		foreach ($columns as $column) {
			$column = trim((string)$column);
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
				throw new \InvalidArgumentException($emptyMessage . '：' . $column);
			}
			if (in_array($column, $normalized, true)) {
				throw new \InvalidArgumentException('insertSelect 列不能重复：' . $column);
			}
			$normalized[] = $column;
		}

		return $normalized;
	}

	/**
	 * 使用安全表达式原子更新字段。
	 *
	 * 该入口不接受 SQL 字符串，字段名和表达式引用列均经过模型字段校验，外部值全部参数绑定。
	 * 为防止误更新全表，必须先调用 where()。
	 *
	 * @param array<string,mixed> $expressions 字段到表达式或绑定值
	 * @return int|false 受影响行数或失败
	 */
	public function updateExpressions(array $expressions): int|false
	{
		if ($expressions === [] || !$this->hasWhere()) {
			self::setMsg('updateExpressions 必须传入表达式并设置 where 条件');
			$this->resetSql();
			return false;
		}
		if (!$this->assertWriteCsrfToken()) {
			$this->resetSql();
			return false;
		}
		try {
			$dialect = $this->dialect();
			$setParts = [];
			$data = [];
			foreach ($expressions as $field => $expression) {
				$field = strtolower((string)$field);
				$this->assertExpressionColumn($field);
				$expression = $expression instanceof DbExpression
					? $expression
					: DbExpression::value($expression);
				if ($expression->containsExcluded()) {
					throw new \InvalidArgumentException('EXCLUDED 表达式只能用于 upsertExpressions');
				}
				foreach ($expression->columns() as $column) {
					$this->assertExpressionColumn($column, false);
				}
				$compiled = $expression->compile(
					fn(string $column): string => $this->quoteIdent($column),
					$dialect
				);
				$setParts[] = $this->quoteIdent($field) . ' = ' . $compiled['sql'];
				$data = array_merge($data, $compiled['data']);
			}
			$where = $this->compileWhere($this->sqlArgs('where'));
			$data = array_merge($data, $where['data']);
			$sql = 'UPDATE ' . $this->tabName . ' SET ' . implode(',', $setParts) . $where['where'];
			$result = $this->query($sql, 'update', $data);
			if (is_int($result) && $result > 0) {
				self::delDataCache();
			}
			return is_int($result) ? $result : false;
		} finally {
			$this->resetSql();
		}
	}

	/**
	 * 校验更新表达式字段属于当前模型且可写。
	 *
	 * @param string $column 字段名
	 * @param bool $writable 是否校验只读限制
	 * @return void
	 */
	private function assertExpressionColumn(string $column, bool $writable = true): void
	{
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)
			|| !in_array(strtolower($column), $this->fieldList, true)) {
			throw new \InvalidArgumentException('更新表达式字段不属于当前模型：' . $column);
		}
		if ($writable && in_array(strtolower($column), $this->readonly ?? [], true)) {
			throw new \InvalidArgumentException('更新表达式不能修改只读字段：' . $column);
		}
	}

	/**
	 * 更新记录
	 * 
	 * 更新数据表中符合条件的记录，支持数据验证、数据过滤和自动填充。
	 * 自动处理更新时间字段。
	 * 支持修改器处理数据。
	 * 支持只读字段保护，防止被修改。
	 * 
	 * @param array $array 要更新的数据数组，默认为$_POST
	 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
	 * @param int $validate 是否进行数据验证（1:验证，0:不验证）
	 * @return mixed 成功返回受影响的行数，失败返回false
	 * @example
	 * ```php
	 * // 更新指定条件的记录
	 * $result = $db->where(['id' => 100])->update(['name' => '李四', 'age' => 26]);
	 * 
	 * // 通过主键更新（数据中包含主键）
	 * $result = $db->update(['id' => 100, 'name' => '李四', 'age' => 26]);
	 * ```
	 */
	public function update(array $array = [],int $filter = 1,int $validate = 0): int|false
	{
		if ($array === [] && isset($this->sql['data']) && is_array($this->sql['data']) && $this->sql['data'] !== []) {
			$array = $this->sql['data'];
		}
		if ($array === []) {
			self::setMsg('update/save 必须传入数据数组');
			return false;
		}

		if($validate || (isset($array['token']) && !empty($array['token']))){
			$result = DbBridge::check($this, $array, 'mod');
			$vali = $result['ok'];
			if (!$vali) {
				self::setMsg($result['message']);
				return false;
			}
		}else{
			$vali = true;	
		}

		if($vali){  
			$data = [];
			$pri_value = '';
	      	if(is_array($array)){
				$array = array_change_key_case($array);
				if(array_key_exists($this->fieldList['pri'], $array)){
					$pri_value = $array[$this->fieldList['pri']];
					unset($array[$this->fieldList['pri']]);	
		       	}
				
				$array = $this->check($array, $filter, 'update');

				// 模型事件：beforeUpdate（钩子可原地修改 $array，返回 false 中断更新）
				if (!$this->fireModelEvent('beforeUpdate', $array)) {
					self::setMsg('beforeUpdate 事件中断了更新操作');
					$this->resetSql();
					return false;
				}
				
				// 字段级写权限：$fillable 白名单优先，否则 $readonly 黑名单（update 额外挡 addtime）
				$array = $this->filterWritable($array, 'update');

   				$s = '';
   				foreach ($array as $k=>$v){
					if(true){
						$s .= $this->quoteIdent($k) . '=?,';
						$data[] = $v;  //value
					}
			 	}
				
			 	$s = rtrim($s, ',');
    			$setfield = $s;
			}else{
				$setfield = $array;
			}

			// SET 子句为空时禁止执行，避免生成 UPDATE table WHERE ... 语法错误
			if (is_array($array) && $setfield === '') {
				self::setMsg('没有可更新的字段');
				$this->resetSql();
				return false;
			}
			
			// 数据含主键时优先按主键更新，忽略残留的链式 where，防止误更新多行
			if($pri_value !== '' && $pri_value !== null){
				$sql = "UPDATE {$this->tabName} SET {$setfield}  WHERE {$this->fieldList["pri"]} = ?";
				$data[] = $pri_value;
			}elseif($this->hasWhere()){
				$where = $this->compileWhere($this->sqlArgs('where'));
				$sql = "UPDATE {$this->tabName} SET {$setfield}".$where['where'];
				
				if(!empty($where['data'])){
					foreach($where['data'] as $v){
						$data[] = $v; //value
					}
				}
			}else{
				self::setMsg('update/save 缺少主键或 where 条件');
				$this->resetSql();
				return false;
			}

			//order 参数绑定
			$sql .= $this->sqlArgs('order') != '' ? $this->compileOrder($this->sqlArgs('order')) : '';
			
			//limit 参数绑定
			$sql .= $this->sqlArgs('limit') != '' ? $this->compileLimit($this->sql["limit"]) : '';
			
			//优化0更新update
			$result = $this->query($sql, 'update', $data);	
			if($result === 0){
				//改变记录的CSRFTOKEN
				session('CSRFTOKEN', '123');
				self::setMsg('数据没有变动');
			}else{
				//删除对应表的缓存
				self::delDataCache();
			}
			$this->resetSql();

			// 模型事件：afterUpdate（受影响行数放进载荷）
			$afterPayload = is_array($array) ? $array : [];
			$afterPayload['__affectedRows'] = $result;
			$this->fireModelEvent('afterUpdate', $afterPayload);

			return $result;
		}else{
			return false;
		}
	}

	/**
	 * 更新记录（update方法的别名）
	 * 
	 * 与update方法功能完全相同，用于更新数据表中符合条件的记录。
	 * 
	 * @param array $array 要更新的数据数组，默认为$_POST
	 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
	 * @param int $validate 是否进行数据验证（1:验证，0:不验证）
	 * @return mixed 成功返回受影响的行数，失败返回false
	 * @see DBServer::update()
	 * @example
	 * ```php
	 * // 更新指定条件的记录
	 * $result = $db->where(['id' => 100])->save(['name' => '李四', 'age' => 26]);
	 * ```
	 */
	public function save(array $array = [],int $filter = 1,int $validate = 0): int|false
	{
		return $this->update($array, $filter, $validate);
	}

	/**
	 * 批量保存数据（插入或更新）
	 * 
	 * 根据数据中是否包含主键值自动判断是执行插入还是更新操作。
	 * 支持一维数组和二维数组操作。
	 * 
	 * @param array $array 要保存的数据数组，支持一维或二维数组
	 * @param int $filter 是否过滤数据（1:过滤，0:不过滤）
	 * @param int $validate 是否进行数据验证（1:验证，0:不验证）
	 * @return int 成功操作的记录数
	 * @example
	 * ```php
	 * // 保存单条记录（一维数组）
	 * $result = $db->saveAll(['id' => 100, 'name' => '王五', 'age' => 27]);
	 * 
	 * // 批量保存多条记录（二维数组）
	 * $result = $db->saveAll([
	 *     ['name' => '赵六', 'age' => 28],  // 插入
	 *     ['id' => 101, 'name' => '钱七', 'age' => 29]  // 更新
	 * ]);
	 * ```
	 */
	public function saveAll(array $array = [],int $filter = 1,int $validate = 0): int
	{
		if (empty($array)) {
			self::setMsg('saveAll方法未执行成功，数组不能为空。');
			return 0;
		}
		
		//一维数组为关联数组 二维数组为索引数组
		if(count($array) == count($array, 1)){
			$array = [$array];
		}
		$change = 0;
		foreach($array as $newarray){
			if(is_array($newarray)){
				//一维数组
				if(isset($newarray[$this->fieldList['pri']])){
					// 更新数组
					$result = $this->update($newarray, $filter, $validate);
					if ($result !== false) {
						$change++;
					}
				}else{
					// 插入数组
					$result = $this->insert($newarray, $filter, $validate);
					if ($result !== false) {
						$change++;
					}
				}
			}
		}
		return $change;
	}

	/**
	 * 删除记录
	 * 
	 * 根据指定条件删除记录，默认执行软删除（更新删除时间字段）。
	 * 支持强制硬删除（完全从数据库删除）。
	 * 
	 * @param mixed ...$args 删除条件，支持多种格式；最后一个参数为true时执行硬删除
	 * @return int|bool 成功返回受影响的行数，失败返回false
	 * @example
	 * ```php
	 * // 软删除（默认）
	 * $result = $db->where(['id' => 100])->delete();
	 * 
	 * // 硬删除
	 * $result = $db->where(['id' => 100])->delete(true);
	 * 
	 * // 根据主键删除
	 * $result = $db->delete(100);
	 * 
	 * // 强制硬删除指定主键
	 * $result = $db->delete(100, true);
	 * ```
	 */
	public function delete(...$args): int
	{
		$where = '';
		$setfield = '';
		$data = [];
		$vali = $this->assertWriteCsrfToken();
		if($vali){
			//软删除字段
			isset($this->deleteTime) OR $this->deleteTime = 'deleteTime';
			if(in_array(true, $args) || !in_array(strtolower($this->deleteTime), $this->fieldList)){
				//删除
				empty($args) && $args = [true];
				if($args[0] === true){  //delete(true)
					$where = $this->compileWhere($this->sqlArgs('where'));
				}else{
					if(is_int($args[0])){  //delete(1,true)
						$where = $this->compileWhere([$args[0]]);
					}else{  //delete([1,['sex'=>'男']],true)
						$where = $this->compileWhere($args[0]);
					}
				}
				
				$data = $where['data'] ?? [];
				$where = $where['where'];
				$order = $this->sqlArgs('order') != '' ? $this->compileOrder($this->sql["order"][0]) : '';
				$limit = $this->sqlArgs('limit') != '' ? $this->compileLimit($this->sql["limit"]) : '';
				if($where == '' && $limit == ''){
					$where = " where {$this->fieldList["pri"]}=''";
				}
				$sql = "DELETE FROM {$this->tabName}{$where}{$order}{$limit}";
			}else{
				//软删除
				$where = count($args)>0 ? $this->compileWhere($args) : $this->compileWhere($this->sqlArgs('where'));
				// SET 的删除时间参数在 SQL 里排最前，必须先 unshift 到 where 参数之前。
				// 原写法 $where['data'] ?? [time()] 在「有 where 条件」时 ?? 不生效 → 时间参数丢失
				// → 占位符数量不匹配（bind message supplies N parameters, but requires N+1）
				$data = is_array($where['data'] ?? null) ? $where['data'] : [];
				array_unshift($data, date('Y-m-d H:i:s', time()));
				$where = $where['where'];
				$order = $this->sqlArgs('order') != '' ?  $this->compileOrder($this->sql["order"][0]) : '';
				$limit = $this->sqlArgs('limit') != '' ? $this->compileLimit($this->sql["limit"]) : '';
				$setfield .= $this->quoteIdent($this->deleteTime) . '=? ';
				$sql = "UPDATE {$this->tabName} SET {$setfield}{$where}{$order}{$limit}";
			}
			// 模型事件：beforeDelete（delete 无业务数据可改，载荷只带参数与模式）
			$delPayload = [
				'args' => $args,
				'soft' => !in_array(true, $args, true),
			];
			if (!$this->fireModelEvent('beforeDelete', $delPayload)) {
				self::setMsg('beforeDelete 事件中断了删除操作');
				$this->resetSql();
				return 0;
			}

			//删除对应表的缓存
			self::delDataCache();
			$result = $this->query($sql, 'update', $data);
			$this->resetSql();

			// 模型事件：afterDelete
			$delPayload['__affectedRows'] = $result;
			$this->fireModelEvent('afterDelete', $delPayload);

			return $result;
		}else{
			return 0;
		}
	}

	/**
	 * 字段值自增
	 * 
	 * 将指定字段的值增加指定的数量。
	 * 支持条件筛选，精确控制哪些记录需要自增。
	 * 
	 * @param string $field 要自增的字段名
	 * @param int $num 自增的数量，默认为1
	 * @return mixed 成功返回受影响的行数，失败返回false
	 * @example
	 * ```php
	 * // 将所有记录的views字段加1
	 * $result = $db->setInc('views');
	 * 
	 * // 将指定条件记录的score字段加5
	 * $result = $db->where(['id' => 100])->setInc('score', 5);
	 * ```
	 */
	public function setInc(?string $field = null,int $num = 1)
	{
		if(is_null($field))
			return false;
		
		$vali = $this->assertWriteCsrfToken();
		if($vali){
			$data = [];
			if($this->hasWhere()){
				$where = $this->compileWhere($this->sqlArgs('where'));
				$data = $where['data'];
				$sql = "UPDATE  {$this->tabName} SET ".$this->quoteIdent($field)." = ".$this->quoteIdent($field)." + $num".$where['where'];
			}else{
				$sql = "UPDATE  {$this->tabName} SET ".$this->quoteIdent($field)." = ".$this->quoteIdent($field)." + $num WHERE 1";
			}
			//删除对应表的缓存
			self::delDataCache();
			$result = $this->query($sql, 'update', $data);
			$this->resetSql();
			return $result;
		}else{
			return false;
		}
	}

	/**
	 * 字段值自减
	 * 
	 * 将指定字段的值减少指定的数量。
	 * 支持条件筛选，精确控制哪些记录需要自减。
	 * 
	 * @param string $field 要自减的字段名
	 * @param int $num 自减的数量，默认为1
	 * @return mixed 成功返回受影响的行数，失败返回false
	 * @example
	 * ```php
	 * // 将所有记录的stock字段减1
	 * $result = $db->setDec('stock');
	 * 
	 * // 将指定条件记录的points字段减10
	 * $result = $db->where(['id' => 100])->setDec('points', 10);
	 * ```
	 */
	public function setDec(?string $field = null,int $num = 1)
	{
		if(is_null($field))
			return false;
		
		$vali = $this->assertWriteCsrfToken();
		if($vali){
			$data = [];
			if($this->hasWhere()){
				$where = $this->compileWhere($this->sqlArgs('where'));
				$data = $where['data'];
				$sql = "UPDATE {$this->tabName} SET ".$this->quoteIdent($field)." = ".$this->quoteIdent($field)." - $num".$where['where'];
			}else{
				$sql = "UPDATE  {$this->tabName} SET ".$this->quoteIdent($field)." = ".$this->quoteIdent($field)." - $num WHERE 1";
			}
			//删除对应表的缓存
			self::delDataCache();
			$result = $this->query($sql, 'update', $data);
			$this->resetSql();
			return $result;
		}else{
			return false;
		}
	}

	/**
	 * 删除记录清空表数据
	 * 
	 * 完全清空数据表中的所有记录，但保留表结构。
	 * 默认按当前方言执行清空；PostgreSQL 仅在 $restartIdentity 为 true 时重置自增序列，
	 * 仅在 $cascade 为 true 时级联清理引用当前表的表。
	 * 注意：此操作不可逆，谨慎使用。
	 * 
	 * @param bool $restartIdentity PostgreSQL 是否同时重置自增序列；其他方言沿用其默认行为
	 * @param bool $cascade PostgreSQL 是否级联清理引用当前表的表；其他方言忽略
	 * @return mixed 成功返回受影响行数或 true，失败返回 false
	 * @example
	 * ```php
	 * // 清空当前操作表的所有数据
	 * $result = $db->truncate();
	 * 
	 * // 清空指定表的数据
	 * $result = $db->table('users')->truncate();
	 * ```
	 */
	public function truncate(bool $restartIdentity = false, bool $cascade = false): mixed
	{
		if (!$this->assertWriteCsrfToken()) {
			return false;
		}

		$dialect = $this->dialect();
		if ($dialect === \Candy\Extend\DB\Dialect\Sqlite::class) {
			$sql = 'DELETE FROM ' . $this->quoteIdent($this->tabName);
		} else {
			$sql = 'TRUNCATE TABLE ' . $this->quoteIdent($this->tabName);
			if ($restartIdentity && $dialect === \Candy\Extend\DB\Dialect\Pgsql::class) {
				$sql .= ' RESTART IDENTITY';
			}
			if ($cascade && $dialect === \Candy\Extend\DB\Dialect\Pgsql::class) {
				$sql .= ' CASCADE';
			}
		}
		self::delDataCache();

		try {
			return $this->query($sql, 'other', []);
		} finally {
			$this->resetSql();
		}
	}

	/**
	 * 字段值拼接
	 * 
	 * 将指定字符串拼接到指定字段值的末尾。
	 * 支持条件筛选，精确控制哪些记录需要拼接。
	 * 
	 * @param string $field 要拼接的字段名
	 * @param string $str 要拼接的字符串
	 * @return mixed 成功返回受影响的行数，失败返回false
	 * @example
	 * ```php
	 * // 将指定条件记录的name字段末尾添加字符串
	 * $result = $db->where(['id' => 100])->conCat('name', '_vip');
	 * 
	 * // 为所有记录的remark字段添加后缀
	 * $result = $db->conCat('remark', ' [备注]');
	 * ```
	 */
	public function conCat(?string $field = null,string $str = '')
	{
		if(is_null($field) || empty($str))
			return false;
		
		$vali = $this->assertWriteCsrfToken();
		if($vali){
			$data=[];
			if($this->hasWhere()){
				$where=$this->compileWhere($this->sqlArgs('where'));
				$data=$where['data'];
				$sql="UPDATE {$this->tabName} SET ".$this->quoteIdent($field)." = CONCAT(".$this->quoteIdent($field).",'{$str}') ".$where['where'];
			}else{
				$sql="UPDATE {$this->tabName} SET ".$this->quoteIdent($field)." = CONCAT(".$this->quoteIdent($field).",'{$str}') WHERE 1";
			}
			//删除对应表的缓存
			self::delDataCache();
			return $this->query($sql, 'update', $data);
		}else{
			return false;
		}
	}

	/**
	 * 写操作 CSRF 校验（链式 token() 时）
	 *
	 * 未设置 token 链时直接通过；失败时写入 setMsg
	 *
	 * @return bool
	 * @access protected
	 */
	protected function assertWriteCsrfToken(): bool
	{
		if (empty($this->sqlArgs('token'))) {
			return true;
		}
		if (Csrf::check((string)$this->sqlArgs('token')[0])) {
			return true;
		}
		self::setMsg('Token令牌无效请刷新后重新提交');
		return false;
	}

}
