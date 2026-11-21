<?php
/**
 * 分段存储驱动 — 位图 KV 操作（__rawBit / RW / Count / Pos）
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

use Throwable;

trait SegmentBitmapKvOps
{
    use SegmentFormat;
    use SegmentKeyCodec;
    use SegmentBitmapHelpers;

    abstract protected function segmentPublicResolveDb(): ?string;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T|false
     */
    abstract protected function segmentBitWithWriteLock(string $key, callable $fn, ?string $dbAlias = null): mixed;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T|false
     */
    abstract protected function segmentBitWithReadLock(string $key, callable $fn, ?string $dbAlias = null): mixed;

    abstract protected function segmentBlockSize(?string $dbAlias = null): int;

    abstract protected function segmentBitOp(
        string $key,
        string $opType,
        ?string $destKey,
        int|array $id,
        ?array $sourceKeys,
        ?string $dbAlias,
        int $expire
    ): int;

    protected function segmentBitHotCacheGet(string $ckey, string $memKey, ?string $dbAlias): ?string
    {
        $cached = $this->getHotCache($ckey, $memKey, $dbAlias);
        if ($cached === null) {
            return null;
        }

        return is_string($cached) ? $cached : '';
    }

    protected function segmentBitHotCacheSet(string $ckey, string $memKey, string $bitString, ?string $dbAlias): void
    {
        $this->setHotCache($ckey, $memKey, $bitString, 0, $dbAlias);
    }

    protected function segmentLoadBitString(string $key, ?string $dbAlias = null): string
    {
        if (empty($key)) {
            return '';
        }

        [$memKey, ,] = $this->getCrc32Key($key);
        $ckey = $this->formatKey($key, $dbAlias);

        $cachedBitString = $this->segmentBitHotCacheGet($ckey, $memKey, $dbAlias);
        if ($cachedBitString !== null) {
            return $cachedBitString;
        }

        $indexMap = $this->segmentIndexMap($dbAlias);
        $metaOrder = $indexMap[$memKey] ?? false;
        if ($metaOrder === false) {
            return '';
        }

        $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
        if (!$this->segmentValidateMeta($meta)) {
            return '';
        }

        $bitString = $this->segmentReadBytes($meta['offset'], $meta['string_length'], $dbAlias);
        if ($bitString === false || strlen($bitString) < $meta['string_length']) {
            $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);

            return '';
        }

        $bitDataCrc32 = crc32($bitString);
        if (isset($meta['data_crc32']) && $meta['data_crc32'] !== $bitDataCrc32) {
            $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);

            return '';
        }

        $this->segmentBitHotCacheSet($ckey, $memKey, $bitString, $dbAlias);

        return $bitString;
    }

    public function __rawBit(string $key, int|array $id, mixed $options = null, int $expire = 0): bool|int|array
    {
        $dbAlias = $this->segmentPublicResolveDb();

        $operation = 'rw';
        $opType = null;
        $destKey = null;
        $sourceKeys = null;
        $posValue = null;

        if (is_string($options)) {
            $operation = strtolower($options);
            if (!in_array($operation, ['rw', 'read', 'write', 'count', 'op', 'pos'], true)) {
                showExceptionMsg('shutdown', '[Segment__rawBit] 操作类型无效，允许值：rw/read/write/count/op/pos，当前值：%s', $options);
            }
        } elseif (is_array($options)) {
            if (isset($options['operation'])) {
                $opType = strtoupper((string)$options['operation']);
                if ($opType === 'COUNT') {
                    $operation = 'count';
                } elseif ($opType === 'POS') {
                    $operation = 'pos';
                    $posValue = (int)($options['value'] ?? 1);
                } elseif (in_array($opType, ['AND', 'OR', 'XOR', 'NOT'], true)) {
                    $operation = 'op';
                    $destKey = $options['destKey'] ?? null;
                    $sourceKeys = $options['sourceKeys'] ?? null;
                    if ($opType !== 'NOT' && $destKey === null && empty($sourceKeys)) {
                        showExceptionMsg('shutdown', '[Segment__rawBit] op操作（除NOT外）必须指定destKey或sourceKeys');
                    }
                } else {
                    showExceptionMsg('shutdown', '[Segment__rawBit] 位图运算类型无效，允许值：AND/OR/XOR/NOT/COUNT/POS，当前值：%s', $opType);
                }
            } elseif (isset($options['value'])) {
                $operation = 'pos';
                $posValue = (int)$options['value'];
                if (!in_array($posValue, [0, 1], true)) {
                    showExceptionMsg('shutdown', '[Segment__rawBit] pos操作value参数必须为0或1');
                }
            }
        } elseif ($options !== null && !is_bool($options)) {
            showExceptionMsg('shutdown', '[Segment__rawBit] options参数类型无效，允许：null/bool/string/array');
        }

        return match ($operation) {
            'count' => $this->segmentRawBitCount($key, $id, $dbAlias),
            'pos' => $this->segmentRawBitPos($key, $id, $posValue ?? 1, $dbAlias),
            'op' => $this->segmentBitOp($key, $opType ?? 'OR', $destKey, $id, $sourceKeys, $dbAlias, $expire),
            default => $this->segmentRawBitRW($key, $id, $options, $expire, $dbAlias),
        };
    }

    private function segmentRawBitRW(
        string $key,
        int|array $id,
        mixed $status,
        int $expire,
        ?string $dbAlias
    ): bool|int|array {
        if (empty($key)) {
            return is_int($id) ? 0 : (is_array($id) ? array_fill(0, count($id), 0) : false);
        }

        $idList = is_array($id) ? $id : [$id];
        foreach ($idList as $offset) {
            if (!is_int($offset) || $offset < 0) {
                showExceptionMsg('shutdown', '[Segment__rawBit] Bit偏移量必须为非负整数，当前值：%s', var_export($offset, true));
            }
        }
        if (!is_null($status) && !is_bool($status)) {
            showExceptionMsg('shutdown', '[Segment__rawBit] status参数仅允许bool|null类型');
        }

        $isQuery = is_null($status);
        $singleId = is_int($id);
        [$memKey, $crc32, $vCrc32] = $this->getCrc32Key($key);
        $ckey = $this->formatKey($key, $dbAlias);
        $indexMap = &$this->segmentIndexMap($dbAlias);
        $metaOrder = $indexMap[$memKey] ?? false;

        try {
            if ($isQuery) {
                $readResult = $this->segmentBitWithReadLock($key, function () use (
                    $key,
                    $idList,
                    $singleId,
                    $ckey,
                    $memKey,
                    $metaOrder,
                    $dbAlias
                ): array|int {
                    $cachedBitString = $this->segmentBitHotCacheGet($ckey, $memKey, $dbAlias);
                    if ($cachedBitString !== null) {
                        $result = $this->segmentCalcBitResult($cachedBitString, $idList);

                        return $singleId ? $result[0] : $result;
                    }

                    if ($metaOrder === false) {
                        $result = array_fill(0, count($idList), 0);

                        return $singleId ? $result[0] : $result;
                    }

                    $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
                    if (!$this->segmentValidateMeta($meta)) {
                        $result = array_fill(0, count($idList), 0);

                        return $singleId ? $result[0] : $result;
                    }

                    $bitString = $this->segmentReadBytes($meta['offset'], $meta['string_length'], $dbAlias);
                    if ($bitString === false || strlen($bitString) < $meta['string_length']) {
                        $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);
                        $result = array_fill(0, count($idList), 0);

                        return $singleId ? $result[0] : $result;
                    }

                    $bitDataCrc32 = crc32($bitString);
                    if (isset($meta['data_crc32']) && $meta['data_crc32'] !== $bitDataCrc32) {
                        $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);
                        $result = array_fill(0, count($idList), 0);

                        return $singleId ? $result[0] : $result;
                    }

                    $this->segmentBitHotCacheSet($ckey, $memKey, $bitString, $dbAlias);
                    $result = $this->segmentCalcBitResult($bitString, $idList);

                    return $singleId ? $result[0] : $result;
                }, $dbAlias);

                if ($readResult === false) {
                    return $singleId ? 0 : array_fill(0, count($idList), 0);
                }

                return $readResult;
            }

            $writeResult = $this->segmentBitWithWriteLock($key, function () use (
                $key,
                $idList,
                $singleId,
                $status,
                $expire,
                $ckey,
                $memKey,
                $crc32,
                $vCrc32,
                $dbAlias,
                &$metaOrder
            ): bool|int|array {
                $meta = [];
                $bitString = '';

                if ($metaOrder !== false) {
                    $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
                    if (!$this->segmentValidateMeta($meta)) {
                        $this->freeIndex($metaOrder, $dbAlias);
                        $this->freeSpace($meta['offset'], $meta['block_length'], $dbAlias);
                        $metaOrder = false;
                    } else {
                        $bitString = $this->segmentReadBytes($meta['offset'], $meta['string_length'], $dbAlias);
                        if ($bitString === false || strlen($bitString) < $meta['string_length']) {
                            $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);
                            $metaOrder = false;
                        } else {
                            $bitDataCrc32 = crc32($bitString);
                            if (isset($meta['data_crc32']) && $meta['data_crc32'] !== $bitDataCrc32) {
                                $this->segmentKvCleanupInvalidMeta($memKey, $metaOrder, $dbAlias);
                                $metaOrder = false;
                            }
                        }
                    }
                }

                if ($metaOrder === false) {
                    $metaOrder = $this->findFreeMetaOrder($dbAlias);
                    if ($metaOrder === false) {
                        return false;
                    }

                    $maxOffset = max($idList);
                    $initLength = (int)ceil(($maxOffset + 1) / self::SEG_BITS_PER_BYTE);
                    $bitString = str_repeat("\0", $initLength);

                    $offset = $this->allocateSpace($initLength, $dbAlias);
                    if ($offset === false) {
                        return false;
                    }

                    $bitDataCrc32 = crc32($bitString);
                    $meta = [
                        'status' => 1,
                        'timestamp' => $expire > 0 ? time() + $expire : 0,
                        'block_length' => $initLength,
                        'string_length' => $initLength,
                        'offset' => $offset,
                        'last_use' => time(),
                        'data_crc32' => $bitDataCrc32,
                    ];
                    $this->writeMetaByOrder($metaOrder, $meta, $dbAlias);
                    $this->updateIndex($memKey, $crc32, $vCrc32, $metaOrder, $dbAlias);
                } elseif ($expire > 0 || $meta['timestamp'] > 0) {
                    $meta['timestamp'] = $expire > 0 ? time() + $expire : 0;
                    $meta['last_use'] = time();
                    $this->writeMetaByOrder($metaOrder, $meta, $dbAlias);
                }

                $maxOffset = max($idList);
                $needLength = (int)ceil(($maxOffset + 1) / self::SEG_BITS_PER_BYTE);
                if ($needLength > strlen($bitString)) {
                    $bitString .= str_repeat("\0", $needLength - strlen($bitString));
                    $blockSize = $this->segmentBlockSize($dbAlias);
                    $oldBlockLength = $meta['block_length'];
                    $newBlockCount = (int)ceil($needLength / $blockSize);
                    $oldBlockCount = (int)ceil($oldBlockLength / $blockSize);

                    if ($newBlockCount > $oldBlockCount) {
                        // 先断言物理可行，避免「释放旧块后分配失败」导致 meta 指向已释放块
                        $this->assertSegmentValueFits($needLength, $dbAlias);
                        $this->freeSpace($meta['offset'], $oldBlockLength, $dbAlias);
                        $newOffset = $this->allocateSpace($needLength, $dbAlias);
                        if ($newOffset === false) {
                            return false;
                        }
                        $meta['offset'] = $newOffset;
                        $meta['block_length'] = $needLength;
                        $meta['string_length'] = $needLength;
                        $this->writeMetaByOrder($metaOrder, $meta, $dbAlias);
                    }
                }

                foreach ($idList as $offset) {
                    $byteIndex = (int)($offset / self::SEG_BITS_PER_BYTE);
                    $bitInByte = $offset % self::SEG_BITS_PER_BYTE;
                    $byte = ord($bitString[$byteIndex]);

                    if ($status) {
                        $byte |= (1 << $bitInByte);
                    } else {
                        $byte &= ~(1 << $bitInByte);
                    }
                    $bitString[$byteIndex] = chr($byte);
                }

                if (!$this->segmentWriteBytes($meta['offset'], $bitString, $dbAlias)) {
                    return false;
                }

                $meta['data_crc32'] = crc32($bitString);
                $meta['string_length'] = strlen($bitString);
                $meta['block_length'] = max($meta['block_length'], strlen($bitString));
                $meta['last_use'] = time();
                $this->writeMetaByOrder($metaOrder, $meta, $dbAlias);
                $this->segmentBitHotCacheSet($ckey, $memKey, $bitString, $dbAlias);
                $this->segmentKvMarkDirty($key, $dbAlias);

                return $singleId ? true : (count($idList) > 0);
            }, $dbAlias);

            if ($writeResult === false) {
                return false;
            }

            return $writeResult;
        } catch (Throwable $e) {
            showExceptionMsg('shutdown', sprintf(
                '[Segment__rawBit] 异常：%s | Key=%s | ID=%s | Status=%s',
                $e->getMessage(),
                $key,
                var_export($id, true),
                var_export($status, true)
            ));

            return is_int($id) ? 0 : false;
        }
    }

    private function segmentRawBitCount(string $key, int|array $id, ?string $dbAlias): int
    {
        if (empty($key)) {
            return 0;
        }

        $idList = is_array($id) ? $id : [$id];
        $start = $idList[0] ?? 0;
        $end = $idList[1] ?? -1;

        $countResult = $this->segmentBitWithReadLock($key, function () use ($key, $start, $end, $dbAlias): int {
            $bitString = $this->segmentLoadBitString($key, $dbAlias);

            return $this->segmentCountBitsInRange($bitString, $start, $end);
        }, $dbAlias);

        return $countResult === false ? 0 : $countResult;
    }

    private function segmentRawBitPos(string $key, int|array $id, int $posValue, ?string $dbAlias): int
    {
        if (empty($key)) {
            return -1;
        }

        if (is_array($id) && count($id) >= 2) {
            $start = (int)$id[0];
            $end = (int)$id[1];
        } else {
            $start = is_int($id) ? $id : 0;
            $end = -1;
        }

        $posResult = $this->segmentBitWithReadLock($key, function () use ($key, $start, $end, $posValue, $dbAlias): int {
            $bitString = $this->segmentLoadBitString($key, $dbAlias);
            if ($bitString === '') {
                return -1;
            }

            $totalBits = strlen($bitString) * self::SEG_BITS_PER_BYTE;
            $endOffset = ($end >= 0) ? min($end, $totalBits - 1) : $totalBits - 1;

            for ($offset = $start; $offset <= $endOffset; $offset++) {
                $byteIndex = (int)($offset / self::SEG_BITS_PER_BYTE);
                $bitInByte = $offset % self::SEG_BITS_PER_BYTE;
                if ($byteIndex < strlen($bitString)) {
                    $bitValue = (ord($bitString[$byteIndex]) >> $bitInByte) & 1;
                    if ($bitValue === $posValue) {
                        return $offset;
                    }
                }
            }

            return -1;
        }, $dbAlias);

        return $posResult === false ? -1 : $posResult;
    }
}
