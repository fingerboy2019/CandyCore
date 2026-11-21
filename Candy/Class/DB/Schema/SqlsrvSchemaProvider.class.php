<?php
/***
 * SQL Server 元数据 Provider
 *
 * 业务说明：INFORMATION_SCHEMA / sys.indexes 探测表、列、索引；建表 SQL 暂返回空
 *
 * $Author: 刘森 (fingerboy@qq.com) $
	 * $Date: 2026-08-18 12:49:07 $
 *
 * @package Candy\Extend\DB\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Schema;

use Candy\Extend\DB\Dialect\Sqlsrv as SqlsrvDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class SqlsrvSchemaProvider extends AbstractSchemaProvider
{
	/**
	 * SQL Server 当前 Provider 不提供无约束名的默认值迁移。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $expression 默认值表达式
	 * @return bool 始终返回 false
	 */
	public function setColumnDefault(string $table, string $column, string $expression): bool
	{
		unset($table, $column, $expression);

		return false;
	}

	/**
	 * SQL Server 当前 Provider 不提供不带完整列定义的非空迁移。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param bool $notNull 是否设置为非空
	 * @return bool 始终返回 false
	 */
	public function setColumnNotNull(string $table, string $column, bool $notNull = true): bool
	{
		unset($table, $column, $notNull);

		return false;
	}
	/**
	 * 引用 SQL Server 标识符。
	 *
	 * @param string $identifier 已校验标识符
	 * @return string SQL 标识符
	 */
	protected function quoteIdentifier(string $identifier): string
	{
		return SqlsrvDialect::quoteIdent($identifier);
	}

	/**
	 * 构造 SQL Server 字段定义修改语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 类型及约束定义
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildAlterColumnSql(
		string $table,
		string $column,
		string $definition
	): ?string {
		$table = trim($table);
		$column = trim($column);
		$definition = trim($definition);
		if (
			!$this->isSafeTableName($table)
			|| !$this->isSafeIdentifier($column)
			|| !$this->isSafeDefinition($definition)
		) {
			return null;
		}

		return 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' ALTER COLUMN ' . $this->quoteIdentifier($column)
			. ' ' . $definition;
	}

	/**
	 * SQL Server 当前 Provider 不提供幂等表复制。
	 *
	 * @param string $source 源表名
	 * @param string $target 目标表名
	 * @return string|null 始终返回 null
	 * @access protected
	 */
	protected function buildCopyTableSql(string $source, string $target): ?string
	{
		unset($source, $target);

		return null;
	}

	/**
	 * 构造 SQL Server 删除字段语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildDropColumnSql(string $table, string $column): ?string
	{
		$table = trim($table);
		$column = trim($column);
		if (!$this->isSafeTableName($table) || !$this->isSafeIdentifier($column)) {
			return null;
		}

		return 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' DROP COLUMN ' . $this->quoteIdentifier($column);
	}

	/**
	 * 构造 SQL Server 删除索引语句。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildDropIndexSql(string $table, string $index): ?string
	{
		$table = trim($table);
		$index = trim($index);
		if (!$this->isSafeTableName($table) || !$this->isSafeIdentifier($index)) {
			return null;
		}

		return 'DROP INDEX ' . $this->quoteIdentifier($index)
			. ' ON ' . $this->quoteIdentifier($table);
	}

	/**
	 * 通过 SQL Server 方言驱动新增字段。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 已校验的字段定义
	 * @return bool 是否执行成功
	 */
	public function addColumn(
		string $table,
		string $column,
		string $definition,
		string $afterColumn = ''
	): bool
	{
		$sql = $this->buildAddColumnSql($table, $column, $definition, false, $afterColumn);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public function schemaName(): string
	{
		return (string) (C('DBNAME') ?? (function_exists('G') ? (string) G('DBNAME') : ''));
	}

	/**
	 * 读取 SQL Server 当前实例排序规则。
	 *
	 * @return string 排序规则；读取失败时为空字符串
	 */
	public function charset(): string
	{
		try {
			$row = $this->db->query(
				"SELECT CAST(SERVERPROPERTY('Collation') AS NVARCHAR(128)) AS coll",
				'find'
			);
			$row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];

			return (string) ($row['coll'] ?? '');
		} catch (\Throwable) {
			return '';
		}
	}

	public function listTables(?string $keyword = null): array
	{
		$sql = "SELECT t.TABLE_NAME AS name, ISNULL(ep.value, '') AS comment "
			. 'FROM INFORMATION_SCHEMA.TABLES t '
			. "LEFT JOIN sys.extended_properties ep ON ep.major_id = OBJECT_ID(QUOTENAME(t.TABLE_SCHEMA) + '.' + QUOTENAME(t.TABLE_NAME)) "
			. 'AND ep.minor_id = 0 AND ep.name = \'MS_Description\' '
			. "WHERE t.TABLE_TYPE = 'BASE TABLE' AND t.TABLE_CATALOG = DB_NAME()";
		$bind = [];
		if ($keyword !== null && trim($keyword) !== '') {
			$sql .= ' AND t.TABLE_NAME LIKE ?';
			$bind[] = '%' . trim($keyword) . '%';
		}
		$sql .= ' ORDER BY t.TABLE_NAME ASC';

		$rows = $this->db->query($sql, 'select', $bind);

		return is_array($rows) ? $this->normalizeNameCommentRows($rows) : [];
	}

	public function listTablesForAdmin(string $keyword = ''): array
	{
		$tables = $this->listTables($keyword !== '' ? $keyword : null);
		$result = [];
		foreach ($tables as $row) {
			$name = (string) ($row['name'] ?? '');
			if ($name === '') {
				continue;
			}
			$rowCount = 0;
			try {
				$countRow = $this->db->query(
					'SELECT COUNT(*) AS c FROM ' . SqlsrvDialect::quoteIdent($name),
					'find'
				);
				if (is_array($countRow)) {
					$countRow = array_change_key_case($countRow, CASE_LOWER);
					$rowCount = (int) ($countRow['c'] ?? 0);
				}
			} catch (\Throwable) {
				$rowCount = 0;
			}
			$result[] = [
				'name' => $name,
				'engine' => 'SQL Server',
				'rows_count' => $rowCount,
				'data_length' => 0,
				'index_length' => 0,
				'collation' => '',
				'create_time' => null,
				'update_time' => null,
				'comment' => (string) ($row['comment'] ?? ''),
			];
		}

		return $result;
	}

	public function tableExists(string $table): bool
	{
		if (!$this->isSafeTableName($table)) {
			return false;
		}
		$hit = false;
		$cached = $this->loadSchemaMetadataRequestCache('table_exists', $table, $hit);
		if ($hit) {
			return (bool) $cached;
		}

		$row = $this->db->query(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
			. "WHERE TABLE_CATALOG = DB_NAME() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME = ?",
			'find',
			[$table]
		);

		$exists = is_array($row) && $row !== [];
		$this->saveSchemaMetadataRequestCache('table_exists', $table, $exists);

		return $exists;
	}

	public function listColumns(string $table): array
	{
		$hit = false;
		$cached = $this->loadSchemaMetadataRequestCache('columns', $table, $hit);
		if ($hit) {
			return is_array($cached) ? $cached : [];
		}
		if (!$this->tableExists($table)) {
			$this->saveSchemaMetadataRequestCache('columns', $table, []);

			return [];
		}

		$rows = $this->db->query(SqlsrvDialect::columnsMetaSql(), 'select', [$table]);
		if (!is_array($rows)) {
			$this->saveSchemaMetadataRequestCache('columns', $table, []);

			return [];
		}

		$identityCols = [];
		try {
			$idRows = $this->db->query(SqlsrvDialect::identityCheckSql($table), 'select', [$table]);
			if (is_array($idRows)) {
				foreach ($idRows as $idRow) {
					if (!is_array($idRow)) {
						continue;
					}
					$idRow = array_change_key_case($idRow, CASE_LOWER);
					$col = strtolower((string) ($idRow['column_name'] ?? ''));
					if ($col !== '') {
						$identityCols[$col] = true;
					}
				}
			}
		} catch (\Throwable) {
			$identityCols = [];
		}

		$columns = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = array_change_key_case($row, CASE_LOWER);
			$field = strtolower((string) ($row['column_name'] ?? ''));
			if ($field === '') {
				continue;
			}
			$nullable = strtoupper((string) ($row['is_nullable'] ?? '')) === 'YES' ? 'YES' : 'NO';
			$key = (($row['constraint_type'] ?? '') === 'PRIMARY KEY') ? 'PRI' : '';
			$extra = isset($identityCols[$field]) ? 'auto_increment' : '';
			$columns[] = [
				'field' => $field,
				'type' => (string) ($row['data_type'] ?? ''),
				'null' => $nullable,
				'key' => $key,
				'default' => $row['column_default'] ?? null,
				'extra' => $extra,
				'comment' => '',
				'collation' => '',
			];
		}

		$this->saveSchemaMetadataRequestCache('columns', $table, $columns);

		return $columns;
	}

	public function getCreateTableSql(string $table): string
	{
		unset($table);

		return '';
	}

	public function getTableIndexGroups(string $table): array
	{
		$hit = false;
		$cached = $this->loadSchemaMetadataRequestCache('indexes', $table, $hit);
		if ($hit) {
			return is_array($cached) ? $cached : [];
		}
		if (!$this->tableExists($table)) {
			$this->saveSchemaMetadataRequestCache('indexes', $table, []);

			return [];
		}

		$sql = 'SELECT '
			. 'i.name AS key_name, '
			. 'CASE WHEN i.is_unique = 1 THEN 0 ELSE 1 END AS non_unique, '
			. 'c.name AS column_name, '
			. 'ic.key_ordinal AS seq_in_index '
			. 'FROM sys.tables t '
			. 'INNER JOIN sys.indexes i ON t.object_id = i.object_id '
			. 'INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id '
			. 'INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id '
			. 'WHERE t.name = ? AND i.type > 0 '
			. 'ORDER BY i.name, ic.key_ordinal';

		$rows = $this->db->query($sql, 'select', [$table]);
		if (!is_array($rows)) {
			$this->saveSchemaMetadataRequestCache('indexes', $table, []);

			return [];
		}

		$groups = $this->mapShowIndexRows($rows);
		foreach ($groups as $name => &$group) {
			if (stripos($name, 'PK_') === 0 || strcasecmp($name, 'PRIMARY') === 0) {
				$group['primary'] = 1;
			}
		}
		unset($group);

		$this->saveSchemaMetadataRequestCache('indexes', $table, $groups);

		return $groups;
	}

	public function listTableNames(): array
	{
		$tables = $this->listTables(null);
		$names = [];
		foreach ($tables as $row) {
			$name = (string) ($row['name'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}
}
