<?php
/***
 * 异步只读查询队列
 *
 * 业务说明：将 select/find/total 推入 Candy\Extend\Queue，由 FlushDbReadJob 消费；未开启时同步执行
 *
 * 行为说明：
 * - 入队后立即返回空结果（select→[]、total→0、find→null），无结果回写
 * - flush 按 alias/role/driver 调用 D()，支持别名级 DRIVER（如 clickhouse）
 * - async_read 默认关闭，需配置计划任务 flush_db_read
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 14:18:00 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use Candy\Extend\Queue;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class DbAsync
{
	public const QUEUE_NAME = 'db_read';

	/**
	 * 是否启用异步读
	 *
	 * @return bool
	 * @access public
	 * @static
	 */
	public static function isEnabled(): bool
	{
		$cfg = function_exists('loadConfig') ? (loadConfig('Db') ?: []) : [];
		return (bool) ($cfg['async_read'] ?? G('DB_ASYNC_READ') ?? C('DB_ASYNC_READ') ?? false);
	}

	/**
	 * 同步执行或入队只读 SQL
	 *
	 * @param object $db 驱动实例
	 * @param string $sql SQL
	 * @param string $method select|find|total
	 * @param array $bind 绑定
	 * @param bool $forceSync 强制同步
	 * @return mixed
	 */
	public static function runRead(object $db, string $sql, string $method, array $bind = [], bool $forceSync = false): mixed
	{
		if ($forceSync || !self::isEnabled()) {
			return $db->query($sql, $method, $bind);
		}

		$alias = method_exists($db, 'getDbAlias') ? $db->getDbAlias() : 'default';
		$role = method_exists($db, 'getDbActiveRole') ? $db->getDbActiveRole() : 'read';
		$driver = method_exists($db, 'getResolvedDriver') ? $db->getResolvedDriver() : DBServer::resolveDriverForAlias($alias);

		$payload = [
			'sql' => $sql,
			'method' => $method,
			'bind' => $bind,
			'alias' => $alias,
			'role' => $role,
			'driver' => $driver,
			'time' => time(),
		];
		Queue::push(self::QUEUE_NAME, json_encode($payload, JSON_UNESCAPED_UNICODE));
		DbMetrics::recordAsyncEnqueue();

		return $method === 'select' ? [] : ($method === 'total' ? 0 : null);
	}

	/**
	 * 消费队列（同步执行 SQL）
	 *
	 * @param int $batch 批次
	 * @return array{processed:int,errors:int}
	 */
	public static function flush(int $batch = 50): array
	{
		$processed = 0;
		$errors = 0;
		for ($i = 0; $i < $batch; $i++) {
			$raw = Queue::pop(self::QUEUE_NAME, -1);
			if ($raw === null || $raw === false || $raw === '') {
				break;
			}
			$item = json_decode((string) $raw, true);
			if (!is_array($item) || empty($item['sql'])) {
				$errors++;
				DbMetrics::recordAsyncError();
				continue;
			}
			try {
				$alias = (string) ($item['alias'] ?? 'default');
				$role = (string) ($item['role'] ?? 'read');
				$driver = (string) ($item['driver'] ?? '');
				if ($driver === '') {
					$driver = DBServer::resolveDriverForAlias($alias);
				}
				$db = D('_dbDriver', $alias, $role, $driver);
				$db->query((string) $item['sql'], (string) ($item['method'] ?? 'select'), (array) ($item['bind'] ?? []));
				$processed++;
				DbMetrics::recordAsyncProcessed();
			} catch (\Throwable) {
				$errors++;
				DbMetrics::recordAsyncError();
			}
		}
		return ['processed' => $processed, 'errors' => $errors];
	}
}

