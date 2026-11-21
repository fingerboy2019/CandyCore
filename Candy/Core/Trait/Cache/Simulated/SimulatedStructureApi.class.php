<?php
/**
 * 模拟结构层公开 API 包装（覆盖 Hash/ZSet/Set/Geo Trait 默认实现）
 *
 * @package Candy\Core\Trait\Cache\Simulated
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Simulated;

trait SimulatedStructureApi
{
    public function hGetAll(string $key): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawHGetAll($this->prefix($key));
    }

    public function hVals(string $key): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawHVals($this->prefix($key));
    }

    public function hMGet(string $key, string ...$fields): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawHMGet($this->prefix($key), ...$fields);
    }

    public function zCount(string $key, float $min, float $max): int
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        return $this->__rawZCount($this->prefix($key), $min, $max);
    }

    public function zIncrBy(string $key, float $increment, mixed $member): float|false
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0.0;
        }
        return $this->__rawZIncrBy($this->prefix($key), $increment, $member);
    }

    public function zRangeByScore(string $key, float $min, float $max, bool $withScore = false, int $offset = 0, int $count = 0): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawZRangeByScore($this->prefix($key), $min, $max, $withScore, $offset, $count);
    }

    public function zPopMin(string $key, int $count = 1): array|string|false
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return false;
        }
        return $this->__rawZPopMin($this->prefix($key), $count);
    }

    public function zPopMax(string $key, int $count = 1): array|string|false
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return false;
        }
        return $this->__rawZPopMax($this->prefix($key), $count);
    }

    public function geoPos(string $key, string ...$members): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawGeoPos($this->prefix($key), ...$members);
    }

    public function geoHash(string $key, string ...$members): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawGeoHash($this->prefix($key), ...$members);
    }

    public function geoRadiusByMember(string $key, string $member, float $radius, string $unit = 'm'): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        return $this->__rawGeoRadiusByMember($this->prefix($key), $member, $radius, $unit);
    }

    public function sPop(string $key, int $count = 1): mixed
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        return $this->__rawSPop($this->prefix($key), $count);
    }

    public function sRandMember(string $key, int $count = 1): mixed
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return null;
        }
        return $this->__rawSRandMember($this->prefix($key), $count);
    }

    public function sDiff(string ...$keys): array
    {
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return [];
        }
        $prefixed = array_map(fn($k) => $this->prefix($k), $keys);
        return $this->__rawSDiff($prefixed);
    }
}
