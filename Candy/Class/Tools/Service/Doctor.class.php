<?php
declare(strict_types=1);
/***
 * 服务依赖预检（Doctor）
 *
 * 业务说明：进页面先跑一遍「服务能不能起来」的预检，把缺失项分成
 *   阻断级（blocking）—— 一定起不来，页面禁用启动按钮
 *   警告级（warning） —— 能起可能有副作用，或状态可能不准
 * 页面与 CLI 共用这一个类（`/sys/service?do=doctor`、`Entry/doctor.php`）。
 *
 * 检查项：
 *   PHP 版本 / pcntl / posix / proc_open / 进程能力开关 / Runtime 目录可写 /
 *   PHP 可执行文件 / 进程存活探测能力 / 入口文件 / 端口占用 / 残留 pid
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 09:45:00
 */
namespace Candy\Extend\Tools\Service;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Process\PhpBinary;
use Candy\Extend\Process\Runner;

final class Doctor
{
    /** 阻断级 */
    public const BLOCKING = 'blocking';

    /** 警告级 */
    public const WARNING = 'warning';

    /**
     * 跑一遍完整预检
     *
     * @param string|null $only 只检查某个服务（null = 所有服务）
     * @return array<string, mixed>
     */
    public static function run(?string $only = null, bool $probePorts = true): array
    {
        $env = self::env();
        $global = self::globalChecks();

        $services = [];
        foreach (Manager::all() as $name => $item) {
            if ($only !== null && $only !== '' && $name !== $only) {
                continue;
            }
            $services[$name] = self::serviceChecks($name, $item, $global, $probePorts);
        }

        // 全部服务都在跑时，「网页起不了子进程」不再是阻断项 ——
        // 它只影响「开启 / 重启」按钮，说成"服务有问题"会和「运行中」自相矛盾。
        $anyStopped = false;
        foreach ($services as $svc) {
            if (empty($svc['running'])) {
                $anyStopped = true;
                break;
            }
        }
        $global = self::adjustForAllRunning($global, $anyStopped, $services);
        // 命令集中到顶部那一条，卡片里不再重复
        $global = self::attachSpawnCommands($global, $services);

        $blocking = 0;
        $warning = 0;
        foreach ($global as $c) {
            $c['level'] === self::BLOCKING ? $blocking++ : $warning++;
        }
        foreach ($services as $svc) {
            $blocking += $svc['blocking'];
            $warning += $svc['warning'];
        }

        return [
            'ok'       => $blocking === 0,
            'summary'  => ['blocking' => $blocking, 'warning' => $warning, 'checked_at' => date('Y-m-d H:i:s')],
            'env'      => $env,
            'global'   => $global,
            'services' => $services,
            'blocking' => $blocking,
            'warning'  => $warning,
        ];
    }

    /**
     * 服务全在运行时，把「网页起不了子进程」从阻断降为警告
     *
     * 否则页面会同时出现「运行中」和「当前环境起不了子进程」，用户无法判断到底哪句对。
     * 降级后语义是：服务没事，只是网页上的「开启 / 重启」按钮用不了。
     *
     * @param array<int, array<string, string>> $global     全局检查项
     * @param bool                              $anyStopped 是否有服务已停止
     * @param array<string, mixed>              $services   各服务预检结果
     * @return array<int, array<string, string>>
     */
    protected static function adjustForAllRunning(array $global, bool $anyStopped, array $services): array
    {
        if ($anyStopped) {
            return $global;
        }

        $runningNames = [];
        foreach ($services as $name => $svc) {
            if (!empty($svc['running'])) {
                $runningNames[] = (string) ($svc['label'] ?? $name);
            }
        }
        $list = $runningNames === [] ? '' : '（' . implode('、', $runningNames) . ' 均运行中）';

        foreach ($global as $i => $c) {
            if ($c['id'] !== 'proc_open') {
                continue;
            }
            $global[$i] = self::item('proc_open', self::WARNING,
                '本环境的网页进程不能起子进程：服务在运行，但网页上的「开启 / 重启」不可用',
                '服务是 CLI 启动的，' . $list . '，运行本身没有问题。' . "\n"
                . '受影响的只有网页按钮：这两个按钮会先停后起，而停掉之后网页起不来，所以被禁用。需要重启请在服务器上执行命令。',
                '');   // 命令统一由 attachSpawnCommands() 填，避免两处文案分叉
        }
        return $global;
    }

    /**
     * 把「各服务的手动命令」集中挂到顶部那条 proc_open 警告上
     *
     * 页面上只提示一次，卡片里不再重复 —— 但命令不能少：
     * 正在跑的给「重启」命令，已停的给「启动」命令。
     *
     * @param array<int, array<string, string>> $global   全局检查项
     * @param array<string, mixed>              $services 各服务预检结果
     * @return array<int, array<string, string>>
     */
    protected static function attachSpawnCommands(array $global, array $services): array
    {
        $lines = ["# 在服务器上执行（网页起不了子进程，只能命令行操作）："];
        $any = false;
        foreach ($services as $name => $svc) {
            $label = (string) ($svc['label'] ?? $name);
            $cmd = empty($svc['running'])
                ? Manager::manualCommand((string) $name)          // 已停 → 启动
                : Manager::manualRestartCommand((string) $name);  // 在跑 → 重启
            if ($cmd === '') {
                continue;
            }
            $any = true;
            $lines[] = '# ' . $label . '（' . $name . '）' . (empty($svc['running']) ? ' 启动：' : ' 重启：');
            $lines[] = $cmd;
        }
        if (!$any) {
            return $global;
        }

        $fix = implode("\n", $lines);
        foreach ($global as $i => $c) {
            if ($c['id'] === 'proc_open') {
                $global[$i]['fix'] = $fix;
            }
        }
        return $global;
    }

    /**
     * 环境信息
     *
     * @return array<string, mixed>
     */
    public static function env(): array
    {
        $user = '';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(@posix_geteuid());
            $user = is_array($info) ? (string) ($info['name'] ?? '') : '';
        }
        return [
            'php'    => PHP_VERSION,
            'sapi'   => (string) PHP_SAPI,
            'ini'    => (string) (php_ini_loaded_file() ?: ''),
            'os'     => PHP_OS_FAMILY,
            'user'   => $user,
            'root'   => Manager::root(),
            'bin'    => class_exists(PhpBinary::class) ? PhpBinary::resolve() : '',
            'source' => class_exists(PhpBinary::class) ? PhpBinary::source() : '',
        ];
    }

    /**
     * 全局检查项（与服务无关）
     *
     * @return array<int, array<string, string>>
     */
    public static function globalChecks(): array
    {
        $out = [];

        // ① PHP 版本
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $out[] = self::item('php_version', self::BLOCKING,
                'PHP 版本过低：' . PHP_VERSION,
                '框架要求 PHP 8.1 以上（推荐 8.4/8.5），当前 ' . PHP_VERSION . ' 起不了 worker。',
                '升级 PHP 后重跑；或把 CLI 指向新版本：/www/server/php/85/bin/php');
        } elseif (version_compare(PHP_VERSION, '8.4.0', '<')) {
            $out[] = self::item('php_version', self::WARNING,
                'PHP 版本偏低：' . PHP_VERSION,
                '框架按 8.4/8.5 双版本维护，低于 8.4 可能有未覆盖的兼容问题。',
                '建议升级到 8.4 或 8.5。');
        }

        // ② 扩展
        foreach (['pcntl', 'posix'] as $ext) {
            if (!extension_loaded($ext)) {
                $out[] = self::item('ext_' . $ext, self::BLOCKING,
                    '缺少 PHP 扩展：' . $ext,
                    'Workerman 进程组依赖 ' . $ext . '（fork / 信号 / 进程信息），缺了直接起不来。',
                    'apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-'
                        . $ext . '（或宝塔：软件商店 → PHP → 安装扩展 → ' . $ext . '），装完重启 php-fpm。');
            }
        }

        // ③ 起子进程的能力
        $why = class_exists(Runner::class) ? Runner::unavailableReason() : '进程能力类不存在';
        if ($why !== null) {
            $out[] = self::item('proc_open', self::BLOCKING,
                '当前 PHP 环境不允许起子进程',
                $why,
                'CLI 侧没被禁：可直接用命令行启动（每条服务卡片下方有可复制命令）。');
        }

        // ④ 进程能力开关
        $gate = '\\Candy\\Extend\\Process\\Gate';
        if (class_exists($gate)) {
            $info = $gate::info();
            if (($info['enabled'] ?? null) !== true) {
                // CLI 启动 worker 不经过 Gate（只有网页里的 Runner::spawn 才校验），
                // 所以命令行里降为警告；网页里仍然是阻断项（线上不能开启）。
                $isCli = in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
                $out[] = self::item('gate', $isCli ? self::WARNING : self::BLOCKING,
                    '进程能力开关未开启（来源：' . (string) ($info['source'] ?? 'unknown') . '）',
                    'Candy\Extend\Process 默认关闭，不开启不会起任何进程。'
                        . ($isCli ? '（命令行启动 worker 不受它影响，只影响网页上的启动按钮）' : ''),
                    "在 Source/Inc/System.inc.php 里加一行：doDefine('PROCESS_ENABLE', true);"
                        . "（或设置环境变量 CANDY_PROCESS=1）");
            }
        }

        // ⑤ Runtime 目录可写
        $root = Manager::root();
        $dirs = [
            'Runtime'                => $root . 'Runtime',
            'Runtime/service'        => $root . 'Runtime/service',
            'Runtime/Logs/process'   => $root . 'Runtime/Logs/process',
        ];
        $bad = [];
        foreach ($dirs as $label => $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                $bad[] = $label;
            }
        }
        if ($bad !== []) {
            $out[] = self::item('runtime_writable', self::BLOCKING,
                '目录不可写：' . implode('、', $bad),
                'pid 文件和进程日志要写进这些目录，写不进去就起不来（或起了但状态页读不到 pid）。',
                'chown -R www:www ' . $root . 'Runtime && chmod -R 775 ' . $root . 'Runtime');
        }

        // ⑥ PHP 可执行文件
        // ⚠️ 不能直接用 is_file 判定：宝塔的 open_basedir 只放行站点目录，
        //    对 /www/server/php/85/bin/php 这种站外路径会**静默返回 false**（文件其实好好的）。
        $bin = class_exists(PhpBinary::class) ? PhpBinary::resolve() : '';
        if ($bin === '') {
            $out[] = self::item('php_binary', self::BLOCKING,
                '没有解析到 PHP CLI 路径',
                '起服务要用 PHP CLI 拉起子进程，连路径都拿不到就无法启动。',
                '在 Source/Inc/System.inc.php 里指定：doDefine(\'PHP_BINARY\', \'/www/server/php/85/bin/php\');');
        } elseif (!self::pathVisible($bin)) {
            // open_basedir 把站外路径挡住了，is_file() 会静默返回 false —— 这是"看不到"，不是"不存在"。
            // 不产生检查项：真不存在的话，点启动时 Manager::start() 会报错并给出处理步骤。
        } elseif (!is_file($bin) || !is_executable($bin)) {
            $out[] = self::item('php_binary', self::BLOCKING,
                '找不到可执行的 PHP CLI：' . $bin,
                '起服务要用 PHP CLI 拉起子进程，找不到就直接失败。',
                '在 Source/Inc/System.inc.php 里指定：doDefine(\'PHP_BINARY\', \'/www/server/php/85/bin/php\');');
        }

        // ⑦ 进程存活探测能力（只影响"状态准不准"）
        if (!is_dir('/proc') && !function_exists('posix_kill')) {
            $out[] = self::item('probe', self::WARNING,
                '当前环境读不到进程状态',
                'open_basedir 挡住了 /proc，且 disable_functions 禁了 posix_kill —— 页面上的「运行中 / 已停止」可能不准。',
                '用命令行确认：ps -ef | grep Entry/');
        }

        return $out;
    }

    /**
     * 单个服务的检查项
     *
     * @param string               $name   服务名
     * @param array<string, mixed> $item   服务定义
     * @param array<int, array<string, string>> $global 全局检查项
     * @return array<string, mixed>
     */
    public static function serviceChecks(string $name, array $item, array $global = [], bool $probePorts = true): array
    {
        $global = $global === [] ? self::globalChecks() : $global;
        $checks = [];

        // 入口文件
        if (empty($item['exists'])) {
            $checks[] = self::item('entry', self::BLOCKING,
                '入口文件不存在：' . (string) ($item['entry'] ?? ''),
                '服务定义指向的启动脚本没有这个文件。',
                '核对 Source/Inc/Service.inc.php 的 entry 路径（相对站点根或绝对路径）。');
        }

        $pid = Manager::pid($name);
        $alive = $pid > 0 && class_exists(Runner::class) && Runner::alive($pid)->running;
        $spawnOk = class_exists(Runner::class) ? Runner::available() : false;

        // 残留 pid
        if ($pid > 0 && !$alive) {
            $checks[] = self::item('stale_pid', self::WARNING,
                '残留 pid 文件：' . $pid . '（进程已不在）',
                '上次可能是被 kill -9 或异常退出，pid 文件没清掉，会影响状态判断。',
                'rm -f ' . Manager::pidFile($name) . ' ' . Manager::altPidFile($name) . '（或点「清残留」）');
        }

        // Swoole 运行时依赖扩展：没装就直接拦住，不要等点了开启才报
        if ($name === 'swoole' && !extension_loaded('swoole')) {
            $checks[] = self::item('ext_swoole', self::BLOCKING,
                '未安装 Swoole 扩展：extension_loaded(\'swoole\') = false',
                'Swoole Web 服务必须由 Swoole 扩展提供协程 HTTP 服务，没有扩展起不来。',
                '按当前 PHP 版本安装扩展后重启 FPM：pecl install swoole（或宝塔面板 → PHP 设置 → 安装扩展 → swoole）');
        }

        // 端口
        $ports = [];
        foreach ($probePorts ? self::portsOf($name) : [] as $one) {
            $listening = self::portListening($one['port'], $one['host']);
            $one['listening'] = $listening;
            $ports[$one['port']] = $one;          // 按端口去重
            if ($listening && !$alive) {
                $checks[] = self::item('port_' . $one['port'], self::BLOCKING,
                    '端口被占用：' . $one['label'] . ' ' . $one['port'],
                    '这个端口上已经有别的进程在监听，服务起来会 bind 失败（Address already in use）。',
                    'ss -ltnp | grep ' . $one['port'] . '   # 查是谁占用，然后结束它；或用「清残留」清理本服务的历史进程');
            } elseif (!$listening && $alive) {
                $checks[] = self::item('port_' . $one['port'], self::WARNING,
                    '进程在运行，但端口没监听：' . $one['label'] . ' ' . $one['port'],
                    '进程活着却没监听端口，通常意味着它卡在启动阶段或绑定失败（状态页会显示"运行中"，但服务其实不可用）。',
                    '看日志：' . Manager::logFile($name) . '  然后点「清残留」重启');
            }
        }

        $blocking = 0;
        $warning = 0;
        foreach ($checks as $c) {
            $c['level'] === self::BLOCKING ? $blocking++ : $warning++;
        }
        $globalBlocking = 0;
        foreach ($global as $c) {
            if ($c['level'] === self::BLOCKING) {
                $globalBlocking++;
            }
        }

        return [
            'name'     => $name,
            'label'    => (string) ($item['label'] ?? $name),
            'running'  => $alive,
            'pid'      => $alive ? $pid : 0,
            'ports'    => array_values($ports),
            'checks'   => $checks,
            'blocking' => $blocking,
            'warning'  => $warning,
            // 能不能点按钮：全局阻断 + 本服务阻断都要为 0
            'can_start'   => $globalBlocking === 0 && $blocking === 0 && !$alive,
            'can_restart' => $globalBlocking === 0 && $blocking === 0 && $spawnOk,
            // 能不能停只看它是否在跑 —— 不允许用"起不来"当理由禁止运维停止服务
            'can_stop'    => $alive,
            'state'    => $alive ? 'running' : 'stopped',
        ];
    }

    /**
     * 服务监听的端口（从框架配置推导，不写死）
     *
     * @param string $name 服务名
     * @return array<int, array{label:string,port:int,host:string}>
     */
    public static function portsOf(string $name): array
    {
        $out = [];
        if ($name === 'process' && class_exists('\\Candy\\Extend\\Worker\\GlobalData')) {
            $addr = \Candy\Extend\Worker\GlobalData::resolveGateway();
            $p = self::splitAddress($addr);
            if ($p !== null) {
                $out[] = ['label' => 'GlobalData', 'port' => $p[1], 'host' => $p[0]];
            }
        }
        // 常驻 Web / Swoole 共用 Server 配置里的 webserver 端口
        if (($name === 'web' || $name === 'swoole') && function_exists('loadConfig')) {
            $serverCfg = loadConfig('Server');
            $label = $name === 'web' ? 'Web' : 'Swoole';
            // Swoole 有独立端口覆盖（webserver.swoole.port），预检要跟着它走
            $portOverride = $name === 'swoole' ? (int) ($serverCfg['webserver']['swoole']['port'] ?? 0) : 0;
            foreach ((array) ($serverCfg['webserver']['server'] ?? []) as $group) {
                foreach ((array) ($group['list'] ?? []) as $one) {
                    $port = $portOverride > 0 ? $portOverride : (int) ($one['port'] ?? (isset($one['ssl']) ? 443 : 80));
                    $host = (string) ($one['ip'] ?? '0.0.0.0');
                    $out[] = ['label' => $label, 'port' => $port, 'host' => $host];
                }
            }
        }
        if ($name === 'push' && class_exists('\\Candy\\Extend\\Tools\\Push\\Config')) {
            $cfg = \Candy\Extend\Tools\Push\Config::get();
            $gw = self::splitAddress((string) ($cfg['gateway']['listen'] ?? ''));
            if ($gw !== null) {
                $out[] = ['label' => 'Gateway', 'port' => $gw[1], 'host' => $gw[0]];
            }
            $rg = self::splitAddress((string) ($cfg['register']['listen'] ?? ''));
            if ($rg !== null) {
                $out[] = ['label' => 'Register', 'port' => $rg[1], 'host' => $rg[0]];
            }
        }
        return $out;
    }

    /**
     * 解析 listen 地址：websocket://0.0.0.0:4020 / text://127.0.0.1:1236 / 127.0.0.1:2207
     *
     * @param string $address 地址
     * @return array{0:string,1:int}|null [host, port]
     */
    public static function splitAddress(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }
        $pos = strpos($address, '://');
        if ($pos !== false) {
            $address = substr($address, $pos + 3);
        }
        if (substr($address, 0, 7) === 'unix://') {
            return null;
        }
        if (!preg_match('/^(.*):(\d+)$/', $address, $m)) {
            return null;
        }
        $host = trim($m[1]) ?: '127.0.0.1';
        $port = (int) $m[2];
        return $port > 0 ? [$host, $port] : null;
    }

    /**
     * 端口是否有人监听（连一下最快，不依赖 ss/lsof）
     *
     * @param int    $port 端口
     * @param string $host 地址
     * @return bool
     */
    public static function portListening(int $port, string $host = '127.0.0.1'): bool
    {
        if ($port <= 0) {
            return false;
        }
        // 探测期间临时静音：框架的 error handler 不认 @，连接失败会写一条错误日志
        // （/sys/service 每 10 秒刷新一次，日志会被刷爆）。这里挂一个「全部吞掉」的
        // handler，探测完立刻恢复。
        set_error_handler(static function () {
            return true;
        });
        try {
        // 0.0.0.0 / :: 这类监听地址，用 127.0.0.1 去连
        if ($host === '0.0.0.0' || $host === '::' || $host === '') {
            $host = '127.0.0.1';
        }
        // ① 首选读 /proc/net/tcp{,6}：不发起连接、不产生任何 warning，最快
        $listening = self::listeningPorts();
        if ($listening !== null) {
            return isset($listening[$port]);
        }

        // ② /proc 不可读（容器 / 受限环境）：回退 sockets 扩展
        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($sock === false) {
                return false;
            }
            socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 0, 'usec' => 300000]);
            socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 300000]);
            $ok = @socket_connect($sock, $host, $port);
            socket_close($sock);
            return (bool) $ok;
        }
        // 回退：没有 sockets 扩展时只能流式连接（失败会写一条 warning，尽量避免高频调用）
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.3);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
        } finally {
            restore_error_handler();
        }
    }


    /**
     * 当前所有监听中的端口（读 /proc/net/tcp 与 tcp6）
     *
     * 为什么不用 socket_connect：连接失败会发 E_WARNING，而框架的 error handler
     * **不认 @ 抑制**，每次探测失败都会写一条错误日志（/sys/service 自动刷新时刷屏）。
     * 读 /proc 不发起连接，也不产生任何 warning。
     *
     * @return array<int, true>|null null = /proc 不可读（调用方回退其它探测方式）
     */
    protected static function listeningPorts(): ?array
    {
        static $cache = false;
        if (is_array($cache)) {
            return $cache;
        }
        $ports = [];
        $readable = false;
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $readable = true;
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                // 格式：sl local_address rem_address st ...；st=0A 表示 LISTEN
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 4 || ($parts[3] ?? '') !== '0A') {
                    continue;
                }
                $local = (string) ($parts[1] ?? '');
                $pos = strrpos($local, ':');
                if ($pos === false) {
                    continue;
                }
                $port = hexdec(substr($local, $pos + 1));
                if ($port > 0) {
                    $ports[(int) $port] = true;
                }
            }
        }
        $cache = $readable ? $ports : null;
        return $cache;
    }

    /**
     * 目标路径是否在 open_basedir 的放行范围内
     *
     * 不在范围内时 is_file()/is_executable() 会**静默返回 false**，
     * 不能据此判定"文件不存在"，只能降级成"无法验证"。
     *
     * @param string $path 绝对路径
     * @return bool
     */
    public static function pathVisible(string $path): bool
    {
        $open = trim((string) ini_get('open_basedir'));
        if ($open === '') {
            return true;
        }
        $path = str_replace('\\', '/', $path);
        foreach (explode(PATH_SEPARATOR, $open) as $base) {
            $base = rtrim(trim(str_replace('\\', '/', $base)), '/');
            if ($base === '') {
                continue;
            }
            if ($path === $base || strpos($path, $base . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 构造一条检查记录
     *
     * @param string $id     标识
     * @param string $level  blocking / warning
     * @param string $title  标题
     * @param string $detail 说明
     * @param string $fix    修复指引（尽量是一条可复制命令）
     * @return array<string, string>
     */
    public static function item(string $id, string $level, string $title, string $detail, string $fix): array
    {
        return [
            'id'     => $id,
            'level'  => $level,
            'title'  => $title,
            'detail' => $detail,
            'fix'    => $fix,
        ];
    }

    /**
     * CLI 文本输出（`Entry/doctor.php` 用）
     *
     * @param array<string, mixed> $report 预检结果
     * @return string
     */
    public static function toText(array $report): string
    {
        $lines = [];
        $env = $report['env'];
        $lines[] = '环境：PHP ' . $env['php'] . ' / ' . $env['sapi'] . ' / ' . ($env['user'] !== '' ? $env['user'] : '?')
            . ' / ini=' . $env['ini'];
        $lines[] = '根目录：' . $env['root'];
        $lines[] = 'PHP CLI：' . $env['bin'] . '（来源：' . $env['source'] . '）';
        $lines[] = str_repeat('-', 78);
        $lines[] = sprintf('阻断 %d 项 · 警告 %d 项', $report['blocking'], $report['warning']);

        $dump = static function (array $checks, string $scope) use (&$lines): void {
            foreach ($checks as $c) {
                $lines[] = sprintf('[%s] %-8s %s', strtoupper($c['level'] === 'blocking' ? 'BLOCK' : 'WARN'), $scope, $c['title']);
                $lines[] = '        说明：' . $c['detail'];
                if ($c['fix'] !== '') {
                    $lines[] = '        处理：' . $c['fix'];
                }
            }
        };

        $dump($report['global'], 'global');
        foreach ($report['services'] as $svc) {
            $lines[] = str_repeat('-', 78);
            $lines[] = sprintf('%s（%s）：%s%s', $svc['label'], $svc['name'],
                $svc['running'] ? '运行中 pid=' . $svc['pid'] : '已停止',
                $svc['ports'] === [] ? '' : '  端口：' . implode(', ', array_map(
                    static fn (array $p): string => $p['label'] . ':' . $p['port']
                        . ($p['listening'] ? '(监听中)' : '(未监听)'),
                    $svc['ports']
                )));
            // 凭据：这个「运行中」是真的还是假的，看这两行
            if ($svc['running']) {
                $startedAt = Manager::processStartedAt((int) $svc['pid']);
                $lines[] = '        启动于：' . ($startedAt > 0 ? date('Y-m-d H:i:s', $startedAt) : '(读不到 /proc)');
                $info = class_exists(Runner::class) ? Runner::alive((int) $svc['pid']) : null;
                $cmd = $info !== null ? (string) ($info->command ?? '') : '';
                $lines[] = '        命令行：' . ($cmd !== '' ? $cmd : '(读不到 /proc)');
            }
            $dump($svc['checks'], $svc['name']);
        }
        $lines[] = str_repeat('-', 78);
        $lines[] = $report['ok'] ? '结论：没有阻断项，可以启动。' : '结论：存在阻断项，先按上面的处理方式修复。';
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
