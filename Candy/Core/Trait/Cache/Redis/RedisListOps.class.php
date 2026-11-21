<?php
/**
 * Redis 驱动 — List 队列与列表
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisListOps
{
    public function __rawIncr(string $key, int $step, int $type): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $num = $type > 0 ? $redis->decrBy($key, $step) : $redis->incrBy($key, $step);
            
            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$num;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawPush(string $key, array $args, bool $recove, int $head = 0): int
    {
        if (empty($key) || empty($args)) return 0;
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            
            if ($recove) {
                $this->__rawDelete($key);
            }

            // 与 KV set 对齐：元素统一 Binary::pack，pop 侧兼容裸字符串
            $packed = [];
            foreach ($args as $arg) {
                $packed[] = Binary::pack(['data' => $arg]);
            }

            $length = $head === 0 
                ? $redis->lPush($key, ...$packed)
                : $redis->rPush($key, ...$packed);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$length;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawPop(string $key, int $head = 0): mixed
    {
        if (empty($key)) return null;
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $rawValue = $head === 0 ? $redis->lPop($key) : $redis->rPop($key);
            
            if ($rawValue === false) {
                $this->recordSuccess();
                return null;
            }

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            
            // 尝试反序列化（兼容普通字符串和Binary打包的数据）
            $unpacked = Binary::tryUnpack($rawValue);
            return is_array($unpacked) && array_key_exists('data', $unpacked)
                ? $unpacked['data']
                : $rawValue;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function __rawRange(string $key,int $offset,int $length = 0, $sort = '',string $order = 'asc'): array|false
    {
        if (empty($key)) return false;
        
        // 多维键支持
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }
        
        try {
            $redis = $this->getClient();
            $serialized = $redis->get($key);

            if ($serialized === false || $serialized === null) {
                $this->setHotMiss($key);
                $this->recordSuccess();
                return false;
            }

            $unpacked = Binary::tryUnpack($serialized);
            if (!is_array($unpacked) || !array_key_exists('data', $unpacked) || $unpacked['data'] === null) {
                return false;
            }
            $array = $unpacked['data'];
            
            if ($paths !== null) {
                $array = $this->getNestedValue($array, $paths);
                if ($array === null) return false;
            }
            if(!is_array($array) || count($array) == 0) return false;
            
            //排序
            if(empty($sort) === false){
                usort($array, function($a, $b) use ($sort, $order) {
                    if (isset($a[$sort]) === false || isset($b[$sort]) === false) {
                        return 0; // 如果键不存在，认为相等
                    }

                    // 使用太空船运算符进行比较
                    $comparison = $a[$sort] <=> $b[$sort];

                    // 根据排序顺序反转比较结果
                    return $order === 'desc' ? -$comparison : $comparison;
                });
            }
            
            return $length === 0 ? $array : array_slice($array, $offset, $length);
        } catch (Throwable) {}

        return false;
    }
    public function __rawLLen(string $key): int
    {
        if (!$this->isFuseAllow()) return 0;

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
    public function __rawLIndex(string $key, int $index): mixed
    {
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $serialized = $redis->lIndex($key, $index);

            if ($serialized === false || $serialized === null) {
                $this->recordSuccess();
                return null;
            }

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
    public function __rawLSet(string $key, int $index, mixed $value): bool
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $serialized = Binary::pack(['data' => $value]);
            $result = $redis->lSet($key, $index, $serialized);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawLRange(string $key, int $start, int $stop): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $serialized = $redis->lRange($key, $start, $stop);

            if (empty($serialized)) {
                $this->recordSuccess();
                return [];
            }

            // 反序列化所有元素
            $result = [];
            foreach ($serialized as $item) {
                $unpacked = Binary::tryUnpack($item);
                $result[] = is_array($unpacked) && array_key_exists('data', $unpacked)
                    ? $unpacked['data']
                    : $item;
            }

            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawLTrim(string $key, int $start, int $stop): bool
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->lTrim($key, $start, $stop);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
}
