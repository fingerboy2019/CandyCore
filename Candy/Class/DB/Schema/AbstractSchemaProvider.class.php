<?php
/***
 * Schema Provider 基类
 *
 * 业务说明：持有驱动实例与通用行规范化；具体 SQL 由子类实现
 *
 * $Author: 刘森 (fingerboy@qq.com) $
	 * $Date: 2026-08-29 16:01:54 $
 *
 * @package Candy\Extend\DB\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Schema;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class AbstractSchemaProvider implements SchemaProviderInterface
{
	/** @var object 数据库驱动 */
	protected object $db;

	/** @var array<string, bool> 当前 Provider 实例内的表存在性缓存 */
	protected array $tableExistsCache = [];

	/** @var array<string, array<string, array>> 当前 Provider 实例内的索引元数据缓存 */
	protected array $indexGroupsCache = [];

	/**
	 * @param object $db D('_dbDriver') 实例
	 */
	public function __construct(object $db)
	{
		$this->db = $db;
	}

	/**
	 * 默认方言不读取数据库字符集。
	 *
	 * 具体 Provider 在能够安全表达时覆写该方法，调用方无需直接访问驱动查询。
	 *
	 * @return string 字符集；不支持时为空字符串
	 */
	public function charset(): string
	{
		return '';
	}

	/**
	 * 默认方言不读取数据库名。
	 *
	 * 具体 Provider 在能够安全表达时覆写该方法。
	 *
	 * @return string 数据库名；不支持时为空字符串
	 */
	public function databaseName(): string
	{
		return '';
	}

	/**
	 * 默认方言不提供创建数据库权限探测。
	 *
	 * 具体方言在能够安全表达时覆写该方法，调用方无需直接访问驱动查询。
	 *
	 * @return bool 是否具备创建数据库权限
	 */
	public function canCreateDatabase(): bool
	{
		return false;
	}

	/**
	 * 通过当前方言驱动新增字段。
	 *
	 * 表名、字段名仅接受安全标识符；字段定义拒绝多语句和注释片段，调用方仍应
	 * 只传入代码内固定的结构定义。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 已校验的字段定义
	 * @param string $afterColumn MySQL 相邻字段名；其他方言应传空字符串
	 * @return bool 是否执行成功
	 */
	public function addColumn(
		string $table,
		string $column,
		string $definition,
		string $afterColumn = ''
	): bool
	{
		$sql = $this->buildAddColumnSql($table, $column, $definition, true, $afterColumn);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过当前方言修改字段定义。
	 *
	 * 默认实现采用 PostgreSQL 语法；MySQL、SQL Server 等方言在具体 Provider
	 * 覆写构造方式。字段定义只接受固定片段，避免把完整 DDL 传入基础设施。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 类型及约束定义
	 * @return bool 是否执行成功
	 */
	public function alterColumn(string $table, string $column, string $definition): bool
	{
		$sql = $this->buildAlterColumnSql($table, $column, $definition);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			unset($this->indexGroupsCache[strtolower(trim($table))]);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过当前方言设置字段默认值表达式。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $expression 固定默认值表达式；空字符串表示删除默认值
	 * @return bool 是否执行成功
	 */
	public function setColumnDefault(string $table, string $column, string $expression): bool
	{
		$sql = $this->buildSetColumnDefaultSql($table, $column, $expression);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			unset($this->indexGroupsCache[strtolower(trim($table))]);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 默认方言不提供字段注释写入。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $comment 注释文本
	 * @return bool 始终返回 false
	 */
	public function commentColumn(string $table, string $column, string $comment): bool
	{
		unset($table, $column, $comment);

		return false;
	}

	/**
	 * 通过当前方言设置或取消字段非空约束。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param bool $notNull 是否设置为非空
	 * @return bool 是否执行成功
	 */
	public function setColumnNotNull(string $table, string $column, bool $notNull = true): bool
	{
		$sql = $this->buildSetColumnNotNullSql($table, $column, $notNull);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过当前方言删除表级约束。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 约束名
	 * @return bool 是否执行成功
	 */
	public function dropConstraint(string $table, string $constraint): bool
	{
		$sql = $this->buildDropConstraintSql($table, $constraint);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			unset($this->indexGroupsCache[strtolower(trim($table))]);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过约束名删除主键；具体方言可覆写无约束名的语法。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 主键约束名
	 * @return bool 是否执行成功
	 */
	public function dropPrimaryKey(string $table, string $constraint = ''): bool
	{
		$constraint = trim($constraint);
		if ($constraint === '') {
			return false;
		}

		return $this->dropConstraint($table, $constraint);
	}

	/**
	 * 通过当前方言新增主键约束。
	 *
	 * @param string $table 物理表名
	 * @param array<int, string> $columns 主键列名
	 * @return bool 是否执行成功
	 */
	public function addPrimaryKey(string $table, array $columns): bool
	{
		$sql = $this->buildAddPrimaryKeySql($table, $columns);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			unset($this->indexGroupsCache[strtolower(trim($table))]);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 默认方言不提供表注释写入。
	 *
	 * @param string $table 物理表名
	 * @param string $comment 注释文本
	 * @return bool 始终返回 false
	 */
	public function commentTable(string $table, string $comment): bool
	{
		unset($table, $comment);

		return false;
	}

	/**
	 * 将现有表复制为幂等备份表。
	 *
	 * 目标已存在时不重复复制，避免迁移重试覆盖首份审计快照。
	 *
	 * @param string $source 源表名
	 * @param string $target 备份表名
	 * @return bool 是否复制成功或目标表已存在
	 */
	public function copyTable(string $source, string $target): bool
	{
		$sql = $this->buildCopyTableSql($source, $target);
		if ($sql === null) {
			return false;
		}

		try {
			if ($this->tableExistsCached($target)) {
				return true;
			}
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($target);
			$this->tableExistsCache[strtolower(trim($target))] = true;

			return true;
		} catch (\Throwable) {
			try {
				$ready = $this->tableExists($target);
				$this->tableExistsCache[strtolower(trim($target))] = $ready;

				return $ready;
			} catch (\Throwable) {
				return false;
			}
		}
	}

	/**
	 * 通过当前方言删除字段。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return bool 是否执行成功
	 */
	public function dropColumn(string $table, string $column): bool
	{
		$sql = $this->buildDropColumnSql($table, $column);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过当前方言删除索引。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return bool 是否执行成功
	 */
	public function dropIndex(string $table, string $index): bool
	{
		$sql = $this->buildDropIndexSql($table, $index);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			unset($this->indexGroupsCache[strtolower(trim($table))]);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 通过当前方言删除数据表。
	 *
	 * 表名只接受安全标识符；不存在时由 IF EXISTS 保持幂等。方言差异通过
	 * supportsDropTableCascade() 控制，业务层不再拼接 DROP TABLE 语句。
	 *
	 * @param string $table 物理表名
	 * @param bool $cascade 是否级联删除依赖对象
	 * @return bool 是否执行成功或数据表已不存在
	 */
	public function dropTable(string $table, bool $cascade = false): bool
	{
		$sql = $this->buildDropTableSql($table, $cascade);
		if ($sql === null) {
			return false;
		}

		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 按结构化列定义确保数据表存在。
	 *
	 * 该方法只接受列/约束定义片段，拒绝多语句与注释注入；表已存在时保持幂等。
	 * 具体方言通过 quoteIdentifier() 提供标识符引用，不向业务层暴露原生 SQL 执行。
	 *
	 * @param string $table 物理表名
	 * @param array<string, string> $columns 列名到固定方言定义的映射
	 * @param array<int, string> $constraints 固定表级约束定义
	 * @return bool 是否已存在或创建成功
	 */
	public function ensureTable(string $table, array $columns, array $constraints = []): bool
	{
		if (!$this->isSafeTableName($table) || $columns === []) {
			return false;
		}

		try {
			if ($this->tableExistsCached($table)) {
				return true;
			}
		} catch (\Throwable) {
			return false;
		}

		$definitions = [];
		foreach ($columns as $column => $definition) {
			$column = trim((string)$column);
			$definition = trim((string)$definition);
			if (!$this->isSafeIdentifier($column) || !$this->isSafeDefinition($definition)) {
				return false;
			}
			$definitions[] = $this->quoteIdentifier($column) . ' ' . $definition;
		}

		foreach ($constraints as $constraint) {
			$constraint = trim((string)$constraint);
			if (!$this->isSafeDefinition($constraint)) {
				return false;
			}
			$definitions[] = $constraint;
		}

		$sql = 'CREATE TABLE ' . $this->quoteIdentifier($table)
			. ' (' . implode(', ', $definitions) . ')';
		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			$this->tableExistsCache[strtolower($table)] = true;
			return true;
		} catch (\Throwable) {
			// 并发请求可能已由其他连接完成建表；再次读取元数据确认最终状态。
			try {
				$ready = $this->tableExists($table);
				$this->tableExistsCache[strtolower($table)] = $ready;

				return $ready;
			} catch (\Throwable) {
				return false;
			}
		}
	}

	/**
	 * 按结构化列名确保普通或唯一索引存在。
	 *
	 * 索引定义仅允许安全标识符；索引已存在时不重复执行 DDL。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @param array<int, string> $columns 索引列名，按索引顺序排列
	 * @param bool $unique 是否创建唯一索引
	 * @param string $where PostgreSQL 部分索引谓词
	 * @return bool 是否已存在或创建成功
	 */
	public function ensureIndex(
		string $table,
		string $index,
		array $columns,
		bool $unique = false,
		string $where = ''
	): bool {
		$where = trim($where);
		if (
			!$this->isSafeTableName($table)
			|| !$this->isSafeIdentifier($index)
			|| $columns === []
			|| ($where !== '' && (!$this->supportsPartialIndex() || !$this->isSafePredicate($where)))
		) {
			return false;
		}
		$quotedColumns = [];
		foreach ($columns as $column) {
			$column = trim((string)$column);
			if (!$this->isSafeIdentifier($column)) {
				return false;
			}
			$quotedColumns[] = $this->quoteIdentifier($column);
		}

		try {
			if (!$this->tableExistsCached($table)) {
				return false;
			}
			$indexes = $this->indexGroupsCached($table);
			foreach ($indexes as $name => $metadata) {
				if (strcasecmp((string)$name, $index) === 0) {
					return true;
				}
			}
		} catch (\Throwable) {
			return false;
		}

		$sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX '
			. $this->quoteIdentifier($index) . ' ON '
			. $this->quoteIdentifier($table) . ' (' . implode(', ', $quotedColumns) . ')'
			. ($where !== '' ? ' WHERE ' . $where : '');
		try {
			$this->db->query($sql, 'other');
			$this->forgetSchemaMetadataRequestCache($table);
			$this->indexGroupsCache[strtolower($table)][strtolower($index)] = [
				'key_name' => $index,
				'non_unique' => $unique ? 0 : 1,
				'unique' => $unique ? 1 : 0,
				'primary' => 0,
				'columns' => $columns,
			];
			return true;
		} catch (\Throwable) {
			// 并发请求可能已由其他连接创建索引；再次读取元数据确认最终状态。
			try {
				$groups = $this->getTableIndexGroups($table);
				$this->indexGroupsCache[strtolower($table)] = $groups;
				foreach ($groups as $name => $metadata) {
					if (strcasecmp((string)$name, $index) === 0) {
						return true;
					}
				}
			} catch (\Throwable) {
			}
			return false;
		}
	}

	/**
	 * 从结构化表定义生成建表预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param array<string, string> $columns 列名到固定方言定义的映射
	 * @param array<int, string> $constraints 固定表级约束定义
	 * @return string 预览 SQL；参数不安全时返回空串
	 */
	public function previewCreateTableSql(string $table, array $columns, array $constraints = []): string
	{
		if (!$this->isSafeTableName($table) || $columns === []) {
			return '';
		}

		$definitions = [];
		foreach ($columns as $column => $definition) {
			$column = trim((string) $column);
			$definition = trim((string) $definition);
			if (!$this->isSafeIdentifier($column) || !$this->isSafeDefinition($definition)) {
				return '';
			}
			$definitions[] = $this->quoteIdentifier($column) . ' ' . $definition;
		}

		foreach ($constraints as $constraint) {
			$constraint = trim((string) $constraint);
			if (!$this->isSafeDefinition($constraint)) {
				return '';
			}
			$definitions[] = $constraint;
		}

		return 'CREATE TABLE ' . $this->quoteIdentifier($table)
			. ' (' . implode(', ', $definitions) . ')';
	}

	/**
	 * 生成新增字段预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 固定字段定义
	 * @param string $afterColumn MySQL 相邻字段名
	 * @return string 预览 SQL；参数不安全时返回空串
	 */
	public function previewAddColumnSql(
		string $table,
		string $column,
		string $definition,
		string $afterColumn = ''
	): string {
		return $this->buildAddColumnSql($table, $column, $definition, true, $afterColumn) ?? '';
	}

	/**
	 * 生成字段修改预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 固定字段定义
	 * @param bool|null $notNull PostgreSQL 可选的非空约束变更
	 * @return string 预览 SQL；参数不安全时返回空串
	 */
	public function previewAlterColumnSql(
		string $table,
		string $column,
		string $definition,
		?bool $notNull = null
	): string {
		$sql = $this->buildAlterColumnSql($table, $column, $definition);
		if ($sql === null) {
			return '';
		}
		if ($notNull === null) {
			return $sql;
		}

		$nullSql = $this->buildSetColumnNotNullSql($table, $column, $notNull);
		if ($nullSql === null) {
			return $sql;
		}

		return $sql . '; ' . $nullSql;
	}

	/**
	 * 生成删除字段预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return string 预览 SQL；参数不安全时返回空串
	 */
	public function previewDropColumnSql(string $table, string $column): string
	{
		return $this->buildDropColumnSql($table, $column) ?? '';
	}

	/**
	 * 生成字段注释预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $comment 注释文本
	 * @return string 预览 SQL；当前方言不支持时返回空串
	 */
	public function previewCommentColumnSql(string $table, string $column, string $comment): string
	{
		return $this->buildCommentColumnSql($table, $column, $comment) ?? '';
	}

	/**
	 * 请求内缓存表存在性探测。
	 *
	 * @param string $table 物理表名
	 * @return bool 表是否存在
	 */
	protected function tableExistsCached(string $table): bool
	{
		$key = strtolower(trim($table));
		if (array_key_exists($key, $this->tableExistsCache)) {
			return $this->tableExistsCache[$key];
		}

		$exists = $this->tableExists($table);
		$this->tableExistsCache[$key] = $exists;

		return $exists;
	}

	/**
	 * 请求内缓存索引分组探测。
	 *
	 * @param string $table 物理表名
	 * @return array<string, array> 索引元数据
	 */
	protected function indexGroupsCached(string $table): array
	{
		$key = strtolower(trim($table));
		if (array_key_exists($key, $this->indexGroupsCache)) {
			return $this->indexGroupsCache[$key];
		}

		$groups = $this->getTableIndexGroups($table);
		$this->indexGroupsCache[$key] = $groups;

		return $groups;
	}

	/**
	 * 引用方言标识符。
	 *
	 * @param string $identifier 已校验标识符
	 * @return string SQL 标识符
	 */
	protected function quoteIdentifier(string $identifier): string
	{
		return $identifier;
	}

	/**
	 * 判断标识符是否安全。
	 *
	 * @param string $identifier 标识符
	 * @return bool 是否安全
	 */
	protected function isSafeIdentifier(string $identifier): bool
	{
		return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', trim($identifier));
	}

	/**
	 * 读取当前请求内的 Schema 元数据缓存。
	 *
	 * 该缓存只作用于当前请求或协程，且连接身份、驱动、读写角色和物理表名
	 * 均参与键值计算；不存在或空结果也会被记录，避免同一请求反复探测缺失表。
	 *
	 * @param string $kind 元数据类型
	 * @param string $table 物理表名
	 * @param bool $hit 是否命中缓存
	 * @return mixed 缓存值；未命中时返回 null
	 */
	protected function loadSchemaMetadataRequestCache(
		string $kind,
		string $table,
		bool &$hit
	): mixed {
		$hit = false;
		$key = $this->schemaMetadataRequestCacheKey($kind, $table);
		if ($key === null) {
			return null;
		}

		$cached = T($key, '', null);
		if (!is_array($cached) || !array_key_exists('value', $cached)) {
			return null;
		}

		$hit = true;

		return $cached['value'];
	}

	/**
	 * 写入当前请求内的 Schema 元数据缓存。
	 *
	 * @param string $kind 元数据类型
	 * @param string $table 物理表名
	 * @param mixed $value 元数据结果
	 * @return void
	 */
	protected function saveSchemaMetadataRequestCache(
		string $kind,
		string $table,
		mixed $value
	): void {
		$key = $this->schemaMetadataRequestCacheKey($kind, $table);
		if ($key === null) {
			return;
		}

		T($key, ['value' => $value]);
	}

	/**
	 * 使当前请求内指定表的 Schema 元数据缓存失效。
	 *
	 * DDL 成功后必须清理请求缓存，避免同一请求后续读取旧字段或索引定义。
	 *
	 * @param string $table 物理表名
	 * @return void
	 */
	protected function forgetSchemaMetadataRequestCache(string $table): void
	{
		$key = strtolower(trim($table));
		unset($this->tableExistsCache[$key]);
		unset($this->indexGroupsCache[$key]);
		if (!function_exists('T')) {
			return;
		}

		foreach (['table_exists', 'columns', 'indexes'] as $kind) {
			$cacheKey = $this->schemaMetadataRequestCacheKey($kind, $table);
			if ($cacheKey !== null) {
				T($cacheKey, null);
			}
		}
	}

	/**
	 * 生成连接隔离的 Schema 元数据请求缓存键。
	 *
	 * @param string $kind 元数据类型
	 * @param string $table 物理表名
	 * @return string|null 请求缓存键；无法使用请求缓存时返回 null
	 */
	private function schemaMetadataRequestCacheKey(string $kind, string $table): ?string
	{
		if (!function_exists('T')) {
			return null;
		}

		$db = $this->db;
		$alias = '';
		$driver = '';
		$role = '';
		$configHash = '';
		try {
			$alias = method_exists($db, 'getDbAlias')
				? (string) $db->getDbAlias()
				: '';
			$driver = method_exists($db, 'getResolvedDriver')
				? (string) $db->getResolvedDriver()
				: '';
			$role = method_exists($db, 'getDbActiveRole')
				? (string) $db->getDbActiveRole()
				: '';
			if (method_exists($db, 'getCurrentConfig')) {
				$config = $db->getCurrentConfig();
				if (is_array($config)) {
					$encodedConfig = json_encode(
						$config,
						JSON_UNESCAPED_SLASHES
							| JSON_UNESCAPED_UNICODE
							| JSON_PARTIAL_OUTPUT_ON_ERROR
					);
					$configHash = hash('sha256', (string) $encodedConfig);
				}
			}
		} catch (\Throwable) {
			$alias = '';
			$driver = '';
			$role = '';
			$configHash = '';
		}

		$identity = [
			'provider' => static::class,
			'db' => get_class($db),
			'alias' => $alias,
			'driver' => $driver,
			'role' => $role,
			'config' => $configHash,
			'kind' => $kind,
			'table' => strtolower(trim($table)),
		];
		if ($configHash === '') {
			$identity['object'] = spl_object_id($db);
		}

		$encoded = json_encode($identity, JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			return null;
		}

		return '__schema_metadata_' . hash('sha256', $encoded);
	}

	/**
	 * 判断固定结构定义是否不含多语句或注释片段。
	 *
	 * @param string $definition 结构定义
	 * @return bool 是否安全
	 */
	protected function isSafeDefinition(string $definition): bool
	{
		return $definition !== ''
			&& !preg_match('/[;\\r\\n\\0]|--|\\/\\*|\\*\\//', $definition);
	}

	/**
	 * 当前方言是否支持部分索引。
	 *
	 * @return bool 是否支持 CREATE INDEX ... WHERE
	 */
	protected function supportsPartialIndex(): bool
	{
		return false;
	}

	/**
	 * 判断部分索引谓词是否仅包含固定表达式字符。
	 *
	 * @param string $predicate 索引谓词
	 * @return bool 是否安全
	 */
	protected function isSafePredicate(string $predicate): bool
	{
		return $predicate !== ''
			&& (bool)preg_match(
				"/^(?:\\s*(?:[a-zA-Z_][a-zA-Z0-9_]*|\\d+(?:\\.\\d+)?|'[a-zA-Z0-9_.%:-]*'|[(),=<>!])\\s*)+$/",
				$predicate
			)
			&& !preg_match('/--|\/\*|\*\//', $predicate);
	}

	/**
	 * 构造安全的 ADD COLUMN 语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 已校验的字段定义
	 * @param bool $withColumn 是否包含 COLUMN 关键字
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildAddColumnSql(
		string $table,
		string $column,
		string $definition,
		bool $withColumn,
		string $afterColumn = ''
	): ?string {
		$table = trim($table);
		$column = trim($column);
		$definition = trim($definition);
		if (
			!$this->isSafeTableName($table)
			|| !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)
			|| $definition === ''
			|| preg_match('/[;\r\n\0]|--|\/\*|\*\//', $definition)
		) {
			return null;
		}

		// PostgreSQL/MySQL 的通用 Provider 走幂等 ADD COLUMN；SQL Server
		// 通过子类传入 false，SQLite 则由子类关闭该方言不支持的 IF NOT EXISTS。
		$afterColumn = trim($afterColumn);
		if ($afterColumn !== ''
			&& (!$this->supportsAddColumnAfter() || !$this->isSafeIdentifier($afterColumn))) {
			return null;
		}

		$keyword = $withColumn ? ' COLUMN' : '';
		$ifNotExists = $withColumn && $this->supportsAddColumnIfNotExists()
			? ' IF NOT EXISTS'
			: '';

		$sql = 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' ADD' . $keyword . $ifNotExists . ' '
			. $this->quoteIdentifier($column) . ' ' . $definition;
		if ($afterColumn !== '') {
			$sql .= ' AFTER ' . $this->quoteIdentifier($afterColumn);
		}

		return $sql;
	}

	/**
	 * 判断 ADD COLUMN 是否支持 AFTER 排序。
	 *
	 * @return bool 是否支持 AFTER
	 * @access protected
	 */
	protected function supportsAddColumnAfter(): bool
	{
		return false;
	}

	/**
	 * 判断 ADD COLUMN 是否支持 IF NOT EXISTS。
	 *
	 * @return bool 是否支持幂等新增字段语法
	 * @access protected
	 */
	protected function supportsAddColumnIfNotExists(): bool
	{
		return true;
	}

	/**
	 * 构造字段注释语句。
	 *
	 * 具体方言在支持列注释时覆写；默认方言不生成注释 DDL。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $comment 注释文本
	 * @return string|null SQL；不支持或参数不安全时返回 null
	 * @access protected
	 */
	protected function buildCommentColumnSql(string $table, string $column, string $comment): ?string
	{
		unset($table, $column, $comment);

		return null;
	}

	/**
	 * 构造安全的字段定义修改语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 类型及约束定义
	 * @return string|null SQL；参数不安全或方言不支持时返回 null
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
			. ' TYPE ' . $definition;
	}

	/**
	 * 构造安全的字段默认值设置语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $expression 固定默认值表达式
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildSetColumnDefaultSql(
		string $table,
		string $column,
		string $expression
	): ?string {
		$table = trim($table);
		$column = trim($column);
		$expression = trim($expression);
		if (!$this->isSafeTableName($table) || !$this->isSafeIdentifier($column)) {
			return null;
		}
		if ($expression !== '' && !$this->isSafeDefinition($expression)) {
			return null;
		}

		return 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' ALTER COLUMN ' . $this->quoteIdentifier($column)
			. ($expression === '' ? ' DROP DEFAULT' : ' SET DEFAULT ' . $expression);
	}

	/**
	 * 构造安全的字段非空约束设置语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param bool $notNull 是否设置为非空
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildSetColumnNotNullSql(
		string $table,
		string $column,
		bool $notNull
	): ?string {
		$table = trim($table);
		$column = trim($column);
		if (!$this->isSafeTableName($table) || !$this->isSafeIdentifier($column)) {
			return null;
		}

		return 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' ALTER COLUMN ' . $this->quoteIdentifier($column)
			. ($notNull ? ' SET NOT NULL' : ' DROP NOT NULL');
	}

	/**
	 * 构造安全的删除约束语句。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 约束名
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
			. ' DROP CONSTRAINT IF EXISTS ' . $this->quoteIdentifier($constraint);
	}

	/**
	 * 构造安全的新增主键语句。
	 *
	 * @param string $table 物理表名
	 * @param array<int, string> $columns 主键列名
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildAddPrimaryKeySql(string $table, array $columns): ?string
	{
		$table = trim($table);
		if (!$this->isSafeTableName($table) || $columns === []) {
			return null;
		}

		$quotedColumns = [];
		foreach ($columns as $column) {
			$column = trim((string)$column);
			if (!$this->isSafeIdentifier($column)) {
				return null;
			}
			$quotedColumns[] = $this->quoteIdentifier($column);
		}

		return 'ALTER TABLE ' . $this->quoteIdentifier($table)
			. ' ADD PRIMARY KEY (' . implode(', ', $quotedColumns) . ')';
	}

	/**
	 * 构造安全的表复制语句。
	 *
	 * 默认采用 PostgreSQL 的 CREATE TABLE AS TABLE 语法；其他方言在需要时覆写。
	 *
	 * @param string $source 源表名
	 * @param string $target 目标表名
	 * @return string|null SQL；参数不安全或方言不支持时返回 null
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
			. ' AS TABLE ' . $this->quoteIdentifier($source);
	}

	/**
	 * 构造安全的删除数据表语句。
	 *
	 * @param string $table 物理表名
	 * @param bool $cascade 是否级联删除依赖对象
	 * @return string|null SQL；参数不安全时返回 null
	 * @access protected
	 */
	protected function buildDropTableSql(string $table, bool $cascade = false): ?string
	{
		$table = trim($table);
		if (!$this->isSafeTableName($table)) {
			return null;
		}

		return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table)
			. ($cascade && $this->supportsDropTableCascade() ? ' CASCADE' : '');
	}

	/**
	 * 判断当前方言是否支持 DROP TABLE CASCADE。
	 *
	 * @return bool 是否支持级联删除
	 * @access protected
	 */
	protected function supportsDropTableCascade(): bool
	{
		return false;
	}

	/**
	 * 构造安全的删除字段语句。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return string|null SQL；参数不安全或方言不支持时返回 null
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
			. ' DROP COLUMN IF EXISTS ' . $this->quoteIdentifier($column);
	}

	/**
	 * 构造安全的删除索引语句。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return string|null SQL；参数不安全或方言不支持时返回 null
	 * @access protected
	 */
	protected function buildDropIndexSql(string $table, string $index): ?string
	{
		$table = trim($table);
		$index = trim($index);
		if (!$this->isSafeTableName($table) || !$this->isSafeIdentifier($index)) {
			return null;
		}

		return 'DROP INDEX IF EXISTS ' . $this->quoteIdentifier($index);
	}

	/**
	 * 通过统一列元数据接口判断字段是否存在。
	 *
	 * 具体方言只负责列元数据读取，字段匹配和大小写兼容由基类统一处理。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return bool 是否包含字段
	 */
	public function hasColumn(string $table, string $column): bool
	{
		$column = strtolower(trim($column));
		if ($column === '') {
			return false;
		}

		foreach ($this->listColumns($table) as $row) {
			$field = strtolower(trim((string)($row['field'] ?? '')));
			if ($field === $column) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 默认方言不执行序列推进。
	 *
	 * PostgreSQL SchemaProvider 覆写该方法；其他方言保持兼容的无操作行为。
	 *
	 * @param string $table 物理表名
	 * @return void
	 */
	public function syncSequence(string $table): void
	{
		unset($table);
	}

	/**
	 * 校验物理表名（防注入）
	 *
	 * @param string $table 表名
	 * @return bool
	 */
	protected function isSafeTableName(string $table): bool
	{
		$table = trim($table);

		return $table !== '' && (bool) preg_match('/^[a-zA-Z0-9_]+$/', $table);
	}

	/**
	 * 统一 name/comment 行
	 *
	 * @param array<int, mixed> $rows 查询结果
	 * @return array<int, array{name:string,comment:string}>
	 */
	protected function normalizeNameCommentRows(array $rows): array
	{
		$list = [];
		foreach ($rows as $row) {
			$row = is_array($row) ? (array_change_key_case($row, CASE_LOWER)) : [];
			$name = (string) ($row['name'] ?? '');
			if ($name === '') {
				continue;
			}
			$list[] = [
				'name' => $name,
				'comment' => (string) ($row['comment'] ?? ''),
			];
		}

		return $list;
	}

	/**
	 * SHOW FULL COLUMNS 形态列映射（field 小写）
	 *
	 * @param array<string, mixed> $row 已小写键的行
	 * @return array<string, mixed>
	 */
	protected function mapColumnCompatRow(array $row): array
	{
		$row = array_change_key_case($row, CASE_LOWER);
		$field = strtolower(trim((string) ($row['field'] ?? $row['column_name'] ?? '')));

		return [
			'field' => $field,
			'type' => (string) ($row['type'] ?? $row['data_type'] ?? $row['column_type'] ?? ''),
			'null' => (string) ($row['null'] ?? $row['is_nullable'] ?? ''),
			'key' => (string) ($row['key'] ?? ''),
			'default' => $row['default'] ?? $row['column_default'] ?? null,
			'extra' => (string) ($row['extra'] ?? ''),
			'comment' => (string) ($row['comment'] ?? $row['column_comment'] ?? ''),
			'collation' => (string) ($row['collation'] ?? $row['collation_name'] ?? ''),
		];
	}

	/**
	 * SHOW INDEX 行 → 索引分组（列名小写）
	 *
	 * @param array<int, mixed> $rows 查询结果
	 * @return array<string, array{key_name:string,non_unique:int,unique:int,primary:int,columns:array<int,string>}>
	 */
	protected function mapShowIndexRows(array $rows): array
	{
		$groups = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = array_change_key_case($row, CASE_LOWER);
			$keyName = (string) ($row['key_name'] ?? $row['index_name'] ?? '');
			if ($keyName === '') {
				continue;
			}
			if (!isset($groups[$keyName])) {
				$nonUnique = (int) ($row['non_unique'] ?? 1);
				$groups[$keyName] = [
					'key_name' => $keyName,
					'non_unique' => $nonUnique,
					'unique' => $nonUnique === 0 ? 1 : 0,
					'primary' => strtoupper($keyName) === 'PRIMARY' ? 1 : 0,
					'columns' => [],
				];
			}
			$seq = (int) ($row['seq_in_index'] ?? $row['ordinal_position'] ?? 0);
			$col = strtolower(trim((string) ($row['column_name'] ?? '')));
			if ($col !== '') {
				$groups[$keyName]['columns'][$seq] = $col;
			}
		}

		foreach ($groups as &$group) {
			ksort($group['columns']);
			$group['columns'] = array_values($group['columns']);
		}
		unset($group);

		return $groups;
	}

	/**
	 * 读取指定字段的数据占用统计。
	 *
	 * 默认方言不提供跨数据库的动态标识符统计；需要时由具体 Provider 实现。
	 *
	 * @param string $table 物理表名
	 * @param string $column 物理字段名
	 * @param bool $numeric 是否按数值零判断默认值
	 * @return array{row_count:int,non_empty_count:int}|null
	 */
	public function getColumnValueStats(string $table, string $column, bool $numeric = false): ?array
	{
		unset($table, $column, $numeric);

		return null;
	}

	/**
	 * 读取指定索引的数据库原生定义。
	 *
	 * 默认方言不暴露索引定义文本；需要时由具体 Provider 实现。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return string|null
	 */
	public function getTableIndexDefinition(string $table, string $index): ?string
	{
		unset($table, $index);

		return null;
	}

	/**
	 * 按字符集列出可用排序规则。
	 *
	 * 默认方言不暴露排序规则元数据；需要时由具体 Provider 实现。
	 *
	 * @param string $charset 字符集
	 * @return array<int, string>
	 */
	public function listCollations(string $charset = ''): array
	{
		unset($charset);

		return [];
	}

	/**
	 * 结束建表 SQL 分号换行
	 *
	 * @param string $sql 原文
	 * @return string
	 */
	protected function finalizeCreateSql(string $sql): string
	{
		$sql = trim($sql);
		if ($sql === '') {
			return '';
		}

		return rtrim($sql, " \t\n\r\0\x0B;") . ';' . PHP_EOL;
	}
}
