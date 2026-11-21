<?php
/**
 * Redis 驱动 — Hash 原生 __raw*
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisHashRawOps
{
    public function __rawHSet(string $key, string $field, mixed $value): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $serialized = Binary::pack(['data' => $value]);
            $result = $redis->hSet($key, $field, $serialized);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawHMSet(string $key, array $fieldValues): bool
    {
        if (empty($fieldValues)) return true;
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $serialized = [];
            foreach ($fieldValues as $field => $value) {
                $serialized[$field] = Binary::pack(['data' => $value]);
            }
            $result = $redis->hMSet($key, $serialized);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawHGet(string $key, string $field, mixed $default = null): mixed
    {
        if (!$this->isFuseAllow()) return $default;

        try {
            $redis = $this->getClient();
            $serialized = $redis->hGet($key, $field);

            if ($serialized === false || $serialized === null) {
                $this->recordSuccess();
                return $default;
            }

            $unpacked = Binary::tryUnpack($serialized);
            $this->recordSuccess();
            return is_array($unpacked) && array_key_exists('data', $unpacked)
                ? $unpacked['data']
                : $default;
        } catch (Throwable) {
            $this->recordFailure();
            return $default;
        }
    }
    public function __rawHGetAll(string $key): array
    {
        // 优先查本地缓存
        if ($this->enableLocalCache && isset($this->hotCache[$key])) {
            $item = $this->hotCache[$key];
            if ($item['expire'] === 0 || $item['expire'] > time()) {
                return is_array($item['value']) ? $item['value'] : [];
            }
        }

        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $rawResult = $redis->hGetAll($key);

            if (empty($rawResult)) {
                $this->recordSuccess();
                return [];
            }

            // 反序列化所有字段值
            $result = [];
            foreach ($rawResult as $field => $serialized) {
                $unpacked = Binary::tryUnpack($serialized);
                $result[$field] = is_array($unpacked) && array_key_exists('data', $unpacked)
                    ? $unpacked['data']
                    : $serialized;
            }

            // 更新本地缓存
            $ttl = $this->__ttl($key);
            $this->setHotCache($key, $result, $ttl > 0 ? time() + $ttl : 0);

            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawHKeys(string $key): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->hKeys($key);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawHLen(string $key): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->hLen($key);

            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawHDel(string $key, string ...$fields): int
    {
        if (empty($fields)) return 0;
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->hDel($key, ...$fields);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawHExists(string $key, string $field): bool
    {
        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->hExists($key, $field);

            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawHVals(string $key): array
    {
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $rawResult = $redis->hVals($key);

            $this->recordSuccess();
            return is_array($rawResult) ? $rawResult : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawHMGet(string $key, string ...$fields): array
    {
        if (empty($fields)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $rawResult = $redis->hMGet($key, $fields);

            $this->recordSuccess();
            if (!is_array($rawResult)) return [];

            // 反序列化所有字段值
            $result = [];
            foreach ($rawResult as $field => $serialized) {
                if ($serialized === false || $serialized === null) {
                    $result[$field] = null;
                    continue;
                }
                $unpacked = Binary::tryUnpack($serialized);
                $result[$field] = is_array($unpacked) && array_key_exists('data', $unpacked)
                    ? $unpacked['data']
                    : $serialized;
            }
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
}
