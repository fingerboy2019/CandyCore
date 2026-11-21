<?php
/**
 * Memory 驱动 — 公开 KV 原始操作
 *
 * @package Candy\Core\Trait\Cache\Memory
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Memory;

use Throwable;

trait MemoryKvPublicOps
{
    /**
     * 设置缓存（支持多维键）
     *
     * @param string $key 键名
     * @param mixed $value 值
     * @param int $ttl 过期秒数，0 永不过期
     * @return bool 是否成功
     */
    public function __rawSet(string $key, mixed $value, int $ttl = 0): bool
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            $internalKey = $this->compileInternalKey($mainKey);
            $arr = [];
            $now = time();
            if (isset($this->data[$internalKey])) {
                $item = $this->data[$internalKey];
                if ($item['expire'] > 0 && $item['expire'] < $now) {
                    $this->memoryRemoveEntry($mainKey);
                } else {
                    $arr = $item['value'];
                }
            }
            is_array($arr) || $arr = [];
            $this->setNestedValue($arr, $paths, $value);
            $this->memoryStoreValue($mainKey, $arr, 0, $now);
            $this->lruElimination();
            $this->cleanupExpiredKeys();
            $this->setDirty($key);
            $this->autoDump();
            return true;
        }

        if (!$this->acquireWriteLock($key)) {
            return false;
        }

        try {
            $now = time();
            $expire = $ttl > 0 ? $now + $ttl : 0;
            $this->memoryStoreValue($key, $value, $expire, $now);
            $this->lruElimination();
            $this->cleanupExpiredKeys();
            $this->setDirty($key);
            $this->autoDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 获取缓存（支持多维键）
     *
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function __rawGet(string $key, mixed $default = null): mixed
    {
        if (($nested = $this->dispatchNestedRawGet($key, $default)) !== null) {
            return $nested['value'];
        }

        if (!$this->acquireReadLock($key)) {
            return $default;
        }

        try {
            $now = time();
            $internalKey = $this->compileInternalKey($key);

            if (!isset($this->data[$internalKey]) || !$this->ownsInternalKey($key, $internalKey)) {
                return $default;
            }

            $item = $this->data[$internalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->releaseReadLock($key);
                $this->__rawDelete($key);
                return $default;
            }

            $this->touchLru($internalKey);
            $this->data[$internalKey]['last_use'] = $now;
            $this->cleanupExpiredKeys();
            $this->autoDump();
            return $item['value'];
        } catch (Throwable) {
            return $default;
        } finally {
            $this->releaseReadLock($key);
        }
    }

    /**
     * 删除缓存（支持多维键）
     *
     * @param string $key 键名
     * @return bool 是否成功
     */
    public function __rawDelete(string $key): bool
    {
        if (($nested = $this->dispatchNestedRawDelete($key)) !== null) {
            return $nested;
        }

        if (in_array(str_replace($this->cachePrefix, '', $key), self::SYSTEM_INTERNAL_KEYS)
            || str_starts_with($key, self::LOCK_PREFIX)) {
            return true;
        }

        if (!$this->acquireWriteLock($key)) {
            return false;
        }

        try {
            if (!$this->memoryRemoveEntry($key)) {
                return true;
            }
            $this->setDirty($key);
            $this->autoDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 判断缓存是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public function __rawHas(string $key): bool
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            $data = $this->__rawGet($mainKey);
            return $data !== null && $this->getNestedValue($data, $paths) !== null;
        }

        if (!$this->acquireReadLock($key)) {
            return false;
        }

        try {
            $now = time();
            $internalKey = $this->compileInternalKey($key);
            if (!isset($this->data[$internalKey]) || !$this->ownsInternalKey($key, $internalKey)) {
                return false;
            }

            $item = $this->data[$internalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->releaseReadLock($key);
                $this->__rawDelete($key);
                return false;
            }
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseReadLock($key);
        }
    }

    /**
     * 获取剩余 TTL
     *
     * @param string $key 键名
     * @return int -1 不存在，0 永不过期，>0 剩余秒数
     */
    public function __ttl(string $key): int
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            return $this->__ttl($mainKey);
        }

        if (!$this->acquireReadLock($key)) {
            return -1;
        }

        try {
            $now = time();
            $internalKey = $this->compileInternalKey($key);
            if (!isset($this->data[$internalKey]) || !$this->ownsInternalKey($key, $internalKey)) {
                return -1;
            }

            $item = $this->data[$internalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->releaseReadLock($key);
                $this->__rawDelete($key);
                return -1;
            }
            return $item['expire'] > 0 ? max(0, $item['expire'] - $now) : 0;
        } catch (Throwable) {
            return -1;
        } finally {
            $this->releaseReadLock($key);
        }
    }

    /**
     * 设置已存在 key 的过期时间
     *
     * @param string $key 键名
     * @param int $ttl 秒数
     * @return bool
     */
    public function __rawExpire(string $key, int $ttl): bool
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        if ($ttl < 0) {
            return false;
        }

        if (!$this->acquireWriteLock($key)) {
            return false;
        }

        try {
            $internalKey = $this->compileInternalKey($key);
            if (!isset($this->data[$internalKey]) || !$this->ownsInternalKey($key, $internalKey)) {
                return false;
            }

            $now = time();
            $item = $this->data[$internalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->releaseWriteLock($key);
                $this->__rawDelete($key);
                return false;
            }

            $this->data[$internalKey]['expire'] = $ttl > 0 ? $now + $ttl : 0;
            $this->setDirty($key);
            $this->autoDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 移除 key 的过期时间
     *
     * @param string $key 键名
     * @return bool
     */
    public function __rawPersist(string $key): bool
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        if (!$this->acquireWriteLock($key)) {
            return false;
        }

        try {
            $internalKey = $this->compileInternalKey($key);
            if (!isset($this->data[$internalKey]) || !$this->ownsInternalKey($key, $internalKey)) {
                return false;
            }

            $now = time();
            $item = $this->data[$internalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->releaseWriteLock($key);
                $this->__rawDelete($key);
                return false;
            }

            $this->data[$internalKey]['expire'] = 0;
            $this->setDirty($key);
            $this->autoDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 重命名 key（仅重映射索引）
     *
     * @param string $oldKey 旧键
     * @param string $newKey 新键
     * @return bool
     */
    public function __rawRename(string $oldKey, string $newKey): bool
    {
        if ($oldKey === $newKey) {
            return true;
        }

        [$mainOld, $pathsOld] = $this->memoryResolveMainKey($oldKey);
        if ($pathsOld !== null) {
            $oldKey = $mainOld;
        }
        [$mainNew, $pathsNew] = $this->memoryResolveMainKey($newKey);
        if ($pathsNew !== null) {
            $newKey = $mainNew;
        }

        $locks = array_unique([$oldKey, $newKey]);
        sort($locks);

        foreach ($locks as $lockKey) {
            if (!$this->acquireWriteLock($lockKey)) {
                foreach ($locks as $released) {
                    if ($released === $lockKey) {
                        break;
                    }
                    $this->releaseWriteLock($released);
                }
                return false;
            }
        }

        try {
            if (isset($this->keyMap[$newKey])) {
                return false;
            }

            $oldInternalKey = $this->compileInternalKey($oldKey);
            if (!isset($this->data[$oldInternalKey]) || !$this->ownsInternalKey($oldKey, $oldInternalKey)) {
                return false;
            }

            $now = time();
            $item = $this->data[$oldInternalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->__rawDelete($oldKey);
                return false;
            }

            unset($this->keyMap[$oldKey]);
            $this->keyMap[$newKey] = $oldInternalKey;
            $this->data[$oldInternalKey]['raw_key'] = $newKey;

            $this->setDirty($oldKey);
            $this->setDirty($newKey);
            $this->autoDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            foreach (array_reverse($locks) as $lockKey) {
                $this->releaseWriteLock($lockKey);
            }
        }
    }

    /**
     * 批量设置缓存
     *
     * @param array $items 键值对
     * @param int $ttl 过期秒数
     * @return bool
     */
    public function __rawMSet(array $items, int $ttl = 0): bool
    {
        if (empty($items)) {
            return true;
        }
        if (!$this->acquireWriteLock('__system_batch')) {
            return false;
        }

        try {
            $now = time();
            $expire = $ttl > 0 ? $now + $ttl : 0;
            foreach ($items as $key => $value) {
                $this->memoryStoreValue((string)$key, $value, $expire, $now);
                $this->setDirty((string)$key);
            }
            $this->lruElimination();
            $this->cleanupExpiredKeys();
            $this->autoDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock('__system_batch');
        }
    }

    /**
     * 批量获取缓存
     *
     * @param array $keys 键名列表
     * @param mixed $default 默认值
     * @return array
     */
    public function __rawMGet(array $keys, mixed $default = null): array
    {
        $result = array_fill_keys($keys, $default);
        if (empty($keys)) {
            return $result;
        }

        if (!$this->acquireReadLock('__system_batch')) {
            return $result;
        }

        try {
            $now = time();
            foreach ($keys as $key) {
                $internalKey = $this->compileInternalKey($key);
                if (!isset($this->data[$internalKey])) {
                    continue;
                }

                $item = $this->data[$internalKey];
                if ($item['expire'] > 0 && $item['expire'] < $now) {
                    continue;
                }

                $result[$key] = $item['value'];
                $this->data[$internalKey]['last_use'] = $now;
            }
            $this->cleanupExpiredKeys();
            $this->autoDump();
            return $result;
        } catch (Throwable) {
            return $result;
        } finally {
            $this->releaseReadLock('__system_batch');
        }
    }

    /**
     * 批量判断缓存是否存在
     *
     * @param array $keys 键名列表
     * @return array
     */
    public function __rawMHas(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->__rawHas($key);
        }
        return $result;
    }

    /**
     * 批量删除缓存
     *
     * @param array $keys 键名列表
     * @return bool
     */
    public function __rawMDelete(array $keys): bool
    {
        if (empty($keys)) {
            return true;
        }
        if (!$this->acquireWriteLock('__system_batch')) {
            return false;
        }

        try {
            foreach ($keys as $key) {
                if ($this->memoryRemoveEntry((string)$key)) {
                    $this->setDirty((string)$key);
                }
            }
            $this->autoDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock('__system_batch');
        }
    }

    /**
     * 原子自增/自减
     *
     * @param string $key 键名
     * @param int $step 步长
     * @param int $type 0 自增，1 自减
     * @return int
     */
    public function __rawIncr(string $key, int $step, int $type): int
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            // 嵌套 key：分片写锁不可重入（__rawSet 内部可能再取同分片锁 → 自锁），
            // 改用全局写锁把「读-算-写」串行化，保证协程/常驻并发下自增不丢更新。
            $nestedLock = '__system_nested_incr';
            if (!$this->acquireWriteLock($nestedLock)) {
                return 0;
            }
            try {
                $arr = $this->__rawGet($mainKey) ?? [];
                is_array($arr) || $arr = [];
                $val = $this->getNestedValue($arr, $paths) ?? 0;
                is_numeric($val) || $val = 0;
                $newVal = $type === 0 ? $val + $step : $val - $step;
                $this->setNestedValue($arr, $paths, $newVal);
                $this->__rawSet($mainKey, $arr);
                return $newVal;
            } finally {
                $this->releaseWriteLock($nestedLock);
            }
        }

        if (!$this->acquireWriteLock($key)) {
            return 0;
        }

        try {
            $now = time();
            $internalKey = $this->compileInternalKey($key);
            $currentVal = 0;
            $expire = 0;

            if (isset($this->data[$internalKey])) {
                $item = $this->data[$internalKey];
                if (!($item['expire'] > 0 && $item['expire'] < $now)) {
                    $currentVal = is_numeric($item['value']) ? (int)$item['value'] : 0;
                    $expire = $item['expire'];
                }
            }

            $newVal = $type === 0 ? $currentVal + $step : $currentVal - $step;
            $this->memoryStoreValue($key, $newVal, $expire, $now);
            $this->lruElimination();
            $this->cleanupExpiredKeys();
            $this->setDirty($key);
            $this->autoDump();
            return $newVal;
        } catch (Throwable) {
            return 0;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 队列推入
     *
     * @param string $key 队列键
     * @param array $args 元素
     * @param bool $cover 覆盖模式
     * @param int $head 0 队头，1 队尾
     * @return int 队列长度
     */
    public function __rawPush(string $key, array $args, bool $cover, int $head = 0): int
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            $arr = $this->__rawGet($mainKey) ?? [];
            is_array($arr) || $arr = [];
            $queue = $this->getNestedValue($arr, $paths);
            if (!is_array($queue)) {
                $queue = [];
            }
            if ($cover) {
                $queue = $args;
            } else {
                foreach ($args as $arg) {
                    $head === 0 ? array_unshift($queue, $arg) : $queue[] = $arg;
                }
            }
            $this->setNestedValue($arr, $paths, $queue);
            $this->__rawSet($mainKey, $arr);
            return count($queue);
        }

        if (!$this->acquireWriteLock($key)) {
            return 0;
        }

        try {
            $now = time();
            $internalKey = $this->compileInternalKey($key);
            $queue = [];
            $expire = 0;

            if (isset($this->data[$internalKey])) {
                $item = $this->data[$internalKey];
                if (!($item['expire'] > 0 && $item['expire'] < $now) && is_array($item['value'])) {
                    $queue = $item['value'];
                    $expire = $item['expire'];
                }
            }

            if ($cover) {
                $queue = $args;
            } else {
                foreach ($args as $arg) {
                    $head === 0 ? array_unshift($queue, $arg) : $queue[] = $arg;
                }
            }

            $this->memoryStoreValue($key, $queue, $expire, $now);
            $this->lruElimination();
            $this->cleanupExpiredKeys();
            $this->setDirty($key);
            $this->autoDump();
            return count($queue);
        } catch (Throwable) {
            return 0;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 队列弹出
     *
     * @param string $key 队列键
     * @param int $head 0 队头，1 队尾
     * @return mixed|null
     */
    public function __rawPop(string $key, int $head = 0): mixed
    {
        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        if ($paths !== null) {
            $arr = $this->__rawGet($mainKey);
            if (!is_array($arr)) {
                return null;
            }
            $queue = $this->getNestedValue($arr, $paths);
            if (!is_array($queue) || empty($queue)) {
                return null;
            }
            $val = $head === 0 ? array_shift($queue) : array_pop($queue);
            $this->setNestedValue($arr, $paths, $queue);
            $this->__rawSet($mainKey, $arr);
            return $val;
        }

        if (!$this->acquireWriteLock($key)) {
            return null;
        }

        try {
            $now = time();
            $internalKey = $this->compileInternalKey($key);
            if (!isset($this->data[$internalKey])) {
                return null;
            }

            $item = $this->data[$internalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->__rawDelete($key);
                return null;
            }

            $queue = $item['value'];
            if (!is_array($queue) || empty($queue)) {
                return null;
            }
            $val = $head === 0 ? array_shift($queue) : array_pop($queue);

            empty($queue) ? $this->__rawDelete($key) : $this->data[$internalKey]['value'] = $queue;
            $this->setDirty($key);
            $this->autoDump();
            return $val;
        } catch (Throwable) {
            return null;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 范围查询（支持排序）
     *
     * @param string $key 键名
     * @param int $offset 偏移
     * @param int $length 长度，0 全部
     * @param mixed $sort 排序字段
     * @param string $order asc|desc
     * @return array|false
     */
    public function __rawRange(string $key, int $offset, int $length = 0, $sort = '', string $order = 'asc'): array|false
    {
        if (!in_array($order, ['asc', 'desc'])) {
            throw new \InvalidArgumentException('Invalid order type');
        }

        if (empty($key)) {
            return false;
        }

        [$mainKey, $paths] = $this->memoryResolveMainKey($key);
        $targetKey = $paths !== null ? $mainKey : $key;

        if (!$this->acquireReadLock($targetKey)) {
            return false;
        }

        try {
            $now = time();
            $internalKey = $this->compileInternalKey($targetKey);
            if (!isset($this->data[$internalKey])) {
                return false;
            }

            $item = $this->data[$internalKey];
            if ($item['expire'] > 0 && $item['expire'] < $now) {
                $this->__rawDelete($targetKey);
                return false;
            }

            $this->touchLru($internalKey);
            $arr = $item['value'];

            if ($paths !== null) {
                $arr = $this->getNestedValue($arr, $paths);
                if ($arr === null) {
                    return false;
                }
            }

            if (!is_array($arr) || empty($arr)) {
                return false;
            }

            if (!empty($sort)) {
                usort($arr, fn($a, $b) => $order === 'desc'
                    ? ($b[$sort] ?? 0) <=> ($a[$sort] ?? 0)
                    : ($a[$sort] ?? 0) <=> ($b[$sort] ?? 0));
            }

            return $length === 0 ? $arr : array_slice($arr, $offset, $length);
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseReadLock($targetKey);
        }
    }
}
