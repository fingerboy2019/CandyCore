<?php
/**
 * SQLite PDO 驱动
 *
 * 配置使用 PATH / DSN / DBNAME（文件路径），无需 HOST/USER/PASS。
 * 配置：DRIVER=pdosqlite；历史 sqlite 仍解析到本类。
 *
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-18 17:32:00
 */
declare(strict_types=1);
namespace Candy\Extend\DB\Driver;

use Candy\Core\Debug;
use Candy\Extend\DB\Dialect\Sqlite as SqliteDialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

require_once __DIR__ . '/Pdo.class.php';

class PdoSqlite extends Pdo {

	/** @var string SQLite 文件路径 */
	protected string $sqlitePath = '';

	/**
	 * SQLite 禁止 PDO 持久连接
	 *
	 * PHP-FPM 下父类会对非 isWebServer 环境开启 ATTR_PERSISTENT；
	 * 权限曾异常时打开的持久连接会一直报 readonly database，必须禁用
	 *
	 * @return array<int|string, mixed>
	 */
	protected function basePdoOptions(): array
	{
		$options = parent::basePdoOptions();
		unset($options[\PDO::ATTR_PERSISTENT]);
		$options[\PDO::ATTR_PERSISTENT] = false;

		return $options;
	}

	/**
	 * SQLite 使用双引号标识符
	 *
	 * @return array{0:string,1:string}
	 */
	protected function sqlIdentQuotes(): array
	{
		return ['"', '"'];
	}

	protected function driverLabel(): string
	{
		return 'Sqlite';
	}

	/**
	 * 应用 SQLite 节点（覆盖扁平连接里的 dbname 语义）
	 *
	 * @param array $config 配置
	 * @return void
	 */
	public function applySqliteNode(array $config): void
	{
		$config = $this->mergeSqliteConfig($config);
		$this->sqlitePath = SqliteDialect::resolvePath($config);
		$this->dbname = $this->sqlitePath;
		$this->host = '';
		$this->user = '';
		$this->pass = '';
	}

	/**
	 * 合并节点配置与全局 PATH（避免仅有 HOST/DBNAME 时落到错误文件）
	 *
	 * @param array<string,mixed> $config 节点配置
	 * @return array<string,mixed>
	 */
	protected function mergeSqliteConfig(array $config): array
	{
		if (empty($config['PATH']) && function_exists('C')) {
			$path = C('PATH');
			if ($path !== null && $path !== '') {
				$config['PATH'] = $path;
			}
		}

		return $config;
	}

	/**
	 * 解析当前应打开的 SQLite 文件绝对路径
	 *
	 * @return string
	 */
	protected function resolveSqliteFilePath(): string
	{
		if ($this->sqlitePath !== '') {
			return $this->sqlitePath;
		}

		$config = is_array($this->activeConnectionConfig) ? $this->activeConnectionConfig : [];

		return SqliteDialect::resolvePath($this->mergeSqliteConfig($config));
	}

	/**
	 * 连接池键使用文件路径，避免 default 池复用到错误库文件
	 *
	 * @return string
	 */
	protected function resolvePoolKey(): string
	{
		if (!empty($this->dbPoolKey) && $this->sqlitePath === '') {
			return (string) $this->dbPoolKey;
		}

		return 'sqlite:' . $this->resolveSqliteFilePath();
	}

	protected function getDB(): ?\PDO
	{
		self::$count++;
		Debug::addMsg('<b>第 ' . self::$count . ' 次连接数据库</b>', 2);
		try {
			$path = $this->resolveSqliteFilePath();
			$dir = dirname($path);
			if (!is_dir($dir)) {
				\Candy\Extend\Dir::create($dir . '/');
			}
			if (!file_exists($path)) {
				touch($path);
			}
			SqliteDialect::ensureFilesystemWritable($path);
			if (!is_writable($dir)) {
				throw new \PDOException(
					'SQLite 目录不可写：' . $dir . '。请执行 chown -R www:www ' . $dir . ' && chmod 775 ' . $dir
				);
			}
			if (is_file($path) && !is_writable($path)) {
				$owner = function_exists('posix_getpwuid')
					? (posix_getpwuid((int) fileowner($path))['name'] ?? (string) fileowner($path))
					: (string) fileowner($path);
				throw new \PDOException(
					'SQLite 文件不可写：' . $path
					. '（所有者 ' . $owner . '）。'
					. '请执行 chown www:www ' . $path . ' 或以 root 运行一次脚手架/CLI 自动修复权限。'
				);
			}
			$pdo = new \PDO('sqlite:' . $path, null, null, $this->basePdoOptions());
			// 确保可写：禁止 query_only，journal 落在同目录（目录须可写）
			$pdo->exec('PRAGMA query_only = OFF');
			$pdo->exec('PRAGMA journal_mode = DELETE');
			return $pdo;
		} catch (\PDOException $e) {
			Debug::showMessage($e->getMessage(), $this->driverLabel());
			return null;
		}
	}

	public function setTable(string $tabName): void
	{
		[$hit, $fields, $auto] = $this->loadTableSchemaCache($tabName);
		if ($hit) {
			$this->fieldList = $fields;
			$this->auto = $auto;
			$this->tabName = $this->physicalTableName($tabName);
			return;
		}
		try {
			$pdo = $this->connect();
			$this->tabName = $this->physicalTableName($tabName);
			$stmt = $pdo->query(SqliteDialect::tableInfoSql($this->tabName));
			$auto = 'no';
			$fields = [];
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				[$fields, $auto] = SqliteDialect::mapTableInfoRow($row, $fields, $auto);
			}
			if (!array_key_exists('pri', $fields) || $fields['pri'] === '' || $fields['pri'] === null) {
				$fields['pri'] = is_array($fields) && $fields !== [] ? (string) reset($fields) : 'id';
			}
			$this->saveTableSchemaCache($tabName, $fields, $auto);
			$this->fieldList = $fields;
			$this->auto = $auto;
		} catch (\PDOException $e) {
			Debug::showMessage($e->getMessage(), $this->driverLabel());
		}
	}

	public function dbSize(): string
	{
		$path = $this->resolveSqliteFilePath();
		return is_file($path) ? sizeCount((int) filesize($path)) : '0 B';
	}

	/**
	 * 执行 SQL；遇 readonly database 时修复权限并重建连接后重试一次
	 *
	 * 结果键规范化由父类 Pdo::query 统一处理
	 *
	 * @param string $sql SQL
	 * @param string $method 查询类型
	 * @param array $data 绑定参数
	 * @return mixed
	 */
	public function query(string $sql, string $method = 'other', array $data = []): mixed
	{
		try {
			return parent::query($sql, $method, $data);
		} catch (\PDOException $e) {
			if (!$this->isSqliteReadonlyException($e)) {
				throw $e;
			}
			$this->recoverSqliteReadonlyFailure();
			return parent::query($sql, $method, $data);
		}
	}

	/**
	 * 是否为 SQLite 只读库错误
	 *
	 * @param \PDOException $e 异常
	 * @return bool
	 */
	protected function isSqliteReadonlyException(\PDOException $e): bool
	{
		return str_contains(strtolower($e->getMessage()), 'readonly database');
	}

	/**
	 * 只读库错误恢复：修正权限并丢弃当前池连接
	 *
	 * @return void
	 */
	protected function recoverSqliteReadonlyFailure(): void
	{
		$path = $this->resolveSqliteFilePath();
		SqliteDialect::ensureFilesystemWritable($path);
		$this->dropPoolConnection($this->resolvePoolKey());
	}
}
