<?php
/**
 * Redis 驱动 — KV 读写 / 批量 / TTL / Key 元数据
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisKvOps
{
    public function __rawSet(string $key, mixed $value, int $ttl = 0): bool
    {
        if (($nested = $this->dispatchNestedRawSet($key, $value, $ttl)) !== null) {
            return $nested;
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $serialized = Binary::pack(['data' => $value]);
            $expireTimestamp = $ttl > 0 ? time() + $ttl : 0;

            $result = $ttl > 0 
                ? (bool)$redis->set($key, $serialized, ['EX' => $ttl])
                : (bool)$redis->set($key, $serialized);

            if ($result) {
                $this->setHotCache($key, $value, $expireTimestamp);
                $this->recordSuccess();
            } else {
                $this->recordFailure();
            }

            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawGet(string $key, mixed $default = null): mixed
    {
        if (($nested = $this->dispatchNestedRawGet($key, $default)) !== null) {
            return $nested['value'];
        }

        // 优先读本地二级缓存（含负缓存）
        $hot = $this->lookupHotCache($key);
        if ($hot !== null) {
            return $hot['miss'] ? $default : $hot['value'];
        }

        if (!$this->isFuseAllow()) return $default;

        try {
            $redis = $this->getClient();
            $serialized = $redis->get($key);

            if ($serialized === false || $serialized === null) {
                $this->setHotMiss($key);
                $this->recordSuccess();
                return $default;
            }

            // 坏包按缓存未命中处理，并删除远端脏值，避免每次请求重复解包和刷日志。
            $unpacked = Binary::tryUnpack($serialized);
            if ($unpacked === null) {
                $redis->del($key);
                $this->deleteHotCache($key);
                $this->setHotMiss($key);
                $this->recordSuccess();
                return $default;
            }
            $value = is_array($unpacked) && array_key_exists('data', $unpacked)
                ? $unpacked['data']
                : $default;

            $ttl = $this->__ttl($key);
            $this->setHotCache($key, $value, $ttl > 0 ? time() + $ttl : 0);
            $this->recordSuccess();

            return $value;
        } catch (Throwable) {
            $this->recordFailure();
            return $default;
        }
    }
    public function __rawDelete(string $key): bool
    {
        if (($nested = $this->dispatchNestedRawDelete($key)) !== null) {
            return $nested;
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $deleted = $redis->del($key);

            // del 返回删除数量，key 不存在时为 0 仍属成功；仅 false 表示命令失败
            if ($deleted === false) {
                $this->recordFailure();
                return false;
            }

            $this->deleteHotCache($key);
            $this->recordSuccess();

            return true;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawHas(string $key): bool
    {
        // 多维键存在性判断
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $hot = $this->lookupHotCache($mainKey);
            if ($hot !== null && $hot['miss']) {
                return false;
            }
            $data = $this->__rawGet($mainKey);
            if ($data === null) {
                return false;
            }
            return $this->getNestedValue($data, $paths) !== null;
        }

        // 本地负缓存视为不存在
        $hot = $this->lookupHotCache($key);
        if ($hot !== null) {
            return !$hot['miss'];
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = (bool)$redis->exists($key);
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __ttl(string $key): int
    {
        // 多维键TTL查询
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            return $this->__ttl($mainKey);
        }

        if (!$this->isFuseAllow()) return -1;

        try {
            $redis = $this->getClient();
            $ttl = $redis->ttl($key);
            $this->recordSuccess();

            if ($ttl === -2) return -1;
            if ($ttl === -1) return 0;
            return max($ttl, -1);
        } catch (Throwable) {
            $this->recordFailure();
            return -1;
        }
    }
    public function __rawMSet(array $items, int $ttl = 0): bool
    {
        if (empty($items)) return true;
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $pipe = $redis->pipeline();
            $expireTimestamp = $ttl > 0 ? time() + $ttl : 0;

            foreach ($items as $key => $value) {
                // 多维键处理
                [$mainKey, $paths] = $this->parseNestedKey($key);
                if ($paths !== null) {
                    // 多维键回退到单个设置（因为需要读取修改）
                    $this->__rawSet($key, $value, $ttl);
                    continue;
                }

                $serialized = Binary::pack(['data' => $value]);
                if ($ttl > 0) {
                    $pipe->set($key, $serialized, ['EX' => $ttl]);
                } else {
                    $pipe->set($key, $serialized);
                }

                // 预更新本地缓存
                $this->setHotCache($key, $value, $expireTimestamp);
            }

            $replies = $pipe->exec();
            $allSuccess = true;
            
            foreach ($replies as $reply) {
                if ($reply !== true && $reply !== 'OK') {
                    $allSuccess = false;
                    break;
                }
            }

            if ($allSuccess) {
                $this->recordSuccess();
            } else {
                $this->recordFailure();
                // 失败时清理本地缓存
                foreach (array_keys($items) as $key) {
                    $this->deleteHotCache($key);
                }
            }

            return $allSuccess;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawMGet(array $keys, mixed $default = null): array
    {
        $result = array_fill_keys($keys, $default);
        if (empty($keys)) return $result;
        if (!$this->isFuseAllow()) return $result;

        // 优先从本地缓存获取
        $missedKeys = [];
        foreach ($keys as $key) {
            [$mainKey, $paths] = $this->parseNestedKey($key);
            if ($paths !== null) {
                // 多维键单独处理
                $result[$key] = $this->__rawGet($key, $default);
                continue;
            }

            $hot = $this->lookupHotCache($key);
            if ($hot !== null) {
                $result[$key] = $hot['miss'] ? $default : $hot['value'];
            } else {
                $missedKeys[] = $key;
            }
        }

        if (empty($missedKeys)) {
            $this->recordSuccess();
            return $result;
        }

        try {
            $redis = $this->getClient();
            $serializedValues = $redis->mget($missedKeys);
            $now = time();

            foreach ($missedKeys as $index => $key) {
                $serialized = $serializedValues[$index] ?? false;
                
                if ($serialized === false || $serialized === null) {
                    $this->setHotMiss($key);
                    continue;
                }

                $unpacked = Binary::tryUnpack($serialized);
                if ($unpacked === null) {
                    $redis->del($key);
                    $this->deleteHotCache($key);
                    $this->setHotMiss($key);
                    continue;
                }
                $value = is_array($unpacked) && array_key_exists('data', $unpacked)
                    ? $unpacked['data']
                    : $default;
                $result[$key] = $value;

                // 更新本地缓存
                $ttl = $this->__ttl($key);
                $this->setHotCache($key, $value, $ttl > 0 ? $now + $ttl : 0);
            }

            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return $result;
        }
    }
    public function __rawMDelete(array $keys): bool
    {
        if (empty($keys)) return true;
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $pipe = $redis->pipeline();
            $simpleKeys = [];

            foreach ($keys as $key) {
                [$mainKey, $paths] = $this->parseNestedKey($key);
                if ($paths !== null) {
                    // 多维键单独处理
                    $this->__rawDelete($key);
                    continue;
                }
                $simpleKeys[] = $key;
                $pipe->del($key);
                // 预清理本地缓存
                $this->deleteHotCache($key);
            }

            if (!empty($simpleKeys)) {
                $pipe->exec();
            }

            $this->recordSuccess();
            return true;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawMHas(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->__rawHas($key);
        }
        return $result;
    }
    public function __clear(): bool
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            // 仅清当前租户作用域；无 TENANT_ID 时等同配置前缀（默认/平台空间）
            $prefix = $this->scopePrefix();

            if ($this->isCluster && $redis instanceof \RedisCluster) {
                $masters = $redis->_masters();
                foreach ($masters as $master) {
                    $cursor = 0;
                    do {
                        [$cursor, $keys] = $redis->scan($cursor, $master, "{$prefix}*", 1000);
                        if (!empty($keys)) {
                            $redis->del($keys);
                        }
                    } while ($cursor > 0);
                }
            } else {
                $cursor = 0;
                do {
                    [$cursor, $keys] = $redis->scan($cursor, "{$prefix}*", 1000);
                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                } while ($cursor > 0);
            }

            $this->clearHotCache();
            $this->recordSuccess();
            return true;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawIncrByFloat(string $key, float $step): float|false
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->incrByFloat($key, $step);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (float)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawExpire(string $key, int $ttl): bool
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            // ttl=0 表示移除过期（永久）。Redis 的 EXPIRE key 0 会立即删除 key，
            // 必须改用 PERSIST，语义与 Memory/Segment 驱动对齐（$ttl>0 ? now+ttl : 0）
            $result = $ttl > 0 ? $redis->expire($key, $ttl) : $redis->persist($key);

            // 更新本地缓存过期时间
            if ($result && isset($this->hotCache[$key])) {
                $this->hotCache[$key]['expire'] = $ttl > 0 ? time() + $ttl : 0;
            }

            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawPersist(string $key): bool
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->persist($key);

            // 更新本地缓存为永不过期
            if ($result && isset($this->hotCache[$key])) {
                $this->hotCache[$key]['expire'] = 0;
            }

            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawRename(string $oldKey, string $newKey): bool
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->rename($oldKey, $newKey);

            if ($result) {
                // 同步本地缓存
                if (isset($this->hotCache[$oldKey])) {
                    $this->hotCache[$newKey] = $this->hotCache[$oldKey];
                    unset($this->hotCache[$oldKey]);
                }
                if (isset($this->lruRecords[$oldKey])) {
                    $this->lruRecords[$newKey] = $this->lruRecords[$oldKey];
                    unset($this->lruRecords[$oldKey]);
                }
                $this->recordSuccess();
                return true;
            }

            $this->recordFailure();
            return false;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
}
