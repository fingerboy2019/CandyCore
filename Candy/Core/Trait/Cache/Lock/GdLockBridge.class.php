<?php
/**
 * GlobalData 分布式锁桥接（CAS {pid, expire}）
 *
 * @package Candy\Core\Trait\Cache\Lock
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Lock;

use GlobalData\Client;

trait GdLockBridge
{
    use DistributedLock;

    abstract protected function getGdClient(): Client;

    protected function getDistributedLockPrefix(): string
    {
        return 'gd_lock_';
    }

    protected function getLockSpinCount(): int
    {
        return $this->spinCount;
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

        $lock = $this->heldLocks[$lockName];

        return is_array($lock) && ($lock['expire'] ?? 0) > time();
    }

    protected function tryAcquireDistributedLockOnce(string $lockKey, string $lockName): bool
    {
        $gd = $this->getGdClient();
        $old = $gd->$lockKey;
        if ($old !== null && !(is_array($old) && ($old['expire'] ?? 0) < time())) {
            return false;
        }

        $newLock = [
            'pid' => getmypid(),
            'expire' => time() + $this->lockTtl,
        ];
        if ($gd->cas($lockKey, $old, $newLock)) {
            $this->heldLocks[$lockName] = $newLock;

            return true;
        }

        return false;
    }

    protected function releaseDistributedLockRemote(string $lockKey, string $lockName): void
    {
        $gd = $this->getGdClient();
        $currentLock = $gd->$lockKey;
        $held = $this->heldLocks[$lockName];

        if (
            is_array($currentLock)
            && is_array($held)
            && ($currentLock['pid'] ?? null) === ($held['pid'] ?? null)
            && ($currentLock['expire'] ?? 0) > time()
        ) {
            unset($gd->$lockKey);
        }
    }
}
