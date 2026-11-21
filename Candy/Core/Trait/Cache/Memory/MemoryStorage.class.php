<?php
/**
 * Memory 驱动 — 数组存储读写与 LRU 联动
 *
 * @package Candy\Core\Trait\Cache\Memory
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Memory;

trait MemoryStorage
{
    /**
     * 解析多维键为主键与路径
     *
     * @param string $key 原始键
     * @return array{0: string, 1: array|null} [mainKey, paths]
     */
    protected function memoryResolveMainKey(string $key): array
    {
        return $this->parseNestedKey($key);
    }

    /**
     * 加载未过期的存储项
     *
     * @param string $key 原始键
     * @return array|null 存储项或 null
     */
    protected function memoryLoadValue(string $key): ?array
    {
        $internalKey = $this->compileInternalKey($key);
        if (!isset($this->data[$internalKey]) || !$this->ownsInternalKey($key, $internalKey)) {
            return null;
        }

        $item = $this->data[$internalKey];
        $now = time();
        if ($item['expire'] > 0 && $item['expire'] < $now) {
            return null;
        }

        return $item;
    }

    /**
     * 写入存储项并更新 LRU
     *
     * @param string $key 原始键
     * @param mixed $value 值
     * @param int $expire 过期时间戳，0 永不过期
     * @param int|null $lastUse 最后访问时间
     * @return void
     */
    protected function memoryStoreValue(string $key, mixed $value, int $expire = 0, ?int $lastUse = null): void
    {
        $now = $lastUse ?? time();
        $internalKey = $this->compileInternalKey($key);
        $this->data[$internalKey] = [
            'value' => $value,
            'expire' => $expire,
            'last_use' => $now,
            'raw_key' => $key,
        ];
        $this->touchLru($internalKey);
    }

    /**
     * 移除存储项并同步 LRU / keyMap（使用前缀路径 lruNodes）
     *
     * @param string $key 原始键
     * @return bool 是否执行了删除
     */
    protected function memoryRemoveEntry(string $key): bool
    {
        $internalKey = $this->compileInternalKey($key);
        if (!isset($this->data[$internalKey])) {
            return false;
        }

        $rawKey = $this->data[$internalKey]['raw_key'];
        $prefix = $this->extractDbPrefix($rawKey);

        if (isset($this->lruNodes[$prefix][$internalKey])) {
            $this->removeFromList($this->lruNodes[$prefix][$internalKey], $prefix);
        }

        unset(
            $this->data[$internalKey],
            $this->lruRecords[$prefix][$internalKey],
            $this->keyMap[$rawKey]
        );

        return true;
    }

    /**
     * SegmentStreamOps — 从内存数组加载 Stream 结构
     *
     * @param string $key 原始键
     * @return mixed Stream 数组或 null
     */
    protected function segmentStreamLoad(string $key): mixed
    {
        $item = $this->memoryLoadValue($key);
        return $item !== null ? $item['value'] : null;
    }

    /**
     * SegmentStreamOps — 持久化 Stream 到内存数组
     *
     * @param string $key 原始键
     * @param array $stream Stream 结构
     * @return bool 是否成功
     */
    protected function segmentStreamSave(string $key, array $stream): bool
    {
        $this->memoryStoreValue($key, $stream, 0);
        $this->lruElimination();
        $this->cleanupExpiredKeys();
        $this->setDirty($key);
        $this->autoDump();
        return true;
    }

    /**
     * SegmentStreamOps — 写锁下执行回调
     *
     * @template T
     * @param string $key 键名
     * @param callable(): T $fn 回调
     * @return T|false
     */
    protected function segmentStreamWithWriteLock(string $key, callable $fn): mixed
    {
        if (!$this->acquireWriteLock($key)) {
            return false;
        }

        try {
            return $fn();
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * SegmentStreamOps — 读锁下执行回调
     *
     * @template T
     * @param string $key 键名
     * @param callable(): T $fn 回调
     * @return T|false
     */
    protected function segmentStreamWithReadLock(string $key, callable $fn): mixed
    {
        if (!$this->acquireReadLock($key)) {
            return false;
        }

        try {
            return $fn();
        } finally {
            $this->releaseReadLock($key);
        }
    }
}
