<?php
/***
 * 主库方言契约
 *
 * 业务说明：ORM 运行时标识符、分页、结果键规范化；各主库 Dialect 实现本接口
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-17 17:23:55 $
 *
 * @package Candy\Extend\DB\Dialect
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Dialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface DialectInterface
{
	/**
	 * 逻辑列名规范化（统一小写）
	 *
	 * @param string $name 物理或逻辑列名
	 * @return string
	 */
	public static function normalizeColumnName(string $name): string;

	/**
	 * 关联数组键名统一为小写
	 *
	 * @param array<string, mixed>|null $row 单行
	 * @return array<string, mixed>|null
	 */
	public static function normalizeRowKeys(?array $row): ?array;

	/**
	 * 查询结果字段名规范化
	 *
	 * @param mixed $result query 返回值
	 * @param string $method select|find|total|insert|update|delete|other
	 * @return mixed
	 */
	public static function normalizeResultKeys(mixed $result, string $method): mixed;

	/**
	 * 引用标识符
	 *
	 * @param string $ident 标识符
	 * @return string
	 */
	public static function quoteIdent(string $ident): string;

	/**
	 * WhereParser 左右引号
	 *
	 * @return array{0:string,1:string}
	 */
	public static function identQuotes(): array;

	/**
	 * 分页 SQL 片段（含前导空格）
	 *
	 * @param int $limit 条数
	 * @param int $offset 偏移
	 * @return string
	 */
	public static function limitSql(int $limit, int $offset = 0): string;

	/**
	 * 单条预处理语句允许的最大绑定参数数。
	 *
	 * @return int 参数上限
	 */
	public static function maxBindParameters(): int;

	/**
	 * 编译日期时间增量表达式，增量值使用一个问号占位符。
	 *
	 * @param string $unit day|hour|minute|second
	 * @param string $baseSql 已安全编译的基准时间表达式
	 * @return string 方言表达式
	 */
	public static function dateAddExpression(string $unit, string $baseSql): string;

	/** @param array<int,string>|string $columns 返回列 @return string RETURNING 子句 */
	public static function returningClause(array|string $columns): string;

	/** @param array<int,string> $insertColumns 插入列 @param array<int,string> $conflictKeys 冲突键 @param array<int,string> $updateColumns 更新列 @return string upsert 子句 */
	public static function upsertClause(array $insertColumns, array $conflictKeys, array $updateColumns): string;

	/**
	 * 编译带安全表达式的 upsert 冲突更新子句。
	 *
	 * @param array<int,string> $insertColumns 插入列
	 * @param array<int,string> $conflictKeys 冲突键
	 * @param array<string,string> $updates 更新列到已编译表达式 SQL
	 * @return string upsert 子句
	 */
	public static function upsertExpressionClause(array $insertColumns, array $conflictKeys, array $updates): string;

	/**
	 * 编译冲突行插入值引用。
	 *
	 * @param string $columnSql 已引用的列名
	 * @return string 方言表达式
	 */
	public static function excludedExpression(string $columnSql): string;

	/** @param string $mode update|share @return string 行锁子句 */
	public static function lockClause(string $mode): string;
}
