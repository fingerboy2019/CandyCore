<?php
/**
 * fork 执行器（形态 D）
 *
 * 用途：在**同一个 PHP 进程里**派生子进程执行一个 callable，父进程不阻塞。
 * 与 Runner::spawn() 的区别：
 *  - spawn() 起的是**另一个 PHP 解释器**（可能版本/扩展都不同）
 *  - fork()   不新起解释器，子进程共享父进程已加载的类、配置、连接
 *
 * ⚠️ 两条硬约束（不遵守会在生产上出诡异 bug）：
 *  1. pcntl 扩展通常**只在 CLI 编译**，FPM 下大概率没有 → 自动降级为同步执行。
 *  2. 即使 FPM 有 pcntl，也**不该在请求里 fork**：子进程继承 FPM 的 client socket
 *     与 DB/Redis 连接，子进程退出时若 flush 了响应会污染父进程正在发的响应。
 *     所以默认只在 CLI / 常驻模式下启用，FPM 下必须显式 force 才允许。
 *
 * ⚠️ 僵尸进程：常驻模式下 fork 后必须回收。父进程侧用 reap() 定期回收，
 *    否则 <defunct> 会一直堆积到进程崩溃。
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Forker
{
    /**
     * 已 fork 但未回收的子进程
     *
     * @var array<int, bool>
     */
    private static array $children = [];

    /**
     * 当前环境是否支持 fork
     *
     * @return bool
     */
    public static function can(): bool
    {
        return self::unavailableReason() === null;
    }

    /**
     * 不支持时返回原因（null = 支持）
     *
     * @return string|null
     */
    public static function unavailableReason(): ?string
    {
        if (Runner::isWindows()) {
            return 'Windows 不支持 pcntl_fork，请改用 Runner::spawn()';
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        foreach (['pcntl_fork', 'pcntl_waitpid'] as $fn) {
            if (function_exists($fn)) {
                continue;
            }
            $why = in_array($fn, $disabled, true)
                ? '被 disable_functions 禁用'
                : '不存在（pcntl 扩展未安装，通常只在 CLI 编译）';
            return $fn . ' ' . $why . '，无法安全 fork，请改用 Runner::spawn()';
        }
        return null;
    }

    /**
     * 当前上下文是否适合 fork
     *
     * 只有 CLI 与常驻服务（定义了 CLIWORKING / CANDY_SERVER / SWOOLE_SERVER）算安全。
     * FPM 下返回 false —— 那里 fork 会继承 client socket，污染响应。
     *
     * @return bool
     */
    public static function isSafeContext(): bool
    {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return true;
        }
        foreach (['CLIWORKING', 'CANDY_SERVER', 'SWOOLE_SERVER'] as $const) {
            if (defined($const)) {
                return true;
            }
        }
        return false;
    }

    /**
     * fork 执行一个 callable
     *
     * 不可用或上下文不安全时**降级为同步执行**并标记 degraded ——
     * 因为一个 callable 无法被转换成「另一个进程里的命令行」，
     * 所以这里的降级只能同步跑完，调用方要能接受这一点。
     *
     * @param callable         $fn  要执行的闭包/回调
     * @param ForkOptions|null $opt 选项
     * @return ForkResult
     */
    public static function run(callable $fn, ?ForkOptions $opt = null): ForkResult
    {
        $opt ??= new ForkOptions();
        Gate::assert('fork');

        $reason = self::unavailableReason();
        if ($reason === null && !self::isSafeContext() && !$opt->force) {
            $reason = '当前上下文（' . PHP_SAPI . '）fork 不安全：子进程会继承 client socket 与 DB 连接，'
                . '可能污染响应。如确认要这么做请传 fork force=true';
        }

        if ($reason !== null) {
            return new ForkResult(false, 0, true, 'sync', $reason, null, $fn());
        }

        $resultFile = $opt->resultFile ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'candy_fork_' . bin2hex(random_bytes(8)) . '.ser');

        $pid = pcntl_fork();
        if ($pid === -1) {
            return new ForkResult(false, 0, true, 'sync', 'pcntl_fork() 失败，已降级为同步执行', null, $fn());
        }

        if ($pid === 0) {
            // ================= 子进程 =================
            $payload = ['ok' => false, 'data' => null, 'error' => '', 'trace' => ''];
            $level = ob_get_level();
            ob_start();
            try {
                $payload['data'] = $fn();
                $payload['ok'] = true;
            } catch (\Throwable $e) {
                $payload['error'] = $e->getMessage();
                $payload['trace'] = $e->getTraceAsString();
            }
            while (ob_get_level() > $level) {
                @ob_end_clean();
            }
            @file_put_contents($resultFile, serialize($payload), LOCK_EX);

            if ($opt->graceful) {
                exit(0);
            }
            // 直接 SIGKILL 自身：绕开 shutdown 函数与析构，
            // 避免在 FPM/常驻下把父进程正在发送的响应再发一次
            if (function_exists('posix_kill')) {
                @posix_kill((int) getmypid(), 9);
            }
            exit(0);
        }

        // ================= 父进程 =================
        self::$children[$pid] = true;
        if ($opt->wait) {
            self::wait($pid, $opt->waitMs);
        }

        return new ForkResult(true, $pid, false, 'fork', '', $resultFile);
    }

    /**
     * 读取子进程结果
     *
     * @param string|null $file run() 返回的 resultFile
     * @return array{ok: bool, data: mixed, error: string, trace: string}|null
     */
    public static function result(?string $file): ?array
    {
        if ($file === null || $file === '' || !is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => true]);
        return is_array($data) ? $data : null;
    }

    /**
     * 等待某个子进程结束
     *
     * @param int $pid    子进程号
     * @param int $waitMs 最多等多少毫秒，0 = 一直等
     * @return int 退出码；-1 表示超时未结束或已不可回收
     */
    public static function wait(int $pid, int $waitMs = 0): int
    {
        if ($pid <= 0 || !function_exists('pcntl_waitpid')) {
            return -1;
        }

        if ($waitMs <= 0) {
            $res = pcntl_waitpid($pid, $status);
            unset(self::$children[$pid]);
            return $res === $pid ? pcntl_wexitstatus($status) : -1;
        }

        $deadline = microtime(true) + $waitMs / 1000;
        do {
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            if ($res === $pid) {
                unset(self::$children[$pid]);
                return pcntl_wexitstatus($status);
            }
            if ($res === -1) {
                unset(self::$children[$pid]);
                return -1;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        return -1;
    }

    /**
     * 回收已结束的子进程（常驻模式下必须定期调用，否则僵尸进程堆积）
     *
     * @return array<int, int> 已回收的 [pid => 退出码]
     */
    public static function reap(): array
    {
        $done = [];
        if (!function_exists('pcntl_waitpid') || self::$children === []) {
            return $done;
        }
        foreach (array_keys(self::$children) as $pid) {
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            if ($res === $pid) {
                $done[$pid] = pcntl_wexitstatus($status);
                unset(self::$children[$pid]);
            } elseif ($res === -1) {
                unset(self::$children[$pid]);
            }
        }
        return $done;
    }

    /**
     * 当前仍被跟踪的子进程
     *
     * @return array<int, bool>
     */
    public static function children(): array
    {
        return self::$children;
    }
}
