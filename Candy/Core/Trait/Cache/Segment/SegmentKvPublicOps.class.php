<?php
/**
 * 分段存储驱动 — KV 公开 API（Set/Get/Delete/Has/TTL/批量/列表/计数器）
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

use Throwable;

trait SegmentKvPublicOps
{
    use SegmentKeyCodec;

    abstract protected function segmentPublicResolveDb(): ?string;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T|false
     */
    /** 写锁等待超过该阈值（秒）即视为存在并发写竞争，强制重载索引与位图 */
    public const CONCURRENT_WRITE_WAIT_SECONDS = 0.0002;

    abstract protected function segmentPublicWithShardWriteLock(string $key, callable $fn): mixed;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    abstract protected function segmentPublicWithReadLock(string $key, callable $fn, mixed $lockFailValue = null): mixed;

    /**
     * 批量读锁：Shmop 全局读锁；File 无锁。
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    abstract protected function segmentPublicWithBatchReadLock(callable $fn, mixed $lockFailValue): mixed;

    /**
     * 读前同步钩子（驱动可覆盖）
     *
     * File 驱动用它保证**跨进程一致性**：别的进程改过段文件时，必须重载
     * 索引/位图并丢弃进程内热缓存，否则本进程会一直读到旧值。
     * Memory/Shmop 等单实例驱动默认无操作。
     *
     * @param string $dbAlias 数据库别名
     * @return void
     */
    /**
     * 写锁内的强制同步钩子（不看序号，无条件重载索引与位图）
     *
     * @param string|null $dbAlias
     * @return void
     */
    protected function segmentForceSyncBeforeWrite(?string $dbAlias): void
    {
        $this->segmentSyncBeforeRead($dbAlias);
    }

    protected function segmentSyncBeforeRead(?string $dbAlias): void
    {
    }

    /**
     * 写后同步钩子（驱动可覆盖）
     *
     * File 驱动用它递增段头写入序号，让其它进程能检测到变更；
     * Memory/Shmop 等单实例驱动默认无操作。
     *
     * @param string $dbAlias 数据库别名
     * @return void
     */
    protected function segmentTouchAfterWrite(?string $dbAlias): void
    {
    }

    public function __rawSet(string $key, mixed $value, int $ttl = 0): bool
    {
        if (empty($key)) {
            return false;
        }

        $dbAlias = $this->segmentPublicResolveDb();
        $lockWaitStart = microtime(true);
        $result = $this->segmentPublicWithShardWriteLock($key, function () use ($key, $value, $ttl, $dbAlias, $lockWaitStart): bool {
            // 锁内先同步其它进程的写入：bitmap / 空闲槽位栈都是进程内副本，
            // 不同步就会出现「多个进程分配同一批槽位/块 → 互相覆盖」。
            //
            // 是否「无条件强制重载」取决于有没有并发：
            //  - 有等待 ⇒ 说明别的进程刚在写，序号比对存在漏检窗口，必须无条件重载（保证不丢）
            //  - 无等待 ⇒ 单进程/无竞争，走序号比对即可（避免每次写都重读整个索引区）
            $waited = microtime(true) - $lockWaitStart;
            if ($waited >= self::CONCURRENT_WRITE_WAIT_SECONDS) {
                $this->segmentForceSyncBeforeWrite($dbAlias);
            } else {
                $this->segmentSyncBeforeRead($dbAlias);
            }

            [$key, $value] = $this->mergeNestedIntoValue(
                $key,
                $value,
                fn (string $mainKey) => $this->segmentKvReadRaw($mainKey, true, $dbAlias) ?? []
            );

            try {
                $written = $this->segmentKvWriteRaw($key, $value, $ttl, true, $dbAlias);
            } catch (\LengthException $e) {
                // 值超过段可容纳的容量属于调用方错误，不能静默返回 false（否则表现为“写了但存不下”）
                throw $e;
            } catch (Throwable) {
                return false;
            }

            if ($written) {
                // 序号递增必须留在锁内：让「数据已落盘」与「序号可见」处于同一临界区
                $this->segmentTouchAfterWrite($dbAlias);
            }

            return $written;
        });

        return (bool)$result;
    }

    public function __rawGet(string $key, mixed $default = null): mixed
    {
        if (empty($key)) {
            return $default;
        }

        if (($nested = $this->dispatchNestedRawGet($key, $default)) !== null) {
            return $nested['value'];
        }

        $dbAlias = $this->segmentPublicResolveDb();
        $this->segmentSyncBeforeRead($dbAlias);
        [$key, $paths] = $this->parseNestedKey($key);
        $ckey = $this->formatKey($key, $dbAlias);
        [$memKey, ,] = $this->getCrc32Key($key);
        $hotVal = $this->getHotCache($ckey, $memKey, $dbAlias);

        if ($hotVal !== null) {
            return $this->extractNestedValue($hotVal, $paths, $default);
        }

        return $this->segmentPublicWithReadLock($key, function () use ($key, $paths, $default, $dbAlias): mixed {
            try {
                $result = $this->segmentKvReadRaw($key, true, $dbAlias);

                return $this->extractNestedValue($result, $paths, $default);
            } catch (Throwable) {
                return $default;
            }
        }, $default);
    }

    public function __rawDelete(string $key): bool
    {
        if (empty($key)) {
            return false;
        }

        $dbAlias = $this->segmentPublicResolveDb();
        [$mainKey, $paths] = $this->parseNestedKey($key);

        if ($paths !== null) {
            $result = $this->segmentPublicWithShardWriteLock($mainKey, function () use ($mainKey, $paths, $dbAlias): bool {
                $data = $this->segmentKvReadRaw($mainKey, true, $dbAlias);
                if ($data === null) {
                    return true;
                }
                if (!is_array($data)) {
                    return false;
                }

                $result = $this->unsetNestedInArray($data, $paths);
                if (!$result['changed']) {
                    return true;
                }

                $ttl = $this->segmentKvTtlUnlocked($mainKey, $dbAlias);

                try {
                    return $this->segmentKvWriteRaw($mainKey, $result['data'], $ttl > 0 ? $ttl : 0, true, $dbAlias);
                } catch (Throwable) {
                    return false;
                }
            });

            $this->segmentTouchAfterWrite($dbAlias);

            return (bool)$result;
        }

        $result = $this->segmentPublicWithShardWriteLock($key, function () use ($key, $dbAlias): bool {
            return $this->segmentKvDeleteFlat($key, $dbAlias);
        });

        $this->segmentTouchAfterWrite($dbAlias);

        return (bool)$result;
    }

    public function __rawHas(string $key): bool
    {
        if (empty($key)) {
            return false;
        }

        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $data = $this->__rawGet($mainKey);
            if ($data === null) {
                return false;
            }

            return $this->getNestedValue($data, $paths) !== null;
        }

        $dbAlias = $this->segmentPublicResolveDb();
        $this->segmentSyncBeforeRead($dbAlias);

        return (bool)$this->segmentPublicWithReadLock($key, function () use ($key, $dbAlias): bool {
            [$memKey, ,] = $this->getCrc32Key($key);
            $indexMap = $this->segmentIndexMap($dbAlias);
            if (!isset($indexMap[$memKey])) {
                return false;
            }

            $meta = $this->getMetaByOrder($indexMap[$memKey], $dbAlias);

            return $meta && $meta['status'] === 1;
        }, false);
    }

    public function __ttl(string $key): int
    {
        if (empty($key)) {
            return -1;
        }

        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            return $this->__ttl($mainKey);
        }

        $dbAlias = $this->segmentPublicResolveDb();

        return (int)$this->segmentPublicWithReadLock($key, function () use ($key, $dbAlias): int {
            return $this->segmentKvTtlUnlocked($key, $dbAlias);
        }, -1);
    }

    public function __rawExpire(string $key, int $ttl): bool
    {
        if (empty($key) || $ttl < 0) {
            return false;
        }

        [$mainKey, $paths] = $this->parseNestedKey($key);
        $key = $paths !== null ? $mainKey : $key;
        $dbAlias = $this->segmentPublicResolveDb();

        $result = $this->segmentPublicWithShardWriteLock($key, function () use ($key, $ttl, $dbAlias): bool {
            return $this->segmentKvUpdateTimestamp($key, $ttl > 0 ? time() + $ttl : 0, $dbAlias);
        });

        return (bool)$result;
    }

    public function __rawPersist(string $key): bool
    {
        if (empty($key)) {
            return false;
        }

        [$mainKey, $paths] = $this->parseNestedKey($key);
        $key = $paths !== null ? $mainKey : $key;
        $dbAlias = $this->segmentPublicResolveDb();

        $result = $this->segmentPublicWithShardWriteLock($key, function () use ($key, $dbAlias): bool {
            return $this->segmentKvUpdateTimestamp($key, 0, $dbAlias);
        });

        return (bool)$result;
    }

    public function __rawRename(string $oldKey, string $newKey): bool
    {
        if ($oldKey === $newKey) {
            return true;
        }

        [$mainOld, $pathsOld] = $this->parseNestedKey($oldKey);
        if ($pathsOld !== null) {
            $oldKey = $mainOld;
        }
        [$mainNew, $pathsNew] = $this->parseNestedKey($newKey);
        if ($pathsNew !== null) {
            $newKey = $mainNew;
        }

        $dbAlias = $this->segmentPublicResolveDb();
        $result = $this->segmentPublicWithSortedShardLocks([$oldKey, $newKey], function () use ($oldKey, $newKey, $dbAlias): bool {
            [$oldMemKey, $oldCrc, $oldVCrc] = $this->getCrc32Key($oldKey);
            [$newMemKey, $newCrc, $newVCrc] = $this->getCrc32Key($newKey);
            $indexMap = &$this->segmentIndexMap($dbAlias);

            if (!isset($indexMap[$oldMemKey])) {
                return false;
            }
            if (isset($indexMap[$newMemKey])) {
                return false;
            }

            $metaOrder = $indexMap[$oldMemKey];
            $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
            if (!$meta || $meta['status'] !== 1) {
                return false;
            }

            if ($meta['timestamp'] > 0 && $meta['timestamp'] < time()) {
                return false;
            }

            unset($indexMap[$oldMemKey]);
            $this->updateIndex($newMemKey, $newCrc, $newVCrc, $metaOrder, $dbAlias);

            $oldCkey = $this->formatKey($oldKey, $dbAlias);
            $newCkey = $this->formatKey($newKey, $dbAlias);
            $this->renameHotCache($oldCkey, $oldMemKey, $newCkey, $newMemKey, $dbAlias);
            $this->segmentKvRenameGcPending($oldMemKey, $newMemKey, $dbAlias);

            return true;
        });

        return (bool)$result;
    }

    public function __rawMSet(array $items, int $ttl = 0): bool
    {
        if ($items === []) {
            return true;
        }

        $dbAlias = $this->segmentPublicResolveDb();

        $result = $this->segmentPublicWithSortedShardLocks(array_keys($items), function () use ($items, $ttl, $dbAlias): bool {
            foreach ($items as $key => $value) {
                [$mainKey, $paths] = $this->parseNestedKey($key);
                if ($paths !== null) {
                    $key = $mainKey;
                    $data = $this->segmentKvReadRaw($key, false, $dbAlias);
                    is_array($data) OR $data = [];
                    $this->setNestedValue($data, $paths, $value);
                    $value = $data;
                }

                try {
                    if (!$this->segmentKvWriteRaw($key, $value, $ttl, true, $dbAlias)) {
                        return false;
                    }
                } catch (Throwable) {
                    return false;
                }
            }

            return true;
        });

        return (bool)$result;
    }

    public function __rawMGet(array $keys, mixed $default = null): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->segmentPublicWithBatchReadLock(function () use ($keys, $default): array {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->__rawGet($key, $default);
            }

            return $result;
        }, array_fill_keys($keys, $default));
    }

    public function __rawMDelete(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $dbAlias = $this->segmentPublicResolveDb();

        $result = $this->segmentPublicWithSortedShardLocks($keys, function () use ($keys, $dbAlias): bool {
            foreach ($keys as $key) {
                [$mainKey, $paths] = $this->parseNestedKey($key);
                if ($paths !== null) {
                    $data = $this->segmentKvReadRaw($mainKey, true, $dbAlias);
                    if ($data === null) {
                        continue;
                    }
                    if (!is_array($data)) {
                        return false;
                    }

                    $nested = $this->unsetNestedInArray($data, $paths);
                    if (!$nested['changed']) {
                        continue;
                    }

                    $ttl = $this->segmentKvTtlUnlocked($mainKey, $dbAlias);
                    try {
                        if (!$this->segmentKvWriteRaw($mainKey, $nested['data'], $ttl > 0 ? $ttl : 0, true, $dbAlias)) {
                            return false;
                        }
                    } catch (Throwable) {
                        return false;
                    }
                    continue;
                }

                if (!$this->segmentKvDeleteFlat($key, $dbAlias)) {
                    return false;
                }
            }

            return true;
        });

        return (bool)$result;
    }

    public function __rawMHas(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->segmentPublicWithBatchReadLock(function () use ($keys): array {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->__rawHas($key);
            }

            return $result;
        }, array_fill_keys($keys, false));
    }

    public function __rawIncr(string $key, int $step, int $type): int
    {
        if (empty($key)) {
            return 0;
        }

        $dbAlias = $this->segmentPublicResolveDb();
        $result = $this->segmentPublicWithShardWriteLock($key, function () use ($key, $step, $type, $dbAlias): int {
            try {
                $num = $this->segmentKvReadRaw($key, false, $dbAlias);
                if ($num === null || !is_numeric($num)) {
                    $num = 0;
                }

                $num = $type > 0 ? max(0, (int)$num - $step) : (int)$num + $step;

                $this->segmentKvWriteRaw($key, $num, 0, true, $dbAlias);

                return (int)$num;
            } catch (Throwable) {
                return 0;
            }
        });

        return is_int($result) ? $result : 0;
    }

    public function __rawPush(string $key, array $args, bool $cover, int $head = 0): int
    {
        if (empty($key)) {
            return 0;
        }

        $dbAlias = $this->segmentPublicResolveDb();
        $result = $this->segmentPublicWithShardWriteLock($key, function () use ($key, $args, $cover, $head, $dbAlias): int {
            try {
                $queue = $this->segmentKvReadRaw($key, false, $dbAlias);
                is_array($queue) OR $queue = [];

                if ($cover) {
                    $queue = $args;
                } else {
                    $queue = $head === 0 ? array_merge($args, $queue) : array_merge($queue, $args);
                }

                $this->segmentKvWriteRaw($key, $queue, 0, true, $dbAlias);

                return count($queue);
            } catch (Throwable) {
                return 0;
            }
        });

        return is_int($result) ? $result : 0;
    }

    public function __rawPop(string $key, int $head = 0): mixed
    {
        if (empty($key)) {
            return null;
        }

        $dbAlias = $this->segmentPublicResolveDb();

        return $this->segmentPublicWithShardWriteLock($key, function () use ($key, $head, $dbAlias): mixed {
            try {
                $queue = $this->segmentKvReadRaw($key, false, $dbAlias);
                is_array($queue) OR $queue = [];

                if ($queue === []) {
                    return null;
                }

                $data = $head === 0 ? array_shift($queue) : array_pop($queue);

                if ($queue === []) {
                    $this->segmentKvDeleteFlat($key, $dbAlias);
                } else {
                    $this->segmentKvWriteRaw($key, $queue, 0, true, $dbAlias);
                }

                return $data;
            } catch (Throwable) {
                return null;
            }
        });
    }

    public function __rawRange(string $key, int $offset, int $length = 0, $sort = '', string $order = 'asc'): array|false
    {
        if (empty($key)) {
            return false;
        }

        $dbAlias = $this->segmentPublicResolveDb();
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        try {
            $ckey = $this->formatKey($key, $dbAlias);
            [$memKey, ,] = $this->getCrc32Key($key);
            $array = $this->getHotCache($ckey, $memKey, $dbAlias);
            if ($array === null) {
                $array = $this->segmentKvReadRaw($key, false, $dbAlias);
            }
            if ($array === null) {
                return false;
            }
            if ($paths !== null) {
                $array = $this->getNestedValue($array, $paths);
                if ($array === null) {
                    return false;
                }
            }
            if (!is_array($array) || $array === []) {
                return false;
            }

            if ($sort !== '' && $sort !== null) {
                usort($array, function ($a, $b) use ($sort, $order) {
                    if (!isset($a[$sort]) || !isset($b[$sort])) {
                        return 0;
                    }

                    $comparison = $a[$sort] <=> $b[$sort];

                    return $order === 'desc' ? -$comparison : $comparison;
                });
            }

            return $length === 0 ? $array : array_slice($array, $offset, $length);
        } catch (Throwable) {
            return false;
        }
    }

    protected function segmentKvDeleteFlat(string $key, ?string $dbAlias): bool
    {
        [$memKey, ,] = $this->getCrc32Key($key);
        $indexMap = $this->segmentIndexMap($dbAlias);

        if (!isset($indexMap[$memKey])) {
            return true;
        }

        $metaOrder = $indexMap[$memKey];
        $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
        if (!$meta || $meta['status'] !== 1) {
            return true;
        }

        $this->freeSpace($meta['offset'], $meta['block_length'], $dbAlias);
        $this->freeIndex($metaOrder, $dbAlias);

        $invalidMeta = $meta;
        $invalidMeta['status'] = 0;
        $this->writeMetaByOrder($metaOrder, $invalidMeta, $dbAlias);

        $ckey = $this->formatKey($key, $dbAlias);
        $this->deleteHotCache($ckey, $memKey, $dbAlias);
        $this->segmentKvRecordGcDelete($memKey, $metaOrder, $meta, $invalidMeta, $dbAlias);
        $this->segmentKvMarkDirty($key, $dbAlias);

        return true;
    }

    protected function segmentKvTtlUnlocked(string $key, ?string $dbAlias): int
    {
        [$memKey, ,] = $this->getCrc32Key($key);
        $indexMap = $this->segmentIndexMap($dbAlias);
        if (!isset($indexMap[$memKey])) {
            return -1;
        }

        $meta = $this->getMetaByOrder($indexMap[$memKey], $dbAlias);
        if (!$meta || $meta['status'] !== 1) {
            return -1;
        }
        if ($meta['timestamp'] === 0) {
            return 0;
        }

        $left = $meta['timestamp'] - time();

        return $left > 0 ? $left : -1;
    }

    protected function segmentKvUpdateTimestamp(string $key, int $timestamp, ?string $dbAlias): bool
    {
        [$memKey, ,] = $this->getCrc32Key($key);
        $indexMap = $this->segmentIndexMap($dbAlias);
        if (!isset($indexMap[$memKey])) {
            return false;
        }

        $metaOrder = $indexMap[$memKey];
        $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
        if (!$meta || $meta['status'] !== 1) {
            return false;
        }

        if ($meta['timestamp'] > 0 && $meta['timestamp'] < time()) {
            return false;
        }

        $meta['timestamp'] = $timestamp;
        $meta['last_use'] = time();
        if (!$this->writeMetaByOrder($metaOrder, $meta, $dbAlias)) {
            return false;
        }

        $ckey = $this->formatKey($key, $dbAlias);
        $this->updateHotCacheExpire($ckey, $meta['timestamp'], $dbAlias);

        return true;
    }

    protected function segmentKvRecordGcDelete(
        string $memKey,
        int $metaOrder,
        array $meta,
        array $invalidMeta,
        ?string $dbAlias
    ): void {
        $gcState = &$this->segmentGcStateRef($dbAlias);
        if (($gcState['status'] ?? '') === 'running') {
            $gcState['pendingWrites'][$memKey] = [
                'order' => $metaOrder,
                'meta' => $invalidMeta,
                'data' => null,
                'offset' => $meta['offset'],
            ];
        }
    }

    protected function segmentKvRenameGcPending(string $oldMemKey, string $newMemKey, ?string $dbAlias): void
    {
        $gcState = &$this->segmentGcStateRef($dbAlias);
        if (($gcState['status'] ?? '') === 'running' && isset($gcState['pendingWrites'][$oldMemKey])) {
            $gcState['pendingWrites'][$newMemKey] = $gcState['pendingWrites'][$oldMemKey];
            unset($gcState['pendingWrites'][$oldMemKey]);
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T|false
     */
    protected function segmentPublicWithSortedShardLocks(array $keys, callable $fn): mixed
    {
        $lockNames = [];
        foreach ($keys as $key) {
            [$mainKey, $paths] = $this->parseNestedKey($key);
            $lockNames[] = $this->getShardLockName($paths !== null ? $mainKey : $key);
        }

        $lockNames = array_values(array_unique($lockNames));
        sort($lockNames);

        $acquired = [];
        foreach ($lockNames as $lockName) {
            if (!$this->__acquireLock($lockName)) {
                foreach (array_reverse($acquired) as $released) {
                    $this->__releaseLock($released);
                }

                return false;
            }
            $acquired[] = $lockName;
        }

        try {
            return $fn();
        } finally {
            foreach (array_reverse($acquired) as $lockName) {
                $this->__releaseLock($lockName);
            }
        }
    }
}
