<?php
/***
 * Channel 服务的启动入口
 *
 * Channel = 跨进程 pub/sub + 队列（frame:// 协议）。它不是 candy 控制台命令，
 * 所以这里自己引导框架：定义常量 → 加载 Candy 自动加载 → 读配置 → 起 Worker。
 *
 * 与 GlobalData 的分工：GlobalData 是共享 KV，Channel 是广播/订阅与队列。
 *
 * 配置：Candy/Class/Tools/Inc/Channel.inc.php（框架默认）← Source/Inc/Channel.inc.php（项目覆盖）
 *
 * 用法：
 *   php Candy/Class/Tools/Service/Entry/channel.php start
 *   php Candy/Class/Tools/Service/Entry/channel.php stop
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 15:05:00
 */

if (PHP_SAPI !== 'cli') {
    exit("这个脚本只能在命令行运行\n");
}

$root = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;

defined('CANDY')     OR define('CANDY', true);
defined('CANDYROOT') OR define('CANDYROOT', $root);
defined('CANDYPATH') OR define('CANDYPATH', $root);

// 框架自动加载（Candy 的 Autoload 已覆盖 Candy\Vendor\Workerman 命名空间）
require_once $root . 'Candy/Autoload.php';

// 读配置（覆盖方优先）
$cfg = [];
foreach ([
    $root . 'Candy/Class/Tools/Inc/Channel.inc.php',
    $root . 'Source/Inc/Channel.inc.php',
] as $file) {
    if (is_file($file)) {
        $one = include $file;
        if (is_array($one)) {
            $cfg = array_replace_recursive($cfg, $one);
        }
    }
}
$host = (string) ($cfg['host'] ?? '127.0.0.1');
$port = (int) ($cfg['port'] ?? 2206);

// 句柄文件按服务隔离（共用 Runtime/Logs/workerman.pid 会互相覆盖）
$pidDir = $root . 'Runtime' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR;
$logDir = $root . 'Runtime' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR;
// 用框架的目录函数创建（统一权限、递归补父目录），并校验可写
foreach ([$pidDir, $logDir] as $dir) {
    if (!\Candy\Extend\Dir::create($dir, 0775)) {
        fwrite(STDERR, "[channel] 无法创建目录：{$dir}\n");
        exit(1);
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0775);
    }
    if (!is_writable($dir)) {
        fwrite(STDERR, "[channel] 目录不可写：{$dir}（属主/权限不对，需 chown www:www）\n");
        exit(1);
    }
}
\Workerman\Worker::$pidFile    = $pidDir . 'channel.pid';
\Workerman\Worker::$logFile    = $logDir . 'channel-workerman.log';
\Workerman\Worker::$stdoutFile = $logDir . 'channel-stdout.log';

fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Channel 服务：pid=' . getmypid()
    . ' host=' . $host . ' port=' . $port . PHP_EOL);

// Channel\Server 构造函数内部就会 new Worker("frame://host:port") 并设好 onMessage
new \Workerman\Channel\Server($host, $port);

\Workerman\Worker::runAll();
