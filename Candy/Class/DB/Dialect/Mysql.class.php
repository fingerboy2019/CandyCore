<?php
/***
 * MySQL 方言
 *
 * 业务说明：标识符/分页/结果键规范化；表结构探测与库体积 SQL
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-17 17:23:55 $
 *
 * @package Candy\Extend\DB\Dialect
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Dialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Mysql implements DialectInterface
{
	use DialectResultNormalize;

	/**
	 * 引用标识符（反引号）
	 *
	 * @param string $ident 标识符
	 * @return string
	 */
	public static function quoteIdent(string $ident): string
	{
		return '`' . str_replace('`', '``', $ident) . '`';
	}

	/**
	 * WhereParser 左右引号
	 *
	 * @return array{0:string,1:string}
	 */
	public static function identQuotes(): array
	{
		return ['`', '`'];
	}

	/**
	 * MySQL LIMIT / LIMIT offset,count
	 *
	 * @param int $limit 条数
	 * @param int $offset 偏移
	 * @return string
	 */
	public static function limitSql(int $limit, int $offset = 0): string
	{
		$limit = max(0, $limit);
		$offset = max(0, $offset);
		if ($offset > 0) {
			return ' LIMIT ' . $offset . ',' . $limit;
		}

		return ' LIMIT ' . $limit;
	}

	/**
	 * 返回 MySQL 预处理语句的保守绑定参数上限。
	 *
	 * @return int 参数上限
	 */
	public static function maxBindParameters(): int
	{
		return 65535;
	}

	/**
	 * 编译 MySQL 日期时间增量表达式。
	 *
	 * @param string $unit day|hour|minute|second
	 * @param string $baseSql 基准时间表达式
	 * @return string 日期时间表达式
	 */
	public static function dateAddExpression(string $unit, string $baseSql): string
	{
		$unit = match (strtolower($unit)) {
			'day' => 'DAY',
			'hour' => 'HOUR',
			'minute' => 'MINUTE',
			'second' => 'SECOND',
			default => throw new \InvalidArgumentException('不支持的日期增量单位'),
		};
		return 'DATE_ADD(' . $baseSql . ', INTERVAL ? ' . $unit . ')';
	}

	public static function returningClause(array|string $columns): string
	{
		unset($columns);
		return '';
	}

	public static function upsertClause(array $insertColumns, array $conflictKeys, array $updateColumns): string
	{
		unset($conflictKeys);
		if ($updateColumns === []) {
			$column = $insertColumns[0] ?? null;
			if (!is_string($column) || $column === '') { throw new \InvalidArgumentException('MySQL upsert 插入列不能为空'); }
			return ' ON DUPLICATE KEY UPDATE ' . self::quoteIdent($column) . '=' . self::quoteIdent($column);
		}
		$updates = array_map(static fn(string $column): string => self::quoteIdent($column) . '=VALUES(' . self::quoteIdent($column) . ')', $updateColumns);
		return ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
	}

	/**
	 * 编译 MySQL 表达式 upsert 冲突更新子句。
	 *
	 * @param array<int,string> $insertColumns 插入列
	 * @param array<int,string> $conflictKeys 冲突键（MySQL 由唯一索引决定）
	 * @param array<string,string> $updates 更新列到表达式 SQL
	 * @return string upsert 子句
	 */
	public static function upsertExpressionClause(array $insertColumns, array $conflictKeys, array $updates): string
	{
		unset($conflictKeys);
		if ($updates === []) {
			$column = $insertColumns[0] ?? null;
			if (!is_string($column) || $column === '') {
				throw new \InvalidArgumentException('MySQL upsert 插入列不能为空');
			}

			return ' ON DUPLICATE KEY UPDATE ' . self::quoteIdent($column) . '=' . self::quoteIdent($column);
		}
		$parts = [];
		foreach ($updates as $column => $sql) {
			$parts[] = self::quoteIdent((string)$column) . '=' . $sql;
		}

		return ' ON DUPLICATE KEY UPDATE ' . implode(',', $parts);
	}

	/**
	 * 编译 MySQL 冲突行插入值引用。
	 *
	 * @param string $columnSql 已引用的列名
	 * @return string 方言表达式
	 */
	public static function excludedExpression(string $columnSql): string
	{
		return 'VALUES(' . $columnSql . ')';
	}

	public static function lockClause(string $mode): string
	{
		return match (strtolower($mode)) { 'update' => ' FOR UPDATE', 'share' => ' LOCK IN SHARE MODE', default => throw new \InvalidArgumentException('不支持的行锁模式') };
	}

	/**
	 * DESC 表结构语句
	 *
	 * @param string $table 含前缀表名
	 * @return string
	 */
	public static function describeTableSql(string $table): string
	{
		return 'DESC ' . self::quoteIdent($table);
	}

	/**
	 * 库体积 SQL
	 *
	 * @param string $schema 库名
	 * @param string $prefix 表前缀
	 * @return string
	 */
	public static function databaseSizeSql(string $schema, string $prefix = ''): string
	{
		$sql = 'SHOW TABLE STATUS FROM ' . self::quoteIdent($schema);
		if ($prefix !== '') {
			$sql .= ' LIKE \'' . addslashes($prefix) . '%\'';
		}
		return $sql;
	}

	/**
	 * 解析 DESC 行
	 *
	 * @param array<string,mixed> $row DESC 行
	 * @param array<string,mixed> $fields 字段累积
	 * @param string $auto 自增标记
	 * @return array{0:array,1:string}
	 */
	public static function mapDescribeRow(array $row, array $fields, string $auto): array
	{
		$row = self::normalizeRowKeys($row) ?? [];
		if (($row['key'] ?? '') === 'PRI') {
			$fields['pri'] = self::normalizeColumnName((string) ($row['field'] ?? ''));
		} else {
			$fields[] = self::normalizeColumnName((string) ($row['field'] ?? ''));
		}
		if (($row['extra'] ?? '') === 'auto_increment') {
			$auto = 'yes';
		}
		return [$fields, $auto];
	}
}
