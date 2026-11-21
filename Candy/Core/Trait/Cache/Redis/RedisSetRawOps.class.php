<?php
/**
 * Redis 驱动 — Set 原生 __raw*
 *
 * @package Candy\Core\Trait\Cache\Redis
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Redis;

use Candy\Core\Impl\Pack\Binary;
use Throwable;

trait RedisSetRawOps
{
    public function __rawSAdd(string $key, mixed ...$members): int
    {
        if (empty($members)) return 0;
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            // 将所有成员转换为字符串
            $members = array_map(fn($m) => (string)$m, $members);
            $result = $redis->sAdd($key, ...$members);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawSRem(string $key, mixed ...$members): int
    {
        if (empty($members)) return 0;
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $members = array_map(fn($m) => (string)$m, $members);
            $result = $redis->sRem($key, ...$members);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawSMembers(string $key): array
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
            $result = $redis->sMembers($key);

            // 更新本地缓存
            $ttl = $this->__ttl($key);
            $this->setHotCache($key, $result, $ttl > 0 ? time() + $ttl : 0);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawSCard(string $key): int
    {
        if (!$this->isFuseAllow()) return 0;

        try {
            $redis = $this->getClient();
            $result = $redis->sCard($key);

            $this->recordSuccess();
            return (int)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return 0;
        }
    }
    public function __rawSIsMember(string $key, mixed $member): bool
    {
        // 优先查本地缓存
        if ($this->enableLocalCache && isset($this->hotCache[$key])) {
            $item = $this->hotCache[$key];
            if ($item['expire'] === 0 || $item['expire'] > time()) {
                $set = $item['value'];
                if (is_array($set)) {
                    return in_array($member, $set, true);
                }
            }
        }

        if (!$this->isFuseAllow()) return false;

        try {
            $redis = $this->getClient();
            $result = $redis->sIsMember($key, (string)$member);

            $this->recordSuccess();
            return (bool)$result;
        } catch (Throwable) {
            $this->recordFailure();
            return false;
        }
    }
    public function __rawSInter(array $keys): array
    {
        if (empty($keys)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->sInter($keys);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawSUnion(array $keys): array
    {
        if (empty($keys)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->sUnion($keys);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
    public function __rawSPop(string $key, int $count = 1): mixed
    {
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $result = $count === 1 ? $redis->sPop($key) : $redis->sPop($key, $count);

            // 清理本地缓存
            $this->deleteHotCache($key);
            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function __rawSRandMember(string $key, int $count = 1): mixed
    {
        if (!$this->isFuseAllow()) return null;

        try {
            $redis = $this->getClient();
            $result = $count === 1 ? $redis->sRandMember($key) : $redis->sRandMember($key, $count);

            $this->recordSuccess();
            return $result;
        } catch (Throwable) {
            $this->recordFailure();
            return null;
        }
    }
    public function __rawSDiff(array $keys): array
    {
        if (empty($keys)) return [];
        if (!$this->isFuseAllow()) return [];

        try {
            $redis = $this->getClient();
            $result = $redis->sDiff($keys);

            $this->recordSuccess();
            return is_array($result) ? $result : [];
        } catch (Throwable) {
            $this->recordFailure();
            return [];
        }
    }
}
