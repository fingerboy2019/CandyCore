<?php
/**
 * 分段存储驱动 — Key 指纹与热缓存键
 *
 * @package Candy\Core\Trait\Cache\Segment
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Segment;

trait SegmentKeyCodec
{
    /**
     * @return array{0: string, 1: int, 2: int} [memKey, crc32, vCrc32]
     */
    protected function getCrc32Key(string $key): array
    {
        $crc32 = crc32($key);
        $vCrc32 = $key === '' ? 0 : crc32($key[0] . $key[strlen($key) - 1]);
        $memKey = $crc32 . '_' . $vCrc32;

        return [$memKey, $crc32, $vCrc32];
    }

    abstract protected function segmentCachePrefix(?string $dbAlias = null): string;

    protected function formatKey(string $key, ?string $dbAlias = null): string
    {
        return md5($this->segmentCachePrefix($dbAlias) . $key);
    }
}
