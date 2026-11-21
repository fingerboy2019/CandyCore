<?php
/**
 * Redis 驱动 — Stream 流
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Throwable;

trait RedisStreamOps
{
    /** phpredis 6+ 将 xAdd/xRange 第 4 参改为 int，与 5.x 的 options 数组不兼容 */
    private static ?bool $streamTypedApi = null;

    private function redisStreamUsesTypedApi(): bool
    {
        if (self::$streamTypedApi === null) {
            $param = (new \ReflectionMethod(\Redis::class, 'xAdd'))->getParameters()[3] ?? null;
            $type = $param?->getType();
            self::$streamTypedApi = $type instanceof \ReflectionNamedType && $type->getName() === 'int';
        }

        return self::$streamTypedApi;
    }

    /**
     * @param \Redis|\RedisCluster $redis
     */
    private function redisXAdd($redis, string $key, string $id, array $fieldValues, int $maxLen): string|false
    {
        if ($this->redisStreamUsesTypedApi()) {
            return $maxLen > 0
                ? $redis->xAdd($key, $id, $fieldValues, $maxLen, true)
                : $redis->xAdd($key, $id, $fieldValues);
        }

        $options = $maxLen > 0 ? ['MAXLEN' => $maxLen] : [];
        return $redis->xAdd($key, $id, $fieldValues, $options);
    }

    /**
     * @param \Redis|\RedisCluster $redis
     * @return array<string, array<string, string>>|false
     */
    private function redisXRange($redis, string $key, string $start, string $end, int $count): array|false
    {
        if ($this->redisStreamUsesTypedApi()) {
            return $count > 0
                ? $redis->xRange($key, $start, $end, $count)
                : $redis->xRange($key, $start, $end);
        }

        $options = $count > 0 ? ['COUNT' => $count] : [];
        return $redis->xRange($key, $start, $end, $options);
    }

    /**
     * @param \Redis|\RedisCluster $redis
     * @return array<string, array<string, string>>|false
     */
    private function redisXRevRange($redis, string $key, string $start, string $end, int $count): array|false
    {
        if ($this->redisStreamUsesTypedApi()) {
            return $count > 0
                ? $redis->xRevRange($key, $start, $end, $count)
                : $redis->xRevRange($key, $start, $end);
        }

        $options = $count > 0 ? ['COUNT' => $count] : [];
        return $redis->xRevRange($key, $start, $end, $options);
    }

    public function __rawXAdd(string $key, array $fieldValues, ?string $id = '*', int $maxLen = 0): string|false
    {
        // 多维键处理：Stream 仅支持主 Key
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $this->redisXAdd($redis, $key, $id, $fieldValues, $maxLen);
            
            if ($result !== false) {
                $this->recordSuccess();
                return $result;
            } else {
                $this->recordFailure();
                return false;
            }
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawXRange(string $key, string $start = '-', string $end = '+', int $count = 0): array|false
    {
        // 多维键处理
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $rawResult = $this->redisXRange($redis, $key, $start, $end, $count);
            
            if ($rawResult === false) {
                $this->recordFailure();
                return false;
            }
            
            // 格式化结果：与 File/Shmop 对齐 [['id' => ..., 'fields' => [...]], ...]
            $result = [];
            foreach ($rawResult as $id => $fields) {
                $result[] = [
                    'id' => $id,
                    'fields' => $fields
                ];
            }
            
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawXRead(string|array $keys, int $count = 0, int $blockMs = 0): array|false
    {
        if (!is_array($keys)) return false;
        if (empty($keys)) return [];
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $rawResult = $redis->xRead($keys, $count, $blockMs);
            
            if ($rawResult === false) {
                $this->recordSuccess(); // 阻塞超时或无新消息不算失败
                return false;
            }
            
            // 格式化结果：与 File/Shmop 对齐 ['key' => [['id' => ..., 'fields' => [...]], ...]]
            $result = [];
            foreach ($rawResult as $key => $messages) {
                $result[$key] = [];
                foreach ($messages as $id => $fields) {
                    $result[$key][] = [
                        'id' => $id,
                        'fields' => $fields
                    ];
                }
            }
            
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawXGroup(string $key, string $group, string $startId = '0', bool $mkStream = false): bool
    {
        // 多维键处理
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            
            // 调用 XGROUP CREATE
            // 注意：phpredis 的 xGroup 方法参数顺序可能因版本而异，这里使用标准 CREATE 命令
            $result = $redis->xGroup('CREATE', $key, $group, $startId, $mkStream);
            
            if ($result) {
                $this->recordSuccess();
                return true;
            } else {
                $this->recordFailure();
                return false;
            }
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawXReadGroup(string $group, string $consumer, string $key, int $count = 0, int $blockMs = 0): array|false
    {
        // 多维键处理
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $rawResult = $redis->xReadGroup($group, $consumer, [$key => '>'], $count, $blockMs);
            
            if ($rawResult === false) {
                $this->recordSuccess(); // 阻塞超时或无新消息不算失败
                return false;
            }
            
            // 格式化结果：与 File/Shmop 对齐 [['id' => ..., 'fields' => [...]], ...]
            $result = [];
            foreach ($rawResult as $streamKey => $messages) {
                foreach ($messages as $id => $fields) {
                    $result[] = [
                        'id' => $id,
                        'fields' => $fields
                    ];
                }
            }
            
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawXAck(string $key, string $group, array $ids): int|false
    {
        // 多维键处理
        [$mainKey, $paths] = $this->parseNestedKey($key);
        if ($paths !== null) {
            $key = $mainKey;
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            
            // 调用 XACK
            $result = $redis->xAck($key, $group, $ids);
            
            if ($result !== false) {
                $this->recordSuccess();
                return $result;
            } else {
                $this->recordFailure();
                return false;
            }
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function xRevRange(string $key, string $start = '+', string $end = '-', int $count = 0): array|false
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return false;
        }
        return $this->__rawXRevRange($this->prefix($key), $start, $end, $count);
    }
    public function xLen(string $key): int
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        return $this->__rawXLen($this->prefix($key));
    }
    public function xDel(string $key, array $ids): int
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        $result = $this->__rawXDel($this->prefix($key), $ids);
        return $result === false ? 0 : (int)$result;
    }
    public function xTrim(string $key, int $maxLen): int
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0;
        }
        $result = $this->__rawXTrim($this->prefix($key), $maxLen);
        return $result === false ? 0 : (int)$result;
    }
    public function __rawXRevRange(string $key, string $start = '+', string $end = '-', int $count = 0): array|false
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $rawResult = $this->redisXRevRange($redis, $key, $start, $end, $count);

            if ($rawResult === false) {
                $this->recordFailure();
                return false;
            }

            // 格式化结果
            $result = [];
            foreach ($rawResult as $id => $fields) {
                $result[] = [
                    'id' => $id,
                    'fields' => $fields
                ];
            }

            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawXLen(string $key): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->xLen($key);

            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawXDel(string $key, array $ids): int|false
    {
        if (empty($ids)) return 0;
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->xDel($key, $ids);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawXTrim(string $key, int $maxLen): int|false
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->xTrim($key, $maxLen);

            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }

    /** 查询消费者组 Pending 摘要或明细。 */
    public function xPending(string $key, string $group, ?string $start = null, ?string $end = null, int $count = 0, ?string $consumer = null): array|false
    {
        if (!$this->isFuseAllow()) return false;
        try {
            $redis = $this->getClient();
            if (!method_exists($redis, 'xPending')) return false;
            $key = $this->prefix($key);
            if ($start === null || $end === null) {
                return $redis->xPending($key, $group);
            }
            $result = $redis->xPending($key, $group, $start, $end, $count, $consumer);
            return is_array($result) ? $result : false;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }

    /** 接管超时 Pending 消息。 */
    public function xClaim(string $key, string $group, string $consumer, int $minIdleMs, array $ids, array $options = []): array|false
    {
        if (!$this->isFuseAllow()) return false;
        try {
            $redis = $this->getClient();
            if (!method_exists($redis, 'xClaim')) return false;
            $result = $redis->xClaim($this->prefix($key), $group, $consumer, $minIdleMs, $ids, $options);
            return is_array($result) ? $result : false;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }

    /** Redis 6.2+ 自动接管 Pending 消息。 */
    public function xAutoClaim(string $key, string $group, string $consumer, int $minIdleMs, string $startId = '0-0', int $count = 0, array $options = []): array|false
    {
        if (!$this->isFuseAllow()) return false;
        try {
            $redis = $this->getClient();
            if (!method_exists($redis, 'xAutoClaim')) return false;
            $result = $count > 0
                ? $redis->xAutoClaim($this->prefix($key), $group, $consumer, $minIdleMs, $startId, $count, $options)
                : $redis->xAutoClaim($this->prefix($key), $group, $consumer, $minIdleMs, $startId);
            return is_array($result) ? $result : false;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
}
