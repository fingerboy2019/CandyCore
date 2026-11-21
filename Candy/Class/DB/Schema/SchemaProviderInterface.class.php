<?php
/***
 * 库表元数据 Provider 契约
 *
 * 业务说明：表/列/索引/建表 SQL 探测；SchemaMeta 门面按 DRIVER 解析实现
 *
 * $Author: 刘森 (fingerboy@qq.com) $
	 * $Date: 2026-08-18 13:50:42 $
 *
 * @package Candy\Extend\DB\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Schema;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface SchemaProviderInterface
{
	/**
	 * 逻辑库名（MySQL 库名；SQLite 为 main；Pgsql 为 current_schema）
	 *
	 * @return string
	 */
	public function schemaName(): string;

	/**
	 * 读取当前数据库字符集。
	 *
	 * 具体方言负责元数据查询；不支持或读取失败时返回空字符串。
	 *
	 * @return string 字符集
	 */
	public function charset(): string;

	/**
	 * 读取当前数据库名。
	 *
	 * 该值与 schemaName() 的逻辑 Schema 概念分离；不支持时返回空字符串。
	 *
	 * @return string 数据库名
	 */
	public function databaseName(): string;

	/**
	 * 判断当前数据库用户是否具备创建数据库权限。
	 *
	 * 该能力仅用于租户入驻前的连接探测；不支持该方言时返回 false。
	 *
	 * @return bool 是否具备创建数据库权限
	 */
	public function canCreateDatabase(): bool;

	/**
	 * 通过当前方言驱动新增字段。
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
	): bool;

	/**
	 * 通过当前方言修改字段定义。
	 *
	 * 仅允许固定的类型/约束定义片段；调用方不得传入完整 ALTER TABLE 语句。
	 * 不支持该方言时返回 false。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 类型及约束定义
	 * @return bool 是否执行成功
	 */
	public function alterColumn(string $table, string $column, string $definition): bool;

	/**
	 * 通过当前方言设置字段默认值表达式。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $expression 固定默认值表达式；空字符串表示删除默认值
	 * @return bool 是否执行成功
	 */
	public function setColumnDefault(string $table, string $column, string $expression): bool;

	/**
	 * 通过当前方言设置或取消字段非空约束。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param bool $notNull 是否设置为非空
	 * @return bool 是否执行成功
	 */
	public function setColumnNotNull(string $table, string $column, bool $notNull = true): bool;

	/**
	 * 通过当前方言写入字段注释。
	 *
	 * 不支持列注释的方言返回 false；空注释表示清空注释。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $comment 注释文本
	 * @return bool 是否执行成功
	 */
	public function commentColumn(string $table, string $column, string $comment): bool;

	/**
	 * 通过当前方言删除表级约束或索引约束。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 约束名
	 * @return bool 是否执行成功；不支持该方言时返回 false
	 */
	public function dropConstraint(string $table, string $constraint): bool;

	/**
	 * 通过当前方言删除主键约束。
	 *
	 * @param string $table 物理表名
	 * @param string $constraint 主键约束名；MySQL 可为空
	 * @return bool 是否执行成功
	 */
	public function dropPrimaryKey(string $table, string $constraint = ''): bool;

	/**
	 * 通过当前方言新增主键约束。
	 *
	 * @param string $table 物理表名
	 * @param array<int, string> $columns 主键列名
	 * @return bool 是否执行成功
	 */
	public function addPrimaryKey(string $table, array $columns): bool;

	/**
	 * 通过当前方言写入数据表注释。
	 *
	 * @param string $table 物理表名
	 * @param string $comment 注释文本
	 * @return bool 是否执行成功；不支持该方言时返回 false
	 */
	public function commentTable(string $table, string $comment): bool;

	/**
	 * 将现有表复制为幂等备份表。
	 *
	 * 仅接受安全物理表名；具体方言负责复制结构和数据。
	 *
	 * @param string $source 源表名
	 * @param string $target 备份表名
	 * @return bool 是否复制成功或目标表已存在
	 */
	public function copyTable(string $source, string $target): bool;

	/**
	 * 通过当前方言删除字段。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return bool 是否执行成功
	 */
	public function dropColumn(string $table, string $column): bool;

	/**
	 * 通过当前方言删除索引。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return bool 是否执行成功
	 */
	public function dropIndex(string $table, string $index): bool;

	/**
	 * 通过当前方言删除数据表。
	 *
	 * @param string $table 物理表名
	 * @param bool $cascade 是否级联删除依赖对象
	 * @return bool 是否执行成功或数据表已不存在
	 */
	public function dropTable(string $table, bool $cascade = false): bool;

	/**
	 * 按结构化列定义确保数据表存在。
	 *
	 * 列定义与约束由 Provider 负责安全标识符引用；调用方不得传入用户输入或完整 SQL。
	 * 表已存在时保持幂等，不负责修改既有列结构。
	 *
	 * @param string $table 物理表名
	 * @param array<string, string> $columns 列名到固定方言定义的映射
	 * @param array<int, string> $constraints 固定表级约束定义
	 * @return bool 是否已存在或创建成功
	 */
	public function ensureTable(string $table, array $columns, array $constraints = []): bool;

	/**
	 * 按结构化列名确保普通或唯一索引存在。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @param array<int, string> $columns 索引列名，按索引顺序排列
	 * @param bool $unique 是否创建唯一索引
	 * @param string $where PostgreSQL 部分索引谓词；其他方言传入时返回 false
	 * @return bool 是否已存在或创建成功
	 */
	public function ensureIndex(
		string $table,
		string $index,
		array $columns,
		bool $unique = false,
		string $where = ''
	): bool;

	/**
	 * 从结构化表定义生成建表预览 SQL，不执行写库。
	 *
	 * 预览与实际 ensureTable 共用 Provider 的标识符和定义校验，业务层不得自行拼接 DDL。
	 *
	 * @param string $table 物理表名
	 * @param array<string, string> $columns 列名到固定方言定义的映射
	 * @param array<int, string> $constraints 固定表级约束定义
	 * @return string 预览 SQL；参数不安全或方言不支持时返回空串
	 */
	public function previewCreateTableSql(string $table, array $columns, array $constraints = []): string;

	/**
	 * 生成新增字段预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 固定字段定义
	 * @param string $afterColumn MySQL 相邻字段名；其他方言传空字符串
	 * @return string 预览 SQL；参数不安全或方言不支持时返回空串
	 */
	public function previewAddColumnSql(
		string $table,
		string $column,
		string $definition,
		string $afterColumn = ''
	): string;

	/**
	 * 生成字段修改预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $definition 固定字段定义
	 * @param bool|null $notNull PostgreSQL 可选的非空约束变更
	 * @return string 预览 SQL；参数不安全或方言不支持时返回空串
	 */
	public function previewAlterColumnSql(
		string $table,
		string $column,
		string $definition,
		?bool $notNull = null
	): string;

	/**
	 * 生成删除字段预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return string 预览 SQL；参数不安全或方言不支持时返回空串
	 */
	public function previewDropColumnSql(string $table, string $column): string;

	/**
	 * 生成字段注释预览 SQL，不执行写库。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @param string $comment 注释文本
	 * @return string 预览 SQL；当前方言不支持时返回空串
	 */
	public function previewCommentColumnSql(string $table, string $column, string $comment): string;

	/**
	 * 列出数据表（轻量：name + comment）
	 *
	 * @param string|null $keyword 表名关键字
	 * @return array<int, array{name:string,comment:string}>
	 */
	public function listTables(?string $keyword = null): array;

	/**
	 * 列出数据表（后台维护：含引擎、行数等）
	 *
	 * @param string $keyword 表名关键字
	 * @return array<int, array<string, mixed>>
	 */
	public function listTablesForAdmin(string $keyword = ''): array;

	/**
	 * 表是否存在
	 *
	 * @param string $table 物理表名
	 * @return bool
	 */
	public function tableExists(string $table): bool;

	/**
	 * 将 PostgreSQL 自增序列推进到当前表最大主键。
	 *
	 * 非 PostgreSQL 方言不提供该能力时保持无操作，由具体 Provider 决定。
	 *
	 * @param string $table 物理表名
	 * @return void
	 */
	public function syncSequence(string $table): void;

	/**
	 * 读取表字段（SHOW FULL COLUMNS 兼容键，field 已小写）
	 *
	 * @param string $table 物理表名
	 * @return array<int, array<string, mixed>>
	 */
	public function listColumns(string $table): array;

	/**
	 * 判断数据表是否包含指定字段。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return bool 是否包含字段
	 */
	public function hasColumn(string $table, string $column): bool;

	/**
	 * 获取建表 SQL（不支持时返回空串）
	 *
	 * @param string $table 物理表名
	 * @return string
	 */
	public function getCreateTableSql(string $table): string;

	/**
	 * 读取表索引分组
	 *
	 * @param string $table 物理表名
	 * @return array<string, array{key_name:string,non_unique:int,unique:int,primary:int,columns:array<int,string>}>
	 */
	public function getTableIndexGroups(string $table): array;

	/**
	 * 读取指定字段的总行数与非默认值行数。
	 *
	 * 用于遗留字段清理前的数据占用检查；具体方言负责安全标识符引用与空值规则。
	 *
	 * @param string $table 物理表名
	 * @param string $column 物理字段名
	 * @param bool $numeric 是否按数值零判断默认值
	 * @return array{row_count:int,non_empty_count:int}|null 不支持或表字段不存在时返回 null
	 */
	public function getColumnValueStats(string $table, string $column, bool $numeric = false): ?array;

	/**
	 * 读取指定索引的数据库原生定义。
	 *
	 * @param string $table 物理表名
	 * @param string $index 索引名
	 * @return string|null 索引定义；不存在或当前方言不支持时返回 null
	 */
	public function getTableIndexDefinition(string $table, string $index): ?string;

	/**
	 * 按字符集列出可用排序规则。
	 *
	 * 仅由支持该数据库元数据能力的具体方言实现；不支持时返回空数组。
	 *
	 * @param string $charset 字符集
	 * @return array<int, string>
	 */
	public function listCollations(string $charset = ''): array;

	/**
	 * 列出全部物理表名
	 *
	 * @return array<int, string>
	 */
	public function listTableNames(): array;
}
