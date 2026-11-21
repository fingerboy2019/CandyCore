<?php
/***
 * MySQL 元数据 Provider
 *
 * 业务说明：INFORMATION_SCHEMA / SHOW 探测表、列、索引与建表 SQL
 *
 * $Author: 刘森 (fingerboy@qq.com) $
	 * $Date: 2026-08-18 12:49:07 $
 *
 * @package Candy\Extend\DB\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Schema;

use Candy\Extend\DB\Dialect\Mysql as MysqlDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class MysqlSchemaProvider extends AbstractSchemaProvider
{
	/**
	 * MySQL 支持新增字段时指定相邻字段。
	 *
	 * @return bool 始终返回 true
	 * @access protected
	 */
	protected function supportsAddColumnAfter(): bool
	{
		return true;
	}

	/**
	 * 通过 MySQL 方言写入数据表注释。
	 *
	 * @param string $table 物理表名
	 * @param string $comment 注释文本
	 * @return bool 是否执行成功
	 */
	public function commentTable(string $table, string $comment): bool
	{
		$table = trim($table);
		if (!$this->isSafeTableName($table)) {
			return false;
		}

		$sql = 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. " COMMENT = '" . str_replace("'", "''", $comment) . "'";
		try {
			$this->db->query($sql, 'other');

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过 MySQL 方言删除表级主键。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 主键约束名（MySQL 不使用）
	 * @return bool 是否执行成功
	 */
	public function dropPrimaryKey(string $table, string $constraint = ''): bool
	{
		$table = trim($table);
		if (!$this->isSafeTableName($table)) {
			return false;
		}

		try {
			$this->db->query(
				'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP PRIMARY KEY',
				'other'
			);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 引用 MySQL 标识符。
	 *
	 * @param string $identifier 已校验标识符
	 * @return string SQL 标识符
	 */
	protected function quoteIdentifier(string $identifier): string
	{
		return MysqlDialect::quoteIdent($identifier);
	}

	/**
	 * 构造 MySQL 字段定义修改语句。
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
			. ' MODIFY COLUMN ' . $this->quoteIdentifier($column)
			. ' ' . $definition;
	}

	/**
	 * 构造 MySQL 删除索引约束语句。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 约束或索引名
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildDropConstraintSql(string $table, string $constraint): ?string
	{
		$table = trim($table);
		$constraint = trim($constraint);
		if (!$this->isSafeTableName($table) || !$this->isSafeIdentifier($constraint)) {
			return null;
		}

		return 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' DROP INDEX ' . $this->quoteIdentifier($constraint);
	}

	/**
	 * 构造 MySQL 表复制语句。
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

	/**
	 * 构造 MySQL 删除索引语句。
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

		return 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' DROP INDEX ' . $this->quoteIdentifier($index);
	}

	public function schemaName(): string
	{
		return (string) (C('DBNAME') ?? (function_exists('G') ? (string) G('DBNAME') : ''));
	}

	/**
	 * 读取 MySQL 当前库字符集。
	 *
	 * @return string 字符集；读取失败时为空字符串
	 */
	public function charset(): string
	{
		try {
			$row = $this->db->query("SHOW VARIABLES LIKE 'character_set_database'", 'find');
			$row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];

			return (string) ($row['value'] ?? '');
		} catch (\Throwable) {
			return '';
		}
	}

	public function listTables(?string $keyword = null): array
	{
		$dbName = $this->schemaName();
		if ($dbName === '') {
			return [];
		}

		$sql = 'SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment '
			. 'FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?';
		$bind = [$dbName];
		if ($keyword !== null && trim($keyword) !== '') {
			$sql .= ' AND TABLE_NAME LIKE ?';
			$bind[] = '%' . trim($keyword) . '%';
		}
		$sql .= ' ORDER BY TABLE_NAME ASC';

		$rows = $this->db->query($sql, 'select', $bind);

		return is_array($rows) ? $this->normalizeNameCommentRows($rows) : [];
	}

	public function listTablesForAdmin(string $keyword = ''): array
	{
		$dbName = $this->schemaName();
		if ($dbName === '') {
			return [];
		}

		$sql = 'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_count, '
			. 'DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, '
			. 'TABLE_COLLATION AS collation, CREATE_TIME AS create_time, UPDATE_TIME AS update_time, '
			. 'TABLE_COMMENT AS comment '
			. 'FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?';
		$bind = [$dbName];
		if ($keyword !== '') {
			$sql .= ' AND TABLE_NAME LIKE ?';
			$bind[] = '%' . $keyword . '%';
		}
		$sql .= ' ORDER BY TABLE_NAME ASC';

		$rows = $this->db->query($sql, 'select', $bind);

		return is_array($rows) ? $rows : [];
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
		$dbName = $this->schemaName();
		if ($dbName === '') {
			$this->saveSchemaMetadataRequestCache('table_exists', $table, false);

			return false;
		}

		$row = $this->db->query(
			'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
			'find',
			[$dbName, $table]
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

		$rows = $this->db->query('SHOW FULL COLUMNS FROM ' . MysqlDialect::quoteIdent($table), 'select');
		if (!is_array($rows)) {
			$this->saveSchemaMetadataRequestCache('columns', $table, []);

			return [];
		}

		$columns = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$columns[] = $this->mapColumnCompatRow(array_change_key_case($row, CASE_LOWER));
		}

		$this->saveSchemaMetadataRequestCache('columns', $table, $columns);

		return $columns;
	}

	public function getCreateTableSql(string $table): string
	{
		if (!$this->tableExists($table)) {
			return '';
		}

		$row = $this->db->query('SHOW CREATE TABLE ' . MysqlDialect::quoteIdent($table), 'find');
		if (!is_array($row)) {
			return '';
		}
		$row = array_change_key_case($row, CASE_LOWER);
		$sql = (string) ($row['create table'] ?? '');

		return $this->finalizeCreateSql($sql);
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

		$rows = $this->db->query('SHOW INDEX FROM ' . MysqlDialect::quoteIdent($table), 'select');
		if (!is_array($rows)) {
			$this->saveSchemaMetadataRequestCache('indexes', $table, []);

			return [];
		}

		$groups = $this->mapShowIndexRows($rows);
		$this->saveSchemaMetadataRequestCache('indexes', $table, $groups);

		return $groups;
	}

	/**
	 * 按字符集读取 MySQL 排序规则。
	 *
	 * 字符集只作为绑定参数传入，避免把页面输入拼接进元数据查询。
	 *
	 * @param string $charset 字符集
	 * @return array<int, string>
	 */
	public function listCollations(string $charset = ''): array
	{
		$charset = trim($charset);
		if ($charset === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
			$charset = 'utf8mb4';
		}

		$rows = $this->db->query(
			'SELECT COLLATION_NAME AS name FROM INFORMATION_SCHEMA.COLLATIONS '
				. 'WHERE CHARACTER_SET_NAME = ? ORDER BY COLLATION_NAME ASC',
			'select',
			[$charset]
		);
		if (!is_array($rows)) {
			return [];
		}

		$list = [];
		foreach ($rows as $row) {
			$row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
			$name = trim((string) ($row['name'] ?? ''));
			if ($name !== '') {
				$list[] = $name;
			}
		}

		return $list;
	}

	public function listTableNames(): array
	{
		$dbName = $this->schemaName();
		$tableList = [];
		if ($dbName !== '') {
			try {
				$rows = $this->db->query(
					'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME ASC',
					'select',
					[$dbName]
				);
				if (is_array($rows)) {
					foreach ($rows as $row) {
						$row = array_change_key_case(is_array($row) ? $row : [], CASE_LOWER);
						$name = (string) ($row['table_name'] ?? '');
						if ($name !== '') {
							$tableList[] = $name;
						}
					}
				}
			} catch (\Throwable) {
				$tableList = [];
			}
		}

		if ($tableList !== []) {
			return $tableList;
		}

		try {
			$fallback = $this->db->query('SHOW TABLES', 'select');
		} catch (\Throwable) {
			return [];
		}
		if (!is_array($fallback)) {
			return [];
		}

		$names = [];
		foreach ($fallback as $row) {
			$names[] = (string) reset($row);
		}

		return $names;
	}
}
