<?php
/**
 * 分段存储驱动 — 块位图分配 / 释放
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

trait SegmentBlockBitmap
{
    use SegmentFormat;

    /** @return string bitmap 字符串引用 */
    abstract protected function &segmentBitmap(?string $dbAlias = null): string;

    abstract protected function segmentTotalBlockCount(?string $dbAlias = null): int;

    abstract protected function segmentBlockSize(?string $dbAlias = null): int;

    abstract protected function segmentDataAreaStart(?string $dbAlias = null): int;

    abstract protected function segmentPersistBitmap(?string $dbAlias = null): void;

    protected function segmentOnBitmapByteDirty(int $byteOffset, ?string $dbAlias = null): void
    {
    }

    protected function segmentUseChunkBitmapScan(): bool
    {
        return false;
    }

    protected function bitGet(int $bit, ?string $dbAlias = null): bool
    {
        $bitmap = &$this->segmentBitmap($dbAlias);
        $byte = (int)($bit / self::SEG_BITS_PER_BYTE);
        $shift = $bit % self::SEG_BITS_PER_BYTE;
        if ($byte >= strlen($bitmap)) {
            return false;
        }

        return (ord($bitmap[$byte]) & (1 << $shift)) !== 0;
    }

    protected function bitSet(int $bit, bool $used, ?string $dbAlias = null): void
    {
        $bitmap = &$this->segmentBitmap($dbAlias);
        $byte = (int)($bit / self::SEG_BITS_PER_BYTE);
        $shift = $bit % self::SEG_BITS_PER_BYTE;
        if ($byte >= strlen($bitmap)) {
            return;
        }

        $chr = ord($bitmap[$byte]);
        $chr = $used ? ($chr | (1 << $shift)) : ($chr & ~(1 << $shift));
        $bitmap[$byte] = chr($chr);
        $this->segmentOnBitmapByteDirty($byte, $dbAlias);
    }

    protected function findBestFitBlocks(int $needBlockCount, ?string $dbAlias = null): int|false
    {
        if ($needBlockCount <= 0) {
            return false;
        }

        $totalBits = $this->segmentTotalBlockCount($dbAlias);
        if ($needBlockCount > $totalBits) {
            return false;
        }

        if ($this->segmentUseChunkBitmapScan()) {
            return $this->findBestFitBlocksChunked($needBlockCount, $dbAlias);
        }

        $currentFree = 0;
        $bestStart = -1;
        $bestLength = PHP_INT_MAX;

        for ($i = 0; $i < $totalBits; $i++) {
            if (!$this->bitGet($i, $dbAlias)) {
                $currentFree++;
                // first-fit：找到第一个够用的连续段就返回，不再扫完整个位图
                if ($currentFree >= $needBlockCount) {
                    return $i - $currentFree + 1;
                }
            } else {
                $currentFree = 0;
            }
        }

        if ($currentFree >= $needBlockCount && $currentFree < $bestLength) {
            $bestStart = $totalBits - $currentFree;
        }

        return $bestStart !== -1 ? $bestStart : false;
    }

    protected function findBestFitBlocksChunked(int $needBlockCount, ?string $dbAlias = null): int|false
    {
        $bitmap = $this->segmentBitmap($dbAlias);
        $totalBits = $this->segmentTotalBlockCount($dbAlias);
        $currentFree = 0;
        $bestStart = -1;
        $bestLength = PHP_INT_MAX;
        $chunkCount = (int)ceil($totalBits / self::SEG_BITS_PER_CHUNK);

        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkStart = $chunk * self::SEG_BITS_PER_CHUNK;
            $chunkEnd = min($chunkStart + self::SEG_BITS_PER_CHUNK, $totalBits);
            $byteOffset = (int)($chunkStart / self::SEG_BITS_PER_BYTE);
            $chunkBin = substr($bitmap, $byteOffset, 8);
            $chunkInt = @unpack('J', $chunkBin)[1] ?? 0;

            if ($chunkInt === 0xFFFFFFFFFFFFFFFF && $chunkEnd - $chunkStart === self::SEG_BITS_PER_CHUNK) {
                $currentFree = 0;
                continue;
            }

            for ($bit = $chunkStart; $bit < $chunkEnd; $bit++) {
                if (!$this->bitGet($bit, $dbAlias)) {
                    $currentFree++;
                    if ($currentFree >= $needBlockCount) {
                        // first-fit：找到第一个够用的连续段就返回
                        return $bit - $needBlockCount + 1;
                    }
                } else {
                    if ($currentFree >= $needBlockCount) {
                        $currentStart = $bit - $currentFree;
                        if ($currentFree < $bestLength) {
                            $bestLength = $currentFree;
                            $bestStart = $currentStart;
                        }
                    }
                    $currentFree = 0;
                }
            }
        }

        if ($currentFree >= $needBlockCount && $currentFree < $bestLength) {
            $bestStart = $totalBits - $currentFree;
        }

        return $bestStart !== -1 ? $bestStart : false;
    }

    protected function mergeFreeBlocks(int $startBit, int $blockCount, ?string $dbAlias = null): void
    {
        for ($i = 0; $i < $blockCount; $i++) {
            $this->bitSet($startBit + $i, false, $dbAlias);
        }

        $mergeStart = $startBit;
        while ($mergeStart > 0 && !$this->bitGet($mergeStart - 1, $dbAlias)) {
            $mergeStart--;
        }

        $mergeEnd = $startBit + $blockCount - 1;
        $totalBits = $this->segmentTotalBlockCount($dbAlias);
        while ($mergeEnd < $totalBits - 1 && !$this->bitGet($mergeEnd + 1, $dbAlias)) {
            $mergeEnd++;
        }
    }

    /**
     * 断言单条数据能在段内装下
     *
     * 必须与「暂时没空间」区分：
     *   - 物理不可能（数据比整段还大）→ 抛异常，无论 GC 多少次都不可能成功；
     *   - 暂时没空间（无足够连续块）→ 仍由 allocateSpace 返回 false，交调用方触发 GC。
     *
     * @param int $needLength 需要的字节数
     * @param string|null $dbAlias 段别名
     * @return void
     * @throws \LengthException 数据超过段可提供的总块数
     */
    protected function assertSegmentValueFits(int $needLength, ?string $dbAlias = null): void
    {
        if ($needLength <= 0) {
            return;
        }

        $blockSize = max(1, $this->segmentBlockSize($dbAlias));
        $totalBlockCount = $this->segmentTotalBlockCount($dbAlias);
        if ($totalBlockCount <= 0) {
            return;
        }

        $needBlockCount = (int) ceil($needLength / $blockSize);
        if ($needBlockCount <= $totalBlockCount) {
            return;
        }

        throw new \LengthException(sprintf(
            '缓存值过大：需要存储 %d 字节（%d 个块，blockSize=%d），'
            . '但当前段只有 %d 个块，单条上限约 %d 字节。'
            . '请减小写入的值，或调大 segmentSize、调小 blockSize。',
            $needLength,
            $needBlockCount,
            $blockSize,
            $totalBlockCount,
            $totalBlockCount * $blockSize
        ));
    }

    /**
     * 容量不足时的扩容钩子（File 驱动按 growStep 逐步 ftruncate；其它驱动默认不扩）
     *
     * @param string|null $dbAlias
     * @return bool 是否真的扩出了一段空间
     */
    protected function segmentGrowCapacity(?string $dbAlias = null): bool
    {
        return false;
    }

    protected function allocateSpace(int $needLength, ?string $dbAlias = null): int|false
    {
        if ($needLength <= 0) {
            return false;
        }

        // 物理不可能时直接报错，不再静默返回 false（否则调用方会白跑一次全段 GC 再失败）
        $this->assertSegmentValueFits($needLength, $dbAlias);

        $blockSize = $this->segmentBlockSize($dbAlias);
        $needBlockCount = (int)ceil($needLength / $blockSize);

        $startBit = $this->findBestFitBlocks($needBlockCount, $dbAlias);
        if ($startBit === false) {
            // 可能是「已开区间用完了」，先按步长扩一段再试一次
            if (!$this->segmentGrowCapacity($dbAlias)) {
                return false;
            }
            $startBit = $this->findBestFitBlocks($needBlockCount, $dbAlias);
            if ($startBit === false) {
                return false;
            }
        }

        for ($i = 0; $i < $needBlockCount; $i++) {
            $this->bitSet($startBit + $i, true, $dbAlias);
        }

        $this->segmentPersistBitmap($dbAlias);

        return $this->segmentDataAreaStart($dbAlias) + $startBit * $blockSize;
    }

    protected function freeSpace(int $offset, int $length, ?string $dbAlias = null): void
    {
        $dataAreaStart = $this->segmentDataAreaStart($dbAlias);
        if ($offset < $dataAreaStart || $length <= 0) {
            return;
        }

        $blockSize = $this->segmentBlockSize($dbAlias);
        $relOffset = $offset - $dataAreaStart;
        $startBit = (int)($relOffset / $blockSize);
        $blockCount = (int)ceil($length / $blockSize);

        $this->mergeFreeBlocks($startBit, $blockCount, $dbAlias);
        $this->segmentPersistBitmap($dbAlias);
    }
}
