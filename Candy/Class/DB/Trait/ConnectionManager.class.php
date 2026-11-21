<?php
/**
 * PDO 连接池治理 Trait
 *
 * 统一池键、空闲超时、健康检查、每 worker 连接上限与指标上报。
 *
 * @package Candy\Extend\DB\Trait
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-08-23 08:40:32 $
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Trait;

use Candy\Extend\DB\DbMetrics;

trait ConnectionManager
{
	/** 每 worker 最大连接数（0=不限制） */
	protected static int $maxConnectionsPerWorker = 0;

	/**
	 * 解析池键
	 *
	 * @return string
	 * @access protected
	 */
	protected function resolvePoolKey(): string
	{
		return !empty($this->dbPoolKey) ? (string) $this->dbPoolKey : (string) $this->dbname;
	}

	/**
	 * 获取每 worker 连接上限
	 *
	 * @return int
	 * @access protected
	 * @static
	 */
	protected static function maxConnectionsLimit(): int
	{
		if (self::$maxConnectionsPerWorker > 0) {
			return self::$maxConnectionsPerWorker;
		}
		$cfg = G('DB_MAX_CONNECTIONS') ?? C('DB_MAX_CONNECTIONS') ?? 32;
		return max(0, (int) $cfg);
	}

	/**
	 * 登记新连接并上报指标
	 *
	 * @param string $dbKey 池键
	 * @param object $pdo PDO
	 * @return object
	 * @access protected
	 */
	protected function registerPoolConnection(string $dbKey, object $pdo): object
	{
		self::$links[$dbKey] = $pdo;
		self::$lastUsed[$dbKey] = time();
		DbMetrics::recordConnectionCreated($dbKey);
		return $pdo;
	}

	/**
	 * 剔除坏连接（先尝试关闭，避免 Workerman 下句柄泄漏）
	 *
	 * @param string $dbKey 池键
	 * @return void
	 * @access protected
	 */
	protected function dropPoolConnection(string $dbKey): void
	{
		if (isset(self::$links[$dbKey])) {
			self::closePoolLink(self::$links[$dbKey]);
		}
		unset(self::$links[$dbKey], self::$lastUsed[$dbKey]);
		DbMetrics::recordReconnect();
	}

	/**
	 * 重置本驱动进程内连接池（Worker fork / reload 后必须调用）
	 *
	 * @return void
	 * @access public
	 * @static
	 */
	public static function resetConnectionPool(): void
	{
		foreach (self::$links as $link) {
			self::closePoolLink($link);
		}
		self::$links = [];
		self::$lastUsed = [];
	}

	/**
	 * 关闭池中单条连接
	 *
	 * @param mixed $link PDO|mysqli|其它
	 * @return void
	 * @access protected
	 * @static
	 */
	protected static function closePoolLink(mixed $link): void
	{
		try {
			if ($link instanceof \mysqli) {
				@$link->close();
				return;
			}
			if ($link instanceof \PDO) {
				// PDO 无显式 close；置空引用即可归还；活跃事务则尝试回滚
				try {
					if ($link->inTransaction()) {
						$link->rollBack();
					}
				} catch (\Throwable) {
				}
			}
		} catch (\Throwable) {
		}
	}

	/**
	 * 创建连接前检查池容量
	 *
	 * @param string $dbKey 池键
	 * @return void
	 * @access protected
	 */
	protected function enforceConnectionLimit(string $dbKey): void
	{
		$limit = self::maxConnectionsLimit();
		if ($limit <= 0 || isset(self::$links[$dbKey])) {
			return;
		}
		if (count(self::$links) < $limit) {
			return;
		}

		$oldestKey = null;
		foreach (self::$links as $candidateKey => $candidate) {
			if ($candidateKey === $dbKey || self::isPoolLinkInTransaction($candidate)) {
				continue;
			}
			$oldestKey = $candidateKey;
			break;
		}

		// 事务连接不能被回收；若所有连接都在事务中，暂时允许池短时超限。
		if ($oldestKey === null) {
			return;
		}
		if (isset(self::$links[$oldestKey])) {
			self::closePoolLink(self::$links[$oldestKey]);
		}
		unset(self::$links[$oldestKey], self::$lastUsed[$oldestKey]);
	}

	/**
	 * 判断连接是否处于数据库事务中。
	 *
	 * 连接池容量治理不得回收活跃事务连接，否则会触发隐式回滚并留下失效的
	 * 事务作用域；非 PDO 驱动保持兼容并按非活跃连接处理。
	 *
	 * @param mixed $link 连接实例
	 * @return bool 是否处于事务中
	 * @access protected
	 * @static
	 */
	protected static function isPoolLinkInTransaction(mixed $link): bool
	{
		if (!$link instanceof \PDO) {
			return false;
		}
		try {
			return $link->inTransaction();
		} catch (\Throwable) {
			return false;
		}
	}
}
