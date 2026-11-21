<?php
/**
 * 进程内热点缓存 + LRU 淘汰（单键模式）
 *
 * 支持负缓存（miss 标记），避免把「未命中」与「缓存了 null」混淆
 * 供 Redis / Memcached / Gd 等驱动复用
 *
 * @package Candy\Core\Cache\Trait
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-18
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache;

trait LocalHotCache
{
	/**
	 * 空值/负缓存 TTL（防穿透，秒）
	 */
	protected const NULL_CACHE_TTL = 5;

	/**
	 * 最大热点缓存条目数
	 */
	private const MAX_HOT_CACHE_COUNT = 200;

	/** @var array<string, array{value: mixed, expire: int, miss?: bool}> */
	protected array $hotCache = [];

	/** @var array<string, int> */
	protected array $lruRecords = [];

	/**
	 * 本地二级缓存 TTL 上限（秒），0 表示不限制（Memcached/Gd 默认）
	 */
	protected function getLocalCacheTtlCap(): int
	{
		return 0;
	}

	/**
	 * 计算写入 hotCache 的过期时间戳
	 */
	protected function resolveHotCacheExpire(int $expireTimestamp): int
	{
		$cap = $this->getLocalCacheTtlCap();
		if ($cap <= 0) {
			return $expireTimestamp;
		}

		$now = time();
		return $expireTimestamp === 0
			? $now + $cap
			: min($expireTimestamp, $now + $cap);
	}

	/**
	 * 查找本地二级缓存
	 *
	 * @return array{miss:bool,value:mixed}|null null=无条目；miss=true 表示负缓存
	 */
	protected function lookupHotCache(string $key): ?array
	{
		if (!$this->enableLocalCache) {
			return null;
		}

		if (!isset($this->hotCache[$key])) {
			return null;
		}

		$item = $this->hotCache[$key];
		$now = time();

		if ($item['expire'] !== 0 && $item['expire'] <= $now) {
			$this->deleteHotCache($key);
			return null;
		}

		$this->touchLru($key);
		return [
			'miss' => !empty($item['miss']),
			'value' => $item['value'] ?? null,
		];
	}

	/**
	 * 从本地二级缓存读取（命中且非负缓存时返回值；无条目或负缓存返回 null）
	 *
	 * 注意：无法区分「未命中」与「值为 null」；新代码请用 lookupHotCache
	 */
	private function getHotCache(string $key): mixed
	{
		$entry = $this->lookupHotCache($key);
		if ($entry === null || $entry['miss']) {
			return null;
		}
		return $entry['value'];
	}

	/**
	 * 写入本地二级缓存（正缓存）
	 */
	private function setHotCache(string $key, mixed $value, int $expireTimestamp): void
	{
		if (!$this->enableLocalCache) {
			return;
		}

		if (count($this->lruRecords) > self::MAX_HOT_CACHE_COUNT * 2) {
			$this->trimLru();
		}

		$this->hotCache[$key] = [
			'value' => $value,
			'expire' => $this->resolveHotCacheExpire($expireTimestamp),
			'miss' => false,
		];
		$this->touchLru($key);
	}

	/**
	 * 写入本地负缓存（仅进程内，不写远端）
	 */
	protected function setHotMiss(string $key, ?int $expireTimestamp = null): void
	{
		if (!$this->enableLocalCache) {
			return;
		}

		$expire = $expireTimestamp ?? (time() + self::NULL_CACHE_TTL);
		if (count($this->lruRecords) > self::MAX_HOT_CACHE_COUNT * 2) {
			$this->trimLru();
		}

		$this->hotCache[$key] = [
			'value' => null,
			'expire' => $this->resolveHotCacheExpire($expire),
			'miss' => true,
		];
		$this->touchLru($key);
	}

	/**
	 * 基类 get() 钩子：仅本地记住 miss，禁止写远端 STRING
	 */
	protected function rememberLocalMiss(string $prefixedKey): void
	{
		$this->setHotMiss($prefixedKey);
	}

	/**
	 * 删除本地二级缓存条目
	 */
	private function deleteHotCache(string $key): void
	{
		unset($this->hotCache[$key], $this->lruRecords[$key]);
	}

	/**
	 * 清空本地二级缓存
	 */
	private function clearHotCache(): void
	{
		$this->hotCache = [];
		$this->lruRecords = [];
	}

	/**
	 * 更新 LRU 访问时间
	 */
	private function touchLru(string $key): void
	{
		$this->lruRecords[$key] = time();
	}

	/**
	 * 裁剪 LRU，淘汰最久未使用的条目
	 */
	private function trimLru(): void
	{
		if (count($this->lruRecords) <= self::MAX_HOT_CACHE_COUNT) {
			return;
		}

		uasort($this->lruRecords, static fn(int $a, int $b): int => $a <=> $b);

		$removeCount = count($this->lruRecords) - self::MAX_HOT_CACHE_COUNT;
		$keysToRemove = array_slice(array_keys($this->lruRecords), 0, $removeCount);

		foreach ($keysToRemove as $key) {
			if (isset($this->lruRecords[$key])) {
				unset($this->hotCache[$key], $this->lruRecords[$key]);
			}
		}
	}
}
