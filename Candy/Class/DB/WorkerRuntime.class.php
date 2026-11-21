<?php
/***
 * Workerman / 长驻进程连接运行时治理
 *
 * 业务说明：Worker 启动与 reload 时重置 DB 进程内连接池与 Cache 单例，避免 fork 继承坏连接
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 22:30:00 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use Candy\Core\Cache;
use Candy\Core\Debug;
use Candy\Extend\DB\Driver\Mysqli;
use Candy\Extend\DB\Driver\Pdo;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class WorkerRuntime
{
	/** @var bool 本进程是否已执行过 boot 重置 */
	private static bool $booted = false;

	/**
	 * Worker 子进程启动时调用（fork 之后）
	 *
	 * 清空继承自 Master 的 DB/Cache 连接，强制按需重建
	 *
	 * @param mixed $worker Workerman Worker（可选）
	 * @return void
	 * @access public
	 * @static
	 */
	public static function onWorkerStart(mixed $worker = null): void
	{
		unset($worker);
		self::resetPools('worker_start');
		self::$booted = true;
	}

	/**
	 * Worker reload 时调用
	 *
	 * @param mixed $worker Workerman Worker（可选）
	 * @return void
	 * @access public
	 * @static
	 */
	public static function onWorkerReload(mixed $worker = null): void
	{
		unset($worker);
		self::resetPools('worker_reload');
	}

	/**
	 * 是否已在本 Worker 内完成 boot 重置
	 *
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isBooted(): bool
	{
		return self::$booted;
	}

	/**
	 * 重置数据库连接池与缓存驱动单例
	 *
	 * @param string $reason 触发原因（日志用）
	 * @return void
	 * @access public
	 * @static
	 */
	public static function resetPools(string $reason = 'manual'): void
	{
		try {
			Pdo::resetConnectionPool();
		} catch (Throwable $e) {
			Debug::addMsg('[WorkerRuntime] Pdo 池重置失败: ' . $e->getMessage(), 2);
		}

		try {
			Mysqli::resetConnectionPool();
		} catch (Throwable $e) {
			Debug::addMsg('[WorkerRuntime] Mysqli 池重置失败: ' . $e->getMessage(), 2);
		}

		try {
			Cache::flushInstance();
		} catch (Throwable $e) {
			Debug::addMsg('[WorkerRuntime] Cache 单例重置失败: ' . $e->getMessage(), 2);
		}

		// 进程内 G() 配置缓存可能含旧连接元数据，按需清空本地视图
		if (function_exists('G')) {
			try {
				G(null, null);
			} catch (Throwable) {
			}
		}

		Debug::addMsg('[WorkerRuntime] 已重置 DB/Cache 连接 reason=' . $reason, 0);
	}

	/**
	 * 当前是否处于 Workerman / CLI 长驻（CLIWORKING）
	 *
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isLongLivedProcess(): bool
	{
		return defined('CLIWORKING') && CLIWORKING;
	}

	/**
	 * 长驻进程是否应禁用 PHP 级持久连接（pconnect / ATTR_PERSISTENT）
	 *
	 * Workerman WebServer：isWebServer()=true → 禁用
	 * 纯 CLI 脚本：按需；默认与 isWebServer 对齐策略由调用方决定
	 *
	 * @return bool true=应使用短连接
	 * @access public
	 * @static
	 */
	public static function shouldAvoidPersistentIo(): bool
	{
		if (function_exists('isWebServer') && isWebServer()) {
			return true;
		}
		// 已定义 CLIWORKING 但非 Gateway：仍可能是长驻任务进程
		return self::isLongLivedProcess() && PHP_SAPI === 'cli';
	}
}

