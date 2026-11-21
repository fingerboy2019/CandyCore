<?php
/***
 * SQLite 元数据 Provider
 *
 * 业务说明：sqlite_master / PRAGMA 探测表、列、索引与建表 SQL
 *
 * $Author: 刘森 (fingerboy@qq.com) $
	 * $Date: 2026-08-18 12:49:07 $
 *
 * @package Candy\Extend\DB\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Schema;

use Candy\Extend\DB\Dialect\Sqlite as SqliteDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class SqliteSchemaProvider extends AbstractSchemaProvider
{
	/**
	 * 引用 SQLite 标识符。
	 *
	 * @param string $identifier 已校验标识符
	 * @return string SQL 标识符
	 */
	protected function quoteIdentifier(string $identifier): string
	{
		return SqliteDialect::quoteIdent($identifier);
	}

	/**
	 * SQLite 通过元数据预检实现幂等新增字段。
	 *
	 * SQLite 的 ALTER TABLE ADD COLUMN 不支持 IF NOT EXISTS，已存在字段直接视为成功。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 已校验的字段定义
	 * @return bool 是否执行成功或字段已存在
	 */
	public function addColumn(
		string $table,
		string $column,
		string $definition,
		string $afterColumn = ''
	): bool
	{
		if ($this->hasColumn($table, $column)) {
			return true;
		}

		return parent::addColumn($table, $column, $definition, $afterColumn);
	}

	/**
	 * SQLite 不支持通用 ADD COLUMN IF NOT EXISTS 构造。
	 *
	 * @return bool 始终返回 false
	 */
	protected function supportsAddColumnIfNotExists(): bool
	{
		return false;
	}

	/**
	 * SQLite 不支持通用 ALTER COLUMN 类型修改。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 类型及约束定义
	 * @return bool 始终返回 false
	 */
	public function alterColumn(string $table, string $column, string $definition): bool
	{
		unset($table, $column, $definition);

		return false;
	}

	/**
	 * SQLite 不支持通用字段默认值修改。
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
	 * SQLite 不支持通用字段非空约束修改。
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
	 * SQLite 不支持通用表约束删除。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 约束名
	 * @return bool 始终返回 false
	 */
	public function dropConstraint(string $table, string $constraint): bool
	{
		unset($table, $constraint);

		return false;
	}

	/**
	 * SQLite 当前兼容层不执行通用字段删除。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return bool 始终返回 false
	 */
	public function dropColumn(string $table, string $column): bool
	{
		unset($table, $column);

		return false;
	}

	/**
	 * SQLite 当前兼容层不执行通用索引删除。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return bool 始终返回 false
	 */
	public function dropIndex(string $table, string $index): bool
	{
		unset($table, $index);

		return false;
	}

	/**
	 * SQLite 当前兼容层不支持向现有表追加主键。
	 *
	 * @param string $table 物理表名
	 * @param array<int, string> $columns 主键列名
	 * @return bool 始终返回 false
	 */
	public function addPrimaryKey(string $table, array $columns): bool
	{
		unset($table, $columns);

		return false;
	}

	/**
	 * 构造 SQLite 表复制语句。
	 *
	 * @param string $source 源表名
	 * @param string $target 目标表名
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildCopyTableSql(string $source, string $target): ?string
	{
		$source = trim($source);
		$target = trim($target);
		if (!$this->isSafeTableName($source) || !$this->isSafeTableName($target)) {
			return null;
		}

		return 'CREATE TABLE IF NOT EXISTS ' . $this->quoteIdentifier($target)
			. ' AS SELECT * FROM ' . $this->quoteIdentifier($source);
	}

	public function schemaName(): string
	{
		return 'main';
	}

	/**
	 * 读取 SQLite 数据库字符集。
	 *
	 * @return string 字符集；读取失败时为空字符串
	 */
	public function charset(): string
	{
		try {
			$row = $this->db->query('PRAGMA encoding', 'find');
			if (!is_array($row)) {
				return '';
			}

			$value = $row['encoding'] ?? reset($row);

			return is_scalar($value) ? (string) $value : '';
		} catch (\Throwable) {
			return '';
		}
	}

	public function listTables(?string $keyword = null): array
	{
		$sql = "SELECT name AS name, '' AS comment FROM sqlite_master "
			. "WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'";
		$bind = [];
		if ($keyword !== null && trim($keyword) !== '') {
			$sql .= ' AND name LIKE ?';
			$bind[] = '%' . trim($keyword) . '%';
		}
		$sql .= ' ORDER BY name ASC';

		$rows = $this->db->query($sql, 'select', $bind);

		return is_array($rows) ? $this->normalizeNameCommentRows($rows) : [];
	}

	public function listTablesForAdmin(string $keyword = ''): array
	{
		$rows = $this->listTables($keyword !== '' ? $keyword : null);
		$result = [];
		foreach ($rows as $row) {
			$name = (string) ($row['name'] ?? '');
			if ($name === '') {
				continue;
			}
			$rowCount = 0;
			try {
				$countRow = $this->db->query(
					'SELECT COUNT(*) AS c FROM ' . SqliteDialect::quoteIdent($name),
					'find'
				);
				if (is_array($countRow)) {
					$countRow = SqliteDialect::normalizeRowKeys($countRow) ?? [];
					$rowCount = (int) ($countRow['c'] ?? 0);
				}
			} catch (\Throwable) {
				$rowCount = 0;
			}
			$result[] = [
				'name' => $name,
				'engine' => 'SQLite',
				'rows_count' => $rowCount,
				'data_length' => 0,
				'index_length' => 0,
				'collation' => 'utf8',
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
			"SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
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

		$rows = $this->db->query(SqliteDialect::tableInfoSql($table), 'select');

		$columns = is_array($rows) ? SqliteDialect::mapPragmaTableInfoRows($rows) : [];
		$this->saveSchemaMetadataRequestCache('columns', $table, $columns);

		return $columns;
	}

	public function getCreateTableSql(string $table): string
	{
		if (!$this->tableExists($table)) {
			return '';
		}

		$row = $this->db->query(
			"SELECT sql AS create_sql FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
			'find',
			[$table]
		);
		if (!is_array($row)) {
			return '';
		}
		$row = SqliteDialect::normalizeRowKeys($row) ?? [];

		return $this->finalizeCreateSql((string) ($row['create_sql'] ?? ''));
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

		$quoted = SqliteDialect::quoteIdent($table);
		$indexRows = $this->db->query('PRAGMA index_list(' . $quoted . ')', 'select');
		if (!is_array($indexRows)) {
			$this->saveSchemaMetadataRequestCache('indexes', $table, []);

			return [];
		}

		$indexInfoMap = [];
		foreach ($indexRows as $indexRow) {
			if (!is_array($indexRow)) {
				continue;
			}
			$indexRow = SqliteDialect::normalizeRowKeys($indexRow) ?? [];
			$indexName = (string) ($indexRow['name'] ?? '');
			if ($indexName === '') {
				continue;
			}
			$infoRows = $this->db->query(
				'PRAGMA index_info(' . SqliteDialect::quoteIdent($indexName) . ')',
				'select'
			);
			$indexInfoMap[$indexName] = is_array($infoRows) ? $infoRows : [];
		}

		$groups = SqliteDialect::mapPragmaIndexGroups($indexRows, $indexInfoMap);
		$this->saveSchemaMetadataRequestCache('indexes', $table, $groups);

		return $groups;
	}

	public function listTableNames(): array
	{
		$rows = $this->db->query(
			"SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name ASC",
			'select'
		);
		if (!is_array($rows)) {
			return [];
		}

		$names = [];
		foreach ($rows as $row) {
			$row = array_change_key_case(is_array($row) ? $row : [], CASE_LOWER);
			$name = (string) ($row['table_name'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}
}
