<?php
/**
 * Redis 风格结构操作模拟实现（Memcached / GlobalData 共用）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

use Throwable;

trait SimulatedStructures
{
    public function __rawIncr(string $key, int $step, int $type): int
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $arr = $this->__rawGet($mainKey) ?? [];
            if (!is_array($arr)) $arr = [];
            $currentVal = $this->getNestedValue($arr, $paths) ?? 0;
            if (!is_numeric($currentVal)) $currentVal = 0;
            $newVal = $type === 0 ? $currentVal + $step : $currentVal - $step;
            $this->setNestedValue($arr, $paths, $newVal);
            $this->__rawSet($mainKey, $arr);
            return $newVal;
        }

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($step, $type) {
            $currentVal = $found && is_numeric($data) ? (int)$data : 0;
            $newVal = $type === 0 ? $currentVal + $step : $currentVal - $step;
            return ['return' => $newVal, 'data' => $newVal];
        });

        return is_int($result) ? $result : (int)($result ?? 0);
    }

    public function __rawIncrByFloat(string $key, float $step): float|false
    {
        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($step) {
            $currentVal = $found && is_numeric($data) ? (float)$data : 0.0;
            $newVal = $currentVal + $step;
            return ['return' => $newVal, 'data' => $newVal, 'refresh_hot_cache' => false];
        });

        return is_float($result) || is_int($result) ? (float)$result : false;
    }

    public function __rawPush(string $key, array $args, bool $recove, int $head = 0): int
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $arr = $this->__rawGet($mainKey) ?? [];
            if (!is_array($arr)) {
                $arr = [];
            }
            $queue = $this->getNestedValue($arr, $paths);
            // cover=true：替换整段队列；否则缺失或非数组时允许新建（与 Redis LPUSH 对齐）
            if ($recove) {
                $queue = [];
            } elseif (!is_array($queue)) {
                $queue = [];
            }
            foreach ($args as $arg) {
                $head === 0 ? array_unshift($queue, $arg) : $queue[] = $arg;
            }
            $this->setNestedValue($arr, $paths, $queue);
            $this->__rawSet($mainKey, $arr);
            return count($queue);
        }

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($args, $recove, $head) {
            // cover=true：截断后写入；cover=false：追加；键不存在时允许创建
            if ($recove) {
                $queue = [];
            } elseif ($found && is_array($data)) {
                $queue = $data;
            } elseif ($found && !is_array($data)) {
                // 类型冲突且不允许覆盖：跳过
                return ['return' => 0, 'skip_save' => true];
            } else {
                $queue = [];
            }
            foreach ($args as $arg) {
                $head === 0 ? array_unshift($queue, $arg) : $queue[] = $arg;
            }
            return ['return' => count($queue), 'data' => $queue];
        });

        return is_int($result) ? $result : (int)($result ?? 0);
    }

    public function __rawPop(string $key, int $head = 0): mixed
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $arr = $this->__rawGet($mainKey);
            if (!is_array($arr)) return null;
            $queue = $this->getNestedValue($arr, $paths);
            if (!is_array($queue) || empty($queue)) return null;
            $value = $head === 0 ? array_shift($queue) : array_pop($queue);
            $this->setNestedValue($arr, $paths, $queue);
            $this->__rawSet($mainKey, $arr);
            return $value;
        }

        return $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($head) {
            if (!$found || !is_array($data) || empty($data)) {
                return ['return' => null, 'skip_save' => true];
            }
            $queue = $data;
            $value = $head === 0 ? array_shift($queue) : array_pop($queue);
            if (empty($queue)) {
                return ['return' => $value, 'delete' => true];
            }
            return ['return' => $value, 'data' => $queue];
        });
    }

    public function __rawRange(string $key, int $offset, int $length = 0, string $sort = '', string $order = 'asc'): array|false
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $data = $this->__rawGet($mainKey);
            if ($data === null || !is_array($data)) return false;
            $array = $this->getNestedValue($data, $paths);
            if ($array === null || !is_array($array)) return false;
        } else {
            $array = $this->simulatedReadArray($key);
            if (empty($array)) return false;
        }

        if (!empty($sort)) {
            usort($array, function ($a, $b) use ($sort, $order) {
                if (!isset($a[$sort]) || !isset($b[$sort])) return 0;
                $comparison = $a[$sort] <=> $b[$sort];
                return $order === 'desc' ? -$comparison : $comparison;
            });
        }

        return $length === 0 ? $array : array_slice($array, $offset, $length);
    }

    public function __rawSIsMember(string $key, mixed $member): bool
    {
        $hot = $this->simulatedReadFromHotCache($key);
        if (is_array($hot)) {
            return in_array($member, $hot, true);
        }

        $set = $this->simulatedReadArray($key);
        return in_array($member, $set, true);
    }

    public function __rawHExists(string $key, string $field): bool
    {
        $hot = $this->simulatedReadFromHotCache($key);
        if (is_array($hot)) {
            return array_key_exists($field, $hot);
        }

        $hash = $this->simulatedReadArray($key);
        return array_key_exists($field, $hash);
    }

    public function __rawZRem(string $key, mixed ...$members): int
    {
        if (empty($members)) return 0;

        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($members) {
            if (!$found || !is_array($data)) {
                return ['return' => 0, 'skip_save' => true];
            }
            $zset = $data;
            $count = 0;
            foreach ($members as $member) {
                $memberStr = (string)$member;
                if (isset($zset[$memberStr])) {
                    unset($zset[$memberStr]);
                    $count++;
                }
            }
            if ($count === 0) {
                return ['return' => 0, 'skip_save' => true];
            }
            return ['return' => $count, 'data' => $zset, 'refresh_hot_cache' => false];
        });

        return is_int($result) ? $result : (int)($result ?? 0);
    }

    public function __rawZRevRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        $zset = $this->simulatedReadArray($key);
        if (empty($zset)) return [];

        arsort($zset, SORT_NUMERIC);
        $keys = array_keys($zset);
        $total = count($keys);
        if ($start < 0) $start = $total + $start;
        if ($stop < 0) $stop = $total + $stop;
        if ($start < 0) $start = 0;
        if ($stop >= $total) $stop = $total - 1;
        if ($start > $stop) return [];

        $length = $stop - $start + 1;
        $sliced = array_slice($zset, $start, $length, true);

        return $withScores ? $sliced : array_keys($sliced);
    }

    public function __rawHGetAll(string $key): array
    {
        $hot = $this->simulatedReadFromHotCache($key);
        if (is_array($hot)) return $hot;

        return $this->simulatedReadArray($key);
    }

    public function __rawHVals(string $key): array
    {
        return array_values($this->__rawHGetAll($key));
    }

    public function __rawHMGet(string $key, string ...$fields): array
    {
        $hash = $this->__rawHGetAll($key);
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $hash[$field] ?? null;
        }
        return $result;
    }

    public function __rawZCount(string $key, float $min, float $max): int
    {
        $zset = $this->simulatedReadArray($key);
        if (empty($zset)) return 0;

        $count = 0;
        foreach ($zset as $score) {
            if ($score >= $min && $score <= $max) {
                $count++;
            }
        }
        return $count;
    }

    public function __rawZIncrBy(string $key, float $increment, mixed $member): float|false
    {
        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($increment, $member) {
            $zset = ($found && is_array($data)) ? $data : [];
            $memberStr = (string)$member;
            $newScore = ($zset[$memberStr] ?? 0.0) + $increment;
            $zset[$memberStr] = $newScore;
            return ['return' => $newScore, 'data' => $zset, 'refresh_hot_cache' => false];
        });

        return is_numeric($result) ? (float)$result : false;
    }

    public function __rawZRangeByScore(string $key, float $min, float $max, bool $withScores = false, int $offset = 0, int $count = 0): array
    {
        $zset = $this->simulatedReadArray($key);
        if (empty($zset)) return [];

        $filtered = [];
        foreach ($zset as $member => $score) {
            if ($score >= $min && $score <= $max) {
                $filtered[$member] = $score;
            }
        }
        asort($filtered, SORT_NUMERIC);

        if ($count > 0) {
            $filtered = array_slice($filtered, $offset, $count, true);
        } elseif ($offset > 0) {
            $filtered = array_slice($filtered, $offset, null, true);
        }

        return $withScores ? $filtered : array_keys($filtered);
    }

    public function __rawZPopMin(string $key, int $count = 1): array|string|false
    {
        return $this->simulatedZPop($key, $count, false);
    }

    public function __rawZPopMax(string $key, int $count = 1): array|string|false
    {
        return $this->simulatedZPop($key, $count, true);
    }

    private function simulatedZPop(string $key, int $count, bool $max): array|string|false
    {
        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($count, $max) {
            if (!$found || !is_array($data) || empty($data)) {
                return ['return' => false, 'skip_save' => true];
            }
            $zset = $data;
            $max ? arsort($zset, SORT_NUMERIC) : asort($zset, SORT_NUMERIC);

            if ($count === 1) {
                $member = array_key_first($zset);
                $score = $zset[$member];
                unset($zset[$member]);
                $return = $max ? $member : [$member => $score];
                if (empty($zset)) {
                    return ['return' => $return, 'delete' => true];
                }
                return ['return' => $return, 'data' => $zset, 'refresh_hot_cache' => false];
            }

            $popped = array_slice($zset, 0, $count, true);
            $zset = array_slice($zset, $count, null, true);
            if (empty($zset)) {
                return ['return' => $popped, 'delete' => true];
            }
            return ['return' => $popped, 'data' => $zset, 'refresh_hot_cache' => false];
        });

        return $result ?? false;
    }

    public function __rawLLen(string $key): int
    {
        $list = $this->simulatedReadArray($key);
        return count($list);
    }

    public function __rawLIndex(string $key, int $index): mixed
    {
        $list = $this->simulatedReadArray($key);
        if (empty($list)) return null;

        $len = count($list);
        if ($index < 0) $index = $len + $index;
        return $list[$index] ?? null;
    }

    public function __rawLSet(string $key, int $index, mixed $value): bool
    {
        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($index, $value) {
            if (!$found || !is_array($data)) {
                return null;
            }
            $list = $data;
            $len = count($list);
            $idx = $index < 0 ? $len + $index : $index;
            if ($idx < 0 || $idx >= $len) {
                return ['return' => false, 'skip_save' => true];
            }
            $list[$idx] = $value;
            return ['return' => true, 'data' => array_values($list)];
        });

        return (bool)$result;
    }

    public function __rawLRange(string $key, int $start, int $stop): array
    {
        $list = $this->simulatedReadArray($key);
        if (empty($list)) return [];

        $len = count($list);
        if ($start < 0) $start = $len + $start;
        if ($stop < 0) $stop = $len + $stop;
        if ($start < 0) $start = 0;
        if ($stop >= $len) $stop = $len - 1;
        if ($start > $stop) return [];

        return array_slice($list, $start, $stop - $start + 1);
    }

    public function __rawLTrim(string $key, int $start, int $stop): bool
    {
        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($start, $stop) {
            if (!$found || !is_array($data)) {
                return ['return' => true, 'skip_save' => true];
            }
            $list = $data;
            $len = count($list);
            $s = $start < 0 ? $len + $start : $start;
            $e = $stop < 0 ? $len + $stop : $stop;
            if ($s < 0) $s = 0;
            if ($e >= $len) $e = $len - 1;
            if ($s > $e) {
                return ['return' => true, 'delete' => true];
            }
            $trimmed = array_slice($list, $s, $e - $s + 1);
            if (empty($trimmed)) {
                return ['return' => true, 'delete' => true];
            }
            return ['return' => true, 'data' => array_values($trimmed)];
        });

        return (bool)($result ?? false);
    }

    public function __rawSPop(string $key, int $count = 1): mixed
    {
        $result = $this->simulatedDriverCasMutate($key, function (bool $found, mixed $data) use ($count) {
            if (!$found || !is_array($data) || empty($data)) {
                return ['return' => $count === 1 ? null : [], 'skip_save' => true];
            }
            $set = array_values($data);
            if ($count === 1) {
                $idx = array_rand($set);
                $value = $set[$idx];
                unset($set[$idx]);
                $set = array_values($set);
                if (empty($set)) {
                    return ['return' => $value, 'delete' => true];
                }
                return ['return' => $value, 'data' => $set];
            }
            $popped = [];
            for ($i = 0; $i < $count && !empty($set); $i++) {
                $idx = array_rand($set);
                $popped[] = $set[$idx];
                unset($set[$idx]);
                $set = array_values($set);
            }
            if (empty($set)) {
                return ['return' => $popped, 'delete' => true];
            }
            return ['return' => $popped, 'data' => $set];
        });

        return $result;
    }

    public function __rawSRandMember(string $key, int $count = 1): mixed
    {
        $set = $this->simulatedReadArray($key);
        if (empty($set)) return $count === 1 ? null : [];

        $set = array_values($set);
        if ($count === 1) {
            return $set[array_rand($set)];
        }

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $set[array_rand($set)];
        }
        return $result;
    }

    public function __rawSDiff(array $keys): array
    {
        if (empty($keys)) return [];

        $base = null;
        foreach ($keys as $key) {
            $set = $this->simulatedReadArray($key);
            if ($base === null) {
                $base = $set;
                continue;
            }
            $base = array_values(array_diff($base, $set));
        }

        return $base ?? [];
    }

    public function __rawGeoPos(string $key, string ...$members): array
    {
        $geo = $this->simulatedReadArray($key);
        $result = [];
        foreach ($members as $member) {
            $result[] = $geo[$member] ?? null;
        }
        return $result;
    }

    public function __rawGeoHash(string $key, string ...$members): array
    {
        $geo = $this->simulatedReadArray($key);
        $result = [];
        foreach ($members as $member) {
            if (!isset($geo[$member]) || !is_array($geo[$member])) {
                $result[] = null;
                continue;
            }
            $pos = $geo[$member];
            $result[] = md5(($pos['lng'] ?? 0) . ',' . ($pos['lat'] ?? 0));
        }
        return $result;
    }

    public function __rawGeoRadiusByMember(string $key, string $member, float $radius, string $unit = 'm'): array
    {
        $geo = $this->simulatedReadArray($key);
        if (!isset($geo[$member]) || !is_array($geo[$member])) return [];

        $center = $geo[$member];
        $radiusM = match (strtolower($unit)) {
            'km' => $radius * 1000,
            'mi' => $radius * 1609.344,
            'ft' => $radius * 0.3048,
            default => $radius,
        };

        $result = [];
        foreach ($geo as $name => $pos) {
            if (!is_array($pos) || !isset($pos['lng'], $pos['lat'])) continue;
            $dist = $this->simulatedGeoDistance($center['lat'], $center['lng'], $pos['lat'], $pos['lng']);
            if ($dist <= $radiusM) {
                $result[$name] = $dist;
            }
        }
        asort($result);
        return $result;
    }

    private function simulatedGeoDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function __rawExpire(string $key, int $ttl): bool
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        return $this->simulatedKeyExpire($key, $ttl);
    }

    public function __rawPersist(string $key): bool
    {
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        return $this->simulatedKeyPersist($key);
    }

    public function __rawRename(string $oldKey, string $newKey): bool
    {
        [$mainKey, $paths] = $this->parseNestedKey($oldKey);
        if ($paths !== null) {
            $oldKey = $mainKey;
        }

        return $this->simulatedKeyRename($oldKey, $newKey);
    }

    abstract protected function simulatedKeyExpire(string $key, int $ttl): bool;
    abstract protected function simulatedKeyPersist(string $key): bool;
    abstract protected function simulatedKeyRename(string $oldKey, string $newKey): bool;
}

