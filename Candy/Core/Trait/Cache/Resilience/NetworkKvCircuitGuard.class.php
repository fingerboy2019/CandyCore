<?php
/**
 * 网络 KV 操作熔断包装（Memcached / GlobalData）
 *
 * @package Candy\Core\Trait\Cache\Resilience
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Resilience;

trait NetworkKvCircuitGuard
{
    use CircuitBreaker;

    /**
     * @template T
     * @param callable(): T $operation
     * @param T $fallback
     * @return T
     */
    protected function fuseGuardValue(callable $operation, mixed $fallback): mixed
    {
        if (!$this->isCircuitBreakerEnabled()) {
            return $operation();
        }

        return $this->executeWithFuse($operation, $fallback);
    }

    /**
     * @param callable(): bool $operation
     */
    protected function fuseGuardBool(callable $operation, bool $fallback = false): bool
    {
        if (!$this->isCircuitBreakerEnabled()) {
            return $operation();
        }

        return $this->executeWithFuseBool($operation, $fallback);
    }
}
