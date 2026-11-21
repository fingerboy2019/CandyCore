<?php
/***
 * 分布式缓存锁（Laravel Cache::lock 等价）
 *
 * 基于 `Cache::cas()` 的 Compare-And-Set 语义实现，天然跨进程/跨机器安全，
 * 不依赖驱动是否提供原生 SETNX。
 *
 * ```php
 * $lock = cache_lock('daily-report', 30);      // 30 秒自动过期
 * if ($lock->get()) {
 *     try {
 *         // 临界区
 *     } finally {
 *         $lock->release();                     // 必须在 finally 里释放
 *     }
 * }
 *
 * // 或回调式（自动释放）
 * cache_lock('daily-report', 30)->get(function () {
 *     // 临界区
 * });
 *
 * // 阻塞等待（最多等 5 秒）
 * cache_lock('daily-report', 30)->block(5, function () { ... });
 * ```
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-13 00:55:00 $
 * @package Candy\Extend\Cache
 */

declare(strict_types=1);

namespace Candy\Extend\Cache;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Core\Cache;

class Lock
{
    /** 锁 key 前缀，避免与业务缓存冲突 */
    private const PREFIX = '__lock:';

    /** @var Cache 缓存驱动实例 */
    private Cache $cache;

    /** @var string 业务侧锁名（不含前缀） */
    private string $name;

    /** @var int 持有时长（秒），0 = 永不过期（不推荐） */
    private int $seconds;

    /** @var string 持有者令牌，保证「只有自己加的锁才能自己解」 */
    private string $owner;

    /** @var bool 当前实例是否已成功获取锁 */
    private bool $held = false;

    /**
     * @param Cache       $cache   缓存驱动
     * @param string      $name    锁名
     * @param int         $seconds 持有时长（秒），防止进程崩溃后永久锁死
     * @param string|null $owner   持有者令牌，默认自动生成
     */
    public function __construct(Cache $cache, string $name, int $seconds = 0, ?string $owner = null)
    {
        $this->cache   = $cache;
        $this->name    = $name;
        $this->seconds = max(0, $seconds);
        $this->owner   = $owner ?? $this->generateOwner();
    }

    /**
     * 尝试获取锁（非阻塞）
     *
     * @param callable|null $callback 传入则获取成功后执行，并在 finally 中自动释放
     * @return bool 是否获取成功；带 callback 时返回 callback 的执行结果转 bool
     */
    public function get(?callable $callback = null): bool
    {
        $key = $this->key();

        // cas(key, null, owner, ttl)：只有当前值为 null（无锁）时才写入成功
        $ok = $this->cache->cas($key, null, $this->owner, $this->seconds);
        $this->held = $ok;

        if (!$ok && $callback !== null) {
            return false;
        }

        if ($ok && $callback !== null) {
            try {
                $callback();
                return true;
            } finally {
                $this->release();
            }
        }

        return $ok;
    }

    /**
     * 阻塞等待获取锁
     *
     * @param int           $seconds  最多等待多少秒
     * @param callable|null $callback 传入则获取成功后执行，并自动释放
     * @return bool 是否获取成功
     */
    public function block(int $seconds, ?callable $callback = null): bool
    {
        $deadline = microtime(true) + max(0, $seconds);

        while (true) {
            if ($this->get($callback)) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            // 100ms 轮询，避免空转打满 CPU
            usleep(100000);
        }
    }

    /**
     * 释放锁
     *
     * 只释放自己持有的锁（用 cas 比对 owner 令牌），避免误删别人的锁。
     *
     * @return bool 是否真的释放了
     */
    public function release(): bool
    {
        if (!$this->held) {
            return false;
        }

        $key = $this->key();
        // cas(key, owner, null)：只有当前值仍是自己写入的 owner 时才清空
        $ok = $this->cache->cas($key, $this->owner, null, 0);
        if ($ok) {
            $this->held = false;
        }

        return $ok;
    }

    /**
     * 强制释放（不管是谁持有的）
     *
     * 用于运维解死锁。业务代码请用 release()。
     *
     * @return void
     */
    public function forceRelease(): void
    {
        $this->cache->delete($this->key());
        $this->held = false;
    }

    /**
     * 当前锁的持有者令牌
     *
     * @return string
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * 当前锁是否由本实例持有
     *
     * @return bool
     */
    public function isHeld(): bool
    {
        return $this->held;
    }

    /**
     * 读取锁的剩余有效期（秒）
     *
     * @return int 无锁或永久锁返回 -1
     */
    public function remaining(): int
    {
        $ttl = $this->cache->ttl($this->key());

        return $ttl > 0 ? $ttl : -1;
    }

    /**
     * 计算实际缓存 key
     *
     * @return string
     */
    private function key(): string
    {
        return self::PREFIX . $this->name;
    }

    /**
     * 生成持有者令牌
     *
     * @return string
     */
    private function generateOwner(): string
    {
        return bin2hex(random_bytes(16));
    }
}
