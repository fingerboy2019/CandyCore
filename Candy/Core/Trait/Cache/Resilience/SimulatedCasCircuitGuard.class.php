<?php
/**
 * 模拟 CAS 层熔断钩子（Memcached / GlobalData）
 *
 * @package Candy\Core\Trait\Cache\Resilience
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Cache\Resilience;

trait SimulatedCasCircuitGuard
{
    protected function simulatedCasCircuitEnabled(): bool
    {
        return method_exists($this, 'isCircuitBreakerEnabled') && $this->isCircuitBreakerEnabled();
    }

    protected function simulatedCasReportLoad(array $load): void
    {
        if (!$this->simulatedCasCircuitEnabled()) {
            return;
        }

        if (!empty($load['error'])) {
            $this->recordFailure();
        }
    }

    protected function simulatedCasReportCommit(bool $success): void
    {
        if (!$this->simulatedCasCircuitEnabled()) {
            return;
        }

        $this->recordCircuitOutcome($success);
    }

    protected function simulatedCasReportSuccess(): void
    {
        if ($this->simulatedCasCircuitEnabled()) {
            $this->recordSuccess();
        }
    }
}
