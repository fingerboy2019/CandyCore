<?php
/***
 * PostgreSQL 元数据 Provider
 *
 * 业务说明：information_schema / pg_catalog 探测表、列、索引；拼近似建表 SQL；列表行数用 reltuples 估算
 *
 * $Author: 刘森 (fingerboy@qq.com) $
	 * $Date: 2026-08-18 13:50:42 $
 *
 * @package Candy\Extend\DB\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Schema;

use Candy\Extend\DB\Dialect\Pgsql as PgsqlDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class PgsqlSchemaProvider extends AbstractSchemaProvider
{
	/**
	 * 通过 PostgreSQL 方言写入数据表注释。
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

		$sql = 'COMMENT ON TABLE ' . $this->quoteIdentifier($table)
			. " IS '" . str_replace("'", "''", $comment) . "'";
		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过 PostgreSQL 方言写入字段注释。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $comment 注释文本
	 * @return bool 是否执行成功
	 */
	public function commentColumn(string $table, string $column, string $comment): bool
	{
		$sql = $this->buildCommentColumnSql($table, $column, $comment);
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

	/**
	 * 构造 PostgreSQL 字段注释语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $comment 注释文本
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildCommentColumnSql(string $table, string $column, string $comment): ?string
	{
		$table = trim($table);
		$column = trim($column);
		if (!$this->isSafeTableName($table) || !$this->isSafeIdentifier($column)) {
			return null;
		}

		return 'COMMENT ON COLUMN ' . $this->quoteIdentifier($table)
			. '.' . $this->quoteIdentifier($column)
			. " IS '" . str_replace("'", "''", $comment) . "'";
	}

	/**
	 * 通过 PostgreSQL 约束名删除主键。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 主键约束名；为空时按 PostgreSQL 默认命名推断
	 * @return bool 是否执行成功
	 */
	public function dropPrimaryKey(string $table, string $constraint = ''): bool
	{
		$constraint = trim($constraint);
		if ($constraint === '') {
			$constraint = trim($table) . '_pkey';
		}

		return $this->dropConstraint($table, $constraint);
	}

	/**
	 * PostgreSQL 支持 DROP TABLE CASCADE。
	 *
	 * @return bool 始终返回 true
	 * @access protected
	 */
	protected function supportsDropTableCascade(): bool
	{
		return true;
	}

	/**
	 * 引用 PostgreSQL 标识符。
	 *
	 * @param string $identifier 已校验标识符
	 * @return string SQL 标识符
	 */
	protected function quoteIdentifier(string $identifier): string
	{
		return PgsqlDialect::quoteIdent($identifier);
	}

	/**
	 * PostgreSQL 支持部分索引。
	 *
	 * @return bool true
	 */
	protected function supportsPartialIndex(): bool
	{
		return true;
	}

	public function schemaName(): string
	{
		$row = $this->db->query('SELECT current_schema() AS s', 'find');
		if (is_array($row)) {
			$row = array_change_key_case($row, CASE_LOWER);
			$s = (string) ($row['s'] ?? '');
			if ($s !== '') {
				return $s;
			}
		}

		return 'public';
	}

	/**
	 * 读取 PostgreSQL 当前库字符集。
	 *
	 * @return string 字符集；读取失败时为空字符串
	 */
	public function charset(): string
	{
		try {
			$row = $this->db->query("SELECT current_setting('server_encoding') AS enc", 'find');
			$row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];

			return (string) ($row['enc'] ?? '');
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * 读取 PostgreSQL 当前数据库名。
	 *
	 * @return string 数据库名；读取失败时为空字符串
	 */
	public function databaseName(): string
	{
		try {
			$row = $this->db->query('SELECT current_database() AS db', 'find');
			$row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];

			return (string) ($row['db'] ?? '');
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * 读取 PostgreSQL 当前用户的建库权限。
	 *
	 * 该查询属于数据库元数据能力，统一封装在 PostgreSQL SchemaProvider，避免
	 * 租户入驻编排直接拼接方言 SQL。
	 *
	 * @return bool 是否具备 CREATE DATABASE 权限
	 */
	public function canCreateDatabase(): bool
	{
		try {
			$row = $this->db->query(
				"SELECT has_database_privilege(current_database(), 'CREATE') AS can_create",
				'find'
			);
			$row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
			$value = $row['can_create'] ?? null;
			if (is_bool($value)) {
				return $value;
			}

			return in_array(
				strtolower(trim((string)$value)),
				['1', 't', 'true', 'yes', 'on'],
				true
			);
		} catch (\Throwable) {
			return false;
		}
	}

	public function listTables(?string $keyword = null): array
	{
		$sql = "SELECT c.relname AS name, COALESCE(obj_description(c.oid), '') AS comment "
			. 'FROM pg_class c '
			. 'JOIN pg_namespace n ON n.oid = c.relnamespace '
			. "WHERE c.relkind = 'r' AND n.nspname = current_schema()";
		$bind = [];
		if ($keyword !== null && trim($keyword) !== '') {
			$sql .= ' AND c.relname LIKE ?';
			$bind[] = '%' . trim($keyword) . '%';
		}
		$sql .= ' ORDER BY c.relname ASC';

		$rows = $this->db->query($sql, 'select', $bind);

		return is_array($rows) ? $this->normalizeNameCommentRows($rows) : [];
	}

	public function listTablesForAdmin(string $keyword = ''): array
	{
		// 行数用 reltuples 估算，避免逐表 COUNT(*) 拖慢列表
		$sql = 'SELECT c.relname AS name, '
			. "COALESCE(obj_description(c.oid), '') AS comment, "
			. 'GREATEST(c.reltuples::bigint, 0) AS rows_count, '
			. 'pg_total_relation_size(c.oid)::bigint AS data_length, '
			. 'pg_indexes_size(c.oid)::bigint AS index_length '
			. 'FROM pg_class c '
			. 'JOIN pg_namespace n ON n.oid = c.relnamespace '
			. "WHERE c.relkind = 'r' AND n.nspname = current_schema()";
		$bind = [];
		if ($keyword !== '') {
			$sql .= ' AND c.relname LIKE ?';
			$bind[] = '%' . trim($keyword) . '%';
		}
		$sql .= ' ORDER BY c.relname ASC';

		$rows = $this->db->query($sql, 'select', $bind);
		if (!is_array($rows)) {
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = array_change_key_case($row, CASE_LOWER);
			$name = (string) ($row['name'] ?? '');
			if ($name === '') {
				continue;
			}
			$result[] = [
				'name' => $name,
				'engine' => 'PostgreSQL',
				'rows_count' => (int) ($row['rows_count'] ?? 0),
				'data_length' => (int) ($row['data_length'] ?? 0),
				'index_length' => (int) ($row['index_length'] ?? 0),
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
			"SELECT 1 AS ok FROM information_schema.tables "
			. "WHERE table_schema = current_schema() AND table_name = ? LIMIT 1",
			'find',
			[$table]
		);

		$exists = is_array($row) && $row !== [];
		$this->saveSchemaMetadataRequestCache('table_exists', $table, $exists);

		return $exists;
	}

	/**
	 * 将 PostgreSQL 自增序列推进到当前表最大主键。
	 *
	 * 表名先经过安全标识符校验；序列名称通过参数绑定传入，避免把租户物理名
	 * 作为未校验 SQL 片段传入。该能力供 Schema 迁移回填复用，不承担业务写入编排。
	 *
	 * @param string $table 物理表名
	 * @return void
	 */
	public function syncSequence(string $table): void
	{
		if (!$this->isSafeTableName($table)) {
			return;
		}

		$qt = PgsqlDialect::quoteIdent($table);
		$this->db->query(
			'SELECT setval(pg_get_serial_sequence(?, \'id\'), '
				. 'GREATEST(COALESCE((SELECT MAX(id) FROM ' . $qt . '), 1)), true)',
			'find',
			[$table]
		);
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

		$rows = $this->db->query(PgsqlDialect::columnsMetaSql(), 'select', [$table]);
		if (!is_array($rows)) {
			$this->saveSchemaMetadataRequestCache('columns', $table, []);

			return [];
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
			$extra = '';
			$default = (string) ($row['column_default'] ?? '');
			$udt = strtolower((string) ($row['udt_name'] ?? ''));
			if (
				stripos($default, 'nextval') !== false
				|| str_contains($udt, 'serial')
				|| stripos($default, 'identity') !== false
				|| strtoupper((string) ($row['is_identity'] ?? '')) === 'YES'
			) {
				$extra = 'auto_increment';
			}
			$type = (string) ($row['data_type'] ?? $udt);
			// 展示层兼容：integer → int，便于表单 base_type 识别
			if ($type === 'integer') {
				$type = 'int';
			} elseif ($type === 'character varying') {
				$type = 'varchar';
			} elseif ($type === 'timestamp without time zone' || $type === 'timestamp with time zone') {
				$type = 'timestamp';
			} elseif ($type === 'double precision') {
				$type = 'double';
			}
			$columns[] = [
				'field' => $field,
				'type' => $type,
				'null' => $nullable,
				'key' => $key,
				'default' => $row['column_default'] ?? null,
				'extra' => $extra,
				'comment' => (string) ($row['column_comment'] ?? $row['comment'] ?? ''),
				'collation' => '',
			];
		}

		$this->saveSchemaMetadataRequestCache('columns', $table, $columns);

		return $columns;
	}

	public function getCreateTableSql(string $table): string
	{
		if (!$this->tableExists($table)) {
			return '';
		}

		$columns = $this->listColumns($table);
		if ($columns === []) {
			return '';
		}

		$qt = PgsqlDialect::quoteIdent($table);
		$lines = [];
		$pkFields = [];
		$commentLines = [];

		$tableComment = '';
		foreach ($this->listTables($table) as $trow) {
			if (($trow['name'] ?? '') === $table) {
				$tableComment = (string) ($trow['comment'] ?? '');
				break;
			}
		}
		// listTables 用 LIKE 可能不准；直接查 obj_description
		$descRow = $this->db->query(
			'SELECT COALESCE(obj_description(c.oid), \'\') AS comment '
			. 'FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
			. 'WHERE c.relkind = \'r\' AND n.nspname = current_schema() AND c.relname = ?',
			'find',
			[$table]
		);
		if (is_array($descRow)) {
			$descRow = array_change_key_case($descRow, CASE_LOWER);
			$tableComment = (string) ($descRow['comment'] ?? $tableComment);
		}

		foreach ($columns as $col) {
			$field = (string) ($col['field'] ?? '');
			if ($field === '') {
				continue;
			}
			$qf = PgsqlDialect::quoteIdent($field);
			$type = strtolower((string) ($col['type'] ?? 'text'));
			$typeSql = $this->mapDisplayTypeToPgsql($type);
			$extra = strtolower((string) ($col['extra'] ?? ''));
			$nullSql = (($col['null'] ?? '') === 'YES') ? 'NULL' : 'NOT NULL';
			$defaultRaw = $col['default'] ?? null;
			$defaultSql = '';
			if ($defaultRaw !== null && (string) $defaultRaw !== '') {
				$defaultSql = ' DEFAULT ' . (string) $defaultRaw;
			}
			if (str_contains($extra, 'auto_increment')) {
				// 近似：IDENTITY（原 serial/nextval 不逐字还原）
				$lines[] = "  {$qf} {$typeSql} GENERATED BY DEFAULT AS IDENTITY {$nullSql}";
			} else {
				$lines[] = "  {$qf} {$typeSql} {$nullSql}{$defaultSql}";
			}
			if (($col['key'] ?? '') === 'PRI') {
				$pkFields[] = $qf;
			}
			$cmt = trim((string) ($col['comment'] ?? ''));
			if ($cmt !== '') {
				$commentLines[] = 'COMMENT ON COLUMN ' . $qt . '.' . $qf . ' IS '
					. $this->quoteLiteral($cmt) . ';';
			}
		}

		if ($pkFields !== []) {
			$lines[] = '  PRIMARY KEY (' . implode(', ', $pkFields) . ')';
		}

		$sql = 'CREATE TABLE ' . $qt . " (\n" . implode(",\n", $lines) . "\n);";
		if ($tableComment !== '') {
			$sql .= "\nCOMMENT ON TABLE " . $qt . ' IS ' . $this->quoteLiteral($tableComment) . ';';
		}
		if ($commentLines !== []) {
			$sql .= "\n" . implode("\n", $commentLines);
		}

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

		$sql = 'SELECT '
			. 'i.relname AS key_name, '
			. 'CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS non_unique, '
			. 'a.attname AS column_name, '
			. 'array_position(ix.indkey, a.attnum) AS seq_in_index '
			. 'FROM pg_class t '
			. 'JOIN pg_index ix ON t.oid = ix.indrelid '
			. 'JOIN pg_class i ON i.oid = ix.indexrelid '
			. 'JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (ix.indkey) '
			. 'JOIN pg_namespace n ON n.oid = t.relnamespace '
			. 'WHERE t.relkind = \'r\' AND n.nspname = current_schema() AND t.relname = ? '
			. 'ORDER BY i.relname, seq_in_index';

		$rows = $this->db->query($sql, 'select', [$table]);
		if (!is_array($rows)) {
			$this->saveSchemaMetadataRequestCache('indexes', $table, []);

			return [];
		}

		$groups = $this->mapShowIndexRows($rows);
		$pkCols = [];
		foreach ($this->listColumns($table) as $col) {
			if (($col['key'] ?? '') === 'PRI') {
				$pkCols[] = (string) $col['field'];
			}
		}
		if ($pkCols !== []) {
			foreach ($groups as $name => &$group) {
				if ($group['columns'] === $pkCols || ($group['unique'] === 1 && $group['columns'] === $pkCols)) {
					$group['primary'] = 1;
					if (strcasecmp($name, 'PRIMARY') !== 0) {
						$groups['PRIMARY'] = $group;
						$groups['PRIMARY']['key_name'] = 'PRIMARY';
						$groups['PRIMARY']['constraint_name'] = $name;
					}
				}
			}
			unset($group);
		}

		$this->saveSchemaMetadataRequestCache('indexes', $table, $groups);

		return $groups;
	}

	/**
	 * 统计 PostgreSQL 表中指定字段的总行数与非默认值行数。
	 *
	 * 表名和字段名只接受安全标识符，并统一由 PostgreSQL 方言引用，避免把租户物理名
	 * 直接拼接到业务模型。调用方通常已完成批量元数据探测，本方法不重复查询表/列存在性；
	 * 数值字段按零判断，文本字段按空串判断。
	 *
	 * @param string $table 物理表名
	 * @param string $column 物理字段名
	 * @param bool $numeric 是否按数值零判断默认值
	 * @return array{row_count:int,non_empty_count:int}|null 表或字段不存在时返回 null
	 */
	public function getColumnValueStats(string $table, string $column, bool $numeric = false): ?array
	{
		if (!$this->isSafeTableName($table) || !$this->isSafeTableName($column)) {
			return null;
		}

		$qt = PgsqlDialect::quoteIdent($table);
		$qc = PgsqlDialect::quoteIdent($column);
		$predicate = $numeric
			? 'COALESCE(' . $qc . ', 0) <> 0'
			: "COALESCE({$qc}, '') <> ''";
		try {
			$row = $this->db->query(
				'SELECT COUNT(*) AS row_count, '
					. 'COUNT(*) FILTER (WHERE ' . $predicate . ') AS non_empty_count '
					. 'FROM ' . $qt,
				'find'
			);
		} catch (\Throwable) {
			return null;
		}
		if (!is_array($row)) {
			return null;
		}
		$row = array_change_key_case($row, CASE_LOWER);

		return [
			'row_count' => (int) ($row['row_count'] ?? 0),
			'non_empty_count' => (int) ($row['non_empty_count'] ?? 0),
		];
	}

	/**
	 * 读取 PostgreSQL 当前 Schema 下的索引定义。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return string|null 索引定义或不存在
	 */
	public function getTableIndexDefinition(string $table, string $index): ?string
	{
		if (!$this->isSafeTableName($table) || !$this->isSafeTableName($index)) {
			return null;
		}

		$row = $this->db->query(
			'SELECT indexdef FROM pg_indexes '
				. 'WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
			'find',
			[$table, $index]
		);
		if (!is_array($row)) {
			return null;
		}
		$row = array_change_key_case($row, CASE_LOWER);
		$definition = trim((string) ($row['indexdef'] ?? ''));

		return $definition !== '' ? $definition : null;
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

	/**
	 * 展示类型 → 近似 PG 类型
	 *
	 * @param string $type
	 * @return string
	 * @access private
	 */
	private function mapDisplayTypeToPgsql(string $type): string
	{
		$type = strtolower(trim($type));
		if (preg_match('/^([a-z]+)(?:\((.+)\))?$/', $type, $m)) {
			$base = $m[1];
			$len = $m[2] ?? '';
		} else {
			$base = $type;
			$len = '';
		}

		return match ($base) {
			'int', 'integer', 'int4' => 'integer',
			'bigint', 'int8' => 'bigint',
			'smallint', 'int2' => 'smallint',
			'tinyint' => 'smallint',
			'varchar', 'character varying' => $len !== '' ? 'varchar(' . $len . ')' : 'varchar',
			'char', 'character' => $len !== '' ? 'char(' . $len . ')' : 'char',
			'text' => 'text',
			'datetime', 'timestamp' => 'timestamp',
			'date' => 'date',
			'time' => 'time',
			'float', 'real' => 'real',
			'double', 'double precision' => 'double precision',
			'decimal', 'numeric' => $len !== '' ? 'numeric(' . $len . ')' : 'numeric',
			'json' => 'json',
			'jsonb' => 'jsonb',
			'bool', 'boolean' => 'boolean',
			'blob', 'bytea' => 'bytea',
			default => $type !== '' ? $type : 'text',
		};
	}

	/**
	 * @param string $value
	 * @return string
	 * @access private
	 */
	private function quoteLiteral(string $value): string
	{
		return "'" . str_replace("'", "''", $value) . "'";
	}
}
