<?php
/**
 * 分段存储驱动 — Redis 位图 KV 纯算法辅助
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

trait SegmentBitmapHelpers
{
    use SegmentFormat;

    /**
     * @param list<int> $idList
     * @return list<int>
     */
    protected function segmentCalcBitResult(string $bitString, array $idList): array
    {
        $result = [];
        foreach ($idList as $offset) {
            $byteIndex = (int)($offset / self::SEG_BITS_PER_BYTE);
            if ($byteIndex >= strlen($bitString)) {
                $result[] = 0;
                continue;
            }
            $bitInByte = $offset % self::SEG_BITS_PER_BYTE;
            $result[] = (ord($bitString[$byteIndex]) & (1 << $bitInByte)) ? 1 : 0;
        }

        return $result;
    }

    protected function segmentCountBitsInRange(string $bitString, int $start, int $end): int
    {
        $length = strlen($bitString) * self::SEG_BITS_PER_BYTE;
        if ($end < 0) {
            $end = $length + $end;
        }
        $end = min($end, $length - 1);
        $start = max(0, $start);

        if ($start > $end) {
            return 0;
        }

        $count = 0;
        for ($i = $start; $i <= $end; $i++) {
            $byteIndex = (int)($i / self::SEG_BITS_PER_BYTE);
            $bitInByte = $i % self::SEG_BITS_PER_BYTE;
            $byte = ord($bitString[$byteIndex]);
            $count += ($byte >> $bitInByte) & 1;
        }

        return $count;
    }
}
