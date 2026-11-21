<?php
/**
 * Memory 驱动 — 分片读写锁与全局锁
 *
 * @package Candy\Core\Trait\Cache\Memory
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Memory;

trait MemoryLocking
{
    /**
     * 申请分片读锁（带写锁饥饿保护）
     *
     * @param string $key 键名
     * @param int|null $timeout 超时毫秒，-1 无限等待
     * @return bool 是否成功
     */
    protected function acquireReadLock(string $key, ?int $timeout = null): bool
    {
        if (str_starts_with($key, '__system_')) {
            return $this->acquireGlobalReadLock($timeout);
        }

        $shardIndex = crc32($key) % self::SHARD_COUNT;
        $timeout ??= $this->lockOutTime;
        $start = microtime(true);
        $isBlock = $timeout === -1;

        while (true) {
            if ($this->writeWaitings[$shardIndex]) {
                $this->writeStarvationCounters[$shardIndex]++;
                if ($this->writeStarvationCounters[$shardIndex] >= self::MAX_STARVATION_THRESHOLD) {
                    usleep($this->retryInterval);
                    continue;
                }
                $this->writeStarvationCounters[$shardIndex] = 0;
            }
            if (!$this->isWriteLockeds[$shardIndex]) {
                $this->readLockCounts[$shardIndex]++;
                return true;
            }

            if (!$isBlock && (int)((microtime(true) - $start) * 1000) >= $timeout) {
                return false;
            }
            usleep($this->retryInterval);
        }
    }

    /**
     * 申请全局读锁
     *
     * @param int|null $timeout 超时毫秒
     * @return bool 是否成功
     */
    protected function acquireGlobalReadLock(?int $timeout = null): bool
    {
        $timeout ??= $this->lockOutTime;
        $start = microtime(true);
        $isBlock = $timeout === -1;

        while (true) {
            if (!$this->globalWriteLocked) {
                $this->globalReadLockCount++;
                return true;
            }

            if (!$isBlock && (int)((microtime(true) - $start) * 1000) >= $timeout) {
                return false;
            }
            usleep($this->retryInterval);
        }
    }

    /**
     * 释放分片读锁
     *
     * @param string $key 键名
     * @return bool 是否成功
     */
    protected function releaseReadLock(string $key): bool
    {
        if (str_starts_with($key, '__system_')) {
            return $this->releaseGlobalReadLock();
        }

        $shardIndex = crc32($key) % self::SHARD_COUNT;
        if (isset($this->readLockCounts[$shardIndex]) && $this->readLockCounts[$shardIndex] > 0) {
            $this->readLockCounts[$shardIndex]--;
        }
        return true;
    }

    /**
     * 释放全局读锁
     *
     * @return bool 是否成功
     */
    protected function releaseGlobalReadLock(): bool
    {
        if ($this->globalReadLockCount > 0) {
            $this->globalReadLockCount--;
        }
        return true;
    }

    /**
     * 申请分片写锁
     *
     * @param string $key 键名
     * @param int|null $timeout 超时毫秒
     * @return bool 是否成功
     */
    protected function acquireWriteLock(string $key, ?int $timeout = null): bool
    {
        if (str_starts_with($key, '__system_')) {
            return $this->acquireGlobalWriteLock($timeout);
        }

        $shardIndex = crc32($key) % self::SHARD_COUNT;
        $timeout ??= $this->lockOutTime;
        $start = microtime(true);
        $isBlock = $timeout === -1;

        $this->writeWaitings[$shardIndex] = true;

        do {
            if ($this->readLockCounts[$shardIndex] === 0 && !$this->isWriteLockeds[$shardIndex]) {
                $this->isWriteLockeds[$shardIndex] = true;
                $this->writeWaitings[$shardIndex] = false;
                $this->writeStarvationCounters[$shardIndex] = 0;
                return true;
            }

            if (!$isBlock && (int)((microtime(true) - $start) * 1000) >= $timeout) {
                break;
            }
            usleep($this->retryInterval);
        } while (true);

        $this->writeWaitings[$shardIndex] = false;
        return false;
    }

    /**
     * 申请全局写锁
     *
     * @param int|null $timeout 超时毫秒
     * @return bool 是否成功
     */
    protected function acquireGlobalWriteLock(?int $timeout = null): bool
    {
        $timeout ??= $this->lockOutTime;
        $start = microtime(true);
        $isBlock = $timeout === -1;

        $this->globalWriteWaiting = true;

        do {
            if ($this->globalReadLockCount === 0 && !$this->globalWriteLocked) {
                $this->globalWriteLocked = true;
                $this->globalWriteWaiting = false;
                return true;
            }

            if (!$isBlock && (int)((microtime(true) - $start) * 1000) >= $timeout) {
                break;
            }
            usleep($this->retryInterval);
        } while (true);

        $this->globalWriteWaiting = false;
        return false;
    }

    /**
     * 释放分片写锁
     *
     * @param string $key 键名
     * @return bool 是否成功
     */
    protected function releaseWriteLock(string $key): bool
    {
        if (str_starts_with($key, '__system_')) {
            return $this->releaseGlobalWriteLock();
        }

        $shardIndex = crc32($key) % self::SHARD_COUNT;
        if (isset($this->isWriteLockeds[$shardIndex])) {
            $this->isWriteLockeds[$shardIndex] = false;
        }
        return true;
    }

    /**
     * 释放全局写锁
     *
     * @return bool 是否成功
     */
    protected function releaseGlobalWriteLock(): bool
    {
        $this->globalWriteLocked = false;
        return true;
    }
}
