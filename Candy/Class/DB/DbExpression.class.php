<?php
/***
 * ORM 安全数据库表达式
 *
 * 业务说明：以受控表达式树替代业务层 SQL 字符串，所有标识符统一引用，所有外部值统一参数绑定。
 *
 * $Author: Codex $
 * $Date: 2026-08-23 06:16:09 $
 *
 * @package Candy\Extend\DB
 */
declare(strict_types=1);

namespace Candy\Extend\DB;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class DbExpression
{
	/**
	 * 创建表达式节点。
	 *
	 * @param string $kind 节点类型
	 * @param array<int,mixed> $arguments 节点参数
	 */
	private function __construct(
		private readonly string $kind,
		private readonly array $arguments = []
	)
	{
	}

	/**
	 * 引用同表字段。
	 *
	 * @param string $column 字段名
	 * @return self 字段表达式
	 */
	public static function column(string $column): self
	{
		self::assertIdentifier($column);
		return new self('column', [strtolower($column)]);
	}

	/**
	 * 引用 upsert 冲突行中的同名插入值。
	 *
	 * 该表达式只能由 upsertExpressions 编译到冲突更新子句，业务代码不能把它当作普通列值使用。
	 *
	 * @param string $column 插入列名
	 * @return self EXCLUDED/VALUES 列表达式
	 */
	public static function excluded(string $column): self
	{
		self::assertIdentifier($column);

		return new self('excluded', [strtolower($column)]);
	}

	/**
	 * 创建绑定值表达式。
	 *
	 * @param mixed $value 外部值
	 * @return self 参数表达式
	 */
	public static function value(mixed $value): self
	{
		return new self('value', [$value]);
	}

	/**
	 * 创建字段原子增加表达式。
	 *
	 * @param string $column 字段名
	 * @param int|float $amount 增加量
	 * @return self 二元运算表达式
	 */
	public static function increment(string $column, int|float $amount = 1): self
	{
		return self::binary(self::column($column), '+', self::value($amount));
	}

	/**
	 * 创建字段原子减少表达式。
	 *
	 * @param string $column 字段名
	 * @param int|float $amount 减少量
	 * @return self 二元运算表达式
	 */
	public static function decrement(string $column, int|float $amount = 1): self
	{
		return self::binary(self::column($column), '-', self::value($amount));
	}

	/**
	 * 创建不低于零的字段增量表达式。
	 *
	 * 由当前数据库方言选择 GREATEST、MAX 或 CASE，增量值始终使用绑定参数。
	 *
	 * @param string $column 字段名
	 * @param int|float $amount 增量，可为负数
	 * @return self 非负截断表达式
	 */
	public static function nonNegative(string $column, int|float $amount = 1): self
	{
		return new self('non_negative', [
			self::column($column),
			self::value($amount),
		]);
	}

	/**
	 * 创建受控二元算术表达式。
	 *
	 * @param self $left 左表达式
	 * @param string $operator 运算符
	 * @param self $right 右表达式
	 * @return self 二元运算表达式
	 */
	public static function binary(self $left, string $operator, self $right): self
	{
		if (!in_array($operator, ['+', '-', '*', '/'], true)) {
			throw new \InvalidArgumentException('不支持的数据库表达式运算符');
		}
		return new self('binary', [$left, $operator, $right]);
	}

	/**
	 * 创建受控类型转换表达式。
	 *
	 * 用于跨版本迁移时将旧表兼容字段投影为目标表类型；类型名使用白名单，避免拼接任意 SQL。
	 *
	 * @param self $expression 待转换表达式
	 * @param string $type 目标类型
	 * @return self 类型转换表达式
	 */
	public static function cast(self $expression, string $type): self
	{
		$type = strtolower(trim($type));
		if (!in_array($type, ['bigint', 'integer', 'numeric', 'text', 'boolean', 'timestamp'], true)) {
			throw new \InvalidArgumentException('不支持的数据库类型转换');
		}
		return new self('cast', [$expression, $type]);
	}

	/**
	 * 创建受控比较表达式，供 CASE WHEN 等条件节点使用。
	 *
	 * @param self $left 左表达式
	 * @param string $operator 比较运算符
	 * @param self $right 右表达式
	 * @return self 比较表达式
	 */
	public static function compare(self $left, string $operator, self $right): self
	{
		$operator = strtoupper(trim($operator));
		if (!in_array($operator, ['=', '<>', '!=', '<', '<=', '>', '>=', 'IS', 'IS NOT'], true)) {
			throw new \InvalidArgumentException('不支持的数据库表达式比较运算符');
		}
		if (in_array($operator, ['IS', 'IS NOT'], true)
			&& ($right->kind !== 'value' || $right->arguments[0] !== null)
		) {
			throw new \InvalidArgumentException('IS 比较只允许 NULL 值');
		}

		return new self('compare', [$left, $operator, $right]);
	}

	/**
	 * 创建字段 IS NULL/IS NOT NULL 条件。
	 *
	 * @param self $expression 被判断的表达式
	 * @param bool $not 是否使用 IS NOT NULL
	 * @return self NULL 比较表达式
	 */
	public static function isNull(self $expression, bool $not = false): self
	{
		return self::compare($expression, $not ? 'IS NOT' : 'IS', self::value(null));
	}

	/**
	 * 创建跨方言的最大值表达式。
	 *
	 * PostgreSQL/MySQL 使用 GREATEST，SQLite 使用 MAX，SQL Server 使用嵌套 CASE；
	 * 每个候选仍由表达式树编译，外部值不会拼接进 SQL。
	 *
	 * @param self ...$expressions 候选表达式，至少两个
	 * @return self 最大值表达式
	 */
	public static function greatest(self ...$expressions): self
	{
		if (count($expressions) < 2) {
			throw new \InvalidArgumentException('GREATEST 至少需要两个表达式');
		}

		return new self('greatest', $expressions);
	}

	/**
	 * 创建受控 CASE WHEN 表达式。
	 *
	 * 分支使用 `[条件表达式, 结果表达式]`，也支持 `['when' => ..., 'then' => ...]`；
	 * 条件应由 compare() 创建，所有结果和条件都继续走参数绑定与方言编译。
	 *
	 * @param array<int,array{0:self,1:self}|array{when:self,then:self}> $when WHEN/THEN 分支
	 * @param self $else ELSE 表达式
	 * @return self CASE 表达式
	 */
	public static function case(array $when, self $else): self
	{
		if ($when === []) {
			throw new \InvalidArgumentException('CASE 至少需要一个 WHEN 分支');
		}

		$branches = [];
		foreach ($when as $branch) {
			if (is_array($branch) && isset($branch['when'], $branch['then'])) {
				$condition = $branch['when'];
				$result = $branch['then'];
			} elseif (array_is_list($branch) && count($branch) === 2) {
				$condition = $branch[0];
				$result = $branch[1];
			} else {
				throw new \InvalidArgumentException('CASE 分支必须包含 when/then 表达式');
			}

			if (!$condition instanceof self || !$result instanceof self || $condition->kind !== 'compare') {
				throw new \InvalidArgumentException('CASE 条件必须是 compare() 表达式');
			}
			$branches[] = [$condition, $result];
		}

		return new self('case', [$branches, $else]);
	}

	/**
	 * 创建 COALESCE 表达式。
	 *
	 * @param self ...$expressions 候选表达式
	 * @return self COALESCE 表达式
	 */
	public static function coalesce(self ...$expressions): self
	{
		if (count($expressions) < 2) {
			throw new \InvalidArgumentException('COALESCE 至少需要两个表达式');
		}
		return new self('coalesce', $expressions);
	}

	/**
	 * 引用数据库当前时间。
	 *
	 * @return self 当前时间表达式
	 */
	public static function currentTimestamp(): self
	{
		return new self('current_timestamp');
	}

	/**
	 * 创建日期时间增量表达式。
	 *
	 * 基准表达式只允许字段或 CURRENT_TIMESTAMP，确保各方言的参数顺序一致。
	 *
	 * @param string $unit day|hour|minute|second
	 * @param int $amount 增量，可为负数
	 * @param self|null $base 基准时间，默认 CURRENT_TIMESTAMP
	 * @return self 日期时间表达式
	 */
	public static function dateAdd(string $unit, int $amount, ?self $base = null): self
	{
		$unit = strtolower($unit);
		if (!in_array($unit, ['day', 'hour', 'minute', 'second'], true)) {
			throw new \InvalidArgumentException('不支持的日期增量单位');
		}
		$base ??= self::currentTimestamp();
		if (!in_array($base->kind, ['column', 'current_timestamp'], true)) {
			throw new \InvalidArgumentException('日期增量基准只允许字段或 CURRENT_TIMESTAMP');
		}
		return new self('date_add', [$unit, $amount, $base]);
	}

	/**
	 * 编译表达式和绑定参数。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名，仅用于冲突更新表达式
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	public function compile(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		return match ($this->kind) {
			'column' => ['sql' => $this->compileColumn($quoteIdentifier, $columnQualifier), 'data' => []],
			'excluded' => ['sql' => $dialectClass::excludedExpression($quoteIdentifier($this->arguments[0])), 'data' => []],
			'value' => ['sql' => '?', 'data' => [$this->arguments[0]]],
			'current_timestamp' => ['sql' => 'CURRENT_TIMESTAMP', 'data' => []],
			'binary' => $this->compileBinary($quoteIdentifier, $dialectClass, $columnQualifier),
			'compare' => $this->compileCompare($quoteIdentifier, $dialectClass, $columnQualifier),
			'coalesce' => $this->compileCoalesce($quoteIdentifier, $dialectClass, $columnQualifier),
			'greatest' => $this->compileGreatest($quoteIdentifier, $dialectClass, $columnQualifier),
			'case' => $this->compileCase($quoteIdentifier, $dialectClass, $columnQualifier),
			'cast' => $this->compileCast($quoteIdentifier, $dialectClass, $columnQualifier),
			'non_negative' => $this->compileNonNegative($quoteIdentifier, $dialectClass, $columnQualifier),
			'date_add' => $this->compileDateAdd($quoteIdentifier, $dialectClass, $columnQualifier),
			default => throw new \LogicException('未知数据库表达式节点'),
		};
	}

	/**
	 * 返回表达式引用的字段。
	 *
	 * @return array<int,string> 字段列表
	 */
	public function columns(): array
	{
		if (in_array($this->kind, ['column', 'excluded'], true)) {
			return [(string)$this->arguments[0]];
		}
		$columns = [];
		$this->collectColumns($this->arguments, $columns);
		return array_values(array_unique($columns));
	}

	/**
	 * 判断表达式树是否引用 upsert 专用的 EXCLUDED/VALUES 节点。
	 *
	 * 普通 UPDATE 没有冲突行上下文，调用方必须拒绝该节点，避免生成无效 SQL。
	 *
	 * @return bool 是否包含 upsert 专用节点
	 */
	public function containsExcluded(): bool
	{
		if ($this->kind === 'excluded') {
			return true;
		}

		return $this->containsExcludedIn($this->arguments);
	}

	/**
	 * 编译二元算术表达式。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileBinary(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$left = $this->arguments[0]->compile($quoteIdentifier, $dialectClass, $columnQualifier);
		$right = $this->arguments[2]->compile($quoteIdentifier, $dialectClass, $columnQualifier);
		return [
			'sql' => '(' . $left['sql'] . ' ' . $this->arguments[1] . ' ' . $right['sql'] . ')',
			'data' => array_merge($left['data'], $right['data']),
		];
	}

	/**
	 * 编译类型转换表达式。
	 *
	 * CAST 是 PostgreSQL、MySQL、SQLite 和 SQL Server 均支持的基础语法，目标类型已在构造阶段白名单校验。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileCast(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$expression = $this->arguments[0]->compile($quoteIdentifier, $dialectClass, $columnQualifier);
		return [
			'sql' => 'CAST(' . $expression['sql'] . ' AS ' . strtoupper((string)$this->arguments[1]) . ')',
			'data' => $expression['data'],
		];
	}

	/**
	 * 编译比较表达式。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileCompare(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$left = $this->arguments[0]->compile($quoteIdentifier, $dialectClass, $columnQualifier);
		if (in_array($this->arguments[1], ['IS', 'IS NOT'], true)) {
			return [
				'sql' => '(' . $left['sql'] . ' ' . $this->arguments[1] . ' NULL)',
				'data' => $left['data'],
			];
		}
		$right = $this->arguments[2]->compile($quoteIdentifier, $dialectClass, $columnQualifier);

		return [
			'sql' => '(' . $left['sql'] . ' ' . $this->arguments[1] . ' ' . $right['sql'] . ')',
			'data' => array_merge($left['data'], $right['data']),
		];
	}

	/**
	 * 编译 COALESCE 表达式。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileCoalesce(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$sql = [];
		$data = [];
		foreach ($this->arguments as $expression) {
			$compiled = $expression->compile($quoteIdentifier, $dialectClass, $columnQualifier);
			$sql[] = $compiled['sql'];
			$data = array_merge($data, $compiled['data']);
		}
		return ['sql' => 'COALESCE(' . implode(',', $sql) . ')', 'data' => $data];
	}

	/**
	 * 编译最大值表达式。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileGreatest(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$compiled = [];
		$data = [];
		foreach ($this->arguments as $expression) {
			$item = $expression->compile($quoteIdentifier, $dialectClass, $columnQualifier);
			$compiled[] = $item;
		}

		$dialectName = strtolower($dialectClass);
		if (str_contains($dialectName, 'sqlsrv')) {
			return $this->compileSqlsrvGreatest($compiled);
		}
		$sql = [];
		foreach ($compiled as $item) {
			$sql[] = $item['sql'];
			$data = array_merge($data, $item['data']);
		}
		if (str_contains($dialectName, 'sqlite')) {
			return ['sql' => 'MAX(' . implode(', ', $sql) . ')', 'data' => $data];
		}

		return ['sql' => 'GREATEST(' . implode(', ', $sql) . ')', 'data' => $data];
	}

	/**
	 * 编译 SQL Server 不支持 GREATEST 的兼容表达式。
	 *
	 * @param array<int,array{sql:string,data:array<int,mixed>}> $compiled 已编译候选
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileSqlsrvGreatest(array $compiled): array
	{
		$right = array_pop($compiled);
		if (!is_array($right)) {
			return ['sql' => '0', 'data' => []];
		}
		$sql = $right['sql'];
		$data = $right['data'];
		while ($compiled !== []) {
			$left = array_pop($compiled);
			if (!is_array($left)) {
				continue;
			}
			$leftSql = $left['sql'];
			$leftData = $left['data'];
			$sql = 'CASE WHEN ' . $leftSql . ' >= ' . $sql . ' THEN ' . $leftSql . ' ELSE ' . $sql . ' END';
			$data = array_merge($leftData, $data, $leftData, $data);
		}

		return ['sql' => $sql, 'data' => $data];
	}

	/**
	 * 编译 CASE WHEN 表达式。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileCase(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$sql = 'CASE';
		$data = [];
		foreach ($this->arguments[0] as $branch) {
			$condition = $branch[0]->compile($quoteIdentifier, $dialectClass, $columnQualifier);
			$result = $branch[1]->compile($quoteIdentifier, $dialectClass, $columnQualifier);
			$sql .= ' WHEN ' . $condition['sql'] . ' THEN ' . $result['sql'];
			$data = array_merge($data, $condition['data'], $result['data']);
		}
		$else = $this->arguments[1]->compile($quoteIdentifier, $dialectClass, $columnQualifier);

		return [
			'sql' => $sql . ' ELSE ' . $else['sql'] . ' END',
			'data' => array_merge($data, $else['data']),
		];
	}

	/**
	 * 编译不低于零的字段增量表达式。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileNonNegative(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$sum = self::binary($this->arguments[0], '+', $this->arguments[1])
			->compile($quoteIdentifier, $dialectClass, $columnQualifier);
		$dialectName = strtolower($dialectClass);
		if (str_contains($dialectName, 'sqlsrv')) {
			return [
				'sql' => 'CASE WHEN ' . $sum['sql'] . ' < 0 THEN 0 ELSE ' . $sum['sql'] . ' END',
				'data' => array_merge($sum['data'], $sum['data']),
			];
		}
		if (str_contains($dialectName, 'sqlite')) {
			return [
				'sql' => 'MAX(0, ' . $sum['sql'] . ')',
				'data' => $sum['data'],
			];
		}

		return [
			'sql' => 'GREATEST(0, ' . $sum['sql'] . ')',
			'data' => $sum['data'],
		];
	}

	/**
	 * 编译日期时间增量表达式。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param class-string $dialectClass 数据库方言类
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return array{sql:string,data:array<int,mixed>} 编译结果
	 */
	private function compileDateAdd(callable $quoteIdentifier, string $dialectClass, ?string $columnQualifier = null): array
	{
		$base = $this->arguments[2]->compile($quoteIdentifier, $dialectClass, $columnQualifier);
		return [
			'sql' => $dialectClass::dateAddExpression((string)$this->arguments[0], $base['sql']),
			'data' => [(int)$this->arguments[1]],
		];
	}

	/**
	 * 编译当前行字段引用。
	 *
	 * PostgreSQL 的 ON CONFLICT DO UPDATE 同时暴露目标行和 EXCLUDED 行；目标字段必须带表限定，
	 * 否则 `column + value` 会被 PostgreSQL 判定为歧义引用。EXCLUDED 节点不经过此方法，保持方言语法。
	 *
	 * @param callable(string):string $quoteIdentifier 标识符引用器
	 * @param string|null $columnQualifier 当前行限定表名
	 * @return string 字段 SQL
	 */
	private function compileColumn(callable $quoteIdentifier, ?string $columnQualifier): string
	{
		$column = $quoteIdentifier((string)$this->arguments[0]);
		if ($columnQualifier === null || $columnQualifier === '') {
			return $column;
		}

		return $quoteIdentifier($columnQualifier) . '.' . $column;
	}

	/**
	 * 校验字段标识符。
	 *
	 * @param string $identifier 字段名
	 * @return void
	 */
	private static function assertIdentifier(string $identifier): void
	{
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
			throw new \InvalidArgumentException('数据库表达式字段名不合法');
		}
	}

	/**
	 * 递归收集表达式树引用的字段。
	 *
	 * @param mixed $value 表达式或分支数组
	 * @param array<int,string> $columns 字段收集结果
	 * @return void
	 */
	private function collectColumns(mixed $value, array &$columns): void
	{
		if ($value instanceof self) {
			if (in_array($value->kind, ['column', 'excluded'], true)) {
				$columns[] = (string)$value->arguments[0];
				return;
			}
			$this->collectColumns($value->arguments, $columns);
			return;
		}
		if (is_array($value)) {
			foreach ($value as $item) {
				$this->collectColumns($item, $columns);
			}
		}
	}

	/**
	 * 递归检查表达式树是否包含 EXCLUDED 节点。
	 *
	 * @param mixed $value 表达式或分支数组
	 * @return bool 是否包含 EXCLUDED
	 */
	private function containsExcludedIn(mixed $value): bool
	{
		if ($value instanceof self) {
			if ($value->kind === 'excluded') {
				return true;
			}
			return $this->containsExcludedIn($value->arguments);
		}
		if (is_array($value)) {
			foreach ($value as $item) {
				if ($this->containsExcludedIn($item)) {
					return true;
				}
			}
		}

		return false;
	}
}
