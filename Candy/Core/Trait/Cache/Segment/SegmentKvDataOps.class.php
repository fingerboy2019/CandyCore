<?php
/**
 * 分段存储驱动 — KV 数据层（序列化读写）
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

use Candy\Core\Impl\Pack\Binary;

trait SegmentKvDataOps
{
    use SegmentFormat;
    use SegmentKeyCodec;

    protected function segmentKvCleanupInvalidMeta(string $memKey, int $metaOrder, ?string $dbAlias = null): void
    {
        $indexMap = &$this->segmentIndexMap($dbAlias);
        if (!isset($indexMap[$memKey])) {
            return;
        }

        $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
        if (!$meta || $meta['status'] !== 1) {
            return;
        }

        $this->freeSpace($meta['offset'], $meta['block_length'], $dbAlias);
        $this->freeIndex($metaOrder, $dbAlias);

        $invalidMeta = $meta;
        $invalidMeta['status'] = 0;
        $this->writeMetaByOrder($metaOrder, $invalidMeta, $dbAlias);
    }

    protected function segmentKvReadRaw(string $key, bool $updateCache = false, ?string $dbAlias = null): mixed
    {
        [$memKey, ,] = $this->getCrc32Key($key);
        $indexMap = $this->segmentIndexMap($dbAlias);

        if (!isset($indexMap[$memKey])) {
            return null;
        }

        $metaOrder = $indexMap[$memKey];
        $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
        if (!$meta || $meta['status'] !== 1) {
            return null;
        }

        if ($meta['timestamp'] > 0 && $meta['timestamp'] < time()) {
            return null;
        }

        $rawData = $this->segmentReadBytes($meta['offset'], $meta['string_length'], $dbAlias);
        if ($rawData === false) {
            $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);
            return null;
        }

        $dataCrc32 = crc32($rawData);
        if (isset($meta['data_crc32']) && $meta['data_crc32'] !== $dataCrc32) {
            $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);
            return null;
        }

        $unpacked = Binary::tryUnpack($rawData);
        if ($unpacked === null) {
            $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);
            return null;
        }
        $result = is_array($unpacked) && array_key_exists('data', $unpacked)
            ? $unpacked['data']
            : null;

        if ($result !== null && $updateCache) {
            $this->segmentKvUpdateHotCache($key, $memKey, $result, $meta['timestamp'], $dbAlias);
        }

        return $result;
    }

    protected function segmentKvWriteRaw(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $updateCache = false,
        ?string $dbAlias = null
    ): bool {
        [$memKey, $crc32, $vCrc32] = $this->getCrc32Key($key);

        $serializedData = Binary::pack(['data' => $value]);
        $dataLength = strlen($serializedData);
        $dataCrc32 = crc32($serializedData);

        $gcState = &$this->segmentGcStateRef($dbAlias);
        if (($gcState['status'] ?? '') === 'running' && ($gcState['currentOffset'] ?? 0) < count($gcState['validItems'] ?? [])) {
            $gcState['pendingWrites'][$memKey] = [
                'order' => null,
                'meta' => null,
                'data' => $serializedData,
                'offset' => null,
            ];
        }

        $indexMap = $this->segmentIndexMap($dbAlias);
        $metaOrder = $indexMap[$memKey] ?? null;
        $meta = $metaOrder !== null ? $this->getMetaByOrder($metaOrder, $dbAlias) : false;
        $isValid = $meta && $meta['status'] === 1;
        $newMeta = [];
        $dataOffset = null;
        $useMetaOrder = $metaOrder;

        if ($isValid && $meta['block_length'] >= $dataLength) {
            $this->segmentWriteBytes($meta['offset'], $serializedData, $dbAlias);
            $newMeta = [
                'status' => 1,
                'timestamp' => $ttl > 0 ? time() + $ttl : 0,
                'block_length' => $meta['block_length'],
                'string_length' => $dataLength,
                'offset' => $meta['offset'],
                'last_use' => time(),
                'data_crc32' => $dataCrc32,
            ];
            $this->writeMetaByOrder($metaOrder, $newMeta, $dbAlias);
            $this->updateIndex($memKey, $crc32, $vCrc32, $metaOrder, $dbAlias);
            $dataOffset = $meta['offset'];
        } else {
            $dataOffset = $this->allocateSpace($dataLength, $dbAlias);
            if ($dataOffset === false) {
                $this->segmentGcCollectSpace($key, $dataLength, $dbAlias);
                $dataOffset = $this->allocateSpace($dataLength, $dbAlias);
                if ($dataOffset === false) {
                    throw new \RuntimeException('分段存储空间已满');
                }
            }

            $this->segmentWriteBytes($dataOffset, $serializedData, $dbAlias);

            $blockLength = $meta ? max($meta['block_length'], $dataLength) : $dataLength;
            $newMeta = [
                'status' => 1,
                'timestamp' => $ttl > 0 ? time() + $ttl : 0,
                'block_length' => $blockLength,
                'string_length' => $dataLength,
                'offset' => $dataOffset,
                'last_use' => time(),
                'data_crc32' => $dataCrc32,
            ];

            $useMetaOrder = $metaOrder ?? $this->findFreeMetaOrder($dbAlias);
            if ($useMetaOrder === false) {
                throw new \RuntimeException('索引槽位已满');
            }

            if ($metaOrder === null || $useMetaOrder !== $metaOrder) {
                $this->removeFreeIndex($useMetaOrder, $dbAlias);
            }

            $this->writeMetaByOrder($useMetaOrder, $newMeta, $dbAlias);
            $this->updateIndex($memKey, $crc32, $vCrc32, $useMetaOrder, $dbAlias);

            if ($meta) {
                $this->freeSpace($meta['offset'], $meta['block_length'], $dbAlias);
            }
        }

        if (($gcState['status'] ?? '') === 'running' && isset($gcState['pendingWrites'][$memKey])) {
            $gcState['pendingWrites'][$memKey]['meta'] = $newMeta;
            $gcState['pendingWrites'][$memKey]['offset'] = $useMetaOrder !== $metaOrder ? $dataOffset : ($meta['offset'] ?? $dataOffset);
            $gcState['pendingWrites'][$memKey]['order'] = $useMetaOrder;
        }

        if ($updateCache) {
            $expireTimestamp = $ttl > 0 ? time() + $ttl : 0;
            $this->segmentKvUpdateHotCache($key, $memKey, $value, $expireTimestamp, $dbAlias);
        }

        $this->segmentKvMarkDirty($key, $dbAlias);

        return true;
    }

    protected function segmentKvUpdateHotCache(
        string $key,
        string $memKey,
        mixed $value,
        int $expireTimestamp,
        ?string $dbAlias = null
    ): void {
        $ckey = $this->formatKey($key, $dbAlias);
        $this->setHotCache($ckey, $memKey, $value, $expireTimestamp, $dbAlias);
        $this->touchLru($memKey, $ckey, $dbAlias);
    }

    protected function segmentKvMarkDirty(string $key, ?string $dbAlias = null): void
    {
    }

    protected function segmentValidateMeta(array $meta): bool
    {
        if ($meta['status'] !== 1) {
            return false;
        }

        if ($meta['timestamp'] > 0 && $meta['timestamp'] < time()) {
            return false;
        }

        return true;
    }
}
