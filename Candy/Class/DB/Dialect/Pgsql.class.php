<?php
/***
 * PostgreSQL 方言
 *
 * 业务说明：标识符/分页/结果键规范化；information_schema 表结构与库体积
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 10:18:01 $
 *
 * @package Candy\Extend\DB\Dialect
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Dialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Pgsql implements DialectInterface
{
	use DialectResultNormalize;

	/**
	 * 引用标识符（双引号）
	 *
	 * @param string $ident 标识符
	 * @return string
	 */
	public static function quoteIdent(string $ident): string
	{
		return '"' . str_replace('"', '""', $ident) . '"';
	}

	/**
	 * WhereParser 左右引号
	 *
	 * @return array{0:string,1:string}
	 */
	public static function identQuotes(): array
	{
		return ['"', '"'];
	}

	/**
	 * PostgreSQL LIMIT … OFFSET …
	 *
	 * @param int $limit 条数
	 * @param int $offset 偏移
	 * @return string
	 */
	public static function limitSql(int $limit, int $offset = 0): string
	{
		$limit = max(0, $limit);
		$offset = max(0, $offset);
		$sql = ' LIMIT ' . $limit;
		if ($offset > 0) {
			$sql .= ' OFFSET ' . $offset;
		}

		return $sql;
	}

	/**
	 * 返回 PostgreSQL 扩展查询协议的绑定参数上限。
	 *
	 * @return int 参数上限
	 */
	public static function maxBindParameters(): int
	{
		return 65535;
	}

	/**
	 * 编译 PostgreSQL 日期时间增量表达式。
	 *
	 * @param string $unit day|hour|minute|second
	 * @param string $baseSql 基准时间表达式
	 * @return string 日期时间表达式
	 */
	public static function dateAddExpression(string $unit, string $baseSql): string
	{
		$unit = self::dateAddUnit($unit);
		return '(' . $baseSql . " + (? * INTERVAL '1 " . $unit . "'))";
	}

	/**
	 * 校验日期增量单位。
	 *
	 * @param string $unit 日期单位
	 * @return string 安全单位
	 */
	private static function dateAddUnit(string $unit): string
	{
		return match (strtolower($unit)) {
			'day', 'hour', 'minute', 'second' => strtolower($unit),
			default => throw new \InvalidArgumentException('不支持的日期增量单位'),
		};
	}

	public static function returningClause(array|string $columns): string
	{
		$columns = is_array($columns) ? $columns : [$columns];
		if ($columns === []) { throw new \InvalidArgumentException('RETURNING 列不能为空'); }
		return ' RETURNING ' . implode(',', array_map([self::class, 'quoteIdent'], $columns));
	}

	public static function upsertClause(array $insertColumns, array $conflictKeys, array $updateColumns): string
	{
		unset($insertColumns);
		if ($conflictKeys === []) {
			if ($updateColumns !== []) {
				throw new \InvalidArgumentException('PostgreSQL 无目标冲突时不能更新列');
			}
			return ' ON CONFLICT DO NOTHING';
		}
		$conflict = implode(',', array_map([self::class, 'quoteIdent'], $conflictKeys));
		if ($updateColumns === []) { return ' ON CONFLICT (' . $conflict . ') DO NOTHING'; }
		$updates = array_map(static fn(string $column): string => self::quoteIdent($column) . '=EXCLUDED.' . self::quoteIdent($column), $updateColumns);
		return ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . implode(',', $updates);
	}

	/**
	 * 编译 PostgreSQL 表达式 upsert 冲突更新子句。
	 *
	 * @param array<int,string> $insertColumns 插入列
	 * @param array<int,string> $conflictKeys 冲突键
	 * @param array<string,string> $updates 更新列到表达式 SQL
	 * @return string upsert 子句
	 */
	public static function upsertExpressionClause(array $insertColumns, array $conflictKeys, array $updates): string
	{
		unset($insertColumns);
		if ($conflictKeys === []) {
			throw new \InvalidArgumentException('PostgreSQL upsert 冲突键不能为空');
		}
		$conflict = implode(',', array_map([self::class, 'quoteIdent'], $conflictKeys));
		if ($updates === []) {
			return ' ON CONFLICT (' . $conflict . ') DO NOTHING';
		}
		$parts = [];
		foreach ($updates as $column => $sql) {
			$parts[] = self::quoteIdent((string)$column) . '=' . $sql;
		}

		return ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . implode(',', $parts);
	}

	/**
	 * 编译 PostgreSQL 冲突行插入值引用。
	 *
	 * @param string $columnSql 已引用的列名
	 * @return string 方言表达式
	 */
	public static function excludedExpression(string $columnSql): string
	{
		return 'EXCLUDED.' . $columnSql;
	}

	public static function lockClause(string $mode): string
	{
		return match (strtolower($mode)) { 'update' => ' FOR UPDATE', 'share' => ' FOR SHARE', default => throw new \InvalidArgumentException('不支持的行锁模式') };
	}

	/**
	 * 表字段元数据 SQL
	 *
	 * @return string
	 */
	public static function columnsMetaSql(): string
	{
		return "
			SELECT
				c.column_name,
				c.column_default,
				c.is_nullable,
				c.data_type,
				c.udt_name,
				c.is_identity,
				tc.constraint_type,
				COALESCE(
					col_description(
						(quote_ident(c.table_schema) || '.' || quote_ident(c.table_name))::regclass,
						c.ordinal_position::int
					),
					''
				) AS column_comment
			FROM information_schema.columns c
			LEFT JOIN information_schema.key_column_usage kcu
				ON c.table_catalog = kcu.table_catalog
				AND c.table_schema = kcu.table_schema
				AND c.table_name = kcu.table_name
				AND c.column_name = kcu.column_name
			LEFT JOIN information_schema.table_constraints tc
				ON tc.table_catalog = kcu.table_catalog
				AND tc.table_schema = kcu.table_schema
				AND tc.constraint_name = kcu.constraint_name
				AND tc.constraint_type = 'PRIMARY KEY'
			WHERE c.table_schema = current_schema()
				AND c.table_name = ?
			ORDER BY c.ordinal_position
		";
	}

	/**
	 * 解析 information_schema 行
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $fields
	 * @param string $auto
	 * @return array{0:array,1:string}
	 */
	public static function mapColumnRow(array $row, array $fields, string $auto): array
	{
		$row = self::normalizeRowKeys($row) ?? [];
		$col = self::normalizeColumnName((string) ($row['column_name'] ?? ''));
		if (($row['constraint_type'] ?? null) === 'PRIMARY KEY') {
			$fields['pri'] = $col;
			$default = (string) ($row['column_default'] ?? '');
			$udt = strtolower((string) ($row['udt_name'] ?? ''));
			if (
				stripos($default, 'nextval') !== false
				|| str_contains($udt, 'serial')
				|| stripos($default, 'identity') !== false
			) {
				$auto = 'yes';
			}
		} else {
			$fields[] = $col;
		}
		return [$fields, $auto];
	}

	/**
	 * 库体积 SQL
	 *
	 * @param string $schema 模式名
	 * @param string $prefix 表前缀
	 * @return string
	 */
	public static function databaseSizeSql(string $schema, string $prefix = ''): string
	{
		unset($schema);
		$sql = "SELECT pg_total_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename)) AS sz
			FROM pg_tables WHERE schemaname = current_schema()";
		if ($prefix !== '') {
			$sql .= " AND tablename LIKE '" . addslashes($prefix) . "%'";
		}
		return $sql;
	}
}
