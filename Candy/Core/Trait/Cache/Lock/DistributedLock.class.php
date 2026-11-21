<?php
/**
 * 分布式锁模板（自旋 + 可重入 + 驱动桥接钩子）
 *
 * @package Candy\Core\Trait\Cache\Lock
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Lock;

use Throwable;

trait DistributedLock
{
    use LockSpinLoop;
    use LockHeldRegistry;

    abstract protected function getDistributedLockPrefix(): string;

    abstract protected function isLockReentrant(string $lockName): bool;

    abstract protected function tryAcquireDistributedLockOnce(string $lockKey, string $lockName): bool;

    abstract protected function releaseDistributedLockRemote(string $lockKey, string $lockName): void;

    protected function buildDistributedLockKey(string $lockName): string
    {
        return $this->getDistributedLockPrefix() . $this->cachePrefix . $lockName;
    }

    protected function generateDistributedLockToken(): string
    {
        return getmypid() . ':' . uniqid('', true);
    }

    protected function beforeDistributedLockAcquire(): bool
    {
        return true;
    }

    protected function afterDistributedLockAcquire(bool $acquired): bool
    {
        return $acquired;
    }

    protected function beforeDistributedLockRelease(): bool
    {
        return true;
    }

    protected function afterDistributedLockRelease(): void
    {
    }

    protected function onDistributedLockError(Throwable $e): void
    {
    }

    public function __acquireLock(string $lockName, ?int $timeout = null): bool
    {
        $timeout ??= $this->lockOutTime;

        if ($this->isLockReentrant($lockName)) {
            return true;
        }

        if (!$this->beforeDistributedLockAcquire()) {
            return false;
        }

        try {
            $lockKey = $this->buildDistributedLockKey($lockName);
            $acquired = $this->lockSpinWait(
                fn (): bool => $this->tryAcquireDistributedLockOnce($lockKey, $lockName),
                $timeout
            );

            return $this->afterDistributedLockAcquire($acquired);
        } catch (Throwable $e) {
            $this->onDistributedLockError($e);

            return false;
        }
    }

    public function __releaseLock(string $lockName): bool
    {
        if (!isset($this->heldLocks[$lockName])) {
            return true;
        }

        if (!$this->beforeDistributedLockRelease()) {
            unset($this->heldLocks[$lockName]);

            return false;
        }

        try {
            $lockKey = $this->buildDistributedLockKey($lockName);
            $this->releaseDistributedLockRemote($lockKey, $lockName);
            $this->afterDistributedLockRelease();
        } catch (Throwable $e) {
            $this->onDistributedLockError($e);
        }

        unset($this->heldLocks[$lockName]);

        return true;
    }
}
