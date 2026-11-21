<?php
/**
 * PDO 驱动抽象基类
 *
 * 提供连接池、空闲超时重建、查询重试、事务与通用 query 流程。
 * 具体引擎（MySQL / 未来其他 PDO 引擎）继承本类并实现 DSN、表结构探测与库体积统计。
 *
 * 注意：不可直接作为 DRIVER 使用；请配置 pdomysql → PdoMysql 等具体驱动。
 *
 * @package Candy\Extend\DB\Driver
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-10-24 09:37:53
 * @abstract
 */
declare(strict_types=1);
namespace Candy\Extend\DB\Driver;

use Candy\Extend\DB\DBServer;
use Candy\Extend\DB\DbMetrics;
use Candy\Extend\DB\Trait\ConnectionManager;
use Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class Pdo extends DBServer {

	use ConnectionManager;
	use \Candy\Extend\DB\Trait\TableSchemaCache;

	/** @var int 连接创建次数 */
	static $count = 0;

	/** @var int SQL 执行次数 */
	static $execute = 0;

	/** @var float SQL 累计耗时（毫秒） */
	static $totalcate = 0;

	/** @var array<string, \PDO> 进程内连接池 */
	static $links = [];

	/** @var array 查询结果缓存占位 */
	static $results = [];

	/** @var array<string, int> 连接最后使用时间 */
	static $lastUsed = [];

	/** 连接空闲超时（秒） */
	const CONNECTION_TIMEOUT = 300;

	/** 断线最大重试次数 */
	const MAX_RECONNECT_ATTEMPTS = 3;

	/** 是否 CLI SAPI */
	const IS_CLI = PHP_SAPI === 'cli';

	/**
	 * 调试/报错时的引擎标签（如 Mysql、PostgreSQL）
	 *
	 * @return string
	 * @access protected
	 */
	abstract protected function driverLabel(): string;

	/**
	 * 创建引擎专用 PDO 连接（DSN / 选项由子类决定）
	 *
	 * @return \PDO|null
	 * @access protected
	 */
	abstract protected function getDB(): ?\PDO;

	/**
	 * 获取或重建 PDO 连接
	 *
	 * @return object
	 * @access protected
	 */
	protected function connect(): object
	{
		if (!extension_loaded('pdo')) {
			Debug::showMessage('请开启PDO扩展。', $this->driverLabel());
		}

		$dbKey = $this->resolvePoolKey();

		if (!empty(self::$links[$dbKey])) {
			if (self::IS_CLI && $this->isConnectionExpired($dbKey)) {
				Debug::addMsg('连接已超时，重建数据库连接', 2);
				$this->dropPoolConnection($dbKey);
				return $this->registerPoolConnection($dbKey, $this->getDB());
			}
			// 不在每条业务 SQL 前额外执行 SELECT 1；真实查询失败时由 retryQuery 重连。
			self::$lastUsed[$dbKey] = time();
			return self::$links[$dbKey];
		}

		$this->enforceConnectionLimit($dbKey);
		return $this->registerPoolConnection($dbKey, $this->getDB());
	}

	/**
	 * 返回底层 PDO 连接（走框架连接池，供租户 DDL / 裸 SQL 场景使用）。
	 *
	 * 业务代码禁止自行 new PDO 绕过框架连接管理；需裸连接时应经本方法取得。
	 *
	 * @return \PDO 底层 PDO 实例
	 * @access public
	 */
	public function pdo(): \PDO
	{
		$connection = $this->connect();
		if (!$connection instanceof \PDO) {
			throw new \RuntimeException('PDO driver does not expose a PDO instance');
		}
		if (!self::isStaticConnectionValid($connection)) {
			$dbKey = $this->resolvePoolKey();
			$this->dropPoolConnection($dbKey);
			$connection = $this->registerPoolConnection($dbKey, $this->getDB());
		}
		return $connection;
	}

	/**
	 * 连接是否空闲超时
	 *
	 * @param string $dbKey 池键
	 * @return bool
	 * @access private
	 */
	private function isConnectionExpired(string $dbKey): bool
	{
		return isset(self::$lastUsed[$dbKey])
			&& (time() - self::$lastUsed[$dbKey]) > self::CONNECTION_TIMEOUT;
	}

	/**
	 * 静态检测 PDO 是否可用
	 *
	 * @param object $pdo PDO
	 * @return bool
	 * @access protected
	 * @static
	 */
	protected static function isStaticConnectionValid(object $pdo): bool
	{
		try {
			$pdo->query('SELECT 1');
			return true;
		} catch (\PDOException) {
			return false;
		}
	}

	/**
	 * 断线自动重试包装
	 *
	 * @param callable $callback 查询回调
	 * @param int $attempts 已重试次数
	 * @return mixed
	 * @access public
	 * @static
	 */
	public static function retryQuery(callable $callback, int $attempts = 0): mixed
	{
		try {
			return $callback();
		} catch (\PDOException $e) {
			if (self::isReconnectablePdoException($e) && $attempts < self::MAX_RECONNECT_ATTEMPTS) {
				Debug::addMsg('数据库连接错误，尝试重新连接 (第' . ($attempts + 1) . '次)', 2);
				foreach (self::$links as $key => $link) {
					if (!$link instanceof \PDO || !self::isStaticConnectionValid($link)) {
						if (method_exists(static::class, 'closePoolLink')) {
							static::closePoolLink($link);
						}
						unset(self::$links[$key], self::$lastUsed[$key]);
						DbMetrics::recordReconnect();
					}
				}
				sleep(1);
				return self::retryQuery($callback, $attempts + 1);
			}
			throw $e;
		}
	}

	/**
	 * 是否为可重连的连接类异常（覆盖 MySQL / PostgreSQL / 通用 SQLSTATE）
	 *
	 * @param \PDOException $e 异常
	 * @return bool
	 * @access protected
	 * @static
	 */
	protected static function isReconnectablePdoException(\PDOException $e): bool
	{
		$mysqlCodes = [2006, 2013, 2014, 2045, 2054];
		$driverCode = $e->errorInfo[1] ?? null;
		if (is_int($driverCode) && in_array($driverCode, $mysqlCodes, true)) {
			return true;
		}
		if (is_numeric($driverCode) && in_array((int) $driverCode, $mysqlCodes, true)) {
			return true;
		}

		$sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
		if (str_starts_with($sqlState, '08')) {
			return true;
		}
		if (in_array($sqlState, ['57P01', '57P02', '57P03', 'HY000', '08006', '08001'], true)) {
			return true;
		}

		$msg = strtolower($e->getMessage());
		foreach (['server has gone away', 'lost connection', 'broken pipe', 'connection reset', 'no connection to the server', 'ssl connection has been closed'] as $needle) {
			if (str_contains($msg, $needle)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 执行 SQL（参数化 + 按 method 解析结果）
	 *
	 * @param string $sql SQL
	 * @param string $method select|find|total|insert|update|delete|other
	 * @param array $data 绑定参数
	 * @return mixed
	 */
	public function query(string $sql, string $method = 'other', array $data = []): mixed
	{
		$startTime = microtime(true);
		$value = $this->escapeStringArray($data);
		$marr = explode('::', $method);
		$method = strtolower(array_pop($marr));

		$this->switchRoleForQuery($method);

		return self::retryQuery(function () use ($sql, $method, $value, $startTime) {
			$pdo = $this->connect();
			$stmt = $pdo->prepare($sql);
			$result = $stmt->execute($value);

			$return = match ($method) {
				'select' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
				'find' => $stmt->fetch(\PDO::FETCH_ASSOC) ?: null,
				'total' => (int) $stmt->fetch(\PDO::FETCH_NUM)[0],
				'insert' => $this->auto == 'yes' ? (int) $pdo->lastInsertId() : ($result ? 1 : 0),
				'insertgetid' => (int) $stmt->fetchColumn(),
				'delete', 'update' => $stmt->rowCount(),
				default => $result,
			};

			self::$execute++;
			$ys = round((microtime(true) - $startTime) * 1000, 4);
			DbMetrics::recordQuery(
				$sql,
				$ys,
				$this->getDbAlias(),
				$this->getDbActiveRole(),
				$value
			);
			Debug::addMsg('[<b>' . $this->dbname . '</b>] 表<b>' . $this->tabName . '</b>结构：' . implode(',', $this->fieldList), 2);
			Debug::addMsg('[用时<font color=\'red\'>' . $ys . '</font>ms] (' . self::$execute . '次) - ' . $sql, 2);
			self::$totalcate += $ys;
			self::$echoSql = $sql;
			$dialect = $this->dialectClass();
			return $dialect::normalizeResultKeys($return, $method);
		});
	}

	/**
	 * 开始事务
	 *
	 * @return bool|null
	 */
	public function beginTransaction(): ?bool
	{
		$this->onTransactionBegin();
		$pdo = $this->connect();
		$pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, 0);
		return $pdo->beginTransaction();
	}

	/**
	 * 提交事务
	 *
	 * @return bool|null
	 */
	public function commit(): ?bool
	{
		$pdo = $this->connect();
		$result = $pdo->commit();
		$pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, 1);
		$this->onTransactionEnd();
		return $result;
	}

	/**
	 * 回滚事务
	 *
	 * @return bool|null
	 */
	public function rollBack(): ?bool
	{
		$pdo = $this->connect();
		$result = $pdo->rollBack();
		$pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, 1);
		$this->onTransactionEnd();
		return $result;
	}

	/**
	 * 数据库服务器版本
	 *
	 * @return string
	 */
	public function dbVersion(): string
	{
		$pdo = $this->connect();
		return $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
	}

	/**
	 * 组装通用 PDO 选项（子类可合并引擎专用选项）
	 *
	 * @return array<int|string, mixed>
	 * @access protected
	 */
	protected function basePdoOptions(): array
	{
		$options = [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_TIMEOUT => 60,
		];
		// Workerman/CLI 长驻禁用 ATTR_PERSISTENT；FPM 短请求可开持久连接
		// isWebServer()=true 表示 Workerman WebServer（CLIWORKING 已定义）
		if (!isWebServer()) {
			$options[\PDO::ATTR_PERSISTENT] = true;
		} else {
			$options[\PDO::ATTR_PERSISTENT] = false;
		}
		return $options;
	}
}
