<?php
declare(strict_types=1);

namespace Workerman\Crontab;

/**
 * Crontab 客户端（对外调用入口）
 *
 * 发布任务、立即执行、查询状态等，无需自行操作 GlobalData 键
 *
 * 示例：
 *   $client = Client::instance();
 *   $client->publish(['job1' => [...]], true);
 *   $client->run('job1');
 *   $client->status();
 */
class Client
{
    private static ?self $instance = null;

    private Manager $manager;

    /**
     * @param array<string,mixed>|null $config 可选配置覆盖
     */
    public function __construct(?array $config = null)
    {
        $this->manager = Manager::instance($config ?? []);
    }

    public static function instance(?array $config = null): self
    {
        if (self::$instance === null || $config !== null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function isValidExpr(string $expr): bool
    {
        return $this->manager->isValidExpr($expr);
    }

    /**
     * @param array<int|string,array<string,mixed>> $jobs
     * @param array<int|string> $removeIds
     * @return array{ok:bool,message:string,count:int,queue_slots:int,version:int,added:int,updated:int,removed:int,unchanged:int}
     */
    public function publish(array $jobs, bool $strict = false, array $removeIds = []): array
    {
        return $this->manager->publish($jobs, $strict, $removeIds);
    }

    /** @return array<int,array<string,mixed>> */
    public function getJobs(): array
    {
        return $this->manager->getJobs();
    }

    public function findJob(string|int $id): ?array
    {
        return $this->manager->findJob($id);
    }

    /** @return array{code:int,message:string} */
    public function run(string|int $id, string $channel = 'manual'): array
    {
        if (!$this->manager->isConnected()) {
            return ['code' => 1, 'message' => 'GlobalData unavailable'];
        }
        $job = $this->manager->findJob($id);
        if ($job === null) {
            return ['code' => 1, 'message' => 'job not found in GlobalData'];
        }
        return $this->manager->runJob($job, $channel);
    }

    /** @return array{code:int,message:string} */
    public function runJob(array $job, string $channel = 'manual'): array
    {
        return $this->manager->runJob($job, $channel);
    }

    /** @return array<int,array<string,string>> */
    public function handlers(): array
    {
        return $this->manager->listHandlers();
    }

    public function resolveHandler(string $key): ?array
    {
        return $this->manager->resolveHandler($key);
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        return $this->manager->status();
    }

    public function isConnected(): bool
    {
        return $this->manager->isConnected();
    }

    public function getVersion(): int
    {
        return $this->manager->getVersion();
    }

    public function manager(): Manager
    {
        return $this->manager;
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        return $this->manager->dashboard();
    }

    /**
     * @param array<int|string> $ids
     */
    public function purgeJobsData(array $ids): bool
    {
        return $this->manager->purgeJobsData($ids);
    }
}
