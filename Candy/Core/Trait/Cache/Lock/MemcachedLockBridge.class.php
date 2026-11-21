<?php
/**
 * Memcached 分布式锁桥接（add + compare-delete）
 *
 * @package Candy\Core\Trait\Cache\Lock
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Lock;

trait MemcachedLockBridge
{
    use DistributedLock;

    abstract protected function getMemcachedClient(): \Memcached;

    protected function getDistributedLockPrefix(): string
    {
        return 'mem_lock_';
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
        return isset($this->heldLocks[$lockName]);
    }

    protected function tryAcquireDistributedLockOnce(string $lockKey, string $lockName): bool
    {
        $lockValue = $this->generateDistributedLockToken();
        if ($this->getMemcachedClient()->add($lockKey, $lockValue, $this->lockTtl)) {
            $this->heldLocks[$lockName] = $lockValue;

            return true;
        }

        return false;
    }

    protected function releaseDistributedLockRemote(string $lockKey, string $lockName): void
    {
        $lockValue = $this->heldLocks[$lockName];
        $mem = $this->getMemcachedClient();
        $data = $mem->get($lockKey, null, \Memcached::GET_EXTENDED);
        if (is_array($data) && ($data['value'] ?? null) === $lockValue) {
            $mem->delete($lockKey);
        }
    }
}
