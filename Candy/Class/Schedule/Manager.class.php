<?php
/**
 * 计划任务执行器
 *
 * 判断哪些任务在给定时间点应当执行，并逐个运行。
 * 供 Console 命令 `schedule:run` / `schedule:list` 使用。
 *
 * @package Candy\Extend\Schedule
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:15:00
 */

declare(strict_types=1);

namespace Candy\Extend\Schedule;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Manager
{
    /** 定义文件（相对 CANDYROOT） */
    public const DEFAULT_PATH = 'Source/Inc/Schedule.inc.php';

    /** @var array<int, array<string, mixed>> 已加载任务 */
    private array $tasks = [];

    /** @var string 定义文件绝对路径 */
    private string $definitionPath;

    /**
     * 构造
     *
     * @param string|null $definitionPath 定义文件路径，默认 CANDYROOT/Source/Inc/Schedule.inc.php
     */
    public function __construct(?string $definitionPath = null)
    {
        $this->definitionPath = $definitionPath
            ?? (rtrim((string) C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_PATH);
    }

    /**
     * 加载任务定义
     *
     * 定义文件返回闭包时以 Schedule 实例调用；返回数组时直接作为任务表。
     *
     * @return array<int, array<string, mixed>>
     */
    public function load(): array
    {
        Schedule::clear();

        if (!is_file($this->definitionPath)) {
            $this->tasks = [];

            return $this->tasks;
        }

        $defined = require $this->definitionPath;
        if (is_callable($defined)) {
            $defined(new Schedule(0));
        } elseif (is_array($defined)) {
            foreach ($defined as $item) {
                if (is_array($item) && isset($item['callback'])) {
                    Schedule::call($item['callback'], (array) ($item['parameters'] ?? []));
                }
            }
        }

        $this->tasks = Schedule::all();

        return $this->tasks;
    }

    /**
     * 获取全部任务
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->tasks === []) {
            $this->load();
        }

        return $this->tasks;
    }

    /**
     * 计算指定时刻到期的任务
     *
     * @param int|null $now 基准时间戳，默认当前
     * @return array<int, array<string, mixed>>
     */
    public function dueEvents(?int $now = null, bool $ignoreCron = false): array
    {
        $now = $now ?? time();
        $parser = new \Workerman\Crontab\Parser();
        $due = [];

        foreach ($this->all() as $index => $task) {
            if (($task['enabled'] ?? true) !== true) {
                continue;
            }

            // --force 只放宽 cron 匹配，仍尊重 when() 业务条件
            if (!$ignoreCron) {
                // 分钟级对齐：把 now 归到本分钟 0 秒来匹配
                $slot = (int) (floor($now / 60) * 60);
                try {
                    $matches = $parser->parse((string) $task['expression'], $slot);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($matches === []) {
                    continue;
                }
            }

            // when() 条件
            if (is_callable($task['when'] ?? null)) {
                try {
                    if (!(bool) ($task['when'])()) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $task['__index'] = $index;
            $due[] = $task;
        }

        return $due;
    }

    /**
     * 执行到期任务
     *
     * @param int|null $now   基准时间戳
     * @param bool     $force 忽略 cron 表达式（仍尊重 when() 与 enabled）
     * @return array<int, array{name: string, ok: bool, message: string, ms: int}>
     */
    public function runDue(?int $now = null, bool $force = false): array
    {
        $tasks = $this->dueEvents($now, $force);
        $results = [];

        foreach ($tasks as $task) {
            $name = $this->taskName($task);
            if ($this->isLocked($task)) {
                $results[] = ['name' => $name, 'ok' => false, 'message' => '上次任务仍在执行，跳过', 'ms' => 0];
                continue;
            }

            $start = microtime(true);
            $this->lock($task);
            try {
                $this->invoke($task);
                $results[] = [
                    'name'    => $name,
                    'ok'      => true,
                    'message' => 'ok',
                    'ms'      => (int) round((microtime(true) - $start) * 1000),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'name'    => $name,
                    'ok'      => false,
                    'message' => $e->getMessage(),
                    'ms'      => (int) round((microtime(true) - $start) * 1000),
                ];
            } finally {
                $this->unlock($task);
            }
        }

        return $results;
    }


    /**
     * 强制执行指定任务（按名称匹配，忽略 cron 表达式与 enabled 状态）
     *
     * 供 `sys/crontab?do=run&name=X` 端点使用；锁与异常处理跟 runDue() 保持一致，
     * 保证手动触发不会与到期执行并发跑同一个任务。
     *
     * @param string $name 任务名（见任务定义里的 name()）
     * @return array<int, array<string, mixed>> 执行结果
     */
    public function runTask(string $name): array
    {
        $results = [];
        $matched = false;

        foreach ($this->all() as $task) {
            if ($this->taskName($task) !== $name) {
                continue;
            }
            $matched = true;

            if ($this->isLocked($task)) {
                $results[] = ['name' => $name, 'ok' => false, 'message' => '上次任务仍在执行，跳过', 'ms' => 0];
                continue;
            }

            $start = microtime(true);
            $this->lock($task);
            try {
                $this->invoke($task);
                $results[] = [
                    'name'    => $name,
                    'ok'      => true,
                    'message' => 'ok',
                    'ms'      => (int) round((microtime(true) - $start) * 1000),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'name'    => $name,
                    'ok'      => false,
                    'message' => $e->getMessage(),
                    'ms'      => (int) round((microtime(true) - $start) * 1000),
                ];
            } finally {
                $this->unlock($task);
            }
        }

        if (!$matched) {
            $results[] = ['name' => $name, 'ok' => false, 'message' => '没有找到该任务'];
        }

        return $results;
    }

    /**
     * 执行单个任务回调
     *
     * @param array<string, mixed> $task 任务
     * @return void
     */
    private function invoke(array $task): void
    {
        $callback = $task['callback'];
        $parameters = (array) ($task['parameters'] ?? []);

        if (is_string($callback)) {
            if (str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);
                $class = replenishNamespace($class);
                (new $class())->{$method}(...$parameters);

                return;
            }
            if (str_contains($callback, '@')) {
                [$class, $method] = explode('@', $callback, 2);
                $class = replenishNamespace($class);
                (new $class())->{$method}(...$parameters);

                return;
            }
            $callback(...$parameters);

            return;
        }

        $callback(...$parameters);
    }

    /**
     * 任务显示名
     *
     * @param array<string, mixed> $task 任务
     * @return string
     */
    private function taskName(array $task): string
    {
        if (($task['name'] ?? '') !== '') {
            return (string) $task['name'];
        }
        $cb = $task['callback'];

        return is_string($cb) ? $cb : 'closure#' . ($task['__index'] ?? 0);
    }

    /**
     * 锁键
     *
     * @param array<string, mixed> $task 任务
     * @return string
     */
    private function lockKey(array $task): string
    {
        return 'schedule:lock:' . md5($this->taskName($task));
    }

    /**
     * 是否已被锁（上次未跑完）
     *
     * @param array<string, mixed> $task 任务
     * @return bool
     */
    private function isLocked(array $task): bool
    {
        if (($task['withoutOverlapping'] ?? false) !== true) {
            return false;
        }
        try {
            $stamp = G($this->lockKey($task), '', 0);
            if (!is_numeric($stamp) || (int) $stamp <= 0) {
                return false;
            }
            // G() 无 TTL，用 overlap 分钟数自己判过期（防异常退出后永久锁死）
            $ttl = max(60, (int) ($task['overlap'] ?? 1440) * 60);

            return (time() - (int) $stamp) < $ttl;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 加锁
     *
     * @param array<string, mixed> $task 任务
     * @return void
     */
    private function lock(array $task): void
    {
        if (($task['withoutOverlapping'] ?? false) !== true) {
            return;
        }
        try {
            G($this->lockKey($task), time());
        } catch (\Throwable) {
            // 缓存不可用时退化为不加锁，宁可重复跑也不丢任务
        }
    }

    /**
     * 解锁
     *
     * @param array<string, mixed> $task 任务
     * @return void
     */
    private function unlock(array $task): void
    {
        if (($task['withoutOverlapping'] ?? false) !== true) {
            return;
        }
        try {
            G($this->lockKey($task), null);
        } catch (\Throwable) {
        }
    }
}
