<?php
/***
 * 框架命令控制台执行器（/sys/cli 的后端）
 *
 * 只做一件事：把「candy 子命令 + 参数」起成独立子进程，拿回 stdout/stderr/退出码。
 *
 * 安全边界（按此实现，不要放宽）：
 *   ① 只执行 `php <candy 入口> <cmd> <args>` —— **不做 shell**，proc_open 传数组，天然无注入；
 *   ② cmd 必须命中命令清单（只读 / 危险两类白名单），参数按空白+引号切分后逐个拼接；
 *   ③ 危险命令必须带「确认词 = 命令名」；
 *   ④ 生产（非 DEBUG）默认只放行只读命令；
 *   ⑤ 超时 kill、输出截断、每次执行写审计日志。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-22 08:00:00 $
 *
 * @package Candy\Extend\Tools\Cli
 */
namespace Candy\Extend\Tools\Cli;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Console
{
    /**
     * 只读命令：生产也允许执行
     */
    private const SAFE = [
        'version', 'list', 'help',
        'doctor',
        'queue:status', 'migrate:status', 'seed:status', 'schedule:list',
        'lifecycle:status', 'lifecycle:preview',
        'im:event-status', 'mcp:list', 'mcp:inspect', 'mcp:client-snippet', 'mcp:compat-check',
        'ops:db-sample-audit', 'ip',
    ];

    /**
     * 危险命令：需要确认词；默认只在 DEBUG 下允许
     */
    private const DANGER = [
        'clear',
        'migrate:run', 'migrate:rollback', 'migrate:make',
        'seed:run', 'seed:make',
        'scaffold:init',
        'lifecycle:run', 'lifecycle:freeze', 'lifecycle:thaw', 'lifecycle:thaw-clear', 'lifecycle:cleanup',
        'im:cleanup-events', 'im:retry-events', 'im:sync-dept', 'im:ensure-support-schema',
        'oauth:registration-cleanup',
        'auth:sync', 'auth:unify',
        'tenant:deploy-demo', 'tenant:sync-local-users',
        'article:marketing-seed',
        'portal:ensure-rag-index-job', 'portal:ensure-scim-outbound-retry-job',
        'portal:ensure-sso-state-cleanup-job', 'portal:ensure-workbench-snapshot-job',
        'apidoc:init', 'catlog:init', 'check:init', 'signer:init', 'payload-crypto:init',
        'build:bin', 'build:phar',
        'make:app', 'make:command', 'make:control', 'make:model', 'make:schema', 'make:validate',
        'generate:crud', 'generate:scaffold',
        'mcp:make-tool', 'mcp:server', 'mcp:grant',
        'blog:new',
        'imchannel:pull', 'creatorhub:check', 'mailbox',
        'queue:work',
    ];

    /**
     * 长驻 / 交互类：明确禁止在面板执行
     */
    private const FORBIDDEN = [
        'composer', 'process', 'web', 'ws', 'swoole', 'run', 'dns', 'ftp', 'nosql', 'webdav', 'worker',
    ];

    /**
     * 输出上限（字节）
     */
    private const MAX_OUTPUT = 65536;

    /**
     * 执行一条 candy 命令
     *
     * @param string $cmd 子命令名
     * @param string $args 参数串（按空白与引号切分）
     * @param string $confirm 危险命令的确认词（必须等于命令名）
     * @param int $timeout 超时秒
     * @return array {ok, code, time, output, command}
     */
    public static function run(string $cmd, string $args = '', string $confirm = '', int $timeout = 20): array
    {
        $cmd = strtolower(trim($cmd));
        if (preg_match('/^[a-z][a-z0-9:_-]{0,48}$/', $cmd) !== 1) {
            return self::fail(400, '命令名不合法');
        }
        if (in_array($cmd, self::FORBIDDEN, true)) {
            return self::fail(403, '该命令是长驻/交互类，不能在面板执行（请在服务器终端运行）');
        }

        $safe   = in_array($cmd, self::SAFE, true);
        $danger = in_array($cmd, self::DANGER, true);
        if ($safe === false && $danger === false) {
            return self::fail(400, '命令不在白名单内：' . $cmd);
        }

        if ($danger) {
            if (isDebug() === false) {
                return self::fail(403, '危险命令仅在 DEBUG 模式可用');
            }
            if ($confirm !== $cmd) {
                return self::fail(400, '危险命令需要确认：confirm 必须等于「' . $cmd . '」');
            }
        }

        $argv = [
            PHP_BINDIR . '/php',
            rtrim(C('CANDYROOT'), '/') . '/candy',
            $cmd,
        ];
        foreach (self::splitArgs($args) as $one) {
            $argv[] = $one;
        }

        $start = microtime(true);
        $res   = self::exec($argv, $timeout);
        $cost  = round((microtime(true) - $start) * 1000);

        self::audit($cmd, $args, $res['code'], $cost);

        return [
            'ok'      => ($res['code'] === 0),
            'code'    => $res['code'],
            'time'    => $cost,
            'output'  => $res['output'],
            'command' => implode(' ', array_map(static fn($v) => escapeshellarg($v), array_slice($argv, 2))),
        ];
    }

    /**
     * 命令清单（供页面渲染）
     *
     * @return array {safe: [], danger: [], forbidden: []}
     */
    public static function catalog(): array
    {
        return [
            'safe'      => self::SAFE,
            'danger'    => self::DANGER,
            'forbidden' => self::FORBIDDEN,
        ];
    }

    /**
     * 起子进程（数组形式，不经 shell）
     *
     * @param array $argv 完整命令
     * @param int $timeout 超时秒
     * @return array {code, output}
     */
    private static function exec(array $argv, int $timeout): array
    {
        if (function_exists('proc_open') === false) {
            return ['code' => 500, 'output' => '当前环境禁用了 proc_open，无法执行命令（请用服务器终端）'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($argv, $descriptors, $pipes, rtrim(C('CANDYROOT'), '/'));
        if (is_resource($proc) === false) {
            return ['code' => 500, 'output' => '进程启动失败'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out    = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while (true) {
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);

            foreach ($read as $r) {
                $chunk = fread($r, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $out .= $chunk;
                }
            }

            $status = proc_get_status($proc);
            if ($status['running'] === false) {
                // 收尾把缓冲读干净
                $out .= (string) stream_get_contents($pipes[1]);
                $out .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                @proc_terminate($proc, 9);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($timedOut) {
            $out .= "\n[超时] 已强制结束（{$timeout}s）";
            $code = 124;
        }

        $out = self::clean($out);
        if (strlen($out) > self::MAX_OUTPUT) {
            $out = substr($out, 0, self::MAX_OUTPUT) . "\n…[输出过长，已截断]";
        }

        return ['code' => (int) $code, 'output' => $out];
    }

    /**
     * 参数切分（支持双引号包裹）
     *
     * @param string $args 参数串
     * @return array
     */
    private static function splitArgs(string $args): array
    {
        $args = trim($args);
        if ($args === '') {
            return [];
        }
        $parts = str_getcsv($args, ' ', '"', '\\');
        $out   = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * 清理输出（去 ANSI 色码、统一换行）
     *
     * @param string $out 原始输出
     * @return string
     */
    private static function clean(string $out): string
    {
        $out = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $out);
        $out = str_replace("\r\n", "\n", (string) $out);
        return trim((string) $out);
    }

    /**
     * 审计日志
     *
     * @param string $cmd 命令
     * @param string $args 参数
     * @param int $code 退出码
     * @param int $cost 耗时 ms
     * @return void
     */
    private static function audit(string $cmd, string $args, int $code, int $cost): void
    {
        $dir = C('RUNTIME') . 'Logs';
        if (is_dir($dir) === false) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] ip=%s cmd=%s args=%s code=%d cost=%dms\n",
            date('Y-m-d H:i:s'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            $cmd,
            substr($args, 0, 200),
            $code,
            $cost
        );
        @file_put_contents($dir . '/cli-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 失败结果
     *
     * @param int $code 业务码
     * @param string $message 提示
     * @return array
     */
    private static function fail(int $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'time' => 0, 'output' => $message, 'command' => ''];
    }
}
