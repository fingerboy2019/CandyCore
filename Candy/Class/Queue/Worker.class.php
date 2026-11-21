<?php
/**
 * 队列消费者
 *
 * 在 Console 命令 `queue:work` 里循环取任务并执行，FPM 与常驻模式都可用。
 *
 * 任务数据约定：
 * - `data.handler` 为可调用标识（`Class::method` / `Class@method` / 函数名）；
 * - 由 Worker 统一调用；不存在时按失败处理。
 *
 * ```php
 * Queue::push('export', ['handler' => 'App\\Job\\Export::run', 'args' => ['type' => 'csv']]);
 * ```
 *
 * @package Candy\Extend\Queue
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Queue;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Worker
{
    /** @var array<int, string> 队列优先级 */
    private array $queues;

    /** @var int 空闲多少秒后退出（0 = 永不退出） */
    private int $stopAfter;

    /**
     * 构造
     *
     * @param array<int, string> $queues    队列名，按优先级
     * @param int                $stopAfter 空闲多少秒后退出；0 = 常驻
     */
    public function __construct(array $queues = ['default'], int $stopAfter = 0)
    {
        $this->queues = $queues === [] ? ['default'] : array_values($queues);
        $this->stopAfter = max(0, $stopAfter);
    }

    /**
     * 主循环
     *
     * @param callable|null $logger 日志回调，签名 (string $line, bool $error)
     * @return int 处理成功的任务数
     */
    public function run(?callable $logger = null): int
    {
        $done = 0;
        $idleSince = time();

        while (true) {
            // 每轮先把到期延迟任务搬过来
            try {
                Queue::migrateDelayed();
            } catch (\Throwable) {
                // 延迟搬运失败不致命
            }

            $timeout = $this->stopAfter > 0 ? min(5, max(1, $this->stopAfter)) : 5;

            try {
                $package = Queue::pop($this->queues, $timeout);
            } catch (\Throwable $e) {
                $this->log($logger, '取任务失败：' . $e->getMessage(), true);
                usleep(500000);
                continue;
            }

            if ($package === null) {
                // 空闲超时退出
                if ($this->stopAfter > 0 && time() - $idleSince >= $this->stopAfter) {
                    $this->log($logger, '空闲 ' . $this->stopAfter . ' 秒，退出', false);
                    break;
                }
                continue;
            }

            $idleSince = time();

            try {
                $this->handle($package);
                $done++;
                $this->log($logger, 'OK ' . (string) ($package['id'] ?? '?'), false);
            } catch (\Throwable $e) {
                $retried = Queue::retryOrFail($package, $e->getMessage());
                $this->log(
                    $logger,
                    ($retried ? 'RETRY ' : 'FAIL ') . (string) ($package['id'] ?? '?') . ' ' . $e->getMessage(),
                    true
                );
            }
        }

        return $done;
    }

    /**
     * 只处理一个任务后返回（--once 模式）
     *
     * @param callable|null $logger 日志回调
     * @return int 处理成功数（0 或 1）
     */
    public function runOnce(?callable $logger = null): int
    {
        try {
            Queue::migrateDelayed();
        } catch (\Throwable) {
        }

        try {
            $package = Queue::pop($this->queues, 1);
        } catch (\Throwable $e) {
            $this->log($logger, '取任务失败：' . $e->getMessage(), true);

            return 0;
        }

        if ($package === null) {
            return 0;
        }

        try {
            $this->handle($package);
            $this->log($logger, 'OK ' . (string) ($package['id'] ?? '?'), false);

            return 1;
        } catch (\Throwable $e) {
            $retried = Queue::retryOrFail($package, $e->getMessage());
            $this->log(
                $logger,
                ($retried ? 'RETRY ' : 'FAIL ') . (string) ($package['id'] ?? '?') . ' ' . $e->getMessage(),
                true
            );

            return 0;
        }
    }

    /**
     * 执行单个任务
     *
     * @param array<string, mixed> $package 任务包
     * @return void
     */
    private function handle(array $package): void
    {
        $data = (array) ($package['data'] ?? []);
        $handler = $data['handler'] ?? null;
        $args = $this->normalizeArgs($data['args'] ?? []);

        if (!is_string($handler) || $handler === '') {
            throw new \RuntimeException('任务缺少 data.handler');
        }

        if (str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
        } elseif (str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
        } else {
            if (!function_exists($handler)) {
                throw new \RuntimeException('处理函数不存在：' . $handler);
            }
            $handler(...$args);

            return;
        }

        if (!class_exists($class)) {
            throw new \RuntimeException('处理类不存在：' . $class);
        }

        $instance = new $class();
        if (!method_exists($instance, $method)) {
            throw new \RuntimeException('处理方法不存在：' . $class . '::' . $method);
        }

        $instance->{$method}(...$args);
    }

    /**
     * 归一化任务参数
     *
     * PHP 8 里 `...$args` 展开关联数组会被当成「命名参数」而报
     * `Unknown named parameter $x`。所以：
     * - 列表数组（[1,2]）→ 原样展开成位置参数；
     * - 关联数组（['n'=>1]）→ 作为单个数组参数传入，与 handler(array $args) 约定一致。
     *
     * @param mixed $args 原始 args
     * @return array<int, mixed>
     */
    private function normalizeArgs(mixed $args): array
    {
        if (!is_array($args)) {
            return $args === null ? [] : [$args];
        }

        if ($args === [] || array_is_list($args)) {
            return array_values($args);
        }

        return [$args];
    }

    /**
     * 输出日志
     *
     * @param callable|null $logger 日志回调
     * @param string        $line   内容
     * @param bool          $error  是否错误
     * @return void
     */
    private function log(?callable $logger, string $line, bool $error): void
    {
        if ($logger !== null) {
            $logger($line, $error);
        }
    }
}
