<?php
/**
 * Redis 驱动 — ZSet 原生 __raw*
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisZSetRawOps
{
    public function __rawZAdd(string $key, float $score, mixed $member): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->zAdd($key, $score, (string)$member);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawZScore(string $key, mixed $member): ?float
    {
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $result = $redis->zScore($key, (string)$member);

            $this->recordSuccess();
            return $result === false ? null : (float)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function __rawZRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->zRange($key, $start, $stop, $withScores);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawZRevRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->zRevRange($key, $start, $stop, $withScores);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawZRank(string $key, mixed $member): ?int
    {
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $result = $redis->zRank($key, (string)$member);

            $this->recordSuccess();
            return $result === false ? null : (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function __rawZRevRank(string $key, mixed $member): ?int
    {
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $result = $redis->zRevRank($key, (string)$member);

            $this->recordSuccess();
            return $result === false ? null : (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function __rawZCard(string $key): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->zCard($key);

            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawZRem(string $key, mixed ...$members): int
    {
        if (empty($members)) return 0;
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $members = array_map(fn($m) => (string)$m, $members);
            $result = $redis->zRem($key, ...$members);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawZIncrBy(string $key, float $increment, mixed $member): float
    {
        if (!$this->isFuseAllow()) return 0.0;

        try {
            $redis = $this->getClient();
            $result = $redis->zIncrBy($key, $increment, (string)$member);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (float)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0.0;
        }
    }
    public function __rawZCount(string $key, float $min, float $max): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->zCount($key, $min, $max);

            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawZRangeByScore(string $key, float $min, float $max, bool $withScores = false, int $offset = 0, int $count = 0): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $options = [];
            if ($withScores) {
                $options['WITHSCORES'] = true;
            }
            if ($count > 0) {
                // 注意：phpredis 的 LIMIT 选项必须是「小写 limit + 数字索引数组」，
                // 写成 ['LIMIT' => ['offset'=>..,'count'=>..]] 会被静默忽略（等于没限量）。
                $options['limit'] = [$offset, $count];
            }

            $result = $redis->zRangeByScore($key, $min, $max, $options);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawZPopMin(string $key, int $count = 1): array|string|false
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->zPopMin($key, $count);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawZPopMax(string $key, int $count = 1): array|string|false
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->zPopMax($key, $count);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawZInter(array $keys, ?array $weights = null, string $aggregate = 'SUM', bool $withScores = false): array
    {
        if (empty($keys)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $options = [];
            
            if ($weights !== null) {
                $options['WEIGHTS'] = $weights;
            }
            $options['AGGREGATE'] = $aggregate;

            $result = $redis->zInter($keys, $options);
            
            if ($withScores) {
                // 如果需要分数，需要额外获取
                $resultWithScores = [];
                foreach ($result as $member) {
                    foreach ($keys as $key) {
                        $score = $redis->zScore($key, $member);
                        if ($score !== false) {
                            $resultWithScores[$member] = ($resultWithScores[$member] ?? 0) + $score;
                        }
                    }
                }
                $result = $resultWithScores;
            }

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawZUnion(array $keys, ?array $weights = null, string $aggregate = 'SUM', bool $withScores = false): array
    {
        if (empty($keys)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $options = [];
            
            if ($weights !== null) {
                $options['WEIGHTS'] = $weights;
            }
            $options['AGGREGATE'] = $aggregate;

            $result = $redis->zUnion($keys, $options);
            
            if ($withScores) {
                // 如果需要分数，需要额外获取
                $resultWithScores = [];
                foreach ($result as $member) {
                    foreach ($keys as $key) {
                        $score = $redis->zScore($key, $member);
                        if ($score !== false) {
                            $resultWithScores[$member] = ($resultWithScores[$member] ?? 0) + $score;
                        }
                    }
                }
                $result = $resultWithScores;
            }

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
}
