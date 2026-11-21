<?php
/**
 * 进程执行器（形态 A 同步 / 形态 B 后台）
 *
 * 提供两种「起新进程」的方式：
 *  - run()   同步执行，阻塞到命令结束，返回 stdout / stderr / 退出码 / 耗时
 *  - spawn() 后台执行，立即返回 pid，标准输出与错误统一落到日志文件
 * 另附进程管理：alive() / find() / kill()
 *
 * 设计约束（每条都是踩过的坑，改之前想清楚）：
 *  1. 一律传数组参数，不传整串命令 —— 免 shell、免转义、免跨平台差异、天然防注入。
 *     仅 spawn() 因为要用 `&` 与重定向，才把数组拼成转义后的 shell 串。
 *  2. 同步执行必须用 stream_select 同时读两路管道 —— 只读 stdout 会在子进程
 *     stderr 写满 64KB 管道缓冲区时双向死锁。
 *  3. 超时默认 0（不限制）。不要学 Symfony Process 那个 60 秒默认值 ——
 *     它会让任何稍慢的命令莫名被杀，报错还只说「exceeded the timeout」。
 *  4. 起子进程用的 PHP 二进制由 PhpBinary::resolve() 决定，业务侧不要写死 'php'。
 *  5. 超时终止是 SIGTERM → 等待 graceSeconds → SIGKILL，不要一上来就 KILL。
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

use Candy\Extend\Process\Exception\ProcessException;
use Candy\Extend\Process\Exception\ProcessTimeoutException;
use Candy\Extend\Process\Exception\ProcessUnavailableException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Runner
{
    /**
     * 单流输出上限默认值（8MB）
     *
     * 防止一条疯狂打日志的命令把 FPM 进程内存吃光。
     */
    public const DEFAULT_MAX_OUTPUT = 8388608;

    /**
     * 当前环境是否支持起进程
     *
     * @return bool
     */
    public static function available(): bool
    {
        return self::unavailableReason() === null;
    }

    /**
     * 不支持时返回具体原因（null = 支持）
     *
     * 提示必须写清「缺什么」，不要只说「不支持」。
     *
     * @return string|null
     */
    public static function unavailableReason(): ?string
    {
        $exists = function_exists('proc_open');
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $listed = in_array('proc_open', $disabled, true);
        if ($exists && !$listed) {
            return null;
        }

        $sapi = (string) PHP_SAPI;
        $ini = (string) php_ini_loaded_file();
        $isCli = $sapi === 'cli' || $sapi === 'phpdbg';
        $why = $exists
            ? 'proc_open 列在 disable_functions 里'
            : 'proc_open 不存在（被 disable_functions 禁用，或该 PHP 构建被裁剪过）';

        $out = [];
        $out[] = 'proc_open 不可用：' . $why . '。';
        $out[] = '当前环境：PHP ' . PHP_VERSION . ' / ' . $sapi . '，ini 文件：' . ($ini !== '' ? $ini : '(未知)');
        $out[] = '关键：CLI（命令行）和 FPM（网站请求）用的是两套 php.ini，这次是「' . $sapi . '」这套被禁了。';
        $out[] = '解法一（治本）改 php.ini：把上面那个 ini 文件里 disable_functions 的 proc_open 删掉，'
            . ($isCli ? 'CLI 改完立刻生效（重跑命令即可）。' : '然后重启 php-fpm（宝塔：软件商店 → PHP → 重载）。');
        $out[] = '解法二（免改动）用 CLI 手动起服务：CLI 没被禁，在服务器上执行下面的手动启动命令即可。';
        return implode("\n", $out);
    }

    /**
     * 同步执行命令（形态 A）
     *
     * @param array<int, string> $cmd 命令数组，如 [PhpBinary::resolve(), '/path/think', 'schedule:run']
     * @param RunOptions|null    $opt 执行选项
     * @return RunResult
     * @throws ProcessUnavailableException proc_open 不可用
     * @throws ProcessException            无法启动子进程
     * @throws ProcessTimeoutException     超时且 throwOnTimeout 为真（异常内带部分输出）
     */
    public static function run(array $cmd, ?RunOptions $opt = null): RunResult
    {
        $opt ??= new RunOptions();
        Gate::assert('sync');
        self::assertAvailable('sync');
        if ($cmd === []) {
            throw new ProcessException('命令不能为空');
        }

        $label = self::describe($cmd);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $opt->env === null ? null : self::mergeEnv($opt->env);

        $start = microtime(true);
        $proc = @proc_open($cmd, $descriptors, $pipes, $opt->cwd, $env);
        if (!is_resource($proc)) {
            throw ProcessException::wrap($label, '无法启动子进程' . self::lastErrorText());
        }

        if ($opt->input !== null && $opt->input !== '') {
            @fwrite($pipes[0], $opt->input);
        }
        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = $opt->timeout > 0 ? $start + $opt->timeout : null;

        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read === []) {
                break;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            $wait = 0.2;
            if ($deadline !== null) {
                $wait = min($wait, max(0.0, $deadline - microtime(true)));
            }
            $sec = (int) $wait;
            $usec = (int) (($wait - $sec) * 1000000);
            if ($wait > 0 && $sec === 0 && $usec === 0) {
                $usec = 1000;
            }

            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                continue;
            }
            foreach ($read as $stream) {
                $chunk = @fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    if (strlen($stdout) < $opt->maxBytes) {
                        $stdout .= $chunk;
                    }
                } elseif (strlen($stderr) < $opt->maxBytes) {
                    $stderr .= $chunk;
                }
            }
        }

        if ($timedOut) {
            self::terminate($proc, $opt->graceSeconds);
        }

        $stdout .= self::drain($pipes[1]);
        $stderr .= self::drain($pipes[2]);

        $status = @proc_get_status($proc);
        $exitCode = -1;
        $signal = null;
        if (is_array($status)) {
            if (!empty($status['signaled'])) {
                $signal = (int) ($status['termsig'] ?? 0);
            }
            $exitCode = (int) ($status['exitcode'] ?? -1);
        }

        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $closed = @proc_close($proc);
        if ($exitCode === -1 && is_int($closed)) {
            $exitCode = $closed;
        }

        $result = new RunResult($label, $exitCode, $stdout, $stderr, $timedOut, microtime(true) - $start, $signal);

        if ($timedOut && $opt->throwOnTimeout) {
            throw (new ProcessTimeoutException('命令执行超时，已终止：' . $label))
                ->setCommand($label)
                ->setTimeout($opt->timeout)
                ->setPartialOutput($result->output());
        }

        return $result;
    }

    /**
     * 后台执行命令（形态 B）：立即返回，不阻塞
     *
     * 标准输出与标准错误统一追加到日志文件 —— 这里绝不能丢弃输出。
     * ThinkAdmin 那套用 `> /dev/null 2>&1` 把任务错误全丢了，出问题时无从排查。
     *
     * @param array<int, string> $cmd 命令数组
     * @param SpawnOptions|null  $opt 后台选项
     * @return SpawnResult
     * @throws ProcessUnavailableException proc_open 不可用
     * @throws ProcessException            无法启动或无法创建日志目录
     */
    public static function spawn(array $cmd, ?SpawnOptions $opt = null): SpawnResult
    {
        $opt ??= new SpawnOptions();
        Gate::assert('spawn');
        self::assertAvailable('spawn');
        if ($cmd === []) {
            throw new ProcessException('命令不能为空');
        }

        $label = self::describe($cmd);
        $log = $opt->logPath ?? self::defaultLogPath();
        $dir = dirname($log);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw ProcessException::wrap($label, '无法创建日志目录：' . $dir);
        }

        $mode = $opt->append ? 'a' : 'w';
        $env = $opt->env === null ? null : self::mergeEnv($opt->env);
        $line = self::shellLine($cmd);

        if ($opt->marker !== null && $opt->marker !== '') {
            $banner = '[' . $opt->marker . '] ' . date('Y-m-d H:i:s') . PHP_EOL;
            @file_put_contents($log, $banner, FILE_APPEND);
        }

        if (self::isWindows()) {
            // Windows 拿不到可靠 pid，返回 0；停止/查询请用 find() 按命令行反查
            // stdin 用 pipe 而非 ['file','NUL']：FPM 的 open_basedir 会拦住对空设备的 open
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $log, $mode],
                2 => ['file', $log, $mode],
            ];
            $proc = @proc_open(['cmd', '/V:OFF', '/E:OFF', '/C', $line], $descriptors, $pipes, $opt->cwd, $env);
            if (!is_resource($proc)) {
                throw ProcessException::wrap($label, '无法启动后台进程' . self::lastErrorText());
            }
            @fclose($pipes[0]);
            @proc_close($proc);
            return new SpawnResult($label, 0, $log, $opt->marker);
        }

        // Linux/macOS：nohup 防 SIGHUP，末尾 echo $! 取真实 pid
        $shell = 'nohup ' . $line . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $log, $mode],
        ];
        $proc = @proc_open(['/bin/sh', '-c', $shell], $descriptors, $pipes, $opt->cwd, $env);
        if (!is_resource($proc)) {
            throw ProcessException::wrap($label, '无法启动后台进程' . self::lastErrorText());
        }
        @fclose($pipes[0]);
        $raw = (string) stream_get_contents($pipes[1]);
        @fclose($pipes[1]);
        @proc_close($proc);

        return new SpawnResult($label, (int) trim($raw), $log, $opt->marker);
    }

    /**
     * 查询某个 pid 是否还活着
     *
     * @param int $pid 进程号
     * @return ProcessInfo
     */
    public static function alive(int $pid): ProcessInfo
    {
        if ($pid <= 0) {
            return new ProcessInfo(0, '', false);
        }

        if (self::isWindows()) {
            $found = self::findByPidWindows($pid);
            return $found ?? new ProcessInfo($pid, '', false);
        }

        $command = '';
        $running = null;

        // 优先读 /proc：能同时拿到真实命令行
        if (@is_dir('/proc/' . $pid)) {
            $raw = @file_get_contents('/proc/' . $pid . '/cmdline');
            $command = is_string($raw) ? trim(str_replace("\0", ' ', $raw)) : '';
            $running = true;
        }

        // /proc 读不到（宝塔 FPM 的 open_basedir 常把 /proc 挡在外面）时，
        // 退到 posix_kill($pid, 0) —— 信号 0 只探活不杀进程
        if ($running === null && function_exists('posix_kill')) {
            $running = @posix_kill($pid, 0);
        }

        // 两条路都不通（open_basedir 挡 /proc + disable_functions 禁 posix_kill）时，
        // 不猜「活着」—— 保持 null，由调用方如实报告「探测不到」，页面给出引导。
        // 假装成功的代价是：进程真死了也显示运行中，用户会以为没事。

        return new ProcessInfo($pid, $command, $running === true);
    }

    /**
     * 按命令行子串反查进程（Windows 拿不到 spawn 的 pid 时靠它）
     *
     * @param string $needle 命令行中的特征串
     * @return array<int, ProcessInfo>
     */
    public static function find(string $needle): array
    {
        if ($needle === '') {
            return [];
        }

        $rows = [];
        if (self::isWindows()) {
            $res = self::safeRun(['wmic', 'process', 'get', 'processid,commandline'], 10);
            foreach (preg_split('/\r\n|\r|\n/', $res) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || stripos($line, $needle) === false) {
                    continue;
                }
                if (preg_match('/^(.*?)\s+(\d+)$/', $line, $m) === 1) {
                    $rows[] = new ProcessInfo((int) $m[2], trim($m[1]), true);
                }
            }
            return $rows;
        }

        $res = self::safeRun(['/bin/ps', '-eo', 'pid,args'], 10);
        foreach (preg_split('/\r\n|\r|\n/', $res) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, $needle) === false) {
                continue;
            }
            if (preg_match('/^(\d+)\s+(.*)$/', $line, $m) === 1) {
                $rows[] = new ProcessInfo((int) $m[1], trim($m[2]), true);
            }
        }
        return $rows;
    }

    /**
     * 终止进程
     *
     * @param int      $pid    进程号
     * @param int|null $signal 指定信号；null = 先 SIGTERM，宽限后仍在则 SIGKILL
     * @param float    $grace  SIGTERM 到 SIGKILL 之间的等待秒数
     * @return bool
     */
    public static function kill(int $pid, ?int $signal = null, float $grace = 1.0): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (self::isWindows()) {
            $res = self::run(['taskkill', '/PID', (string) $pid, '/F'], new RunOptions(timeout: 10, throwOnTimeout: false));
            return $res->ok();
        }

        if (function_exists('posix_kill')) {
            $sig = $signal ?? 15;
            if (!@posix_kill($pid, $sig)) {
                return false;
            }
            if ($signal === null) {
                $until = microtime(true) + max(0.0, $grace);
                while (microtime(true) < $until) {
                    if (!is_dir('/proc/' . $pid)) {
                        return true;
                    }
                    usleep(50000);
                }
                @posix_kill($pid, 9);
            }
            return true;
        }

        $sig = $signal ?? 15;
        $res = self::run(['/bin/kill', '-' . $sig, (string) $pid], new RunOptions(timeout: 5, throwOnTimeout: false));
        return $res->ok();
    }

    /**
     * 默认日志路径：Runtime/Logs/process/YYYY-MM-DD.log
     *
     * @param string|null $name 任务名（可空），会给日志加一层语义
     * @return string
     */
    public static function defaultLogPath(?string $name = null): string
    {
        $root = function_exists('C') ? (string) C('CANDYROOT') : '';
        if ($root === '') {
            $root = defined('CANDYROOT') ? (string) CANDYROOT : sys_get_temp_dir();
        }
        $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR . 'process';
        if ($name !== null && $name !== '') {
            $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name) ?: 'job';
            return $dir . DIRECTORY_SEPARATOR . $safe . '-' . date('Y-m-d') . '.log';
        }
        return $dir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
    }

    /**
     * 命令数组转「可读串」（仅用于日志与报错，不要拿去执行）
     *
     * @param array<int, string> $cmd 命令数组
     * @return string
     */
    public static function describe(array $cmd): string
    {
        return implode(' ', $cmd);
    }

    /**
     * 命令数组转「转义后的 shell 串」
     *
     * 只有 spawn() 需要 —— 因为 `&` 与重定向必须有 shell。
     *
     * @param array<int, string> $cmd 命令数组
     * @return string
     */
    public static function shellLine(array $cmd): string
    {
        $parts = [];
        foreach ($cmd as $piece) {
            $parts[] = escapeshellarg((string) $piece);
        }
        return implode(' ', $parts);
    }

    /**
     * 是否 Windows
     *
     * @return bool
     */
    public static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\' || stripos(PHP_OS_FAMILY, 'Windows') !== false;
    }

    /**
     * 最近一条 PHP 错误（用于给失败信息补原因）
     *
     * `@proc_open` 会压掉告警输出，但 error_get_last() 仍能拿到，例如
     * "open_basedir restriction in effect"、"Permission denied"。
     *
     * @return string
     */
    private static function lastErrorText(): string
    {
        $err = error_get_last();
        if (is_array($err) && isset($err['message']) && $err['message'] !== '') {
            return '：' . $err['message'];
        }
        return '';
    }

    /**
     * 合并环境变量：继承当前环境 + 覆盖给定项
     *
     * @param array<string, string> $env 覆盖项
     * @return array<string, string>
     */
    private static function mergeEnv(array $env): array
    {
        $base = getenv();
        if (!is_array($base)) {
            $base = [];
        }
        foreach ($env as $key => $val) {
            $base[(string) $key] = (string) $val;
        }
        return $base;
    }

    /**
     * 排干管道残余内容
     *
     * @param resource $stream 管道
     * @return string
     */
    private static function drain($stream): string
    {
        $out = '';
        if (!is_resource($stream)) {
            return $out;
        }
        while (!feof($stream)) {
            $chunk = @fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
        }
        return $out;
    }

    /**
     * 超时终止：SIGTERM → 宽限 → SIGKILL
     *
     * @param resource $proc  进程句柄
     * @param float    $grace 宽限秒数
     * @return void
     */
    private static function terminate($proc, float $grace): void
    {
        @proc_terminate($proc, 15);
        if (self::isWindows()) {
            return;
        }
        $until = microtime(true) + max(0.0, $grace);
        while (microtime(true) < $until) {
            $status = @proc_get_status($proc);
            if (!is_array($status) || empty($status['running'])) {
                return;
            }
            usleep(50000);
        }
        @proc_terminate($proc, 9);
    }

    /**
     * 内部用：跑一条查询命令并返回合并输出，失败不抛异常
     *
     * @param array<int, string> $cmd     命令数组
     * @param int                $timeout 超时秒数
     * @return string
     */
    private static function safeRun(array $cmd, int $timeout): string
    {
        try {
            $res = self::run($cmd, new RunOptions(timeout: $timeout, throwOnTimeout: false));
            return $res->output();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 内部用：Windows 下按 pid 查进程
     *
     * @param int $pid 进程号
     * @return ProcessInfo|null
     */
    private static function findByPidWindows(int $pid): ?ProcessInfo
    {
        $res = self::safeRun(['tasklist', '/FI', 'PID eq ' . $pid, '/FO', 'CSV', '/NH'], 10);
        if ($res === '') {
            return null;
        }
        if (preg_match('/"([^"]+)","' . $pid . '"/', $res, $m) === 1) {
            return new ProcessInfo($pid, $m[1], true);
        }
        return null;
    }

    /**
     * 能力断言
     *
     * @param string $mode 能力标识：sync / spawn
     * @return void
     * @throws ProcessUnavailableException
     */
    private static function assertAvailable(string $mode): void
    {
        $reason = self::unavailableReason();
        if ($reason !== null) {
            throw (new ProcessUnavailableException($reason))->setMode($mode);
        }
    }
}
