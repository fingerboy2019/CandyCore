<?php
/**
 * Redis 驱动 — HyperLogLog 原生 __raw*
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisHyperLogRawOps
{
    public function __rawPFAdd(string $key, mixed ...$elements): bool
    {
        if (empty($elements)) return true;
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $elements = array_map(fn($e) => (string)$e, $elements);
            $result = $redis->pfAdd($key, $elements);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawPFCount(string $key): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->pfCount($key);

            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawPFMerge(string $destKey, array $sourceKeys): bool
    {
        if (empty($sourceKeys)) return false;
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->pfMerge($destKey, $sourceKeys);

            // 清理本地缓存
            $this->deleteHotCache($destKey);
            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
}
