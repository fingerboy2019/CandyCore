<?php
/***
 * 任务运行态（防重叠执行 + 僵尸进程识别）
 *
 * 为什么需要它：
 *   `Workerman\Crontab\Manager` 自带 `singleton` 与 `acquireLock`，但那把锁只管
 *   **常驻引擎自己调度的那一次**。而下面三条路径都绕开它：
 *     ① 页面「立即执行」（`?do=runonce`）
 *     ② tick 端点（`?do=tick`）或外部 cron 打的 tick
 *     ③ FPM 多进程同时打 tick
 *   一旦某次执行卡住（等网络、死循环、外部程序挂起），就再没有第二次机会，
 *   且页面上只显示「上次成功」，看不出「这次还挂着」。
 *
 * 做法：每个任务一个状态文件 `Runtime/cron/run/<id>.lock`，内容是
 *   `{pid, started_at, deadline}`（JSON）。
 * 判定「还在跑」= 文件存在 且 进程活着 且 未过 deadline。
 *   - 进程已死（kill -9 / 重启）→ 立刻判定为「已结束」并清掉，不会永久卡死
 *   - 进程活着但过了 deadline → 僵尸，交调用方按策略处理（跳过 / 杀掉）
 *
 * 为什么用文件而不是 Cache / `G()`：
 *   ① 这份状态的语义是「丢了就等于没在跑」——缓存可淘汰的假设在这里是错的
 *      （对照 rules：`G()` 不可丢，但这里连「可丢」都不接受，所以要独立介质）
 *   ② 要跨进程（FPM / 常驻 / CLI）看到同一个事实，文件最直接
 *   ③ `Runtime/` 本来就是可重建运行时数据的家，不污染 `Data/`
 *
 * @package Candy\Extend\Tools\Cron
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-20 11:30:00
 */
namespace Candy\Extend\Tools\Cron;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class RunState
{
    /**
     * 状态文件目录（相对项目根）
     */
    public const DIR = 'Runtime/cron/run';

    /**
     * 单条状态的读取
     *
     * @param string $id 任务 id
     * @return array<string, mixed>|null
     */
    public static function read(string $id): ?array
    {
        $path = self::path($id);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 综合判定：是否在跑 / 是否僵尸
     *
     * @param string $id 任务 id
     * @return array{running:bool, expired:bool, state:array<string,mixed>|null, reason:string}
     */
    public static function status(string $id): array
    {
        $state = self::read($id);
        if ($state === null) {
            return ['running' => false, 'expired' => false, 'state' => null, 'reason' => '无状态文件'];
        }

        $pid = (int) ($state['pid'] ?? 0);
        $deadline = (int) ($state['deadline'] ?? 0);

        if ($pid <= 0 || !self::pidAlive($pid)) {
            // 进程已经不在了：状态是脏的，清掉，视为「没在跑」
            self::clear($id);
            return ['running' => false, 'expired' => false, 'state' => $state, 'reason' => '上次进程已退出'];
        }

        if ($deadline > 0 && time() > $deadline) {
            return [
                'running' => true,
                'expired' => true,
                'state'   => $state,
                'reason'  => '上次执行已超时（pid ' . $pid . '，启动于 ' . date('Y-m-d H:i:s', (int) ($state['started_at'] ?? 0)) . '）',
            ];
        }

        return ['running' => true, 'expired' => false, 'state' => $state, 'reason' => '上次仍在执行（pid ' . $pid . '）'];
    }

    /**
     * 登记「开始执行」
     *
     * @param string $id      任务 id
     * @param int    $timeout 超时秒数（0 = 不设 deadline）
     * @return bool 是否写入成功
     */
    public static function begin(string $id, int $timeout = 0): bool
    {
        $path = self::path($id, true);
        if ($path === '') {
            return false;
        }

        $now = time();
        $payload = [
            'pid'        => self::currentPid(),
            'started_at' => $now,
            'deadline'   => $timeout > 0 ? $now + $timeout : 0,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return false;
        }
        $ok = false;
        if (@flock($fp, LOCK_EX)) {
            @ftruncate($fp, 0);
            @rewind($fp);
            $ok = @fwrite($fp, (string) $json) !== false;
            @fflush($fp);
            @flock($fp, LOCK_UN);
        }
        @fclose($fp);
        return $ok;
    }

    /**
     * 登记「执行结束」
     *
     * @param string $id 任务 id
     * @return void
     */
    public static function end(string $id): void
    {
        self::clear($id);
    }

    /**
     * 杀掉状态里记录的进程（僵尸清理）
     *
     * 只杀「确认为自己写进去的那个 pid」，不做模糊匹配。
     *
     * @param string $id 任务 id
     * @return bool 是否发出了终止信号
     */
    public static function kill(string $id): bool
    {
        $state = self::read($id);
        $pid = (int) ($state['pid'] ?? 0);
        if ($pid <= 0 || !self::pidAlive($pid)) {
            self::clear($id);
            return false;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15); // SIGTERM
            return true;
        }
        return false;
    }

    /**
     * 删除状态文件
     *
     * @param string $id 任务 id
     * @return void
     */
    public static function clear(string $id): void
    {
        $path = self::path($id);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 列出全部残留状态（排障用）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $dir = self::dir();
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $rows = [];
        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*.lock') as $file) {
            $name = basename((string) $file, '.lock');
            $state = self::read($name);
            if ($state === null) {
                continue;
            }
            $state['id'] = $name;
            $state['alive'] = self::pidAlive((int) ($state['pid'] ?? 0));
            $rows[] = $state;
        }
        return $rows;
    }

    /**
     * 状态文件路径
     *
     * @param string $id      任务 id
     * @param bool   $ensure  是否顺带建目录
     * @return string 空串表示无法定位
     */
    protected static function path(string $id, bool $ensure = false): string
    {
        $safe = self::safeId($id);
        if ($safe === '') {
            return '';
        }
        $dir = self::dir();
        if ($dir === '') {
            return '';
        }
        if ($ensure && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $safe . '.lock';
    }

    /**
     * 目录绝对路径
     *
     * @return string
     */
    protected static function dir(): string
    {
        $root = function_exists('C') ? (string) C('CANDYROOT') : '';
        if ($root === '') {
            $root = defined('CANDYROOT') ? (string) CANDYROOT : '';
        }
        if ($root === '') {
            return '';
        }
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DIR);
    }

    /**
     * 文件名安全化（任务 id 是外部输入，绝不能直接拼路径）
     *
     * @param string $id 任务 id
     * @return string
     */
    protected static function safeId(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $id);
        return (string) $safe;
    }

    /**
     * 当前进程 pid
     *
     * @return int
     */
    protected static function currentPid(): int
    {
        return function_exists('posix_getpid') ? (int) posix_getpid() : (int) getmypid();
    }

    /**
     * 进程是否还活着
     *
     * @param int $pid 进程号
     * @return bool
     */
    protected static function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        // 自己永远算活着（同进程内嵌调用时，/proc 判断也没问题，但省一次系统调用）
        if ($pid === self::currentPid()) {
            return true;
        }
        if (function_exists('posix_kill')) {
            // 信号 0 只做存在性与权限检查，不投递
            if (@posix_kill($pid, 0)) {
                return true;
            }
            // EPERM（1）说明进程存在但无权限：同样算活着
            if (function_exists('posix_get_last_error') && posix_get_last_error() === 1) {
                return true;
            }
            return false;
        }
        if (is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }
        return true; // 无法判定时保守认为「还活着」，让 deadline 兜底，绝不误杀
    }
}
