<?php
/***
 * SQLite 方言
 *
 * 业务说明：表结构探测、标识符引用、列名小写规范化（与 PdoSqlite 查询结果键一致）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-17 17:23:55 $
 *
 * @package Candy\Extend\DB\Dialect
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Dialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Sqlite implements DialectInterface
{
	use DialectResultNormalize;


	/** 已探测标记（惰性探测一次） */
	private static bool $versionProbed = false;

	/** SQLite 版本号整数（3.35.5 → 33505）；null = 无法探测 */
	private static ?int $serverVersion = null;
	/**
	 * 惰性探测 SQLite 服务端版本号
	 *
	 * 探测不到时返回 null（缺 pdo_sqlite 扩展 / CLI 环境 / 内存库不可用），
	 * 此时调用方按「支持」放行，避免在无法判定的环境里误报。
	 *
	 * @return int|null 如 3.35.5 → 33505
	 */
	private static function serverVersionId(): ?int
	{
		if (self::$versionProbed) {
			return self::$serverVersion;
		}
		self::$versionProbed = true;
		self::$serverVersion = null;

		if (!extension_loaded('pdo_sqlite')) {
			return null;
		}
		try {
			$pdo = new \PDO('sqlite::memory:');
			$raw = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
		} catch (\Throwable) {
			return null;
		}
		if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $raw, $m) === 1) {
			self::$serverVersion = ((int) $m[1]) * 10000 + ((int) $m[2]) * 100 + (int) $m[3];
		}

		return self::$serverVersion;
	}

	/**
	 * 版本号整数 → 可读字符串
	 *
	 * @param int $id 如 33505
	 * @return string 如 3.35.5
	 */
	private static function formatVersion(int $id): string
	{
		return sprintf('%d.%d.%d', intdiv($id, 10000), intdiv($id % 10000, 100), $id % 100);
	}

	/**
	 * 版本守卫：低于要求就明确拒绝，而不是让数据库报语法错
	 *
	 * @param int $minVersion 最低版本（如 32400 表示 3.24.0）
	 * @param string $feature 特性名
	 * @return void
	 */
	private static function requireVersion(int $minVersion, string $feature): void
	{
		$current = self::serverVersionId();
		if ($current !== null && $current < $minVersion) {
			throw new \BadMethodCallException(sprintf(
				'SQLite %s 需要 %s 以上，当前为 %s',
				$feature,
				self::formatVersion($minVersion),
				self::formatVersion($current)
			));
		}
	}

	/**
	 * RETURNING 子句（SQLite 3.35+）
	 *
	 * @param array|string $columns 列名或列名集合
	 * @return string
	 */
	public static function returningClause(array|string $columns): string
	{
		self::requireVersion(33500, 'RETURNING');
		$columns = is_array($columns) ? $columns : [$columns];
		if ($columns === []) {
			throw new \InvalidArgumentException('RETURNING 列不能为空');
		}

		return ' RETURNING ' . implode(',', array_map([self::class, 'quoteIdent'], $columns));
	}

	/**
	 * UPSERT 子句（SQLite 3.24+，语法与 PostgreSQL 一致）
	 *
	 * @param array $insertColumns 插入列
	 * @param array $conflictKeys 冲突键
	 * @param array $updateColumns 冲突时更新的列
	 * @return string
	 */
	public static function upsertClause(array $insertColumns, array $conflictKeys, array $updateColumns): string
	{
		self::requireVersion(32400, 'UPSERT');
		unset($insertColumns);
		if ($conflictKeys === []) {
			if ($updateColumns !== []) {
				throw new \InvalidArgumentException('SQLite 无目标冲突时不能更新列');
			}

			return ' ON CONFLICT DO NOTHING';
		}
		$conflict = implode(',', array_map([self::class, 'quoteIdent'], $conflictKeys));
		if ($updateColumns === []) {
			return ' ON CONFLICT (' . $conflict . ') DO NOTHING';
		}
		$updates = array_map(
			static fn(string $column): string => self::quoteIdent($column) . '=EXCLUDED.' . self::quoteIdent($column),
			$updateColumns
		);

		return ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . implode(',', $updates);
	}

	/**
	 * UPSERT 表达式子句（SQLite 3.24+）
	 *
	 * @param array $insertColumns 插入列
	 * @param array $conflictKeys 冲突键
	 * @param array $updates 列名 => 更新表达式 SQL
	 * @return string
	 */
	public static function upsertExpressionClause(array $insertColumns, array $conflictKeys, array $updates): string
	{
		self::requireVersion(32400, 'UPSERT');
		unset($insertColumns);
		if ($conflictKeys === []) {
			throw new \InvalidArgumentException('SQLite upsert 冲突键不能为空');
		}
		$conflict = implode(',', array_map([self::class, 'quoteIdent'], $conflictKeys));
		if ($updates === []) {
			return ' ON CONFLICT (' . $conflict . ') DO NOTHING';
		}
		$parts = [];
		foreach ($updates as $column => $sql) {
			$parts[] = self::quoteIdent((string) $column) . '=' . $sql;
		}

		return ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . implode(',', $parts);
	}

	/**
	 * EXCLUDED 列引用（SQLite 3.24+）
	 *
	 * @param string $columnSql 已引用列名 SQL
	 * @return string
	 */
	public static function excludedExpression(string $columnSql): string
	{
		self::requireVersion(32400, 'EXCLUDED 表达式');

		return 'EXCLUDED.' . $columnSql;
	}

	/**
	 * 行级锁子句
	 *
	 * SQLite 是整库文件锁，没有 FOR UPDATE 语义，因此恒定拒绝。
	 *
	 * @param string $mode 锁模式
	 * @return string 不返回；始终抛出异常
	 */
	public static function lockClause(string $mode): string
	{
		throw new \BadMethodCallException('SQLite 无行级锁（整库文件锁），不支持 FOR UPDATE');
	}

	/**
	 * PRAGMA table_info SQL
	 *
	 * @param string $table 含前缀表名
	 * @return string
	 */
	public static function tableInfoSql(string $table): string
	{
		return 'PRAGMA table_info(' . self::quoteIdent($table) . ')';
	}

	/**
	 * 解析 PRAGMA table_info 行到 ORM fieldList
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $fields
	 * @param string $auto
	 * @return array{0:array,1:string}
	 */
	public static function mapTableInfoRow(array $row, array $fields, string $auto): array
	{
		$row = self::normalizeRowKeys($row) ?? [];
		$col = self::normalizeColumnName((string) ($row['name'] ?? ''));
		$pk = (int) ($row['pk'] ?? 0);
		$type = strtoupper((string) ($row['type'] ?? ''));
		if ($pk === 1) {
			$fields['pri'] = $col;
			if (str_contains($type, 'INT')) {
				$auto = 'yes';
			}
		} else {
			$fields[] = $col;
		}

		return [$fields, $auto];
	}

	/**
	 * PRAGMA table_info 多行 → SHOW FULL COLUMNS 形态（字段名小写）
	 *
	 * @param array<int, mixed> $rows PRAGMA 查询结果
	 * @return array<int, array<string, mixed>>
	 */
	public static function mapPragmaTableInfoRows(array $rows): array
	{
		$columns = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = self::normalizeRowKeys($row) ?? [];
			$field = self::normalizeColumnName((string) ($row['name'] ?? ''));
			if ($field === '') {
				continue;
			}
			$notNull = (int) ($row['notnull'] ?? 0);
			$pk = (int) ($row['pk'] ?? 0);
			$type = (string) ($row['type'] ?? '');
			$extra = '';
			if ($pk === 1 && stripos($type, 'INT') !== false) {
				$extra = 'auto_increment';
			}
			$columns[] = [
				'field' => $field,
				'type' => $type,
				'null' => $notNull === 1 ? 'NO' : 'YES',
				'key' => $pk === 1 ? 'PRI' : '',
				'default' => $row['dflt_value'] ?? null,
				'extra' => $extra,
				'comment' => '',
				'collation' => '',
			];
		}

		return $columns;
	}

	/**
	 * PRAGMA index_list / index_info → SHOW INDEX 分组形态（列名小写）
	 *
	 * @param array<int, mixed> $indexRows PRAGMA index_list 结果
	 * @param array<int, array<int, mixed>> $indexInfoMap 索引名 => PRAGMA index_info 行列表
	 * @return array<string, array{key_name:string,non_unique:int,unique:int,primary:int,columns:array<int,string>}>
	 */
	public static function mapPragmaIndexGroups(array $indexRows, array $indexInfoMap): array
	{
		$groups = [];
		foreach ($indexRows as $indexRow) {
			if (!is_array($indexRow)) {
				continue;
			}
			$indexRow = self::normalizeRowKeys($indexRow) ?? [];
			$indexName = (string) ($indexRow['name'] ?? '');
			if ($indexName === '') {
				continue;
			}
			$origin = strtolower((string) ($indexRow['origin'] ?? ''));
			$isPrimary = $origin === 'pk';
			$keyName = $isPrimary ? 'PRIMARY' : $indexName;
			$unique = (int) ($indexRow['unique'] ?? 0);

			$columns = [];
			$infoRows = $indexInfoMap[$indexName] ?? [];
			foreach ($infoRows as $infoRow) {
				if (!is_array($infoRow)) {
					continue;
				}
				$infoRow = self::normalizeRowKeys($infoRow) ?? [];
				$seq = (int) ($infoRow['seqno'] ?? 0);
				$col = self::normalizeColumnName((string) ($infoRow['name'] ?? ''));
				if ($col !== '') {
					$columns[$seq] = $col;
				}
			}
			ksort($columns);

			$groups[$keyName] = [
				'key_name' => $keyName,
				'non_unique' => $unique === 1 ? 0 : 1,
				'unique' => $unique === 1 ? 1 : 0,
				'primary' => $isPrimary ? 1 : 0,
				'columns' => array_values($columns),
			];
		}

		return $groups;
	}

	/**
	 * 引用标识符
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
	 * SQLite LIMIT / LIMIT … OFFSET …
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
	 * 返回兼容旧版 SQLite 的绑定参数上限。
	 *
	 * @return int 参数上限
	 */
	public static function maxBindParameters(): int
	{
		return 999;
	}

	/**
	 * 编译 SQLite 日期时间增量表达式。
	 *
	 * @param string $unit day|hour|minute|second
	 * @param string $baseSql 基准时间表达式
	 * @return string 日期时间表达式
	 */
	public static function dateAddExpression(string $unit, string $baseSql): string
	{
		$unit = match (strtolower($unit)) {
			'day' => ' days',
			'hour' => ' hours',
			'minute' => ' minutes',
			'second' => ' seconds',
			default => throw new \InvalidArgumentException('不支持的日期增量单位'),
		};
		return 'datetime(' . $baseSql . ", (? || '" . $unit . "'))";
	}

	/**
	 * 解析 SQLite 文件路径
	 *
	 * @param array<string,mixed> $config 配置
	 * @return string 绝对或相对路径
	 */
	public static function resolvePath(array $config): string
	{
		if (!empty($config['DSN']) && is_string($config['DSN'])) {
			$dsn = $config['DSN'];
			if (str_starts_with($dsn, 'sqlite:')) {
				return substr($dsn, 7);
			}

			return $dsn;
		}
		if (!empty($config['PATH'])) {
			return (string) $config['PATH'];
		}
		if (function_exists('C')) {
			$globalPath = C('PATH');
			if ($globalPath !== null && $globalPath !== '') {
				return (string) $globalPath;
			}
		}
		$dbname = (string) ($config['DBNAME'] ?? '');
		if (
			$dbname !== ''
			&& (str_contains($dbname, '/') || str_ends_with(strtolower($dbname), '.db') || str_ends_with(strtolower($dbname), '.sqlite'))
		) {
			return $dbname;
		}

		return C('RUNTIME') . 'Data/sqlite.db';
	}

	/**
	 * 确保 SQLite 目录、库文件及 WAL/SHM 附属文件可被 Web 进程读写
	 *
	 * CLI/Agent 以 root 创建库文件时自动 chown 为 www，避免 Web 报 attempt to write a readonly database
	 *
	 * @param string $sqlitePath 数据库文件绝对路径
	 * @return void
	 */
	public static function ensureFilesystemWritable(string $sqlitePath): void
	{
		$sqlitePath = trim($sqlitePath);
		if ($sqlitePath === '') {
			return;
		}

		$dir = dirname($sqlitePath);
		if (is_dir($dir)) {
			@chmod($dir, 0775);
		}

		$paths = [$sqlitePath];
		foreach (['-wal', '-shm', '-journal'] as $suffix) {
			$sidecar = $sqlitePath . $suffix;
			if (is_file($sidecar)) {
				$paths[] = $sidecar;
			}
		}

		foreach ($paths as $path) {
			if (is_file($path)) {
				@chmod($path, 0664);
			}
		}

		if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
			return;
		}

		$webUser = 'www';
		$webGroup = 'www';
		foreach ([$dir, ...$paths] as $item) {
			if (!file_exists($item)) {
				continue;
			}
			@chown($item, $webUser);
			@chgrp($item, $webGroup);
		}
	}
}
