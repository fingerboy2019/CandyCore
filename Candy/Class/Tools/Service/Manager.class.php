<?php
/***
 * 常驻服务管理（开启 / 关闭 / 状态 / 日志）
 *
 * 业务说明：把「手工在命令行敲 php xxx.php 起进程」变成页面上点一下。
 * 进程能力由 `Candy\Extend\Process\Runner` 提供（proc_open / nohup），
 * 不依赖 pcntl、不依赖 workerman 的命令行工具。
 *
 * 服务清单来源（按优先级）：
 *   ① `Source/Inc/Service.inc.php` 的 `services` 数组（项目自定义）
 *   ② 内置默认（本目录 `Entry/*.php` 里存在的入口）
 *
 * 配置写法：
 * ```php
 * return [
 *     'services' => [
 *         'globaldata' => [
 *             'label' => 'GlobalData 共享数据服务',
 *             'desc'  => 'G() 的跨进程共享依赖它',
 *             'entry' => 'Candy/Class/Tools/Service/Entry/gd.php',   // 相对站点根
 *             'args'  => [],
 *         ],
 *     ],
 * ];
 * ```
 *
 * 运行态（pid / 日志）落在 `Runtime/service/`，这是**进程控制状态**，
 * 不是业务数据（业务数据一律走 `G()`）。
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 13:00:00
 */
namespace Candy\Extend\Tools\Service;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Process\PhpBinary;
use Candy\Extend\Process\Runner;
use Candy\Extend\Process\SpawnOptions;

final class Manager
{
    /**
     * 站点根目录
     *
     * @return string
     */
    public static function root(): string
    {
        $root = defined('CANDYROOT') ? CANDYROOT : (function_exists('C') ? (string) C('CANDYROOT') : '');
        if ($root === '') {
            $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;
        }
        return rtrim($root, "/" . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * pid 文件路径
     *
     * @param string $name 服务名
     * @return string
     */
    public static function pidFile(string $name): string
    {
        return self::root() . 'Runtime' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . $name . '.pid';
    }


    /**
     * Workerman 入口自己写的 pid 文件（`<name>-workerman.pid`）
     *
     * @param string $name 服务名
     * @return string
     */
    public static function altPidFile(string $name): string
    {
        return self::root() . 'Runtime' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . $name . '-workerman.pid';
    }

    /**
     * 服务清单
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $services = [];
        $disabled = [];

        // ① 项目配置
        $configFile = self::root() . 'Source' . DIRECTORY_SEPARATOR . 'Inc' . DIRECTORY_SEPARATOR . 'Service.inc.php';
        if (is_file($configFile)) {
            $config = @include $configFile;
            if (is_array($config) && isset($config['services']) && is_array($config['services'])) {
                foreach ($config['services'] as $name => $item) {
                    if ($item === false) {          // 明确关掉某个（内置）服务
                        $disabled[] = (string) $name;
                        continue;
                    }
                    if (!is_array($item)) {
                        continue;
                    }
                    $services[(string) $name] = self::normalize((string) $name, $item);
                }
            }
        }

        // ② 框架内置服务：装上即可用，不需要 Source/Inc/Service.inc.php
        foreach (self::builtinServices() as $name => $item) {
            if (isset($services[$name]) || in_array($name, $disabled, true)) {
                continue;                       // 项目配置优先，false 可关闭
            }
            $services[$name] = self::normalize($name, $item);
        }

        return $services;
    }

    /**
     * 框架内置服务（装上即可用，不需要 Source/Inc/Service.inc.php）
     *
     * @return array<string, array<string, mixed>>
     */
    public static function builtinServices(): array
    {
        $entryDir = __DIR__ . DIRECTORY_SEPARATOR . 'Entry' . DIRECTORY_SEPARATOR;
        $builtin  = [
            'process' => [
                'label' => '计划任务调度',
                'desc'  => '计划任务到点由它执行；GlobalData 共享内存（推送离线索引、在线映射等跨进程数据）也走这里。框架内置，无需配置',
                'entry' => $entryDir . 'process.php',
                'args'  => ['start'],
            ],
        ];

        // 推送网关同样是框架模块，但只有站点真启用了推送（有 Push.inc.php）才列出来，避免噪声
        $pushInc = self::root() . 'Source' . DIRECTORY_SEPARATOR . 'Inc' . DIRECTORY_SEPARATOR . 'Push.inc.php';
        if (is_file($entryDir . 'push.php') && is_file($pushInc)) {
            $builtin['push'] = [
                'label' => '推送网关',
                'desc'  => 'GatewayWorker 三进程：Register(1236) + Gateway(4020) + BusinessWorker，支持按应用 / uid / 分组推送',
                'entry' => $entryDir . 'push.php',
                'args'  => ['start'],
            ];
        }

        // 常驻 Web 服务：始终列出（框架能力要能看见）；启动意图由「点开启」表达，不需要 enabled 开关
        if (is_file($entryDir . 'web.php')) {
            $builtin['web'] = [
                'label' => '常驻 Web 服务',
                'desc'  => 'Workerman HTTP 服务（可替代 FPM）：支持热重载、max_request 回收；端口与 worker 数见 /sys/config 的 Server 项',
                'entry' => $entryDir . 'web.php',
                'args'  => ['start'],
            ];
        }

        // Swoole Web 服务（框架能力：协程运行时，与常驻 Web 服务二选一；同一份 Server 配置）
        if (is_file($entryDir . 'swoole.php')) {
            $builtin['swoole'] = [
                'label' => 'Swoole Web 服务',
                'desc'  => 'Swoole 协程 HTTP 服务（可替代 FPM / Workerman）',
                'entry' => $entryDir . 'swoole.php',
                'args'  => ['start'],
            ];
        }

        // NoSQL 服务（框架能力：类 Redis 的内存 KV，多用户/多库/认证/限流）
        if (is_file($entryDir . 'nosql.php')) {
            $builtin['nosql'] = [
                'label' => 'NoSQL 服务',
                'desc'  => 'Workerman NoSQL（类 Redis）：多用户/多库/认证/限流，binary:// 协议；端口与用户见 /sys/config 的 NoSQL 项',
                'entry' => $entryDir . 'nosql.php',
                'args'  => ['start'],
            ];
        }

        // Channel 服务（框架能力：跨进程 pub/sub + 队列）
        if (is_file($entryDir . 'channel.php')) {
            $builtin['channel'] = [
                'label' => 'Channel 服务',
                'desc'  => '跨进程发布订阅 + 队列（frame://），补 GlobalData 只能读写单个键的短板；仅本机监听为准',
                'entry' => $entryDir . 'channel.php',
                'args'  => ['start'],
            ];
        }

        // 邮件服务（框架能力：POP3 / IMAP 收信服务）
        if (is_file($entryDir . 'mail.php')) {
            $builtin['mail'] = [
                'label' => '邮件服务',
                'desc'  => 'POP3/IMAP 邮件服务器（Mail\\Server）：支持认证回调、SSL 监听；端口见 /sys/config 的 Mail 项',
                'entry' => $entryDir . 'mail.php',
                'args'  => ['start'],
            ];
        }

        return $builtin;
    }

    /**
     * 规整一条服务定义
     *
     * @param string               $name 服务名
     * @param array<string, mixed> $item 原始定义
     * @return array<string, mixed>
     */
    protected static function normalize(string $name, array $item): array
    {
        $entry = (string) ($item['entry'] ?? '');
        if ($entry !== '' && $entry[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $entry)) {
            $entry = self::root() . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entry);
        }

        return [
            'name'   => $name,
            'label'  => (string) ($item['label'] ?? $name),
            'desc'   => (string) ($item['desc'] ?? ''),
            'entry'  => $entry,
            'args'   => array_values((array) ($item['args'] ?? [])),
            'exists' => $entry !== '' && is_file($entry),
        ];
    }

    /**
     * 全部服务状态
     *
     * @return array<int, array<string, mixed>>
     */
    public static function status(): array
    {
        $out = [];
        foreach (self::all() as $name => $item) {
            $pid = self::pid($name);
            $running = false;
            $info = null;
            if ($pid > 0) {
                $info = Runner::alive($pid);
                $running = !empty($info->running);
                if (!$running) {
                    @unlink(self::pidFile($name));
                    $pid = 0;
                }
            }
            $out[] = [
                'name'    => $name,
                'label'   => $item['label'],
                'desc'    => $item['desc'],
                'entry'   => $item['entry'],
                'exists'  => $item['exists'],
                'running' => $running,
                // 该环境能否可靠判断进程存活（open_basedir 挡 /proc + 禁 posix_kill 时不能）
                'probe_limited' => !is_dir('/proc') && !function_exists('posix_kill'),
                'probe_hint' => (!is_dir('/proc') && !function_exists('posix_kill'))
                    ? '当前 PHP 环境读不到进程信息（open_basedir 限制 /proc，或 disable_functions 禁了 posix_kill），'
                        . '这里的「运行中」状态可能不准。请用命令行确认：ps -ef | grep ' . $name
                    : '',
                'pid'     => $pid,
                // 证据：真实命令行 + 启动时刻（回答"这个运行中是不是假的"）
                'cmd'        => $info !== null ? (string) ($info->command ?? '') : '',
                'started_at' => $running ? self::processStartedAt($pid) : 0,
                'log'     => self::logFile($name),
                'log_time' => is_file(self::logFile($name)) ? (int) @filemtime(self::logFile($name)) : 0,
            ];
        }
        return $out;
    }

    /**
     * 读取记录的 pid
     *
     * @param string $name 服务名
     * @return int
     */
    public static function pid(string $name): int
    {
        $file = self::pidFile($name);
        if (is_file($file)) {
            $pid = (int) trim((string) @file_get_contents($file));
            if ($pid > 0) {
                return $pid;
            }
        }

        // 兜底：Workerman 入口自己也会写一枚 pid（<name>-workerman.pid）。
        // 有些进程（如 cron）由 Workerman 托管，Manager 这一侧的文件可能没落住，读它的。
        $alt = self::altPidFile($name);
        if (is_file($alt)) {
            return (int) trim((string) @file_get_contents($alt));
        }

        // 兜底 2：框架 Console\Command 把所有 Workerman 命令的 pid 统一写到
        // Runtime/Logs/workerman.pid（见 Command.class.php 的 Worker::\$pidFile）。
        // 多个命令共用一个文件，所以必须校验这个 pid 的进程确实是本服务的入口。
        $shared = self::root() . 'Runtime' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR . 'workerman.pid';
        if (is_file($shared)) {
            $pid = (int) trim((string) @file_get_contents($shared));
            if ($pid > 0 && self::pidBelongsTo($pid, $name)) {
                return $pid;
            }
        }

        return 0;
    }

    /**
     * 校验某个 pid 的进程是不是指定服务的入口（防共用 pid 文件串号）
     *
     * @param int    $pid  进程号
     * @param string $name 服务名
     * @return bool
     */
    protected static function pidBelongsTo(int $pid, string $name): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (!is_dir('/proc/' . $pid)) {
            // 读不到 /proc（open_basedir 挡住了）：无法判断这个 pid 属于哪个服务，
            // 不能凭空认领 —— 交给调用方按「探测不到」处理。
            return false;
        }
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if (!is_string($cmdline) || trim($cmdline) === '') {
            // Workerman 用 cli_set_process_title 改写进程标题后，/proc/<pid>/cmdline
            // 可能整段为空（标题比原 cmdline 长时内核会清空）。此时无法比对入口路径，
            // 但这个 pid 来自 workerman.pid（只在启动时写一次），进程又确实活着，就认它。
            return true;
        }
        return str_contains(str_replace("\0", ' ', $cmdline), 'Entry/' . $name . '.php');
    }

    /**
     * 进程启动时刻（从 /proc/<pid>/stat 的 starttime + /proc/uptime 反算）
     *
     * 用来回答「这个『运行中』到底是不是真的、什么时候起来的」。
     * 拿不到就返回 0（不猜）。
     *
     * @param int $pid 进程号
     * @return int 时间戳；0 = 无法判断
     */
    public static function processStartedAt(int $pid): int
    {
        if ($pid <= 0 || !is_file('/proc/' . $pid . '/stat') || !is_file('/proc/uptime')) {
            return 0;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        $up   = @file_get_contents('/proc/uptime');
        if (!is_string($stat) || !is_string($up) || $stat === '') {
            return 0;
        }
        // comm 字段可能含空格和括号，从最后一个 ')' 之后再切
        $pos = strrpos($stat, ')');
        if ($pos === false) {
            return 0;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $pos + 1))) ?: [];
        // state 是全局第 3 个字段；starttime 是第 22 个 → 数组下标 19
        if (!isset($fields[19])) {
            return 0;
        }
        $ticks  = (float) $fields[19];
        $uptime = (float) (explode(' ', trim($up))[0] ?? 0);
        if ($uptime <= 0) {
            return 0;
        }
        $hz  = 100.0;                     // Linux 用户态默认 CLK_TCK
        $age = $uptime - ($ticks / $hz);
        if ($age < 0) {
            return 0;
        }
        return (int) round(time() - $age);
    }

    /**
     * 日志文件路径
     *
     * @param string $name 服务名
     * @return string
     */
    public static function logFile(string $name): string
    {
        return Runner::defaultLogPath('service-' . $name);
    }



    /**
     * 一个服务的全部日志位置
     *
     * 为什么要区分：这两份日志回答的是**不同问题**，不能只给一份。
     *   runtime — 进程跑起来之后发生的事（reload / SIGTERM / worker exit / 业务报错）
     *   spawn   — 启动那一刻的 stdout/stderr（banner、PHP Fatal、端口占用、扩展缺失）
     *             失败原因**只会出现在这里**，因为那时 Workerman 的 logFile 还没接管
     *
     * @param string $name 服务名
     * @return array{spawn:string,runtime:string,stdout:string}
     */
    public static function logFiles(string $name): array
    {
        $root = self::root();
        return [
            'spawn'   => self::logFile($name),
            'runtime' => $root . 'Runtime/Logs/service/' . $name . '-workerman.log',
            'stdout'  => $root . 'Runtime/Logs/service/' . $name . '-stdout.log',
        ];
    }

    /**
     * 服务日志分段（运行日志 + 启动输出），供服务页一次看全
     *
     * @param string $name  服务名
     * @param int    $lines 每段最多几行
     * @return array<int, array{key:string,label:string,path:string,exists:bool,lines:array<int,string>}>
     */
    public static function logSegments(string $name, int $lines = 120): array
    {
        $files = self::logFiles($name);
        $defs = [
            'runtime' => '运行日志',
            'spawn'   => '启动输出',
            'stdout'  => '标准输出',
        ];
        $out = [];
        foreach ($defs as $key => $label) {
            $path = (string) ($files[$key] ?? '');
            $exists = $path !== '' && is_file($path);
            $rows = $exists ? self::tailOf($path, $lines) : [];
            // stdout 只在有内容时才列，避免页面出现两段空块
            if ($key === 'stdout' && $rows === []) {
                continue;
            }
            $out[] = [
                'key'    => $key,
                'label'  => $label,
                'path'   => str_replace(self::root(), '', $path),
                'exists' => $exists,
                'lines'  => $rows,
            ];
        }
        return $out;
    }

    /**
     * 读文件末尾 N 行（只读最后 32KB，避免大文件全量读）
     *
     * @param string $file  文件
     * @param int    $lines 行数
     * @return array<int, string>
     */
    protected static function tailOf(string $file, int $lines): array
    {
        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return [];
        }
        $size  = (int) @filesize($file);
        $chunk = 8192 * 4;
        $start = max(0, $size - $chunk);
        @fseek($fp, $start);
        $data = (string) @fread($fp, $chunk);
        @fclose($fp);
        $all = preg_split('/\r?\n/', rtrim($data, "\r\n")) ?: [];
        return array_slice($all, -max(1, $lines));
    }

    /**
     * 清空某个服务的日志
     *
     * 为什么需要：服务上一次留下的错误会和"这次启动"的表现混在一起，
     * 判断"这次到底有没有问题"就失去意义。开启前清空，日志里只剩本次启动的内容。
     *
     * @param string $name 服务名
     * @return array{ok:bool, removed:array<int,string>, freed:int}
     */
    public static function clearLogs(string $name): array
    {
        $removed = [];
        $freed   = 0;
        $root    = self::root();
        $patterns = [
            $root . 'Runtime/Logs/service/' . $name . '-*.log',              // workerman 运行日志 / stdout
            $root . 'Runtime/Logs/service/service-' . $name . '-*.log',      // 旧路径（兼容）
            // ⚠️ 服务页「日志」看的是这个：Runner::defaultLogPath('service-'.$name)
            //    每次点击开启/失败都会往里追加，不清就会累积十几条历史启动记录
            $root . 'Runtime/Logs/process/service-' . $name . '-*.log',
            $root . 'Runtime/NoSql/' . $name . '.log',                       // NoSQL 自己写这里
        ];
        foreach ($patterns as $pattern) {
            foreach ((array) @glob($pattern) as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $freed += (int) @filesize($file);
                if (@unlink($file)) {
                    $removed[] = str_replace($root, '', $file);
                }
            }
        }
        return ['ok' => true, 'removed' => $removed, 'freed' => $freed];
    }

    /**
     * 统一设置某个服务的 Workerman 句柄文件
     *
     * 每个服务必须有独立的 pid / 日志文件：Workerman 默认的
     * `Runtime/Logs/workerman.pid` 是全局共用的，两个服务会互相把对方的 pid
     * 当成自己的，启动时报 "already running" 直接退出（2026-11-17 实测）。
     *
     * 约定：
     *   pid      → Runtime/service/<name>.pid          （与 Manager::pidFile 一致）
     *   内部日志 → Runtime/Logs/service/<name>-workerman.log
     *   标准输出 → Runtime/Logs/service/<name>-stdout.log
     *
     * @param string $name 服务名
     * @return void
     */
    public static function applyHandles(string $name): void
    {
        if (!class_exists('\\Workerman\\Worker')) {
            return;
        }
        $runtime = self::root() . 'Runtime' . DIRECTORY_SEPARATOR;
        $logDir  = $runtime . 'Logs' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR;
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $pidDir = $runtime . 'service' . DIRECTORY_SEPARATOR;
        if (!is_dir($pidDir)) {
            @mkdir($pidDir, 0775, true);
        }

        \Workerman\Worker::$pidFile    = $pidDir . $name . '.pid';
        \Workerman\Worker::$logFile    = $logDir . $name . '-workerman.log';
        \Workerman\Worker::$stdoutFile = $logDir . $name . '-stdout.log';
    }

    /**
     * 等某个服务的监听端口全部释放（最多 6 秒）
     *
     * 进程已退出不等于端口已释放：内核要等监听 socket 的引用计数归零，
     * 常驻服务（尤其 Swoole 多 worker）收尾慢时会被新实例撞上。
     *
     * @param string $name 服务名
     * @return void
     */
    protected static function waitPortsFree(string $name): void
    {
        if (!class_exists(Doctor::class) || !method_exists(Doctor::class, 'portsOf')) {
            return;
        }
        $ports = [];
        foreach (Doctor::portsOf($name) as $one) {
            $ports[(int) $one['port']] = (string) ($one['host'] ?? '127.0.0.1');
        }
        foreach ($ports as $port => $host) {
            if ($port <= 0) {
                continue;
            }
            for ($i = 0; $i < 60; $i++) {
                if (!self::portBusy($port, $host)) {
                    break;
                }
                usleep(100000);
            }
        }
    }

    /**
     * 端口上有没有人在监听
     *
     * @param int    $port 端口
     * @param string $host 监听地址（0.0.0.0 / :: 视为本机回环探活）
     * @return bool
     */
    protected static function portBusy(int $port, string $host = '127.0.0.1'): bool
    {
        if ($port <= 0) {
            return false;
        }
        $host = ($host === '' || $host === '0.0.0.0' || $host === '::') ? '127.0.0.1' : $host;
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.3);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    /**
     * 开启服务
     *
     * @param string $name 服务名
     * @return array{ok:bool, message:string, pid:int}
     */
    public static function start(string $name): array
    {
        $services = self::all();
        if (!isset($services[$name])) {
            return self::failure('unknown', '未知服务：' . $name, [
                'reason' => '服务清单里没有「' . $name . '」，可能已经在 Source/Inc/Service.inc.php 里删掉了。',
                'steps'  => [
                    '打开 Source/Inc/Service.inc.php，确认 services 里的键名。',
                    '刷新本页后重试。',
                ],
            ]);
        }
        $item = $services[$name];
        if (!$item['exists']) {
            return self::failure('entry_missing', '入口文件不存在：' . $item['entry'], [
                'reason' => 'Source/Inc/Service.inc.php 里登记了这个服务，但它指向的入口脚本不存在。',
                'steps'  => [
                    '确认 entry 路径写得对不对（相对站点根，或给绝对路径）。',
                    '这个服务已经不用了的话，把 Service.inc.php 里对应的整段配置删掉。',
                ],
            ]);
        }

        $old = self::pid($name);
        if ($old > 0 && Runner::alive($old)->running) {
            return ['ok' => true, 'message' => '已在运行（pid ' . $old . '）', 'pid' => $old];
        }

        // 旧进程可能正在退出（SIGTERM 后要 1 秒左右才真消失）：
        // 不等它退完就起新实例，两个进程会抢同一个 pid 文件，导致状态丢失、服务页显示"已停止"。
        for ($i = 0; $i < 12; $i++) {
            $alive = self::pid($name);
            if ($alive <= 0 || !Runner::alive($alive)->running) {
                break;
            }
            usleep(200000);
        }

        // 端口可能比进程退得更晚：Swoole 的 master 要等 worker 收尾，实测能拖过 1.5s，
        // 此时起新实例会 bind 失败（Address already in use），服务页就显示"已停止"。
        self::waitPortsFree($name);

        // 开启前清空旧日志：把"之前的错误"和"这次启动"分开，避免误判
        self::clearLogs($name);

        $cmd = array_merge([PhpBinary::resolve(), $item['entry']], array_map('strval', $item['args']));
        $log = self::logFile($name);
        $dir = dirname($log);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);      // 重定向的目录得先存在，否则命令直接失败
        }

        try {
            $result = Runner::spawn($cmd, new SpawnOptions($log, self::root(), null, true, $name));
        } catch (\Throwable $e) {
            return self::spawnFailure($name, $e, $log);
        }

        $pid = (int) $result->pid;
        if ($pid <= 0) {
            return self::failure('no_pid', '启动失败：没有拿到进程号', [
                'reason'   => '命令已经提交，但读不到子进程的 pid（可能被系统立刻回收了）。',
                'steps'    => [
                    '看下面的日志尾巴确认有没有报错。',
                    '也可以用下面的命令手动启动，终端里能直接看到报错。',
                ],
                'manual'   => self::manualCommand($name),
                'log'      => $log,
                'log_tail' => self::logs($name, 15),
            ]);
        }

        $dir = dirname(self::pidFile($name));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::pidFile($name), (string) $pid);

        // ★ 起来了要自检一次：进程若立刻退出，不能报「已启动」——
        //   端口被占用、入口脚本报错、权限不足都是这一瞬间暴露的，用户只会看到"点了没反应"。
        usleep(700000);
        if (!Runner::alive($pid)->running) {
            @unlink(self::pidFile($name));
            // 入口脚本可能自己 daemonize 换了 pid（-d 模式）：再按 pid 文件找一次，别误报失败
            $alias = self::pid($name);
            if ($alias > 0 && $alias !== $pid && Runner::alive($alias)->running) {
                @file_put_contents(self::pidFile($name), (string) $alias);
                return ['ok' => true, 'message' => '已启动（pid ' . $alias . '）', 'pid' => $alias];
            }
            @unlink(self::altPidFile($name));
            $tail  = self::logs($name, 20);
            $guess = self::guessExitReason($tail);
            return self::failure('died', '进程起来了又马上退出（pid ' . $pid . '）', [
                'reason'   => $guess['reason'],
                'steps'    => $guess['steps'],
                'manual'   => self::manualCommand($name),
                'log'      => $log,
                'log_tail' => $tail,
            ]);
        }

        return ['ok' => true, 'message' => '已启动（pid ' . $pid . '）', 'pid' => $pid];
    }

    /**
     * 关闭服务
     *
     * @param string $name 服务名
     * @return array{ok:bool, message:string}
     */
    public static function stop(string $name): array
    {
        $pid = self::pid($name);
        if ($pid <= 0) {
            return ['ok' => true, 'message' => '本来就没在运行'];
        }
        $ok = Runner::kill($pid, null, 1.5);
        @unlink(self::pidFile($name));
        @unlink(self::altPidFile($name));
        return ['ok' => $ok, 'message' => $ok ? '已停止（pid ' . $pid . '）' : '停止失败，进程可能已退出'];
    }

    /**
     * 强制结束某个服务的全部残留进程（pid 文件丢了也能清）
     *
     * @param string $name 服务名
     * @return array{ok:bool, message:string, pids:array<int,int>}
     */
    public static function forceStop(string $name): array
    {
        $services = self::all();
        if (!isset($services[$name])) {
            return ['ok' => false, 'message' => '未知服务：' . $name, 'pids' => []];
        }

        $needle = basename((string) $services[$name]['entry']);
        $killed = [];
        foreach (Runner::find($needle) as $info) {
            if (Runner::kill((int) $info->pid, null, 1.0)) {
                $killed[] = (int) $info->pid;
            }
        }
        @unlink(self::pidFile($name));
        @unlink(self::altPidFile($name));

        return [
            'ok'      => true,
            'message' => $killed ? ('已强制结束 ' . implode(', ', $killed)) : '没有找到残留进程',
            'pids'    => $killed,
        ];
    }

    /**
     * 重启
     *
     * @param string $name 服务名
     * @return array{ok:bool, message:string, pid:int}
     */
    public static function restart(string $name): array
    {
        $pid     = self::pid($name);
        $running = $pid > 0 && Runner::alive($pid)->running;
        $why     = Runner::unavailableReason();

        // 重启 = 先停后起。这个环境起不了进程时，停了就再也起不来 —— 宁可什么都不做。
        if ($running && $why !== null) {
            return self::failure('restart_blocked', '已取消重启：当前环境起不了进程，重启会让服务停掉且起不来', [
                'reason' => $why,
                'steps'  => [
                    '先按上面的 ini 路径解决 proc_open 被禁的问题，再重启；或者',
                    '用下面的命令在服务器上手动重启（stop 和 start 一起做，不会留下停着的状态）。',
                ],
                'manual' => self::manualRestartCommand($name),
                'log'    => self::logFile($name),
            ]);
        }

        self::stop($name);
        usleep(300000);
        return self::start($name);
    }

    /**
     * 读日志尾部
     *
     * @param string $name  服务名
     * @param int    $lines 行数
     * @return array<int, string>
     */
    public static function logs(string $name, int $lines = 80, string $source = 'spawn'): array
    {
        $files = self::logFiles($name);
        $file  = $files[$source] ?? $files['spawn'];
        if ($source === 'auto') {
            // 运行日志有内容就用它，否则退回启动输出
            $runtime = $files['runtime'];
            $file = (is_file($runtime) && (int) @filesize($runtime) > 0) ? $runtime : $files['spawn'];
        }
        if (!is_file($file)) {
            return [];
        }
        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return [];
        }
        $size = (int) @filesize($file);
        $chunk = 8192 * 4;
        $start = max(0, $size - $chunk);
        @fseek($fp, $start);
        $data = (string) @fread($fp, $chunk);
        @fclose($fp);

        $all = preg_split('/\r?\n/', rtrim($data, "\r\n")) ?: [];
        return array_slice($all, -max(1, $lines));
    }

    /**
     * 生成可复制的手动启动命令（服务页起不来时的兜底）
     *
     * @param string $name 服务名
     * @return string
     */
    public static function manualCommand(string $name): string
    {
        $services = self::all();
        $item = $services[$name] ?? null;
        if (!is_array($item) || empty($item['entry'])) {
            return '';
        }
        $parts = [PhpBinary::resolve(), (string) $item['entry']];
        foreach ((array) ($item['args'] ?? []) as $one) {
            $parts[] = (string) $one;
        }
        $arg = '';
        foreach ($parts as $one) {
            $arg .= ' ' . escapeshellarg($one);
        }
        $log = self::logFile($name);
        // 手动命令是给人（通常 root）在服务器上跑的，不是当前 PHP 进程跑，所以固定加 runuser
        $runuser = '';
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser', '/bin/runuser'] as $bin) {
            if (file_exists($bin)) {
                $runuser = $bin . ' -u www -- ';
                break;
            }
        }
        return 'cd ' . escapeshellarg(self::root()) . ' && ' . $runuser . ltrim($arg)
            . ' > ' . escapeshellarg($log) . ' 2>&1 &';
    }

    /**
     * runuser 前缀（root 执行时切到 www，否则 pid 文件属主是 root，状态页读不到）
     *
     * @return string
     */
    protected static function runuserPrefix(): string
    {
        foreach (['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser', '/bin/runuser'] as $bin) {
            if (file_exists($bin)) {
                return $bin . ' -u www -- ';
            }
        }
        return '';
    }

    /**
     * 拼一串 shell 参数
     *
     * @param array<int, string> $parts 参数
     * @return string
     */
    protected static function shellArgs(array $parts): string
    {
        $out = '';
        foreach ($parts as $one) {
            $out .= ' ' . escapeshellarg((string) $one);
        }
        return ltrim($out);
    }

    /**
     * 手动停止命令（本环境起不了进程时，只能命令行停）
     *
     * @param string $name 服务名
     * @return string
     */
    public static function manualStopCommand(string $name): string
    {
        $services = self::all();
        $item = $services[$name] ?? null;
        if (!is_array($item) || empty($item['entry'])) {
            return '';
        }
        $args = array_map('strval', (array) ($item['args'] ?? []));
        if ($args === []) {
            $args = ['start'];
        }
        $stopArgs = [];
        foreach ($args as $one) {
            $stopArgs[] = ($one === 'start') ? 'stop' : $one;
        }
        return 'cd ' . escapeshellarg(self::root()) . ' && ' . self::runuserPrefix()
            . self::shellArgs(array_merge([PhpBinary::resolve(), (string) $item['entry']], $stopArgs));
    }

    /**
     * 手动重启命令（restart 被环境挡住时给人复制）
     *
     * @param string $name 服务名
     * @return string
     */
    public static function manualRestartCommand(string $name): string
    {
        $services = self::all();
        $item = $services[$name] ?? null;
        if (!is_array($item) || empty($item['entry'])) {
            return '';
        }
        $args = array_map('strval', (array) ($item['args'] ?? []));
        if ($args === []) {
            $args = ['start'];
        }
        $stopArgs = [];
        foreach ($args as $one) {
            $stopArgs[] = ($one === 'start') ? 'stop' : $one;
        }
        $base = array_merge([PhpBinary::resolve(), (string) $item['entry']], []);
        $run  = self::runuserPrefix();
        $log  = self::logFile($name);

        return 'cd ' . escapeshellarg(self::root()) . ' && '
            . $run . self::shellArgs(array_merge($base, $stopArgs)) . ' ; sleep 1 ; '
            . $run . self::shellArgs(array_merge($base, $args))
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    }

    /**
     * 当前 PHP 环境（失败提示里要说清「去改哪个 ini」）
     *
     * @return array<string, mixed>
     */
    public static function envInfo(): array
    {
        $ini = (string) php_ini_loaded_file();
        return [
            'php'               => PHP_VERSION,
            'sapi'              => (string) PHP_SAPI,
            'ini'               => $ini,
            'proc_open'         => function_exists('proc_open'),
            'disable_functions' => (string) ini_get('disable_functions'),
        ];
    }

    /**
     * 统一的失败返回结构（页面据此渲染引导卡，不再只有一句 message）
     *
     * @param string               $code    失败类型
     * @param string               $message 一句话结论
     * @param array<string, mixed> $extra   覆盖项
     * @return array<string, mixed>
     */
    protected static function failure(string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'ok'       => false,
            'code'     => $code,
            'message'  => $message,
            'reason'   => $message,
            'steps'    => [],
            'manual'   => '',
            'log'      => '',
            'log_tail' => [],
            'env'      => self::envInfo(),
            'pid'      => 0,
        ], $extra);
    }

    /**
     * spawn 抛异常时的失败结构
     *
     * @param string     $name 服务名
     * @param \Throwable $e    异常
     * @param string     $log  日志路径
     * @return array<string, mixed>
     */
    protected static function spawnFailure(string $name, \Throwable $e, string $log): array
    {
        $manual = self::manualCommand($name);
        $why = Runner::unavailableReason();
        if ($why !== null) {
            return self::failure('spawn_disabled', '当前 PHP 环境（' . PHP_SAPI . '）不允许起子进程', [
                'reason'   => $why,
                'steps'    => [
                    '治本：按上面的 ini 路径改 disable_functions，删掉 proc_open，然后重载 PHP（宝塔：软件商店 → PHP → 重载配置）。',
                    '免改动：直接复制下面的命令，在服务器上执行（CLI 侧没禁 proc_open，服务照常起来）。',
                    '起来后回到本页点「刷新状态」确认。',
                ],
                'manual'   => $manual,
                'log'      => $log,
                'log_tail' => self::logs($name, 15),
            ]);
        }
        return self::failure('spawn_error', '启动失败：' . $e->getMessage(), [
            'reason'   => (string) $e->getMessage(),
            'steps'    => [
                '看下面的日志尾巴确认原因。',
                '确认入口脚本存在、Runtime 目录属主是 www。',
            ],
            'manual'   => $manual,
            'log'      => $log,
            'log_tail' => self::logs($name, 15),
        ]);
    }

    /**
     * 「进程起来又立刻退出」的原因推测（日志特征 → 处理建议）
     *
     * @param array<int, string> $tail 日志尾巴
     * @return array{reason:string, steps:array<int, string>}
     */
    protected static function guessExitReason(array $tail): array
    {
        $text = strtolower(implode("\n", $tail));

        if (str_contains($text, 'already in use') || str_contains($text, 'failed to listen')) {
            return [
                'reason' => '端口被占用（日志里出现 Address already in use / failed to listen）'
                    . '—— 多半是上一个实例还没退干净，新实例 bind 失败。',
                'steps'  => [
                    '点「清残留」结束掉残留进程。',
                    '等 2 秒再点「开启服务」。',
                    '还占着的话在服务器上执行 ss -ltnp 看是谁在听这个端口。',
                ],
            ];
        }
        if (str_contains($text, 'permission denied')) {
            return [
                'reason' => '权限不足（日志里出现 Permission denied）—— 常见是 Runtime 目录属主不是 www，写不了 pid / 日志。',
                'steps'  => [
                    '在服务器上执行：chown -R www:www Runtime',
                    '再点「开启服务」。',
                ],
            ];
        }
        if (str_contains($text, 'no such file') || str_contains($text, 'not found')) {
            return [
                'reason' => '入口脚本或它依赖的文件找不到（日志里出现 No such file / not found）。',
                'steps'  => [
                    '核对 Source/Inc/Service.inc.php 里的 entry 路径。',
                    '确认依赖的 Vendor 目录是完整的。',
                ],
            ];
        }
        if (trim($text) === '') {
            return [
                'reason' => '一行日志都没输出就退出了 —— 通常是被环境直接挡住（php.ini、扩展缺失、CLI 受限），没来得及报错。',
                'steps'  => [
                    '复制下面的命令在服务器上手动执行，终端里能直接看到报错。',
                    '把报错贴出来再定位。',
                ],
            ];
        }
        return [
            'reason' => '进程启动后立刻退出，原因写在下面的日志里。',
            'steps'  => [
                '按日志里的报错处理。',
                '处理完重新点「开启服务」。',
            ],
        ];
    }

}
