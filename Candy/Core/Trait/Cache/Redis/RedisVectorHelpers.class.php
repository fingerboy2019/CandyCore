<?php
/**
 * Redis 驱动 — 向量检索辅助
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisVectorHelpers
{
    /**
     * 全局向量键索引（供前缀线性扫描）
     *
     * @return string
     * @access private
     */
    private function vectorGlobalIndexKey(): string
    {
        return $this->prefix('__vector_index');
    }

    /**
     * @deprecated 旧分组索引键算法，保留避免外部误用时找不到方法
     */
    private function getVectorIndexKey(string $key): string
    {
        $parts = explode(':', $key);
        return implode(':', array_slice($parts, 0, -1)) . '_index';
    }

    private function calcCosineSimilarity(array $v1, array $v2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        foreach ($v1 as $i => $val1) {
            $val2 = $v2[$i] ?? 0;
            $dotProduct += $val1 * $val2;
            $norm1 += $val1 * $val1;
            $norm2 += $val2 * $val2;
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        if ($norm1 === 0.0 || $norm2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($norm1 * $norm2);
    }

    private function calcEuclideanDistance(array $v1, array $v2): float
    {
        $sum = 0.0;
        foreach ($v1 as $i => $val1) {
            $val2 = $v2[$i] ?? 0;
            $diff = $val1 - $val2;
            $sum += $diff * $diff;
        }
        $distance = sqrt($sum);
        return 1.0 / (1.0 + $distance);
    }

    private function calcDotProduct(array $v1, array $v2): float
    {
        $dotProduct = 0.0;
        foreach ($v1 as $i => $val1) {
            $val2 = $v2[$i] ?? 0;
            $dotProduct += $val1 * $val2;
        }
        return $dotProduct;
    }
}
