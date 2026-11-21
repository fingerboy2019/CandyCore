<?php
/**
 * 分布式锁自旋等待（Redis / Memcached / GlobalData 共用）
 *
 * @package Candy\Core\Trait\Cache\Lock
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Lock;

trait LockSpinLoop
{
    abstract protected function getLockSpinCount(): int;

    abstract protected function getLockRetryIntervalUs(): int;

    /**
     * @param callable(): bool $tryOnce 单次加锁尝试
     */
    protected function lockSpinWait(callable $tryOnce, int $timeoutMs): bool
    {
        $startTime = microtime(true);
        $isBlock = $timeoutMs === -1;

        while (true) {
            for ($i = 0, $spin = $this->getLockSpinCount(); $i < $spin; $i++) {
                if ($tryOnce()) {
                    return true;
                }
            }

            if (!$isBlock) {
                $costMs = (int)((microtime(true) - $startTime) * 1000);
                if ($costMs >= $timeoutMs) {
                    break;
                }
            }

            usleep($this->getLockRetryIntervalUs());
        }

        return false;
    }
}
