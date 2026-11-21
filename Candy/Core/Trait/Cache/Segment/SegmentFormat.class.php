<?php
/**
 * 分段存储驱动 — 二进制布局常量与编解码
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

trait SegmentFormat
{
    protected const SEG_MAGIC_NUMBER = 0xDEADBEEF;

    protected const SEG_HEADER_FORMAT = 'NCNNNNNNN';
    protected const SEG_HEADER_UNPACK_FORMAT = 'Nmagic_number/Cversion/Ndata_capacity/Nwriting_flag/Nwriting_start_time/Nblock_size/Nmax_var_number/Nbitmap_length/Nreserved';
    protected const SEG_HEADER_SIZE = 33;

    protected const SEG_INDEX_FORMAT = 'NNN';
    protected const SEG_INDEX_UNPACK_FORMAT = 'Ncrc32/Nv_crc32/Nmeta_order';
    protected const SEG_INDEX_LENGTH = 12;
    protected const SEG_INDEX_ORDER_FREE = 0xFFFFFFFF;

    protected const SEG_META_LENGTH = 25;
    protected const SEG_META_FORMAT = 'cNNNNNN';
    protected const SEG_META_UNPACK_FORMAT = 'cstatus/Ntimestamp/Nblock_length/Nstring_length/Noffset/Nlast_use/Ndata_crc32';

    protected const SEG_DEFAULT_BLOCK_SIZE = 4096;
    protected const SEG_BITS_PER_BYTE = 8;
    protected const SEG_BITS_PER_CHUNK = 64;

    protected const SEG_META_CACHE_TTL = 30;
    protected const SEG_MAX_META_CACHE_COUNT = 500;

    /**
     * 索引区头长度（File=2, Shmop=6）
     */
    abstract protected function segmentIndexHeaderLength(): int;

    protected function segmentIndexEntryOffset(int $metaOrder): int
    {
        return self::SEG_HEADER_SIZE + $this->segmentIndexHeaderLength() + $metaOrder * self::SEG_INDEX_LENGTH;
    }

    protected function segmentMetaOffset(int $metaOrder, int $indexAreaFixedSize, int $bitmapAreaSize): int
    {
        return self::SEG_HEADER_SIZE + $indexAreaFixedSize + $bitmapAreaSize + $metaOrder * self::SEG_META_LENGTH;
    }

    /** @param array<string, int> $meta */
    protected function segmentPackMeta(array $meta): string
    {
        return pack(self::SEG_META_FORMAT, ...array_values($meta));
    }

    /** @return array<string, int>|false */
    protected function segmentUnpackMeta(string $bin): array|false
    {
        if (strlen($bin) < self::SEG_META_LENGTH) {
            return false;
        }

        $meta = unpack(self::SEG_META_UNPACK_FORMAT, $bin);

        return is_array($meta) ? $meta : false;
    }

    protected function segmentPackIndex(int $crc32, int $vCrc32, int $metaOrder): string
    {
        return pack(self::SEG_INDEX_FORMAT, $crc32, $vCrc32, $metaOrder);
    }

    protected function segmentPackFreeIndex(): string
    {
        return pack(self::SEG_INDEX_FORMAT, 0, 0, self::SEG_INDEX_ORDER_FREE);
    }

    /**
     * 推导「自洽」的槽位数
     *
     * 段内布局：header(33) + 索引头 + 槽位×12 + bitmap + 槽位×25 + 数据块。
     * 槽位越多，index/meta 区越大，可容纳块数越少 —— 两者互相挤压。
     * 目标：槽位 ≈ 块数 ÷ 0.8（0.8 是 GC 的 LRU 裁剪线），使标称槽位不再虚高。
     * 迭代 4 次即可收敛（每轮变化递减）。
     *
     * @param int $segmentSize 段大小（字节）
     * @param int $blockSize 块大小（字节）
     * @param int $indexHeaderLength 索引区头长度（File=2, Shmop=6）
     * @return int 自洽槽位数（不小于 16）
     */
    protected static function segmentResolveSlotCount(int $segmentSize, int $blockSize, int $indexHeaderLength): int
    {
        $blockSize = max(1, $blockSize);
        $slots = (int) max(16, (int) floor($segmentSize / $blockSize));
        for ($i = 0; $i < 4; $i++) {
            $overhead = self::SEG_HEADER_SIZE + $indexHeaderLength
                + $slots * self::SEG_INDEX_LENGTH
                + $slots * self::SEG_META_LENGTH;
            $available = $segmentSize - $overhead;
            if ($available <= $blockSize * self::SEG_BITS_PER_BYTE) {
                $slots = (int) max(16, $slots >> 1);
                continue;
            }
            $bitmap = (int) ceil($available / ($blockSize * self::SEG_BITS_PER_BYTE + 1));
            $blocks = $bitmap * self::SEG_BITS_PER_BYTE;
            $slots = (int) max(16, (int) floor($blocks * 1.25));
        }

        return (int) max(16, $slots);
    }

    /**
     * 段的有效条目容量 = min(块数, 槽位×0.8)
     *
     * 块数决定物理上限，槽位×0.8 决定 GC 的 LRU 裁剪线，取小者才是真实容量。
     */
    protected static function segmentEffectiveCapacity(int $totalBlockCount, int $maxVarNumber): int
    {
        return (int) max(0, min($totalBlockCount, (int) floor($maxVarNumber * 0.8)));
    }

    /**
     * 槽位多于块数时告警一次（同 alias+参数只报一次，避免每请求刷日志）
     */
    protected static function segmentWarnSlotMismatch(
        int $maxVarNumber,
        int $totalBlockCount,
        int $blockSize,
        int $segmentSize,
        int $indexHeaderLength,
        string $alias = 'default'
    ): void {
        static $reported = [];
        if ($totalBlockCount <= 0) {
            return;
        }

        // GC 的 LRU 裁剪线是「槽位 × 0.8」。
        // 只有它高于块数时，槽位才是虚高的（2000 槽 vs 1008 块就是这种）；
        // 低于块数说明槽位才是瓶颈，不算虚高，不告警。
        $slotLine = (int) floor($maxVarNumber * 0.8);
        if ($slotLine <= $totalBlockCount) {
            return;
        }

        $flag = $alias . '|' . $maxVarNumber . '|' . $totalBlockCount;
        if (isset($reported[$flag])) {
            return;
        }
        $reported[$flag] = true;

        error_log(sprintf(
            '[cache] 段 %s 槽位虚高：maxVarNumber=%d（裁剪线 %d 条），但段内只有 %d 个块'
            . '（segmentSize=%d, blockSize=%d），实际容量仅 %d 条。'
            . '建议 maxVarNumber=%d，或调大 segmentSize / 调小 blockSize。',
            $alias,
            $maxVarNumber,
            $slotLine,
            $totalBlockCount,
            $segmentSize,
            $blockSize,
            self::segmentEffectiveCapacity($totalBlockCount, $maxVarNumber),
            self::segmentResolveSlotCount($segmentSize, $blockSize, $indexHeaderLength)
        ));
    }
}
