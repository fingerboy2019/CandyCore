<?php
/***
 * 库表元数据门面
 *
 * 业务说明：按 DRIVER 解析 SchemaProvider；业务/Generator/表单维护只调本类
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 06:10:00 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use Candy\Extend\DB\Schema\MysqlSchemaProvider;
use Candy\Extend\DB\Schema\PgsqlSchemaProvider;
use Candy\Extend\DB\Schema\SchemaProviderInterface;
use Candy\Extend\DB\Schema\SqliteSchemaProvider;
use Candy\Extend\DB\Schema\SqlsrvSchemaProvider;
use Candy\Extend\DB\Trait\DbLinkRole;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SchemaMeta
{
	/** @var SchemaProviderInterface|null */
	private static ?SchemaProviderInterface $provider = null;

	/**
	 * 当前连接是否为 SQLite
	 *
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isSqliteDriver(): bool
	{
		$driver = strtolower((string) (G('DRIVER') ?: C('DRIVER') ?: ''));
		if (in_array($driver, ['sqlite', 'pdosqlite'], true)) {
			return true;
		}

		$node = [
			'HOST' => C('HOST'),
			'DBNAME' => C('DBNAME'),
			'PATH' => C('PATH'),
			'DSN' => C('DSN'),
		];

		return DbLinkRole::isSqliteNodeConfig($node);
	}

	/**
	 * 解析并缓存当前 SchemaProvider
	 *
	 * @return SchemaProviderInterface|null
	 * @access public
	 * @static
	 */
	public static function provider(): ?SchemaProviderInterface
	{
		if (self::$provider !== null) {
			return self::$provider;
		}

		$db = self::db();
		if ($db === null) {
			return null;
		}

		$short = DBServer::resolveDriverClass((string) (G('DRIVER') ?: C('DRIVER') ?: 'pdomysql'));
		self::$provider = self::buildProvider($db, $short);

		return self::$provider;
	}

	/**
	 * 为指定数据库驱动创建元数据 Provider。
	 *
	 * 租户迁移可能持有独立连接，不能依赖当前全局连接；该入口不写入全局
	 * Provider 缓存，保证不同租户连接不会串用元数据上下文。
	 *
	 * @param object $db 数据库驱动实例
	 * @return SchemaProviderInterface|null 元数据 Provider
	 */
	public static function providerFor(object $db): ?SchemaProviderInterface
	{
		if (!method_exists($db, 'getResolvedDriver')) {
			return null;
		}

		try {
			$short = (string)$db->getResolvedDriver();
			if ($short === '') {
				return null;
			}

			return self::buildProvider($db, $short);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * 测试或切换 DRIVER 后丢弃缓存 Provider
	 *
	 * @return void
	 * @access public
	 * @static
	 */
	public static function resetProvider(): void
	{
		self::$provider = null;
	}

	/**
	 * 获取数据库驱动实例
	 *
	 * @return object|null
	 * @access private
	 * @static
	 */
	private static function db(): ?object
	{
		if (!function_exists('D')) {
			return null;
		}

		try {
			// 有租户上下文时走隔离库（MCP Grant / 租户请求显式 TENANT_ID）
			if (\Candy\Core\Bootstrap::tenantId() !== null) {
				return D('_dbDriver', true);
			}

			return D('_dbDriver');
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * 当前库名（委托 Provider）
	 *
	 * @return string
	 * @access public
	 * @static
	 */
	public static function schemaName(): string
	{
		$p = self::provider();

		return $p !== null ? $p->schemaName() : '';
	}

	/**
	 * 列出数据表（代码生成器等轻量场景）
	 *
	 * @param string|null $keyword 表名关键字
	 * @return array<int, array{name:string,comment:string}>
	 * @access public
	 * @static
	 */
	public static function listTables(?string $keyword = null): array
	{
		$p = self::provider();

		return $p !== null ? $p->listTables($keyword) : [];
	}

	/**
	 * 列出数据表（后台表维护）
	 *
	 * @param string $keyword 表名关键字
	 * @return array<int, array<string, mixed>>
	 * @access public
	 * @static
	 */
	public static function listTablesForAdmin(string $keyword = ''): array
	{
		$p = self::provider();

		return $p !== null ? $p->listTablesForAdmin($keyword) : [];
	}

	/**
	 * 表是否存在
	 *
	 * @param string $table 物理表名
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function tableExists(string $table): bool
	{
		$p = self::provider();

		return $p !== null && $p->tableExists($table);
	}

	/**
	 * 读取表字段（兼容 SHOW FULL COLUMNS 键名）
	 *
	 * @param string $table 物理表名
	 * @return array<int, array<string, mixed>>
	 * @access public
	 * @static
	 */
	public static function listColumns(string $table): array
	{
		$p = self::provider();

		return $p !== null ? $p->listColumns($table) : [];
	}

	/**
	 * 判断当前连接的数据表是否包含指定字段。
	 *
	 * @param string $table 物理表名
	 * @param string $column 字段名
	 * @return bool 是否包含字段
	 */
	public static function hasColumn(string $table, string $column): bool
	{
		$p = self::provider();

		return $p !== null && $p->hasColumn($table, $column);
	}

	/**
	 * 获取建表 SQL
	 *
	 * @param string $table 物理表名
	 * @return string
	 * @access public
	 * @static
	 */
	public static function getCreateTableSql(string $table): string
	{
		$p = self::provider();

		return $p !== null ? $p->getCreateTableSql($table) : '';
	}

	/**
	 * 读取表索引分组
	 *
	 * @param string $table 物理表名
	 * @return array<string, array{key_name:string,non_unique:int,unique:int,primary:int,columns:array<int,string>}>
	 * @access public
	 * @static
	 */
	public static function getTableIndexGroups(string $table): array
	{
		$p = self::provider();

		return $p !== null ? $p->getTableIndexGroups($table) : [];
	}

	/**
	 * 列出全部物理表名
	 *
	 * @return array<int, string>
	 * @access public
	 * @static
	 */
	public static function listTableNames(): array
	{
		$p = self::provider();

		return $p !== null ? $p->listTableNames() : [];
	}

	/**
	 * 按驱动短类名实例化元数据 Provider。
	 *
	 * @param object $db 数据库驱动实例
	 * @param string $short 驱动短类名
	 * @return SchemaProviderInterface 元数据 Provider
	 */
	private static function buildProvider(object $db, string $short): SchemaProviderInterface
	{
		return match ($short) {
			'PdoSqlite', 'Sqlite' => new SqliteSchemaProvider($db),
			'PdoPgsql', 'Pgsql' => new PgsqlSchemaProvider($db),
			'PdoSqlsrv' => new SqlsrvSchemaProvider($db),
			default => new MysqlSchemaProvider($db),
		};
	}
}
