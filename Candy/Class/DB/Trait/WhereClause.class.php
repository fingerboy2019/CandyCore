<?php
/***
 * WHERE 条件链式与编译
 *
 * 业务说明：where/orWhere、字段比较、EXISTS 子查询、compileWhere、软删除条件 compileTrashed
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-03 23:14:47 $
 *
 * @package Candy\Extend\DB\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait WhereClause {
	/**
	 * 追加 WHERE 条件（AND）
	 *
	 * @param mixed ...$args 条件参数，格式同 where 链式调用
	 * @return static
	 */
	public function where(mixed ...$args): static
	{
		$this->appendWhereClause('AND', $args);
		return $this;
	}

	/**
	 * 追加 WHERE 条件（OR）。
	 *
	 * @param mixed ...$args 条件参数，格式同 where 链式调用
	 * @return static 当前查询对象
	 */
	public function orWhere(mixed ...$args): static
	{
		$this->appendWhereClause('OR', $args);
		return $this;
	}

	/**
	 * 追加受控数据库表达式条件（AND）。
	 *
	 * 表达式由 DbExpression 构造并在编译时统一引用标识符、绑定参数；
	 * 禁止将 upsert 专用 EXCLUDED 节点用于普通 WHERE。
	 *
	 * @param \Candy\Extend\DB\DbExpression $expression 比较或 NULL 判断表达式
	 * @return static 当前查询对象
	 */
	public function whereExpression(\Candy\Extend\DB\DbExpression $expression): static
	{
		$this->appendWhereExpression('AND', $expression);
		return $this;
	}

	/**
	 * 追加受控数据库表达式条件（OR）。
	 *
	 * @param \Candy\Extend\DB\DbExpression $expression 比较或 NULL 判断表达式
	 * @return static 当前查询对象
	 */
	public function orWhereExpression(\Candy\Extend\DB\DbExpression $expression): static
	{
		$this->appendWhereExpression('OR', $expression);
		return $this;
	}

	/**
	 * 添加字段与字段之间的比较条件。
	 *
	 * @param string $left 当前查询字段
	 * @param string $operator 比较运算符
	 * @param string|array{0:string,1:string} $right 当前查询字段或外层字段
	 * @return static 当前查询对象
	 */
	public function whereColumn(string $left, string $operator, string|array $right): static
	{
		$operator = strtoupper(trim($operator));
		if (!in_array($operator, ['=', '<>', '!=', '>', '>=', '<', '<='], true)) {
			throw new \InvalidArgumentException('whereColumn 不支持的比较运算符');
		}
		if (!$this->isColumnIdentifier($left)) {
			throw new \InvalidArgumentException('whereColumn 左字段名不合法');
		}
		if (is_string($right) && !$this->isColumnIdentifier($right)) {
			throw new \InvalidArgumentException('whereColumn 右字段名不合法');
		}
		if (is_array($right) && (
			count($right) !== 2
			|| $right[0] !== 'outer'
			|| !is_string($right[1])
			|| !$this->isColumnIdentifier($right[1])
		)) {
			throw new \InvalidArgumentException('whereColumn 外层字段格式不合法');
		}

		$this->sql['where_columns'][] = [
			'left' => $left,
			'operator' => $operator,
			'right' => $right,
		];
		return $this;
	}

	/**
	 * 添加 EXISTS 子查询条件。
	 *
	 * @param object $subquery 具备 compileSubquery 方法的 ORM 模型
	 * @return static 当前查询对象
	 */
	public function whereExists(object $subquery): static
	{
		$this->appendExistsClause(false, $subquery, 'AND');
		return $this;
	}

	/**
	 * 添加 OR EXISTS 子查询条件。
	 *
	 * @param object $subquery 具备 compileSubquery 方法的 ORM 模型
	 * @return static 当前查询对象
	 */
	public function orWhereExists(object $subquery): static
	{
		$this->appendExistsClause(false, $subquery, 'OR');
		return $this;
	}

	/**
	 * 添加 OR 连接的 EXISTS 子查询组。
	 *
	 * 组内条件使用 OR，整组再以 AND 接入当前查询，适合表达
	 * `固定条件 AND (EXISTS(...) OR EXISTS(...))`。
	 *
	 * @param array<int,object> $subqueries 具备 compileSubquery 方法的 ORM 模型
	 * @return static 当前查询对象
	 */
	public function whereExistsAny(array $subqueries): static
	{
		$this->appendExistsGroup($subqueries, 'AND');
		return $this;
	}

	/**
	 * 添加 NOT EXISTS 子查询条件。
	 *
	 * @param object $subquery 具备 compileSubquery 方法的 ORM 模型
	 * @return static 当前查询对象
	 */
	public function whereNotExists(object $subquery): static
	{
		$this->appendExistsClause(true, $subquery, 'AND');
		return $this;
	}

	/**
	 * 添加 OR 连接的 NOT EXISTS 子查询组。
	 *
	 * @param array<int,object> $subqueries 具备 compileSubquery 方法的 ORM 模型
	 * @return static 当前查询对象
	 */
	public function whereNotExistsAny(array $subqueries): static
	{
		$this->appendExistsGroup($subqueries, 'AND', true);
		return $this;
	}

	public function data(array $data): static
	{
		$this->sql['data'] = $data;
		return $this;
	}

	public function cache(bool $enable = true): static
	{
		$this->sql['querycache'] = $enable;
		return $this;
	}

	private function appendWhereClause(string $logic, array $args): void
	{
		if ($args === []) {
			return;
		}
		if (!isset($this->sql['where']) || !is_array($this->sql['where'])) {
			$this->sql['where'] = [];
		}
		$this->sql['where'][] = ['logic' => $logic, 'args' => $args];
	}

	/**
	 * 追加表达式条件并校验逻辑连接符。
	 *
	 * @param string $logic AND 或 OR
	 * @param \Candy\Extend\DB\DbExpression $expression 受控表达式
	 * @return void
	 */
	private function appendWhereExpression(string $logic, \Candy\Extend\DB\DbExpression $expression): void
	{
		$logic = strtoupper(trim($logic));
		if (!in_array($logic, ['AND', 'OR'], true)) {
			throw new \InvalidArgumentException('WHERE 表达式逻辑连接符不合法');
		}
		if ($expression->containsExcluded()) {
			throw new \InvalidArgumentException('普通 WHERE 不允许使用 EXCLUDED 表达式');
		}
		$this->sql['where_expressions'][] = [
			'logic' => $logic,
			'expression' => $expression,
		];
	}

	private function hasWhere(): bool
	{
		foreach (['where', 'where_columns', 'where_exists', 'where_expressions'] as $key) {
			$stack = $this->sql[$key] ?? [];
			if (is_array($stack) && $stack !== []) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 构建 WHERE 条件子句
	 *
	 * 根据参数构建 SQL 的 WHERE 条件子句，支持多种条件格式：
	 * - 空条件：返回空字符串
	 * - 字符串条件：直接拼接
	 * - 数组条件：解析为键值对条件
	 * - 多维数组：支持复杂的 AND/OR 条件组合
	 *
	 * @param array $args 条件参数数组
	 * @param string $join 连接符（如 ' AND '、' OR '）
	 * @param string $where WHERE 关键字（默认为 ' WHERE '）
	 * @param string $pri 主键字段名
	 * @return array 包含 WHERE 子句和参数数据的数组
	 *   - where: 构建好的 WHERE 子句字符串
	 *   - data: 用于参数绑定的数据数组
	 */
	private function compileWhere(
		mixed $input,
		string $join = '',
		string $where = ' WHERE ',
		string $pri = '',
		string $outerQualifier = ''
	): array
	{
		if ($pri === '' || $pri === null) {
			$pri = (string) ($this->fieldList['pri'] ?? 'id');
		}

		$data = [];
		$parsed = ['sql' => '', 'data' => []];
		if (is_array($input) && count($input) === 2 && is_string($input[0]) && is_array($input[1])) {
			$parsed = ['sql' => $join . $input[0], 'data' => $input[1]];
		} elseif (!empty($input)) {
			$stack = $this->normalizeWhereInput($input);
			if ($stack !== []) {
				$parsed = self::compileWhereStack($stack, $join, $pri, ...$this->sqlIdentQuotes());
			}
		}

		$parts = [];
		if ($parsed['sql'] !== '') {
			$parts[] = $parsed['sql'];
			array_push($data, ...$parsed['data']);
		}

		$expressionConditions = $this->compileWhereExpressions();
		if ($expressionConditions['sql'] !== '') {
			$parts[] = $expressionConditions['sql'];
			array_push($data, ...$expressionConditions['data']);
		}

		$columnConditions = $this->compileWhereColumns($join, $outerQualifier);
		if ($columnConditions['sql'] !== '') {
			$parts[] = $columnConditions['sql'];
			array_push($data, ...$columnConditions['data']);
		}

		$currentQualifier = $join === ''
			? $this->quoteIdent($this->tabName)
			: rtrim($join, '.');
		$existsConditions = $this->compileExistsConditions($currentQualifier);
		if ($existsConditions['sql'] !== '') {
			$parts[] = $existsConditions['sql'];
			array_push($data, ...$existsConditions['data']);
		}

		$sql = implode(' AND ', $parts);
		if ($sql === '') {
			return ['where' => $this->compileTrashed(), 'data' => $data];
		}

		$where .= $sql;
		$where .= $this->compileTrashed('AND');

		return ['where' => $where, 'data' => $data];
	}

	/**
	 * 编译受控表达式条件。
	 *
	 * 多个表达式先按调用顺序组合并整体加括号，避免 OR 条件改变既有固定过滤条件的优先级。
	 *
	 * @return array{sql:string,data:array<int,mixed>} 编译后的 SQL 与绑定参数
	 */
	private function compileWhereExpressions(): array
	{
		$parts = [];
		$data = [];
		$quoteIdentifier = fn(string $column): string => $this->quoteIdent($column);
		foreach ((array)($this->sql['where_expressions'] ?? []) as $condition) {
			$logic = strtoupper((string)($condition['logic'] ?? 'AND'));
			$expression = $condition['expression'] ?? null;
			if (!in_array($logic, ['AND', 'OR'], true)
				|| !$expression instanceof \Candy\Extend\DB\DbExpression
			) {
				throw new \LogicException('WHERE 表达式状态无效');
			}
			if ($expression->containsExcluded()) {
				throw new \LogicException('普通 WHERE 不允许使用 EXCLUDED 表达式');
			}
			$compiled = $expression->compile($quoteIdentifier, $this->dialect());
			$parts[] = [
				'logic' => $logic,
				'sql' => $compiled['sql'],
			];
			array_push($data, ...(array)$compiled['data']);
		}
		if ($parts === []) {
			return ['sql' => '', 'data' => []];
		}

		$sql = '';
		foreach ($parts as $index => $part) {
			if ($index > 0) {
				$sql .= ' ' . $part['logic'] . ' ';
			}
			$sql .= $part['sql'];
		}

		return ['sql' => '(' . $sql . ')', 'data' => $data];
	}

	/**
	 * 追加 exists 谓词并校验子查询编译接口。
	 *
	 * @param bool $negative 是否生成 NOT EXISTS
	 * @param object $subquery 子查询模型
	 * @return void
	 */
	private function appendExistsClause(bool $negative, object $subquery, string $logic): void
	{
		if (!method_exists($subquery, 'compileSubquery')) {
			throw new \InvalidArgumentException('exists 子查询必须是 ORM 模型');
		}
		$logic = strtoupper($logic);
		if (!in_array($logic, ['AND', 'OR'], true)) {
			throw new \InvalidArgumentException('exists 逻辑连接符不合法');
		}
		// 子查询对象包含连接状态，不能直接进入查询缓存指纹；反关联改为每次按当前快照执行。
		$this->sql['querycache'] = false;
		$this->sql['where_exists'][] = [
			'logic' => $logic,
			'negative' => $negative,
			'query' => $subquery,
		];
	}

	/**
	 * 追加 OR 连接的 exists 子查询组。
	 *
	 * @param array<int,object> $subqueries 子查询模型列表
	 * @param string $logic 组与既有条件的连接符
	 * @param bool $negative 是否生成 NOT EXISTS
	 * @return void
	 */
	private function appendExistsGroup(array $subqueries, string $logic, bool $negative = false): void
	{
		$valid = [];
		foreach ($subqueries as $subquery) {
			if (!is_object($subquery) || !method_exists($subquery, 'compileSubquery')) {
				throw new \InvalidArgumentException('exists 子查询必须是 ORM 模型');
			}
			$valid[] = [
				'negative' => $negative,
				'query' => $subquery,
			];
		}
		if ($valid === []) {
			return;
		}
		$logic = strtoupper($logic);
		if (!in_array($logic, ['AND', 'OR'], true)) {
			throw new \InvalidArgumentException('exists 逻辑连接符不合法');
		}
		$this->sql['querycache'] = false;
		$this->sql['where_exists'][] = [
			'logic' => $logic,
			'group' => $valid,
		];
	}

	/**
	 * 编译字段比较条件。
	 *
	 * @param string $fieldPrefix 当前查询字段前缀
	 * @param string $outerQualifier 外层表或别名的已引用限定符
	 * @return array{sql:string,data:array<int,mixed>}
	 */
	private function compileWhereColumns(string $fieldPrefix, string $outerQualifier): array
	{
		$parts = [];
		foreach ((array)($this->sql['where_columns'] ?? []) as $condition) {
			$left = (string)($condition['left'] ?? '');
			$operator = (string)($condition['operator'] ?? '=');
			$right = $condition['right'] ?? '';
			if (!$this->isColumnIdentifier($left) || !in_array($operator, ['=', '<>', '!=', '>', '>=', '<', '<='], true)) {
				throw new \InvalidArgumentException('whereColumn 条件不合法');
			}

			$leftSql = $fieldPrefix . $this->quoteIdent($left);
			if (is_array($right)) {
				if ($outerQualifier === '' || ($right[0] ?? '') !== 'outer' || !$this->isColumnIdentifier((string)($right[1] ?? ''))) {
					throw new \LogicException('exists 子查询缺少合法的外层字段限定符');
				}
				$rightSql = $outerQualifier . '.' . $this->quoteIdent((string)$right[1]);
			} else {
				if (!$this->isColumnIdentifier($right)) {
					throw new \InvalidArgumentException('whereColumn 右字段名不合法');
				}
				$rightSql = $fieldPrefix . $this->quoteIdent($right);
			}
			$parts[] = $leftSql . ' ' . $operator . ' ' . $rightSql;
		}

		return ['sql' => implode(' AND ', $parts), 'data' => []];
	}

	/**
	 * 编译 exists 子查询条件。
	 *
	 * @param string $parentQualifier 当前查询表或别名的已引用限定符
	 * @return array{sql:string,data:array<int,mixed>}
	 */
	private function compileExistsConditions(string $parentQualifier): array
	{
		$parts = [];
		$data = [];
		foreach ((array)($this->sql['where_exists'] ?? []) as $condition) {
			$group = $condition['group'] ?? null;
			if (is_array($group)) {
				$groupParts = [];
				foreach ($group as $groupCondition) {
					$compiled = $this->compileExistsCondition($groupCondition, $parentQualifier);
					$groupParts[] = $compiled['sql'];
					array_push($data, ...(array)$compiled['data']);
				}
				if ($groupParts !== []) {
					$parts[] = [
						'logic' => strtoupper((string)($condition['logic'] ?? 'AND')),
						'sql' => '(' . implode(' OR ', $groupParts) . ')',
					];
				}
				continue;
			}
			$compiled = $this->compileExistsCondition($condition, $parentQualifier);
			$parts[] = [
				'logic' => strtoupper((string)($condition['logic'] ?? 'AND')),
				'sql' => $compiled['sql'],
			];
			array_push($data, ...(array)$compiled['data']);
		}

		if ($parts === []) {
			return ['sql' => '', 'data' => []];
		}
		$built = '';
		foreach ($parts as $index => $part) {
			if ($index > 0) {
				$built .= ' ' . $part['logic'] . ' ';
			}
			$built .= $part['sql'];
		}

		return ['sql' => '(' . $built . ')', 'data' => $data];
	}

	/**
	 * 编译单个 exists 子查询。
	 *
	 * @param array{negative?:bool,query?:object} $condition 子查询配置
	 * @param string $outerQualifier 外层表或别名的已引用限定符
	 * @return array{sql:string,data:array<int,mixed>}
	 */
	private function compileExistsCondition(array $condition, string $outerQualifier): array
	{
		$query = $condition['query'] ?? null;
		if (!is_object($query) || !method_exists($query, 'compileSubquery')) {
			throw new \LogicException('exists 子查询对象无效');
		}
		$compiled = $query->compileSubquery($outerQualifier);
		if (!is_array($compiled) || !isset($compiled['sql'], $compiled['data'])) {
			throw new \LogicException('exists 子查询编译结果无效');
		}
		$keyword = !empty($condition['negative']) ? 'NOT EXISTS' : 'EXISTS';
		return [
			'sql' => $keyword . ' (' . $compiled['sql'] . ')',
			'data' => (array)$compiled['data'],
		];
	}

	/**
	 * 判断字段标识符是否安全。
	 *
	 * @param string $column 字段名
	 * @return bool
	 */
	private function isColumnIdentifier(string $column): bool
	{
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1;
	}

	/**
	 * @return array<int, array{logic:string,args:array}>
	 */
	private function normalizeWhereInput(mixed $input): array
	{
		if (!is_array($input) || $input === []) {
			return [];
		}
		if (isset($input[0]) && is_array($input[0]) && array_key_exists('logic', $input[0])) {
			return $input;
		}
		return [['logic' => 'AND', 'args' => $input]];
	}

	/**
	 * 构建软删除条件
	 *
	 * 根据当前查询的软删除设置，构建相应的 WHERE 条件：
	 * - withTrashed(): 包含已删除的记录
	 * - onlyTrashed(): 仅包含已删除的记录
	 * - 默认：不包含已删除的记录
	 *
	 * @param string $link 连接符（如 'WHERE'、'AND'）
	 * @return string 构建好的软删除条件字符串
	 */
	protected function compileTrashed(string $link = 'WHERE'): string
	{
		$where = '';
		//软删除检查
		isset($this->deleteTime) OR $this->deleteTime = 'deleteTime';
		if(in_array(strtolower($this->deleteTime), $this->fieldList)){
			$col = $this->quoteIdent((string)$this->deleteTime);
			if($this->sqlArgs('onlytrashed') === true){
				// 仅已删除：跨方言用 IS NOT NULL（禁止 `<> null` / `= null`）
				$where .= ' '. $link .' ' . $col . ' IS NOT NULL';
			}elseif($this->sqlArgs('withtrashed') === false){
				// 默认排除已删除
				$where .= ' '. $link .' ' . $col . ' IS NULL';
			}
		}
		
		return $where;
	}

}
