<?php
/**
 * Redis 分布式锁桥接（SET NX PX + Lua 释放 + 熔断钩子）
 *
 * @package Candy\Core\Trait\Cache\Lock
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Lock;

use Throwable;

trait RedisLockBridge
{
    use DistributedLock;

    abstract protected function getRedisClient(): \Redis|\RedisCluster;

    protected function getDistributedLockPrefix(): string
    {
        return 'redis_lock_';
    }

    protected function getLockSpinCount(): int
    {
        return 50;
    }

    protected function getLockRetryIntervalUs(): int
    {
        return $this->retryInterval;
    }

    protected function isLockReentrant(string $lockName): bool
    {
        if (!isset($this->heldLocks[$lockName])) {
            return false;
        }

        $lockKey = $this->buildDistributedLockKey($lockName);

        return $this->__rawHas($lockKey) && $this->__rawGet($lockKey) === $this->heldLocks[$lockName];
    }

    protected function tryAcquireDistributedLockOnce(string $lockKey, string $lockName): bool
    {
        $lockValue = $this->generateDistributedLockToken();
        $ok = $this->getRedisClient()->set($lockKey, $lockValue, ['NX', 'PX' => $this->lockTtl * 1000]);
        if ($ok) {
            $this->heldLocks[$lockName] = $lockValue;

            return true;
        }

        return false;
    }

    protected function releaseDistributedLockRemote(string $lockKey, string $lockName): void
    {
        $lockValue = $this->heldLocks[$lockName];
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
else
    return 0
end
LUA;
        $this->getRedisClient()->eval($script, [$lockKey, $lockValue], 1);
    }

    protected function beforeDistributedLockAcquire(): bool
    {
        return $this->isFuseAllow();
    }

    protected function afterDistributedLockAcquire(bool $acquired): bool
    {
        $this->recordSuccess();

        return $acquired;
    }

    protected function beforeDistributedLockRelease(): bool
    {
        return $this->isFuseAllow();
    }

    protected function afterDistributedLockRelease(): void
    {
        $this->recordSuccess();
    }

    protected function onDistributedLockError(Throwable $e): void
    {
        $this->recordFailure();
    }
}
