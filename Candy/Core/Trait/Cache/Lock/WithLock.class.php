<?php

declare(strict_types=1);

/**
 * Cache 临界区：把「读 → 判断 → 写」包进一把锁
 *
 * 解决的问题：驱动层的单方法复合操作（zAdd/sAdd/hSet…）内部都有 __acquireLock，
 * 但**跨方法的业务组合**（如 zRangeByScore 再 zRem、has 再 set）是两次独立加锁，
 * 中间存在空窗，多进程会重复取到同一批 → 必须由调用方显式包一个临界区。
 *
 * ```php
 * $rows = $cache->withLock('cron.queue', function ($c) use ($now) {
 *     $rows = $c->zRangeByScore('cron.queue', -INF, $now, 0, 500);
 *     $rows && $c->zRem('cron.queue', ...$rows);
 *     return $rows;
 * });
 * ```
 *
 * 约定：
 * - 多 key 会按字符串排序后依次加锁，避免两个事务交叉加锁造成死锁；
 * - 锁名由 __acquireLock 内部自行加前缀，**调用方不要自己 prefix()**；
 * - 锁的过期时间由各驱动的 lockOutTime 决定，本方法只控制等待超时。
 *
 * @package Candy\Core\Trait\Cache
 */

namespace Candy\Core\Trait\Cache\Lock;

trait WithLock
{
    /**
     * 在锁保护下执行回调（多键临界区）
     *
     * @param string|array<int,string> $keys     需要互斥的键（可多个，按序加锁）
     * @param callable                 $callback 回调，签名为 fn(Cache $cache): mixed
     * @param int|null                 $timeout  获取锁的等待超时（毫秒），null 用驱动默认
     * @return mixed 回调的返回值
     * @throws \RuntimeException 任一键加锁失败时抛出（已持有的锁会在 finally 里释放）
     */
    public function withLock(string|array $keys, callable $callback, ?int $timeout = null): mixed
    {
        $keys = array_values(array_unique(array_map('strval', (array) $keys)));
        sort($keys, SORT_STRING);

        $acquired = [];
        try {
            foreach ($keys as $key) {
                if (!$this->__acquireLock($key, $timeout)) {
                    throw new \RuntimeException('获取缓存锁超时：' . $key);
                }
                $acquired[] = $key;
            }

            return $callback($this);
        } finally {
            foreach (array_reverse($acquired) as $key) {
                $this->__releaseLock($key);
            }
        }
    }

    /**
     * 单键临界区（withLock 的简写）
     *
     * @param string   $key      需要互斥的键
     * @param callable $callback 回调，签名为 fn(Cache $cache): mixed
     * @param int|null $timeout  获取锁的等待超时（毫秒）
     * @return mixed
     */
    public function withKeyLock(string $key, callable $callback, ?int $timeout = null): mixed
    {
        return $this->withLock([$key], $callback, $timeout);
    }
}
