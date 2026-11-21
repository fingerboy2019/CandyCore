<?php
/***
 * SQL Server 方言
 *
 * 标识符引用、OFFSET/FETCH 分页、information_schema 表结构与库体积。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-17 17:23:55 $
 *
 * @package Candy\Extend\DB\Dialect
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Dialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Sqlsrv implements DialectInterface
{
	use DialectResultNormalize;

	public static function returningClause(array|string $columns): string { throw new \BadMethodCallException('SQL Server OUTPUT 尚未接入'); }
	public static function upsertClause(array $insertColumns, array $conflictKeys, array $updateColumns): string { throw new \BadMethodCallException('SQL Server upsert 尚未接入'); }
	/**
	 * 拒绝 SQL Server 表达式 upsert，避免伪造跨方言原子语义。
	 *
	 * @param array $insertColumns 插入列
	 * @param array $conflictKeys 冲突键
	 * @param array $updates 更新表达式
	 * @return string 不返回；始终抛出异常
	 */
	public static function upsertExpressionClause(array $insertColumns, array $conflictKeys, array $updates): string
	{
		throw new \BadMethodCallException('SQL Server 表达式 upsert 尚未接入');
	}

	/**
	 * 拒绝 SQL Server EXCLUDED 表达式。
	 *
	 * @param string $columnSql 已引用列名 SQL
	 * @return string 不返回；始终抛出异常
	 */
	public static function excludedExpression(string $columnSql): string
	{
		throw new \BadMethodCallException('SQL Server EXCLUDED 表达式尚未接入');
	}
	public static function lockClause(string $mode): string { throw new \BadMethodCallException('SQL Server 行锁方言尚未接入'); }

	/**
	 * 引用标识符（方括号）
	 *
	 * @param string $ident 标识符
	 * @return string
	 */
	public static function quoteIdent(string $ident): string
	{
		$ident = str_replace(']', ']]', $ident);
		return '[' . $ident . ']';
	}

	/**
	 * WhereParser 左右引号
	 *
	 * @return array{0:string,1:string}
	 */
	public static function identQuotes(): array
	{
		return ['[', ']'];
	}

	/**
	 * 分页子句（SQL Server 2012+ OFFSET/FETCH）
	 *
	 * 无 offset 时仍输出 OFFSET 0 以满足 FETCH 语法要求。
	 *
	 * @param int $limit 条数
	 * @param int $offset 偏移
	 * @return string
	 */
	public static function limitSql(int $limit, int $offset = 0): string
	{
		$limit = max(0, $limit);
		$offset = max(0, $offset);
		return ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
	}

	/**
	 * 返回 SQL Server 单条语句的绑定参数上限。
	 *
	 * @return int 参数上限
	 */
	public static function maxBindParameters(): int
	{
		return 2100;
	}

	/**
	 * 编译 SQL Server 日期时间增量表达式。
	 *
	 * @param string $unit day|hour|minute|second
	 * @param string $baseSql 基准时间表达式
	 * @return string 日期时间表达式
	 */
	public static function dateAddExpression(string $unit, string $baseSql): string
	{
		$unit = match (strtolower($unit)) {
			'day', 'hour', 'minute', 'second' => strtolower($unit),
			default => throw new \InvalidArgumentException('不支持的日期增量单位'),
		};
		return 'DATEADD(' . $unit . ', ?, ' . $baseSql . ')';
	}

	/**
	 * 表字段元数据 SQL（绑定表名参数）
	 *
	 * @return string
	 */
	public static function columnsMetaSql(): string
	{
		return "
			SELECT
				c.COLUMN_NAME AS column_name,
				c.COLUMN_DEFAULT AS column_default,
				c.IS_NULLABLE AS is_nullable,
				c.DATA_TYPE AS data_type,
				tc.CONSTRAINT_TYPE AS constraint_type
			FROM INFORMATION_SCHEMA.COLUMNS c
			LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
				ON c.TABLE_CATALOG = kcu.TABLE_CATALOG
				AND c.TABLE_SCHEMA = kcu.TABLE_SCHEMA
				AND c.TABLE_NAME = kcu.TABLE_NAME
				AND c.COLUMN_NAME = kcu.COLUMN_NAME
			LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
				ON tc.TABLE_CATALOG = kcu.TABLE_CATALOG
				AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
				AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
				AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
			WHERE c.TABLE_NAME = ?
			ORDER BY c.ORDINAL_POSITION
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
		$col = strtolower((string) ($row['column_name'] ?? ''));
		if (($row['constraint_type'] ?? null) === 'PRIMARY KEY') {
			$fields['pri'] = $col;
			$default = (string) ($row['column_default'] ?? '');
			if (
				stripos($default, 'identity') !== false
				|| stripos($default, 'newid') !== false
			) {
				$auto = 'yes';
			}
		} else {
			$fields[] = $col;
		}
		return [$fields, $auto];
	}

	/**
	 * 检测 IDENTITY 列补充自增标记
	 *
	 * @param string $table 表名
	 * @return string
	 */
	public static function identityCheckSql(string $table): string
	{
		return 'SELECT COLUMN_NAME AS column_name FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_NAME = ? AND COLUMNPROPERTY(OBJECT_ID(TABLE_SCHEMA + \'.\' + TABLE_NAME), COLUMN_NAME, \'IsIdentity\') = 1';
	}

	/**
	 * 库体积 SQL（按表前缀过滤）
	 *
	 * @param string $prefix 表前缀
	 * @return string
	 */
	public static function databaseSizeSql(string $prefix = ''): string
	{
		$sql = 'SELECT SUM(a.total_pages) * 8 * 1024 AS sz
			FROM sys.tables t
			INNER JOIN sys.indexes i ON t.OBJECT_ID = i.object_id
			INNER JOIN sys.partitions p ON i.object_id = p.OBJECT_ID AND i.index_id = p.index_id
			INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
			WHERE i.index_id <= 1';
		if ($prefix !== '') {
			$sql .= " AND t.name LIKE '" . str_replace("'", "''", $prefix) . "%'";
		}
		return $sql;
	}
}
