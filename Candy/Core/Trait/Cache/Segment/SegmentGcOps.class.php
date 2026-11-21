<?php
/**
 * 分段存储驱动 — GC 空间回收
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

trait SegmentGcOps
{
    use SegmentFormat;

    abstract protected function segmentMetaAreaSize(?string $dbAlias = null): int;

    abstract protected function segmentGcTryBegin(string $excludeKey, int $needLength, ?string $dbAlias = null): bool;

    abstract protected function segmentGcFinish(?string $dbAlias = null): void;

    abstract protected function segmentGcRebuildLru(?string $dbAlias = null): void;

    protected function segmentGcInitState(?string $dbAlias = null): void
    {
        $gcState = &$this->segmentGcStateRef($dbAlias);
        $gcState = [
            'status' => 'idle',
            'processedCount' => 0,
            'totalCount' => 0,
            'validItems' => [],
            'newIndexMap' => [],
            'currentOffset' => 0,
            'pendingWrites' => [],
        ];
    }

    /**
     * @return array{0: false|int, 1: false|int}
     */
    protected function collectSpace(string $excludeKey = '', int $needLength = 0, ?string $dbAlias = null): array
    {
        if (!$this->segmentGcTryBegin($excludeKey, $needLength, $dbAlias)) {
            return [false, false];
        }

        try {
            $now = time();
            $excludeMemKey = '';
            if ($excludeKey !== '') {
                [$excludeMemKey, ,] = $this->getCrc32Key($excludeKey);
            }

            $indexMap = $this->segmentIndexMap($dbAlias);
            $validItems = [];
            foreach ($indexMap as $memKey => $metaOrder) {
                if ($memKey === $excludeMemKey) {
                    continue;
                }
                $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
                if (!$meta || $meta['status'] !== 1) {
                    continue;
                }
                if ($meta['timestamp'] > 0 && $meta['timestamp'] < $now) {
                    continue;
                }

                $chunk = $this->segmentReadBytes($meta['offset'], $meta['string_length'], $dbAlias);
                if ($chunk === false) {
                    continue;
                }

                $validItems[] = [
                    'mem_key' => $memKey,
                    'meta' => $meta,
                    'data' => $chunk,
                    'meta_order' => $metaOrder,
                ];
            }

            $maxVarNumber = $this->segmentMaxVarNumber($dbAlias);
            if (count($validItems) > $maxVarNumber * 0.8) {
                uasort(
                    $validItems,
                    static fn(array $a, array $b): int => ($a['meta']['last_use'] ?? 0) <=> ($b['meta']['last_use'] ?? 0)
                );
                $validItems = array_slice($validItems, (int)(count($validItems) * 0.2), preserve_keys: true);
            }

            $bitmap = &$this->segmentBitmap($dbAlias);
            $bitmap = str_repeat("\0", $this->segmentBitmapAreaSize($dbAlias));

            $indexMapRef = &$this->segmentIndexMap($dbAlias);
            $indexMapRef = [];
            $metaToMemKey = &$this->segmentMetaToMemKey($dbAlias);
            $metaToMemKey = [];
            $freeMetaOrders = &$this->segmentFreeMetaOrders($dbAlias);
            $freeMetaOrders = [];
            $this->clearMetaCache($dbAlias);

            for ($i = 0; $i < $maxVarNumber; $i++) {
                $freeMetaOrders[] = $i;
            }
            $freeMetaOrders = array_reverse($freeMetaOrders);

            $metaString = '';
            $blockSize = $this->segmentBlockSize($dbAlias);
            $dataAreaStart = $this->segmentDataAreaStart($dbAlias);
            $newIndexMap = [];

            foreach ($validItems as $item) {
                $memKey = $item['mem_key'];
                $chunk = $item['data'];
                $oldMeta = $item['meta'];

                $needBlockCount = (int)ceil(strlen($chunk) / $blockSize);
                $startBit = $this->findBestFitBlocks($needBlockCount, $dbAlias);
                if ($startBit === false) {
                    continue;
                }

                for ($i = 0; $i < $needBlockCount; $i++) {
                    $this->bitSet($startBit + $i, true, $dbAlias);
                }

                $newOffset = $dataAreaStart + $startBit * $blockSize;
                if ($freeMetaOrders === []) {
                    continue;
                }
                $useMetaOrder = array_pop($freeMetaOrders);

                $newMetaItem = [
                    'status' => 1,
                    'timestamp' => $oldMeta['timestamp'],
                    'block_length' => strlen($chunk),
                    'string_length' => strlen($chunk),
                    'offset' => $newOffset,
                    'last_use' => $oldMeta['last_use'] ?? $now,
                    'data_crc32' => crc32($chunk),
                ];

                $metaBin = pack(self::SEG_META_FORMAT, ...array_values($newMetaItem));
                $metaString .= str_pad($metaBin, self::SEG_META_LENGTH, "\0");

                $this->segmentWriteBytes($newOffset, $chunk, $dbAlias);

                $newIndexMap[$memKey] = [
                    'order' => $useMetaOrder,
                    'meta' => $newMetaItem,
                ];
            }

            $this->segmentPersistBitmap($dbAlias);

            $metaString = str_pad($metaString, $this->segmentMetaAreaSize($dbAlias), "\0");
            $metaOffset = self::SEG_HEADER_SIZE
                + $this->segmentIndexAreaFixedSize($dbAlias)
                + $this->segmentBitmapAreaSize($dbAlias);
            $this->segmentWriteBytes($metaOffset, $metaString, $dbAlias);

            foreach ($newIndexMap as $memKey => $info) {
                [$crc32, $vCrc32] = array_map('intval', explode('_', (string)$memKey, 2));
                $this->updateIndex((string)$memKey, $crc32, $vCrc32, $info['order'], $dbAlias);
                $this->addMetaToCache($info['order'], $info['meta'], $dbAlias);
            }

            $this->segmentGcMergePendingWrites($dbAlias);
            $this->segmentGcRebuildLru($dbAlias);

            return [false, false];
        } finally {
            $this->segmentGcFinish($dbAlias);
        }
    }

    protected function segmentGcMergePendingWrites(?string $dbAlias = null): void
    {
        $gcState = &$this->segmentGcStateRef($dbAlias);
        if (($gcState['pendingWrites'] ?? []) === []) {
            return;
        }

        foreach ($gcState['pendingWrites'] as $memKey => $writeInfo) {
            if (!isset($writeInfo['meta'], $writeInfo['data'])) {
                continue;
            }

            $useMetaOrder = $writeInfo['order'] ?? $this->findFreeMetaOrder($dbAlias);
            if ($useMetaOrder === false) {
                continue;
            }

            $this->removeFreeIndex($useMetaOrder, $dbAlias);

            if (isset($writeInfo['offset'])) {
                $this->segmentWriteBytes((int)$writeInfo['offset'], $writeInfo['data'], $dbAlias);
            }

            $this->writeMetaByOrder($useMetaOrder, $writeInfo['meta'], $dbAlias);

            [$crc32, $vCrc32] = array_map('intval', explode('_', (string)$memKey, 2));
            $this->updateIndex((string)$memKey, $crc32, $vCrc32, $useMetaOrder, $dbAlias);
        }
    }
}
