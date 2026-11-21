<?php
declare(strict_types=1);

namespace Workerman\Crontab;

use Workerman\Timer;
use Workerman\Worker;

/**
 * Crontab 调度服务（Worker 进程入口）
 *
 * 强依赖 GlobalData：网关未配置或连接失败时终止进程
 * 用法：'handler' => \Workerman\Crontab\Server::class
 */
class Server extends Worker
{
    private static int $loadedVersion = 0;

    private ?Manager $manager = null;

    private ?PollingEngine $engine = null;

    private int $gdReadyMaxAttempts = 30;

    private int $bootstrapDelay = 5;

    private int $versionWatchInterval = 2;

    public function __construct()
    {
        parent::__construct();

        // ⚠️ Workerman 调度的是 `$worker->onWorkerStart` **属性回调**，不会自动调用同名方法。
        // 少了这一行，`onWorkerStart()` 永远不执行 → 不 bootstrap、不注册 Timer、不 tick，
        // 表现为「worker 显示 [OK]，但 queue 里的到期任务永远不被 drain」。
        $this->onWorkerStart = [$this, 'onWorkerStart'];
    }

    private function getManager(): Manager
    {
        if ($this->manager === null) {
            $this->manager = Manager::instance();
            $config = $this->manager->getEngineConfig();
            $this->gdReadyMaxAttempts = (int) ($config['gdReadyMaxAttempts'] ?? 30);
            $this->bootstrapDelay = (int) ($config['bootstrapDelay'] ?? 5);
            $this->versionWatchInterval = (int) ($config['versionWatchInterval'] ?? 2);
            $this->engine = new PollingEngine($this->manager, $config);
        }
        return $this->manager;
    }

    private function getEngine(): PollingEngine
    {
        $this->getManager();
        return $this->engine;
    }

    /**
     * GlobalData 不可用时终止调度进程
     *
     * @param string $reason 失败原因
     * @return void
     */
    private function fatal(string $reason): void
    {
        $gateway = $this->getManager()->resolveGateway();
        $msg = '[crontab] FATAL: ' . $reason;
        if ($gateway !== '') {
            $msg .= ' (gateway=' . $gateway . ')';
        }
        $msg .= '. Scheduler requires GlobalData service. Start globaldata first: php candy process start -d';
        echo $msg . PHP_EOL;
        Worker::stopAll(1, $reason);
    }

    public function onWorkerStart(): void
    {
        $manager = $this->getManager();
        if ($manager->resolveGateway() === '') {
            $this->fatal('globalDataGateway not configured');
            return;
        }

        $attempt = 0;
        $bootstrapTimerId = null;
        $bootstrapTimerId = Timer::add(1, function () use (&$attempt, &$bootstrapTimerId, $manager) {
            $attempt++;
            if (!$manager->isConnected()) {
                if ($attempt >= $this->gdReadyMaxAttempts) {
                    if ($bootstrapTimerId !== null) {
                        Timer::del($bootstrapTimerId);
                    }
                    $this->fatal('GlobalData service not ready after ' . $this->gdReadyMaxAttempts . 's');
                }
                return;
            }

            if ($bootstrapTimerId !== null) {
                Timer::del($bootstrapTimerId);
            }

            try {
                $manager->requireGlobalData();
            } catch (\Throwable $e) {
                $this->fatal($e->getMessage());
                return;
            }

            $delay = max(0, $this->bootstrapDelay);
            Timer::add($delay > 0 ? $delay : 0.001, function () {
                $this->bootstrap(true);

                // 时间戳队列（推送 / HTTP 回调）的 tick 驱动：按配置挂载，失败不影响调度器本身
                \Candy\Extend\Tools\Cron\Server::attach();

                static $pollStarted = false;
                if (!$pollStarted) {
                    $pollStarted = true;
                    Timer::add(1, function () {
                        $this->tick();
                    });
                }

                static $watchStarted = false;
                if (!$watchStarted) {
                    $watchStarted = true;
                    $interval = max(1, $this->versionWatchInterval);
                    Timer::add($interval, function () {
                        $version = $this->getManager()->getVersion();
                        if ($version > 0 && $version !== self::$loadedVersion) {
                            $this->bootstrap(false);
                        }
                    });
                }
            }, null, false);
        });
    }

    private function bootstrap(bool $isBootstrap): void
    {
        try {
            $result = $this->getManager()->bootstrap();
        } catch (\Throwable $e) {
            echo '[crontab] bootstrap failed: ' . $e->getMessage() . PHP_EOL;
            if ($isBootstrap) {
                $this->fatal('bootstrap failed: ' . $e->getMessage());
            }
            return;
        }

        self::$loadedVersion = (int) ($result['version'] ?? 0);
        $this->getEngine()->resetPolling();

        $action = $isBootstrap ? 'bootstrapped' : 'reloaded';
        echo '[crontab] ' . $action . ' ' . (int) ($result['job_count'] ?? 0)
            . ' job(s), queue slots=' . (int) ($result['queue_slots'] ?? 0)
            . ', version=' . self::$loadedVersion . PHP_EOL;
    }

    private function tick(): void
    {
        if (!$this->getManager()->isConnected()) {
            return;
        }

        foreach ($this->getEngine()->tick() as $task) {
            if (!is_array($task)) {
                continue;
            }
            $this->runJob($task);
        }
    }

    private function runJob(array $job): void
    {
        $id = $job['id'] ?? $job['jid'] ?? '';
        if ($id === '' || $id === 0) {
            return;
        }

        $singleton = (bool) ($job['singleton'] ?? true);
        $manager = $this->getManager();

        if ($singleton && !$manager->acquireLock($id)) {
            $this->getEngine()->reschedule($job);
            return;
        }

        try {
            $manager->runJob($job, 'worker');
        } catch (\Throwable $e) {
            echo '[crontab] job #' . $id . ' failed: ' . $e->getMessage() . PHP_EOL;
        } finally {
            if ($singleton) {
                $manager->releaseLock($id);
            }
            $this->getEngine()->reschedule($job);
        }
    }
}
