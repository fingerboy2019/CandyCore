<?php
/***
 * 队列操作管理器
 *
 * 业务说明：非 File 缓存走 Cache List（rPush/lPop/lLen）；File 回退整包数组；非 Redis 时 Debug 建议换 Redis
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 21:22:14 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);
namespace Candy\Extend;

use Candy\Core\Cache;
use Candy\Core\Debug;
use Candy\Core\Impl\Cache\Redis as RedisCache;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Queue {
	
	/**
	 * 缓存处理器对象
	 *
	 * @var object|null
	 */
	private static ?object $handler = null;
    
	/**
	 * 表前缀
	 *
	 * @var string|null
	 */
	private static ?string $tablePrefix = null;

	/** 本请求是否已提示非 Redis */
	private static bool $nonRedisHinted = false;

	/** @var array<string,bool> 本请求已做过旧数组→List 迁移的键 */
	private static array $legacyMigrated = [];
	
	/**
	 * 初始化队列服务
	 *
	 * @return object 返回缓存处理器实例
	 */
	public static function init(): object
	{
		$prefix = G('TABPREFIX');
		if ($prefix === null || $prefix === '') {
			$prefix = C('TABPREFIX');
		}
		self::$tablePrefix = is_string($prefix) ? $prefix : '';

		if (is_null(self::$handler)) {
			$handler = Cache::store();
			if ($handler === false) {
				showExceptionMsg('shutdown', '请先配置好缓存，再使用队列功能!', null, null, true);
			} else {
				self::$handler = $handler;
				self::maybeHintNonRedis($handler);
			}
		}

		return self::$handler;
	}

	/**
	 * 删除集合中指定的元素
	 *
	 * List 模式无 LREM 时：出队重建（仅适合短队列）；File 模式仍改整包数组
	 *
	 * @param string $key 队列标识符
	 * @param string $itemValue 要删除的元素值
	 * @return bool
	 */
	public static function removeItem(string $key, string $itemValue): bool
	{
		self::init();
		$fullKey = self::fullKey($key);

		if (!self::usesListBackend()) {
			$data = (array) self::$handler->get($fullKey);
			if ($data === []) {
				return false;
			}
			foreach ($data as $k => $v) {
				if ($v == $itemValue) {
					unset($data[$k]);
				}
			}
			unset($data[$itemValue]);
			return (bool) self::$handler->set($fullKey, array_values($data));
		}

		self::migrateLegacyArrayIfNeeded($fullKey);
		$kept = [];
		$removed = false;
		// 非阻塞掏空再写回（短队列场景；长队列请避免 removeItem）
		while (true) {
			$item = self::$handler->lPop($fullKey);
			if ($item === null || $item === false) {
				break;
			}
			if (!$removed && (string) $item === $itemValue) {
				$removed = true;
				continue;
			}
			$kept[] = is_string($item) ? $item : (string) $item;
		}
		foreach ($kept as $item) {
			self::$handler->rPush($fullKey, [$item], false);
		}

		return $removed;
	}

	/**
	 * 删除集合中指定的元素（兼容旧名）
	 *
	 * @deprecated 使用 removeItem()
	 * @param string $key 队列标识符
	 * @param string $sonKey 要删除的元素值
	 * @return bool
	 */
	public static function srem(string $key, string $sonKey): bool
	{
		return self::removeItem($key, $sonKey);
	}
    
	/**
	 * 将元素加入队列尾部
	 *
	 * 非 File：Cache::rPush（cover=false）；File：整包数组追加
	 *
	 * @param string $key 队列标识符
	 * @param string $value 要添加的元素值
	 * @return bool
	 */
	public static function push(string $key, string $value): bool
	{
		self::init();
		$fullKey = self::fullKey($key);

		if (!self::usesListBackend()) {
			$data = (array) self::$handler->get($fullKey);
			$data[] = $value;
			return (bool) self::$handler->set($fullKey, $data);
		}

		self::migrateLegacyArrayIfNeeded($fullKey);
		try {
			$len = self::$handler->rPush($fullKey, [$value], false);
			return $len > 0;
		} catch (Throwable $e) {
			Debug::addMsg('[Queue] rPush 失败：' . $e->getMessage(), 4);
			return false;
		}
	}

	/**
	 * 当前队列积压条数
	 *
	 * 非 File：lLen（Redis 为 O(1) LLEN；其它驱动多为模拟）；File：整包 count
	 *
	 * @param string $key 队列标识符（不含表前缀）
	 * @return int
	 */
	public static function length(string $key): int
	{
		self::init();
		$fullKey = self::fullKey($key);

		if (!self::usesListBackend()) {
			return count((array) self::$handler->get($fullKey));
		}

		self::migrateLegacyArrayIfNeeded($fullKey);
		try {
			return (int) self::$handler->lLen($fullKey);
		} catch (Throwable) {
			return 0;
		}
	}
	
	/**
	 * 出队（队头）
	 *
	 * timeout≤0 且非 0：非阻塞立即返回；timeout=0：空队列 sleep 轮询；timeout>0：最多等待秒数
	 *
	 * @param string $key 队列标识符
	 * @param int $timeout 等待超时（秒）
	 * @return mixed|null
	 */
	public static function pop(string $key, int $timeout = 0): mixed
	{
		self::init();
		$fullKey = self::fullKey($key);
		$second = 0;

		while (true) {
			if (self::usesListBackend()) {
				self::migrateLegacyArrayIfNeeded($fullKey);
				$res = self::$handler->lPop($fullKey);
				if ($res !== null && $res !== false && $res !== '') {
					return $res;
				}
			} else {
				$data = (array) self::$handler->get($fullKey);
				if ($data !== []) {
					$res = array_shift($data);
					self::$handler->set($fullKey, $data);
					return $res;
				}
			}

			// timeout < 0：非阻塞（Oplog/ChWrite flush 用 -1）
			if ($timeout < 0) {
				return null;
			}
			if ($timeout === 0) {
				sleep(1);
				continue;
			}
			if ($second < $timeout) {
				sleep(1);
				$second++;
				continue;
			}
			return null;
		}
	}
	
	/**
	 * 关闭队列连接（清空本进程句柄）
	 *
	 * @return bool
	 */
	public static function close(): bool
	{
		self::$handler = null;
		self::$nonRedisHinted = false;
		self::$legacyMigrated = [];
		return true;
	}

	/**
	 * 是否走 List 后端（非 File）
	 *
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function usesListBackend(): bool
	{
		try {
			return !Cache::isFileDriver();
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * @param string $key 逻辑键
	 * @return string
	 * @access private
	 * @static
	 */
	private static function fullKey(string $key): string
	{
		return (string) self::$tablePrefix . $key;
	}

	/**
	 * 非 Redis 驱动时 Debug 注入建议（每请求最多一次）
	 *
	 * @param object $handler 缓存实例
	 * @return void
	 * @access private
	 * @static
	 */
	private static function maybeHintNonRedis(object $handler): void
	{
		if (self::$nonRedisHinted || !function_exists('isDebug') || !\isDebug()) {
			return;
		}
		if (!self::usesListBackend()) {
			return;
		}
		if ($handler instanceof RedisCache) {
			return;
		}
		self::$nonRedisHinted = true;
		$driver = $handler::class;
		Debug::addMsg(
			'[Queue] 当前缓存驱动为 ' . $driver
			. '，队列使用模拟 List（整包/CAS/锁），建议将 CACHE.default 设为 redis 以获得原生 LPUSH/RPOP/LLEN。',
			0
		);
	}

	/**
	 * 将旧版「KV 数组队列」迁移为 List（仅在确有旧数据时）
	 *
	 * 注意：Redis 驱动对不存在的 key 调用 get() 会写入空字符串占位，导致后续 List 操作 WRONGTYPE；
	 * 故 Redis 仅用 TYPE 探测，确认是 string 后再 get 迁移。
	 *
	 * @param string $fullKey 已含表前缀的键
	 * @return void
	 * @access private
	 * @static
	 */
	private static function migrateLegacyArrayIfNeeded(string $fullKey): void
	{
		if (isset(self::$legacyMigrated[$fullKey])) {
			return;
		}
		self::$legacyMigrated[$fullKey] = true;

		try {
			if (self::$handler instanceof RedisCache) {
				self::migrateRedisStringQueueToList($fullKey);
				return;
			}

			// Memcached/Gd 等模拟驱动：与旧 Queue 同为数组 KV，一般无需迁移
			$legacy = self::$handler->get($fullKey);
			if (!is_array($legacy) || $legacy === []) {
				return;
			}
			self::rewriteArrayAsList($fullKey, $legacy);
		} catch (Throwable) {
		}
	}

	/**
	 * Redis：仅当键类型为 string（旧整包队列）时迁移为 List
	 *
	 * @param string $fullKey 逻辑键（含表前缀）
	 * @return void
	 * @access private
	 * @static
	 */
	private static function migrateRedisStringQueueToList(string $fullKey): void
	{
		$handler = self::$handler;
		$ref = new \ReflectionObject($handler);
		$prefixMethod = $ref->getMethod('prefix');
		$pk = (string) $prefixMethod->invoke($handler, $fullKey);

		$getClient = $ref->getMethod('getClient');
		$client = $getClient->invoke($handler);
		$type = (int) $client->type($pk);

		$stringType = defined('\Redis::REDIS_STRING') ? (int) \Redis::REDIS_STRING : 1;
		if ($type !== $stringType) {
			return;
		}

		$legacy = $handler->get($fullKey);
		if (!is_array($legacy) || $legacy === []) {
			// 空字符串占位或非数组：删掉以免挡住 List
			$handler->delete($fullKey);
			return;
		}
		self::rewriteArrayAsList($fullKey, $legacy);
	}

	/**
	 * 删除旧键并按 List 重写入队
	 *
	 * @param string $fullKey 键
	 * @param array $legacy 旧数组元素
	 * @return void
	 * @access private
	 * @static
	 */
	private static function rewriteArrayAsList(string $fullKey, array $legacy): void
	{
		self::$handler->delete($fullKey);
		foreach ($legacy as $item) {
			$payload = is_string($item) ? $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE);
			self::$handler->rPush($fullKey, [$payload], false);
		}
		Debug::addMsg('[Queue] 已迁移旧数组队列为 List：' . $fullKey . ' count=' . count($legacy), 0);
	}
}

