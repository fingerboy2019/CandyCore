<?php
/**
 * 分段存储驱动 — 索引槽位 / 元数据缓存
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

trait SegmentMetaIndex
{
    use SegmentFormat;

    /** @return array<string, int> */
    abstract protected function &segmentIndexMap(?string $dbAlias = null): array;

    /** @return array<int, string> */
    abstract protected function &segmentMetaToMemKey(?string $dbAlias = null): array;

    /** @return list<int> */
    abstract protected function &segmentFreeMetaOrders(?string $dbAlias = null): array;

    /**
     * @return array<int, array{timestamp: int, data: array<string, int>}>
     */
    abstract protected function &segmentMetadataCache(?string $dbAlias = null): array;

    /** @return array<int, float> */
    abstract protected function &segmentMetaLRU(?string $dbAlias = null): array;

    abstract protected function segmentMaxVarNumber(?string $dbAlias = null): int;

    abstract protected function segmentIndexAreaFixedSize(?string $dbAlias = null): int;

    abstract protected function segmentBitmapAreaSize(?string $dbAlias = null): int;

    abstract protected function segmentReadBytes(int $offset, int $length, ?string $dbAlias = null): string|false;

    abstract protected function segmentWriteBytes(int $offset, string $data, ?string $dbAlias = null): bool;

    abstract protected function segmentGcCollectSpace(string $key, int $needLength, ?string $dbAlias = null): void;

    /** @return array<string, mixed> */
    abstract protected function &segmentGcStateRef(?string $dbAlias = null): array;

    protected function findFreeMetaOrder(?string $dbAlias = null): int|false
    {
        $freeMetaOrders = &$this->segmentFreeMetaOrders($dbAlias);
        if ($freeMetaOrders !== []) {
            return array_pop($freeMetaOrders);
        }

        $indexMap = $this->segmentIndexMap($dbAlias);
        $usedOrders = array_flip($indexMap);
        $maxVarNumber = $this->segmentMaxVarNumber($dbAlias);

        for ($metaOrder = 0; $metaOrder < $maxVarNumber; $metaOrder++) {
            if (!isset($usedOrders[$metaOrder])) {
                return $metaOrder;
            }
        }

        $this->segmentGcCollectSpace('', 0, $dbAlias);

        if ($freeMetaOrders !== []) {
            return array_pop($freeMetaOrders);
        }

        return false;
    }

    protected function removeFreeIndex(int $metaOrder, ?string $dbAlias = null): void
    {
        $freeMetaOrders = &$this->segmentFreeMetaOrders($dbAlias);
        $freeKey = array_search($metaOrder, $freeMetaOrders, true);
        if ($freeKey !== false) {
            unset($freeMetaOrders[$freeKey]);
            $freeMetaOrders = array_values($freeMetaOrders);
        }
    }

    protected function freeIndex(int $metaOrder, ?string $dbAlias = null): void
    {
        $this->segmentWriteBytes(
            $this->segmentIndexEntryOffset($metaOrder),
            $this->segmentPackFreeIndex(),
            $dbAlias
        );

        $metaToMemKey = &$this->segmentMetaToMemKey($dbAlias);
        if (isset($metaToMemKey[$metaOrder])) {
            $memKey = $metaToMemKey[$metaOrder];
            $indexMap = &$this->segmentIndexMap($dbAlias);
            $metadataCache = &$this->segmentMetadataCache($dbAlias);
            unset($indexMap[$memKey], $metadataCache[$metaOrder], $metaToMemKey[$metaOrder]);
        }

        $freeMetaOrders = &$this->segmentFreeMetaOrders($dbAlias);
        $freeMetaOrders[] = $metaOrder;
    }

    protected function updateIndex(string $memKey, int $crc32, int $vCrc32, int $metaOrder, ?string $dbAlias = null): void
    {
        $this->segmentWriteBytes(
            $this->segmentIndexEntryOffset($metaOrder),
            $this->segmentPackIndex($crc32, $vCrc32, $metaOrder),
            $dbAlias
        );

        $indexMap = &$this->segmentIndexMap($dbAlias);
        $metaToMemKey = &$this->segmentMetaToMemKey($dbAlias);
        $indexMap[$memKey] = $metaOrder;
        $metaToMemKey[$metaOrder] = $memKey;

        $gcState = &$this->segmentGcStateRef($dbAlias);
        if (($gcState['status'] ?? '') === 'running' && isset($gcState['pendingWrites'][$memKey])) {
            $gcState['pendingWrites'][$memKey]['order'] = $metaOrder;
        }

        $this->removeFreeIndex($metaOrder, $dbAlias);
    }

    protected function getMetaByOrder(int $metaOrder, ?string $dbAlias = null): array|false
    {
        $metadataCache = &$this->segmentMetadataCache($dbAlias);
        if (isset($metadataCache[$metaOrder])) {
            $cacheItem = $metadataCache[$metaOrder];
            if (isset($cacheItem['timestamp'], $cacheItem['data']) && time() - $cacheItem['timestamp'] <= self::SEG_META_CACHE_TTL) {
                $this->touchMetaLRU($metaOrder, $dbAlias);
                return $cacheItem['data'];
            }
            unset($metadataCache[$metaOrder]);
        }

        $metaOffset = $this->segmentMetaOffset(
            $metaOrder,
            $this->segmentIndexAreaFixedSize($dbAlias),
            $this->segmentBitmapAreaSize($dbAlias)
        );
        $metaBin = $this->segmentReadBytes($metaOffset, self::SEG_META_LENGTH, $dbAlias);
        if ($metaBin === false) {
            return false;
        }

        $meta = $this->segmentUnpackMeta($metaBin);
        if ($meta === false) {
            return false;
        }

        $this->addMetaToCache($metaOrder, $meta, $dbAlias);

        return $meta;
    }

    protected function writeMetaByOrder(int $metaOrder, array $meta, ?string $dbAlias = null): bool
    {
        $metaOffset = $this->segmentMetaOffset(
            $metaOrder,
            $this->segmentIndexAreaFixedSize($dbAlias),
            $this->segmentBitmapAreaSize($dbAlias)
        );
        $result = $this->segmentWriteBytes($metaOffset, $this->segmentPackMeta($meta), $dbAlias);

        if ($result) {
            $this->addMetaToCache($metaOrder, $meta, $dbAlias);

            $gcState = &$this->segmentGcStateRef($dbAlias);
            if (($gcState['status'] ?? '') === 'running') {
                foreach ($gcState['pendingWrites'] as &$pendingWrite) {
                    if (($pendingWrite['order'] ?? null) === $metaOrder) {
                        $pendingWrite['meta'] = $meta;
                        $pendingWrite['offset'] = $meta['offset'];
                        break;
                    }
                }
                unset($pendingWrite);
            }
        }

        return $result;
    }

    protected function clearMetaCache(?string $dbAlias = null): void
    {
        $metadataCache = &$this->segmentMetadataCache($dbAlias);
        $metadataCache = [];
    }

    protected function addMetaToCache(int $metaOrder, array $meta, ?string $dbAlias = null): void
    {
        $metadataCache = &$this->segmentMetadataCache($dbAlias);
        if (count($metadataCache) >= self::SEG_MAX_META_CACHE_COUNT) {
            $this->evictMetaLRU($dbAlias);
        }

        $metadataCache[$metaOrder] = [
            'timestamp' => time(),
            'data' => $meta,
        ];
    }

    protected function touchMetaLRU(int $metaOrder, ?string $dbAlias = null): void
    {
        $metaLRU = &$this->segmentMetaLRU($dbAlias);
        if ($metaLRU !== []) {
            if (isset($metaLRU[$metaOrder])) {
                unset($metaLRU[$metaOrder]);
            }
            $metaLRU[$metaOrder] = microtime(true);
            return;
        }

        $metadataCache = &$this->segmentMetadataCache($dbAlias);
        if (isset($metadataCache[$metaOrder])) {
            $metadataCache[$metaOrder]['timestamp'] = time();
        }
    }

    protected function evictMetaLRU(?string $dbAlias = null): void
    {
        $metadataCache = &$this->segmentMetadataCache($dbAlias);
        $metaLRU = &$this->segmentMetaLRU($dbAlias);

        if ($metaLRU !== []) {
            $oldestOrder = array_search(min($metaLRU), $metaLRU, true);
            if ($oldestOrder !== false) {
                unset($metadataCache[$oldestOrder], $metaLRU[$oldestOrder]);
            }
            return;
        }

        $oldestOrder = array_key_first($metadataCache);
        if ($oldestOrder !== null) {
            unset($metadataCache[$oldestOrder]);
        }
    }
}
