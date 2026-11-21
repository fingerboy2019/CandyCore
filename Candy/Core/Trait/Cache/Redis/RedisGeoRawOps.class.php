<?php
/**
 * Redis 驱动 — Geo 原生 __raw*
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisGeoRawOps
{
    public function __rawGeoAdd(string $key, float $longitude, float $latitude, string $member): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->geoAdd($key, $longitude, $latitude, $member);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawGeoPos(string $key, string ...$members): array
    {
        if (empty($members)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->geoPos($key, ...$members);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawGeoDist(string $key, string $member1, string $member2, string $unit = 'm'): ?float
    {
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $result = $redis->geoDist($key, $member1, $member2, $unit);

            $this->recordSuccess();
            return $result === false ? null : (float)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function __rawGeoRadius(string $key, float $longitude, float $latitude, float $radius, string $unit = 'm', array $options = []): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->geoRadius($key, $longitude, $latitude, $radius, $unit, $options);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawGeoHash(string $key, string ...$members): array
    {
        if (empty($members)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->geoHash($key, ...$members);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawGeoRadiusByMember(string $key, string $member, float $radius, string $unit = 'm', array $options = []): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->geoRadiusByMember($key, $member, $radius, $unit, $options);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
}
