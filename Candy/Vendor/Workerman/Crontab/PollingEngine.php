<?php
declare(strict_types=1);

namespace Workerman\Crontab;

/**
 * GlobalData 执行队列轮询引擎（自适应间隔）
 */
class PollingEngine
{
    private Manager $manager;

    private int $queryRemain = 0;

    private int $queryEmptyCount = 0;

    private int $queryInterval;

    private int $queryIntervalMax;

    private int $queryEmptyCountMax;

    public function __construct(Manager $manager, array $config = [])
    {
        $this->manager = $manager;
        $this->queryInterval = (int) ($config['queryInterval'] ?? 0);
        $this->queryIntervalMax = (int) ($config['queryIntervalMax'] ?? 5);
        $this->queryEmptyCountMax = (int) ($config['queryEmptyCountMax'] ?? 2);
    }

    public function resetPolling(): void
    {
        $this->queryRemain = 0;
        $this->queryEmptyCount = 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function tick(): array
    {
        if ($this->queryRemain > 0) {
            $this->queryRemain--;
            return [];
        }

        try {
            $drain = $this->manager->drainDueTasks();
        } catch (\Throwable) {
            $this->queryRemain = $this->queryIntervalMax;
            return [];
        }

        $tasks = $drain['tasks'] ?? [];
        $nextRunAt = (int) ($drain['next_run_at'] ?? 0);

        if ($tasks !== []) {
            $this->applyNextRunBackoff($nextRunAt);
            $this->queryEmptyCount = 0;
            return is_array($tasks) ? $tasks : [];
        }

        if ($nextRunAt > 0) {
            $this->applyNextRunBackoff($nextRunAt);
            $this->queryEmptyCount = 0;
            return [];
        }

        $this->applyEmptyBackoff();
        return [];
    }

    public function reschedule(array $job): void
    {
        $id = $job['id'] ?? $job['jid'] ?? '';
        if ($id === '' || $id === 0) {
            return;
        }
        $this->manager->reschedule($id, $job);
    }

    private function applyNextRunBackoff(int $nextRunAt): void
    {
        if ($nextRunAt <= 0) {
            $this->queryRemain = $this->queryIntervalMax;
            return;
        }

        $remain = $nextRunAt - time();
        if ($remain > 1) {
            $this->queryRemain = $remain > $this->queryIntervalMax ? $this->queryIntervalMax : $remain;
        }
    }

    private function applyEmptyBackoff(): void
    {
        $this->queryEmptyCount++;
        if ($this->queryEmptyCount < $this->queryEmptyCountMax) {
            return;
        }

        $base = max($this->queryInterval, 1);
        $this->queryRemain = (int) min($base * 2, $this->queryIntervalMax);
        $this->queryEmptyCount = 0;
    }
}
