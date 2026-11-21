<?php
/**
 * Redis 驱动 — Redis 原生公开 API 覆写
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisNativePublicApi
{
    public function sAdd(string $key, mixed ...$members): int|bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawSAdd($this->prefix($key), ...$members);
    }
    public function sAddBulk(string $key, array $members, int $ttl = 0): int|bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        $result = $this->__rawSAdd($key, ...$members);
        if ($result > 0 && $ttl > 0) {
            $this->__rawExpire($key, $ttl);
        }
        return $result;
    }
    public function sRem(string $key, mixed ...$members): int|bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawSRem($this->prefix($key), ...$members);
    }
    public function sMembers(string $key): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawSMembers($this->prefix($key));
    }
    public function sCard(string $key): int
    {
        return $this->__rawSCard($this->prefix($key));
    }
    public function sUnion(string ...$keys): array
    {
        $prefixedKeys = array_map(fn($k) => $this->prefix($k), $keys);
        return $this->__rawSUnion($prefixedKeys);
    }
    public function sInter(string ...$keys): array
    {
        $prefixedKeys = array_map(fn($k) => $this->prefix($k), $keys);
        return $this->__rawSInter($prefixedKeys);
    }
    public function hSet(string $key, string|array $field, mixed $value = null, int $ttl = 0): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        
        if (is_array($field)) {
            // 批量设置
            $result = $this->__rawHMSet($key, $field);
        } else {
            // 单个设置
            $result = $this->__rawHSet($key, (string)$field, $value) > 0;
        }
        
        if ($result && $ttl > 0) {
            $this->__rawExpire($key, $ttl);
        }
        return $result;
    }
    public function hMSet(string $key, array $fieldValues, int $ttl = 0): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        $result = $this->__rawHMSet($key, $fieldValues);
        if ($result && $ttl > 0) {
            $this->__rawExpire($key, $ttl);
        }
        return $result;
    }
    public function hGet(string $key, string $field, mixed $default = null): mixed
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return $default;
        }
        return $this->__rawHGet($this->prefix($key), $field, $default);
    }
    public function hGetAll(string $key): array
    {
        return $this->__rawHGetAll($this->prefix($key));
    }
    public function hKeys(string $key): array
    {
        return $this->__rawHKeys($this->prefix($key));
    }
    public function hVals(string $key): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawHVals($this->prefix($key));
    }
    public function hMGet(string $key, array $fields): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawHMGet($this->prefix($key), ...$fields);
    }
    public function hLen(string $key): int
    {
        return $this->__rawHLen($this->prefix($key));
    }
    public function hDel(string $key, string ...$fields): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawHDel($this->prefix($key), ...$fields) > 0;
    }
    public function zAdd(string $key, float $score, mixed $member): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawZAdd($this->prefix($key), $score, $member) > 0;
    }
    public function zIncrBy(string $key, float $increment, mixed $member): float|false
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0.0;
        }
        return $this->__rawZIncrBy($this->prefix($key), $increment, $member);
    }
    public function zRange(string $key, int $start = 0, int $end = -1, bool $withScore = false): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawZRange($this->prefix($key), $start, $end, $withScore);
    }
    public function zRevRange(string $key, int $start = 0, int $end = -1, bool $withScore = false): array
    {
        return $this->__rawZRevRange($this->prefix($key), $start, $end, $withScore);
    }
    public function zRank(string $key, mixed $member): ?int
    {
        return $this->__rawZRank($this->prefix($key), $member);
    }
    public function zRevRank(string $key, mixed $member): ?int
    {
        return $this->__rawZRevRank($this->prefix($key), $member);
    }
    public function zScore(string $key, mixed $member): ?float
    {
        return $this->__rawZScore($this->prefix($key), $member);
    }
    public function zCount(string $key, float $min, float $max): int
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        return $this->__rawZCount($this->prefix($key), $min, $max);
    }
    public function zRangeByScore(string $key, float $min, float $max, int $offset = 0, int $count = -1, bool $withScore = false): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawZRangeByScore($this->prefix($key), $min, $max, $withScore, $offset, $count);
    }
    public function zPopMin(string $key, int $count = 1): ?array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $result = $this->__rawZPopMin($this->prefix($key), $count);
        return is_array($result) ? $result : null;
    }
    public function zPopMax(string $key, int $count = 1): ?array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $result = $this->__rawZPopMax($this->prefix($key), $count);
        return is_array($result) ? $result : null;
    }

    public function zPopDue(
        string $key,
        ?float $maxScore = null,
        int $limit = self::ZPOPDUE_DEFAULT_LIMIT
    ): array {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }

        $maxScore = $maxScore ?? (float) time();
        $limit    = self::normalizePopLimit($limit);

        $key  = $this->prefix($key);
        $rows = $this->__rawZRangeByScore($key, -INF, $maxScore, false, 0, $limit > 0 ? $limit : 0);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $member) {
            // ZREM 单命令原子：只有返回 1 才说明这次是我取走的（另一进程会拿到 0）
            if ($this->__rawZRem($key, $member) === 1) {
                $out[] = (string) $member;
            }
        }

        return $out;
    }
    public function zCard(string $key): int
    {
        return $this->__rawZCard($this->prefix($key));
    }
    public function zRem(string $key, mixed ...$members): int
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        return $this->__rawZRem($this->prefix($key), ...$members);
    }
    public function hLogAdd(string $key, mixed ...$elements): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawPFAdd($this->prefix($key), ...$elements);
    }
    public function hLogCount(string $key): int
    {
        return $this->__rawPFCount($this->prefix($key));
    }
    public function geoAdd(string $key, float $longitude, float $latitude, string $member): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawGeoAdd($this->prefix($key), $longitude, $latitude, $member) > 0;
    }
    public function geoPos(string $key, string ...$members): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawGeoPos($this->prefix($key), ...$members);
    }
    public function geoDist(string $key, string $member1, string $member2, string $unit = 'm'): ?float
    {
        return $this->__rawGeoDist($this->prefix($key), $member1, $member2, $unit);
    }
    public function geoHash(string $key, string ...$members): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawGeoHash($this->prefix($key), ...$members);
    }
    public function geoRadius(string $key, float $longitude, float $latitude, float $radius, string $unit = 'm', array $options = []): array
    {
        return $this->__rawGeoRadius($this->prefix($key), $longitude, $latitude, $radius, $unit, $options);
    }
    public function geoRadiusByMember(string $key, string $member, float $radius, string $unit = 'm', array $options = []): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawGeoRadiusByMember($this->prefix($key), $member, $radius, $unit, $options);
    }
    public function sIsMember(string $key, mixed $member): bool
    {
        return $this->__rawSIsMember($this->prefix($key), $member);
    }
    public function sPop(string $key, int $count = 1): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $result = $this->__rawSPop($this->prefix($key), $count);
        if ($result === false || $result === null) {
            return [];
        }
        return is_array($result) ? $result : [$result];
    }
    public function sRandMember(string $key, int $count = 1): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $result = $this->__rawSRandMember($this->prefix($key), $count);
        if ($result === false || $result === null) {
            return [];
        }
        return is_array($result) ? $result : [$result];
    }
    public function sDiff(string ...$keys): array
    {
        $prefixedKeys = array_map(fn($k) => $this->prefix($k), $keys);
        return $this->__rawSDiff($prefixedKeys);
    }
    public function hExists(string $key, string $field): bool
    {
        return $this->__rawHExists($this->prefix($key), $field);
    }
    public function hLogMerge(string $dest, string ...$sources): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $destKey = $this->prefix($dest);
        $sourceKeys = array_map(fn($k) => $this->prefix($k), $sources);
        return $this->__rawPFMerge($destKey, $sourceKeys);
    }
    public function cFilterInit(string $key, int $capacity = 100000, float $loadFactor = 0.9): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        // Redis 使用 Bitmap 实现，自动扩展，只需初始化元数据
        $key = $this->prefix($key);
        $metadata = [
            'type' => 'cuckoo_filter',
            'capacity' => $capacity,
            'loadFactor' => $loadFactor,
            'count' => 0,
            'bitSize' => $capacity * 2 // 冗余空间
        ];
        return $this->__rawSet($key . ':meta', $metadata);
    }
    public function cFilterAdd(string $key, string $element): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        // 使用多个哈希函数映射到位图
        $hash1 = crc32($element);
        $hash2 = crc32($element . '_seed1');
        $bitSize = 200000; // 默认位图大小
        
        $pos1 = abs($hash1) % $bitSize;
        $pos2 = abs($hash2) % $bitSize;
        
        // 设置两个位
        $result1 = $this->__rawBit($key . ':data', $pos1, true);
        $result2 = $this->__rawBit($key . ':data', $pos2, true);
        
        if ($result1 && $result2) {
            // 更新计数
            $meta = $this->__rawGet($key . ':meta') ?? [];
            if (isset($meta['count'])) {
                $meta['count']++;
                $this->__rawSet($key . ':meta', $meta);
            }
        }
        return $result1 && $result2;
    }
    public function cFilterExists(string $key, string $element): bool
    {
        $key = $this->prefix($key);
        $hash1 = crc32($element);
        $hash2 = crc32($element . '_seed1');
        $bitSize = 200000;
        
        $pos1 = abs($hash1) % $bitSize;
        $pos2 = abs($hash2) % $bitSize;
        
        $bit1 = $this->__rawBit($key . ':data', $pos1);
        $bit2 = $this->__rawBit($key . ':data', $pos2);
        
        return (int)$bit1 === 1 && (int)$bit2 === 1;
    }
    public function cFilterClear(string $key): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        // 删除位图和元数据
        return $this->__rawDelete($key . ':data') && $this->__rawDelete($key . ':meta');
    }
    public function cFilterDelete(string $key, string $element): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        $hash1 = crc32($element);
        $hash2 = crc32($element . '_seed1');
        $bitSize = 200000;
        
        $pos1 = abs($hash1) % $bitSize;
        $pos2 = abs($hash2) % $bitSize;
        
        // 清除位（注意：这可能会影响其他元素）
        $result1 = $this->__rawBit($key . ':data', $pos1, false);
        $result2 = $this->__rawBit($key . ':data', $pos2, false);
        
        if ($result1 && $result2) {
            $meta = $this->__rawGet($key . ':meta') ?? [];
            if (isset($meta['count']) && $meta['count'] > 0) {
                $meta['count']--;
                $this->__rawSet($key . ':meta', $meta);
            }
        }
        return $result1 && $result2;
    }
    /**
     * 写入向量（结构化）并登记到全局向量索引供前缀扫描
     *
     * @param string $key 逻辑键
     * @param array $vector 向量
     * @param int $dimension 维度
     * @param int $expire 过期秒数
     * @return bool
     */
    public function vectorSet(string $key, array $vector, int $dimension, int $expire = 0): bool
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        if ($dimension <= 0 || count($vector) !== $dimension || !$this->isValidVector($vector)) {
            return false;
        }

        $fullKey = $this->prefix($key);
        $data = [
            'dimension' => $dimension,
            'vector' => array_map('floatval', $vector),
            'norm' => $this->calculateVectorNorm($vector),
            'timestamp' => time(),
        ];
        $result = $this->__rawSet($fullKey, $data, $expire);
        if ($result) {
            $indexKey = $this->vectorGlobalIndexKey();
            $index = $this->__rawGet($indexKey) ?? [];
            if (!is_array($index)) {
                $index = [];
            }
            $index[$fullKey] = 1;
            $this->__rawSet($indexKey, $index, 0);
        }
        return (bool)$result;
    }

    /**
     * 读取向量结构
     *
     * @param string $key 逻辑键
     * @return array|null
     */
    public function vectorGet(string $key): ?array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        $data = $this->__rawGet($this->prefix($key));
        if (!is_array($data) || !isset($data['vector']) || !is_array($data['vector'])) {
            // 兼容历史裸向量
            $extracted = $this->extractStoredVector($data);
            return $extracted === null ? null : [
                'dimension' => count($extracted),
                'vector' => $extracted,
                'norm' => $this->calculateVectorNorm($extracted),
                'timestamp' => 0,
            ];
        }
        return $data;
    }

    /**
     * 前缀线性扫描近邻（非 ANN）
     *
     * @param string $prefix 逻辑键前缀
     * @param array $queryVector 查询向量
     * @param int $topK topK
     * @param float $threshold 余弦下限
     * @return array<int, array{key:string,score:float}>
     */
    public function vectorScanSearch(string $prefix, array $queryVector, int $topK = 10, float $threshold = 0.0): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        if (!$this->isValidVector($queryVector)) {
            return [];
        }

        $index = $this->__rawGet($this->vectorGlobalIndexKey()) ?? [];
        if (!is_array($index) || $index === []) {
            return [];
        }

        $fullPrefix = $this->prefix($prefix);
        $scores = [];
        foreach ($index as $vectorKey => $_) {
            if (!is_string($vectorKey) || !str_starts_with($vectorKey, $fullPrefix)) {
                continue;
            }
            $raw = $this->__rawGet($vectorKey);
            $vector = $this->extractStoredVector($raw);
            if ($vector === null || !$this->isVectorLengthMatch($vector, $queryVector)) {
                continue;
            }
            $scores[$vectorKey] = $this->calcCosineSimilarity($vector, $queryVector);
        }

        return $this->normalizeVectorSearchResults($scores, $topK, $threshold);
    }

    /**
     * @deprecated 转调 vectorScanSearch
     */
    public function vectorAnnSearch(string $key, array $queryVector, int $topK = 10, float $threshold = 0.0): array
    {
        return $this->vectorScanSearch($key, $queryVector, $topK, $threshold);
    }

    /**
     * 删除向量并更新索引
     *
     * @param string $key 逻辑键
     * @return bool
     */
    public function vectorDelete(string $key): bool
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $fullKey = $this->prefix($key);
        $result = $this->__rawDelete($fullKey);
        if ($result) {
            $indexKey = $this->vectorGlobalIndexKey();
            $index = $this->__rawGet($indexKey) ?? [];
            if (is_array($index)) {
                unset($index[$fullKey]);
                $this->__rawSet($indexKey, $index, 0);
            }
        }
        return (bool)$result;
    }

    public function stackPush(string $key, mixed $element): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            $redis = $this->getClient();
            $serialized = Binary::pack(['data' => $element]);
            $result = $redis->lPush($key, $serialized);
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function stackPop(string $key): int|bool|null
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        try {
            $redis = $this->getClient();
            $serialized = $redis->lPop($key);
            if ($serialized === false || $serialized === null) {
                $this->recordSuccess();
                return null;
            }
            $this->deleteHotCache($key);
            $this->recordSuccess();
            $unpacked = Binary::tryUnpack($serialized);
            return is_array($unpacked) && array_key_exists('data', $unpacked)
                ? $unpacked['data']
                : $serialized;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function stackPeek(string $key): mixed
    {
        $key = $this->prefix($key);
        try {
            $redis = $this->getClient();
            $serialized = $redis->lRange($key, 0, 0);
            if (empty($serialized)) {
                return null;
            }
            $unpacked = Binary::tryUnpack($serialized[0]);
            return is_array($unpacked) && array_key_exists('data', $unpacked)
                ? $unpacked['data']
                : $serialized[0];
        } catch (Throwable) {
            return null;
        }
    }
    public function stackIsEmpty(string $key): bool
    {
        return $this->stackSize($key) === 0;
    }
    public function stackSize(string $key): int
    {
        $key = $this->prefix($key);
        try {
            $redis = $this->getClient();
            $result = $redis->lLen($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function stackClear(string $key): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $key = $this->prefix($key);
        return $this->__rawDelete($key);
    }
    public function lLen(string $key): int
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        return $this->__rawLLen($this->prefix($key));
    }
    public function lIndex(string $key, int $index): mixed
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        return $this->__rawLIndex($this->prefix($key), $index);
    }
    public function lSet(string $key, int $index, mixed $value): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawLSet($this->prefix($key), $index, $value);
    }
    public function lRange(string $key, int $start, int $stop): array
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawLRange($this->prefix($key), $start, $stop);
    }
    public function lTrim(string $key, int $start, int $stop): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawLTrim($this->prefix($key), $start, $stop);
    }
}
