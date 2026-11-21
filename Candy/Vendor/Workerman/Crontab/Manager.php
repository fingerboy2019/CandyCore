<?php
declare(strict_types=1);

namespace Workerman\Crontab;

use GlobalData\Client as GdClient;
use Throwable;

/**
 * Crontab 调度管理器
 *
 * Crontab 表达式解析 + GlobalData 任务存储 + 时间戳执行队列 + 单例锁 + 处理器执行
 * 依赖：Workerman GlobalData、PHP 8+
 */
class Manager
{
    private static ?self $instance = null;

    /** @var array<string,mixed> */
    private array $config;

    private ?Parser $parser = null;

    private ?GdClient $client = null;

    private string $jobsKey;

    private string $queueKey;

    private string $versionKey;

    private string $lockPrefix;

    private string $runSummaryKey;

    private string $runLogsKey;

    public static function instance(?array $config = null): self
    {
        if (self::$instance === null || $config !== null) {
            self::$instance = new self($config ?? []);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = Config::get($config);
        $prefix = (string) ($this->config['prefix'] ?? 'workerman_crontab_');
        $this->jobsKey = $prefix . 'jobs';
        $this->queueKey = $prefix . 'queue';
        $this->versionKey = $prefix . 'version';
        $this->lockPrefix = $prefix . 'lock_';
        $this->runSummaryKey = $prefix . 'run_summary';
        $this->runLogsKey = $prefix . 'run_logs';
    }

    public function isValidExpr(string $expr): bool
    {
        $expr = trim($expr);
        return $expr !== '' && $this->getParser()->isValid($expr);
    }

    public function computeNextRunTimestamp(string $expr, int $after = 0): int
    {
        $expr = trim($expr);
        if ($expr === '' || !$this->isValidExpr($expr)) {
            return 0;
        }

        try {
            return $this->getParser()->nextRunTimestamp($expr, $after > 0 ? $after : time());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 合并发布任务到 GlobalData（查漏补缺，非全量覆盖）
     *
     * @param array<int|string,array<string,mixed>> $jobs 待写入/更新的任务
     * @param bool $strict GlobalData 不可用时是否返回失败
     * @param array<int|string> $removeIds 应从 GD 移除的任务 ID（暂停、删除等）
     * @return array{ok:bool,message:string,count:int,queue_slots:int,version:int,added:int,updated:int,removed:int,unchanged:int}
     */
    public function publish(array $jobs, bool $strict = false, array $removeIds = []): array
    {
        $empty = [
            'ok' => false,
            'message' => 'GlobalData unavailable',
            'count' => 0,
            'queue_slots' => 0,
            'version' => 0,
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
        ];

        $client = $this->getClient(false);
        if ($client === null) {
            if ($strict) {
                return $empty;
            }
            return array_merge($empty, ['ok' => true, 'message' => 'skipped']);
        }

        $existing = $this->getJobsMap($client);
        $added = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            $normalized = $this->normalizeJob($job);
            $id = $normalized['id'];
            if ($id === '' || !$normalized['enabled']) {
                continue;
            }
            $key = (string) $id;
            $prev = $existing[$key] ?? null;
            if (is_array($prev) && $this->jobDefinitionEquals($prev, $normalized)) {
                $unchanged++;
                continue;
            }
            if ($prev === null) {
                $added++;
            } else {
                $updated++;
            }
            $existing[$key] = $normalized;
        }

        $removed = 0;
        foreach ($removeIds as $rid) {
            $key = (string) $rid;
            if ($key === '' || !isset($existing[$key])) {
                continue;
            }
            unset($existing[$key]);
            $removed++;
        }

        if ($added === 0 && $updated === 0 && $removed === 0) {
            return [
                'ok' => true,
                'message' => 'noop',
                'count' => count($existing),
                'queue_slots' => 0,
                'version' => $this->getVersion(),
                'added' => 0,
                'updated' => 0,
                'removed' => 0,
                'unchanged' => $unchanged,
            ];
        }

        try {
            $client->{$this->jobsKey} = $existing;
            $queueSlots = $this->rebuildQueue(array_values($existing), $client);
            $version = time();
            $client->{$this->versionKey} = $version;
            return [
                'ok' => true,
                'message' => 'published',
                'count' => count($existing),
                'queue_slots' => $queueSlots,
                'version' => $version,
                'added' => $added,
                'updated' => $updated,
                'removed' => $removed,
                'unchanged' => $unchanged,
            ];
        } catch (Throwable $e) {
            return array_merge($empty, ['message' => $e->getMessage()]);
        }
    }

    /**
     * 从 GlobalData 清除任务相关的全部运行数据
     *
     * 移除 jobs/queue 条目、单例锁、run_summary、run_logs
     *
     * @param array<int|string> $ids 任务 ID 列表
     * @return bool 是否处理成功（GD 未连接时返回 false）
     */
    public function purgeJobsData(array $ids): bool
    {
        $ids = array_values(array_filter(array_map(static fn($id) => (string) $id, $ids), static fn(string $id): bool => $id !== '' && $id !== '0'));
        if ($ids === []) {
            return true;
        }

        $client = $this->getClient(false);
        if ($client === null) {
            return false;
        }

        try {
            $this->publish([], false, $ids);

            $summary = $client->{$this->runSummaryKey} ?? [];
            if (is_array($summary)) {
                foreach ($ids as $id) {
                    unset($summary[$id]);
                }
                $client->{$this->runSummaryKey} = $summary;
            }

            $logs = $client->{$this->runLogsKey} ?? [];
            if (is_array($logs)) {
                foreach ($ids as $id) {
                    unset($logs[$id]);
                }
                $client->{$this->runLogsKey} = $logs;
            }

            foreach ($ids as $id) {
                try {
                    unset($client->{$this->lockPrefix . $id});
                } catch (Throwable) {
                }
            }

            $client->{$this->versionKey} = time();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 读取 GlobalData 任务映射（id => job）
     *
     * @return array<string,array<string,mixed>>
     */
    public function getJobsMap(?GdClient $client = null): array
    {
        $client = $client ?? $this->getClient(false);
        if ($client === null) {
            return [];
        }
        try {
            $jobs = $client->{$this->jobsKey} ?? [];
            if (!is_array($jobs)) {
                return [];
            }
            $map = [];
            foreach ($jobs as $id => $job) {
                if (!is_array($job)) {
                    continue;
                }
                $normalized = $this->normalizeJob($job);
                $key = (string) ($normalized['id'] !== '' ? $normalized['id'] : $id);
                if ($key === '') {
                    continue;
                }
                $map[$key] = $normalized;
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 比较两任务定义是否一致（用于查漏补缺）
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return bool
     */
    public function jobDefinitionEquals(array $a, array $b): bool
    {
        $na = $this->normalizeJob($a);
        $nb = $this->normalizeJob($b);
        foreach (['id', 'name', 'handler', 'cron_expr', 'enabled', 'singleton'] as $field) {
            if ($na[$field] !== $nb[$field]) {
                return false;
            }
        }
        return $na['params'] == $nb['params'];
    }

    /** @return array<int,array<string,mixed>> */
    public function getJobs(): array
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return [];
        }
        try {
            $jobs = $client->{$this->jobsKey} ?? [];
            return is_array($jobs) ? array_values($jobs) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function findJob(string|int $id): ?array
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return null;
        }
        try {
            $jobs = $client->{$this->jobsKey} ?? [];
            if (!is_array($jobs) || !isset($jobs[$id])) {
                return null;
            }
            $job = $jobs[$id];
            return is_array($job) ? $job : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function getVersion(): int
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return 0;
        }
        try {
            return (int) ($client->{$this->versionKey} ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public function rebuildQueue(?array $jobs = null, ?GdClient $client = null): int
    {
        $client = $client ?? $this->getClient(false);
        if ($client === null) {
            return 0;
        }

        if ($jobs === null) {
            $jobs = $this->getJobs();
        }

        $queue = [];
        $now = time();
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            $normalized = $this->normalizeJob($job);
            if ($normalized['id'] === '' || !$normalized['enabled']) {
                continue;
            }
            $expr = $normalized['cron_expr'];
            if ($expr === '') {
                continue;
            }
            $next = $this->computeNextRunTimestamp($expr, $now - 1);
            if ($next <= 0) {
                continue;
            }
            if (!isset($queue[$next])) {
                $queue[$next] = [];
            }
            $queue[$next][] = $normalized;
        }

        try {
            if ($queue !== []) {
                ksort($queue);
                $client->{$this->queueKey} = $queue;
            } else {
                unset($client->{$this->queueKey});
            }
        } catch (Throwable) {
            return 0;
        }

        return count($queue);
    }

    /** @return array{tasks:array,next_run_at:int,has_queue:bool} */
    public function drainDueTasks(): array
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return ['tasks' => [], 'next_run_at' => 0, 'has_queue' => false];
        }

        try {
            $queue = $client->{$this->queueKey} ?? [];
        } catch (Throwable) {
            return ['tasks' => [], 'next_run_at' => 0, 'has_queue' => false];
        }

        if (!is_array($queue) || $queue === []) {
            return ['tasks' => [], 'next_run_at' => 0, 'has_queue' => false];
        }

        $now = time();
        $toRun = [];
        $remaining = [];

        foreach ($queue as $runTime => $taskList) {
            $runTime = (int) $runTime;
            if ($runTime <= $now) {
                if (is_array($taskList)) {
                    foreach ($taskList as $task) {
                        if (is_array($task)) {
                            $toRun[] = $task;
                        }
                    }
                }
            } else {
                $remaining[$runTime] = $taskList;
            }
        }

        if ($toRun !== []) {
            try {
                if ($remaining !== []) {
                    $client->{$this->queueKey} = $remaining;
                } else {
                    unset($client->{$this->queueKey});
                }
            } catch (Throwable) {
            }
        }

        return [
            'tasks' => $toRun,
            'next_run_at' => $remaining !== [] ? (int) min(array_keys($remaining)) : 0,
            'has_queue' => $remaining !== [] || $toRun !== [],
        ];
    }

    public function reschedule(string|int $id, array $job): void
    {
        if ($id === '' || $id === 0) {
            return;
        }

        $normalized = $this->normalizeJob($job);
        $expr = $normalized['cron_expr'];
        if ($expr === '') {
            return;
        }

        $next = $this->computeNextRunTimestamp($expr, time());
        if ($next <= 0) {
            return;
        }

        $client = $this->getClient(false);
        if ($client === null) {
            return;
        }

        try {
            $queue = $client->{$this->queueKey} ?? [];
            if (!is_array($queue)) {
                $queue = [];
            }
            if (!isset($queue[$next])) {
                $queue[$next] = [];
            }
            $queue[$next][] = $normalized;
            ksort($queue);
            $client->{$this->queueKey} = $queue;
        } catch (Throwable) {
        }
    }

    public function acquireLock(string|int $id, int $ttl = 300): bool
    {
        if ($id === '' || $id === 0) {
            return false;
        }

        $client = $this->getClient(false);
        if ($client === null) {
            return true;
        }

        $key = $this->lockPrefix . $id;
        $now = time();
        $token = ['pid' => getmypid(), 'expire' => $now + $ttl];

        try {
            $current = $client->{$key} ?? null;
            if (is_array($current) && (int) ($current['expire'] ?? 0) > $now) {
                return false;
            }
            if ($client->cas($key, $current, $token)) {
                return true;
            }
            $current = $client->{$key} ?? null;
            if (is_array($current) && (int) ($current['expire'] ?? 0) <= $now) {
                return (bool) $client->cas($key, $current, $token);
            }
            return false;
        } catch (Throwable) {
            return true;
        }
    }

    public function releaseLock(string|int $id): void
    {
        if ($id === '' || $id === 0) {
            return;
        }

        $client = $this->getClient(false);
        if ($client === null) {
            return;
        }

        try {
            unset($client->{$this->lockPrefix . $id});
        } catch (Throwable) {
        }
    }

    /** @return array{code:int,message:string} */
    public function runJob(array $job, string $channel = 'worker'): array
    {
        $normalized = $this->normalizeJob($job);
        if (!$normalized['enabled']) {
            return ['code' => 1, 'message' => 'job disabled'];
        }

        $handler = $this->resolveHandler($normalized['handler']);
        if ($handler === null) {
            return ['code' => 1, 'message' => 'handler invalid'];
        }

        $params = is_array($normalized['params']) ? $normalized['params'] : [];
        $startedAt = microtime(true);

        try {
            $instance = new $handler['class']();
            $method = $handler['method'];
            if (!method_exists($instance, $method)) {
                $out = ['code' => 1, 'message' => 'handler method missing'];
            } else {
                $result = $instance->{$method}($params);
                $out = [
                    'code' => (int) ($result['code'] ?? 1),
                    'message' => (string) ($result['message'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $out = ['code' => 1, 'message' => $e->getMessage()];
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->dispatchFinished($normalized, $channel, $out, $durationMs);

        return $out;
    }

    /** @return array<int,array<string,string>> */
    public function listHandlers(): array
    {
        $handlers = $this->config['handlers'] ?? [];
        $list = [];
        foreach ($handlers as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $list[] = [
                'id' => (string) $key,
                'title' => (string) ($item['title'] ?? $key),
                'desc' => (string) ($item['desc'] ?? ''),
            ];
        }
        return $list;
    }

    public function resolveHandler(string $handlerKey): ?array
    {
        $item = $this->config['handlers'][$handlerKey] ?? null;
        if (!is_array($item) || empty($item['class'])) {
            return null;
        }
        return [
            'class' => (string) $item['class'],
            'method' => (string) ($item['method'] ?? 'run'),
            'title' => (string) ($item['title'] ?? $handlerKey),
        ];
    }

    public function isConnected(): bool
    {
        return $this->getClient(false) !== null;
    }

    /**
     * 要求 GlobalData 必须可用（Server 启动与严格执行路径）
     *
     * @throws \RuntimeException 网关未配置或连接失败时抛出
     * @return GdClient
     */
    public function requireGlobalData(): GdClient
    {
        $gateway = $this->resolveGateway();
        if ($gateway === '') {
            throw new \RuntimeException('globalDataGateway not configured');
        }

        return $this->getClient(true);
    }

    public function resolveGateway(): string
    {
        return trim((string) ($this->config['globalDataGateway'] ?? ''));
    }

    /** @return array<string,int> */
    public function getEngineConfig(): array
    {
        $defaults = [
            'queryInterval' => 0,
            'queryIntervalMax' => 5,
            'queryEmptyCountMax' => 2,
            'bootstrapDelay' => 5,
            'gdReadyMaxAttempts' => 30,
            'versionWatchInterval' => 2,
        ];

        $fromLib = is_array($this->config['engine'] ?? null) ? $this->config['engine'] : [];
        $defaults = array_merge($defaults, array_intersect_key($fromLib, $defaults));

        $__processInc = function_exists('incResolve') ? incResolve('Process') : (defined('CANDY') && function_exists('C') ? C('INCPATH') . 'Process.inc.php' : '');
        if ($__processInc !== '' && is_file($__processInc) && function_exists('loadConfig')) {
            $process = loadConfig('Process');
            $fromProcess = is_array($process['scheduler']['engine'] ?? null) ? $process['scheduler']['engine'] : [];
            $defaults = array_merge($defaults, array_intersect_key($fromProcess, $defaults));
        }

        return $defaults;
    }

    /** @return array{job_count:int,queue_slots:int,version:int} */
    public function bootstrap(): array
    {
        $this->requireGlobalData();
        $jobs = $this->getJobs();
        $slots = $this->rebuildQueue($jobs);
        return [
            'job_count' => count($jobs),
            'queue_slots' => $slots,
            'version' => $this->getVersion(),
        ];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $connected = $this->isConnected();
        $queueSlots = 0;
        if ($connected) {
            $client = $this->getClient(false);
            try {
                $queue = $client->{$this->queueKey} ?? [];
                $queueSlots = is_array($queue) ? count($queue) : 0;
            } catch (Throwable) {
            }
        }

        return [
            'gateway' => $this->resolveGateway(),
            'globaldata_connected' => $connected,
            'job_count' => $connected ? count($this->getJobs()) : 0,
            'queue_slot_count' => $queueSlots,
            'version' => $connected ? $this->getVersion() : 0,
        ];
    }

    /**
     * GlobalData 看板数据（任务定义、执行队列、版本与锁状态）
     *
     * @return array<string,mixed>
     */
    public function dashboard(): array
    {
        $prefix = (string) ($this->config['prefix'] ?? 'workerman_crontab_');
        $connected = $this->isConnected();
        $empty = [
            'connected' => false,
            'gateway' => $this->resolveGateway(),
            'prefix' => $prefix,
            'keys' => [
                'jobs' => $this->jobsKey,
                'queue' => $this->queueKey,
                'version' => $this->versionKey,
                'lock_prefix' => $this->lockPrefix,
                'run_summary' => $this->runSummaryKey,
                'run_logs' => $this->runLogsKey,
            ],
            'version' => 0,
            'version_at' => '',
            'jobs' => [],
            'queue' => [],
            'queue_slot_count' => 0,
            'next_run_ts' => 0,
            'next_run_at' => '',
            'due_slot_count' => 0,
        ];

        if (!$connected) {
            return $empty;
        }

        $client = $this->getClient(false);
        $version = $this->getVersion();
        $now = time();
        $jobsBoard = [];

        foreach ($this->getJobs() as $job) {
            if (!is_array($job)) {
                continue;
            }
            $normalized = $this->normalizeJob($job);
            $id = $normalized['id'];
            $locked = false;
            if ($client !== null && $id !== '' && $id !== 0) {
                try {
                    $lock = $client->{$this->lockPrefix . $id} ?? null;
                    $locked = is_array($lock) && (int) ($lock['expire'] ?? 0) > $now;
                } catch (Throwable) {
                }
            }
            $nextTs = $normalized['enabled']
                ? $this->computeNextRunTimestamp($normalized['cron_expr'], $now)
                : 0;
            $jobsBoard[] = [
                'id' => $normalized['id'],
                'name' => $normalized['name'],
                'handler' => $normalized['handler'],
                'cron_expr' => $normalized['cron_expr'],
                'enabled' => $normalized['enabled'],
                'singleton' => $normalized['singleton'],
                'next_run_ts' => $nextTs,
                'next_run_at' => $nextTs > 0 ? date('Y-m-d H:i:s', $nextTs) : '',
                'locked' => $locked,
            ];
        }

        usort($jobsBoard, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        $queueBoard = [];
        $nextRunTs = 0;
        $dueSlotCount = 0;

        if ($client !== null) {
            try {
                $queue = $client->{$this->queueKey} ?? [];
            } catch (Throwable) {
                $queue = [];
            }

            if (is_array($queue)) {
                foreach ($queue as $runTs => $taskList) {
                    $runTs = (int) $runTs;
                    $slotJobs = [];
                    if (is_array($taskList)) {
                        foreach ($taskList as $task) {
                            if (!is_array($task)) {
                                continue;
                            }
                            $n = $this->normalizeJob($task);
                            $slotJobs[] = [
                                'id' => $n['id'],
                                'name' => $n['name'],
                                'handler' => $n['handler'],
                            ];
                        }
                    }
                    $due = $runTs <= $now;
                    if ($due) {
                        $dueSlotCount++;
                    }
                    if (!$due && ($nextRunTs === 0 || $runTs < $nextRunTs)) {
                        $nextRunTs = $runTs;
                    }
                    $queueBoard[] = [
                        'run_ts' => $runTs,
                        'run_at' => date('Y-m-d H:i:s', $runTs),
                        'due' => $due,
                        'job_count' => count($slotJobs),
                        'jobs' => $slotJobs,
                    ];
                }
            }
        }

        usort($queueBoard, static fn(array $a, array $b): int => $a['run_ts'] <=> $b['run_ts']);

        return [
            'connected' => true,
            'gateway' => $this->resolveGateway(),
            'prefix' => $prefix,
            'keys' => [
                'jobs' => $this->jobsKey,
                'queue' => $this->queueKey,
                'version' => $this->versionKey,
                'lock_prefix' => $this->lockPrefix,
                'run_summary' => $this->runSummaryKey,
                'run_logs' => $this->runLogsKey,
            ],
            'version' => $version,
            'version_at' => $version > 0 ? date('Y-m-d H:i:s', $version) : '',
            'jobs' => $jobsBoard,
            'queue' => $queueBoard,
            'queue_slot_count' => count($queueBoard),
            'next_run_ts' => $nextRunTs,
            'next_run_at' => $nextRunTs > 0 ? date('Y-m-d H:i:s', $nextRunTs) : '',
            'due_slot_count' => $dueSlotCount,
        ];
    }

    /**
     * 执行记录保留天数（GlobalData run_logs）
     */
    public function getRetentionDays(): int
    {
        $record = $this->config['record'] ?? [];
        return max(1, (int) ($record['retention_days'] ?? 7));
    }

    /**
     * 写入 GlobalData 执行摘要与明细（不落 MySQL）
     *
     * @param array<string,mixed> $job 任务快照
     * @param string $channel 触发渠道
     * @param array{code:int,message:string} $result 执行结果
     * @param int $durationMs 耗时毫秒
     * @return void
     */
    public function recordRun(array $job, string $channel, array $result, int $durationMs): void
    {
        $normalized = $this->normalizeJob($job);
        $id = $normalized['id'];
        if ($id === '' || $id === 0) {
            return;
        }

        $client = $this->getClient(false);
        if ($client === null) {
            return;
        }

        $status = ((int) ($result['code'] ?? 1)) === 0 ? 0 : 1;
        $message = mb_substr((string) ($result['message'] ?? ''), 0, 500);
        $runAt = time();
        $rid = $runAt . '_' . bin2hex(random_bytes(4));

        $summary = [
            'run_at' => $runAt,
            'run_at_fmt' => date('Y-m-d H:i:s', $runAt),
            'status' => $status,
            'message' => $message,
            'duration_ms' => max(0, $durationMs),
            'channel' => $channel,
        ];
        $entry = array_merge(['rid' => $rid], $summary);

        try {
            $summaries = $client->{$this->runSummaryKey} ?? [];
            if (!is_array($summaries)) {
                $summaries = [];
            }
            $summaries[(string) $id] = $summary;
            $client->{$this->runSummaryKey} = $summaries;

            $logs = $client->{$this->runLogsKey} ?? [];
            if (!is_array($logs)) {
                $logs = [];
            }
            $jidKey = (string) $id;
            $list = is_array($logs[$jidKey] ?? null) ? $logs[$jidKey] : [];
            $list[] = $entry;
            $logs[$jidKey] = $this->pruneRunLogEntries($list);
            $client->{$this->runLogsKey} = $logs;
        } catch (Throwable) {
        }
    }

    /**
     * 读取单任务 GlobalData 执行摘要
     *
     * @param string|int $id 任务 ID
     * @return array<string,mixed>|null
     */
    public function getRunSummary(string|int $id): ?array
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return null;
        }
        try {
            $summaries = $client->{$this->runSummaryKey} ?? [];
            if (!is_array($summaries)) {
                return null;
            }
            $row = $summaries[(string) $id] ?? null;
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 读取全部任务 GlobalData 执行摘要
     *
     * @return array<string,array<string,mixed>>
     */
    public function getAllRunSummaries(): array
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return [];
        }
        try {
            $summaries = $client->{$this->runSummaryKey} ?? [];
            return is_array($summaries) ? $summaries : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 分页读取 GlobalData 执行明细
     *
     * @param string|int $id 任务 ID
     * @param int $page 页码
     * @param int $limit 每页条数
     * @return array{count:int,data:array<int,array<string,mixed>>}
     */
    public function listRunLogs(string|int $id, int $page = 1, int $limit = 20): array
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return ['count' => 0, 'data' => []];
        }

        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        try {
            $logs = $client->{$this->runLogsKey} ?? [];
            if (!is_array($logs)) {
                return ['count' => 0, 'data' => []];
            }
            $list = is_array($logs[(string) $id] ?? null) ? $logs[(string) $id] : [];
            $list = $this->pruneRunLogEntries($list);
            $count = count($list);
            $offset = ($page - 1) * $limit;
            return [
                'count' => $count,
                'data' => array_slice($list, $offset, $limit),
            ];
        } catch (Throwable) {
            return ['count' => 0, 'data' => []];
        }
    }

    /**
     * 按 rid 筛选 GlobalData 执行明细（入库用）
     *
     * @param string|int $id 任务 ID
     * @param array<int,string> $rids 为空则返回该任务全部 GD 日志
     * @return array<int,array<string,mixed>>
     */
    public function pickRunLogs(string|int $id, array $rids = []): array
    {
        $client = $this->getClient(false);
        if ($client === null) {
            return [];
        }

        try {
            $logs = $client->{$this->runLogsKey} ?? [];
            if (!is_array($logs)) {
                return [];
            }
            $list = is_array($logs[(string) $id] ?? null) ? $logs[(string) $id] : [];
            $list = $this->pruneRunLogEntries($list);
            if ($rids === []) {
                return $list;
            }
            $want = array_flip(array_map('strval', $rids));
            $picked = [];
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rid = (string) ($row['rid'] ?? '');
                if ($rid !== '' && isset($want[$rid])) {
                    $picked[] = $row;
                }
            }
            return $picked;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 裁剪超过保留期的执行明细
     *
     * @param array<int,array<string,mixed>> $list
     * @return array<int,array<string,mixed>>
     */
    private function pruneRunLogEntries(array $list): array
    {
        $cutoff = time() - $this->getRetentionDays() * 86400;
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['run_at'] ?? 0) >= $cutoff) {
                $out[] = $row;
            }
        }
        usort($out, static fn(array $a, array $b): int => (int) ($b['run_at'] ?? 0) <=> (int) ($a['run_at'] ?? 0));
        return $out;
    }

    public function normalizeJob(array $job): array
    {
        $id = $job['id'] ?? $job['jid'] ?? '';
        $params = $job['params'] ?? [];
        if (is_string($params) && $params !== '') {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($params)) {
            $params = [];
        }

        $enabled = $job['enabled'] ?? $job['status'] ?? 1;
        if (is_int($enabled)) {
            $enabled = $enabled === 1;
        }

        $singleton = $job['singleton'] ?? 1;
        if (is_int($singleton)) {
            $singleton = $singleton === 1;
        }

        return [
            'id' => is_numeric($id) ? (int) $id : (string) $id,
            'name' => (string) ($job['name'] ?? ''),
            'handler' => (string) ($job['handler'] ?? ''),
            'cron_expr' => trim((string) ($job['cron_expr'] ?? '')),
            'params' => $params,
            'enabled' => (bool) $enabled,
            'singleton' => (bool) $singleton,
        ];
    }

    private function dispatchFinished(array $job, string $channel, array $result, int $durationMs): void
    {
        try {
            $this->recordRun($job, $channel, $result, $durationMs);
        } catch (Throwable) {
        }

        $cb = $this->config['on_job_finished'] ?? null;
        if (!is_callable($cb)) {
            return;
        }
        try {
            $cb($job, $channel, $result, $durationMs);
        } catch (Throwable) {
        }
    }

    private function getClient(bool $strict = false): ?GdClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $gateway = $this->resolveGateway();
        if ($gateway === '') {
            return null;
        }

        if (!class_exists(GdClient::class, false)) {
            $paths = [];
            if (defined('CANDYPATH')) {
                $paths[] = CANDYPATH . 'Candy/Vendor/Workerman/GlobalData/Client.php';
            }
            $paths[] = __DIR__ . '/../GlobalData/Client.php';
            $loaded = false;
            foreach ($paths as $file) {
                if (is_file($file)) {
                    require_once $file;
                    $loaded = true;
                    break;
                }
            }
            if (!$loaded) {
                return null;
            }
        }

        try {
            $client = new GdClient($gateway);
            if ($client->getStatus() === false) {
                return $strict ? throw new \RuntimeException('GlobalData unavailable') : null;
            }
            $this->client = $client;
            return $client;
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            return null;
        }
    }

    private function getParser(): Parser
    {
        if ($this->parser === null) {
            $this->parser = new Parser();
        }
        return $this->parser;
    }
}
