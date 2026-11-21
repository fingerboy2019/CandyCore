<?php
/**
 * 已持有锁登记与 shutdown 释放
 *
 * @package Candy\Core\Trait\Cache\Lock
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Lock;

trait LockHeldRegistry
{
    /** @var array<string, mixed> */
    protected array $heldLocks = [];

    public function __releaseAllLocksOnShutdown(): void
    {
        foreach (array_keys($this->heldLocks) as $lockName) {
            $this->__releaseLock($lockName);
        }
    }
}
