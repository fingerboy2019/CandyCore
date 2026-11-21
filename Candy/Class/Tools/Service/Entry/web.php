<?php
/***
 * 常驻 Web 服务的启动入口
 *
 * 与 process.php 同理：`Manager::start()` 固定以 `[php, <entry>, ...args]` 拉起进程，
 * 而 WebServer 要用 `php <站点根>/candy server start` 启动。这里把 argv 摆成 candy
 * 期望的形状再 require 它。
 *
 * pid 由 Command\Server::runServer() 在 runAll 之前写入 Runtime/service/web.pid，
 * 所以这里不需要重复写（避免 daemonize 后 pid 不一致）。
 *
 * 用法：
 *   php Candy/Class/Tools/Service/Entry/web.php start
 *   php Candy/Class/Tools/Service/Entry/web.php status
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 12:20:00
 */

if (PHP_SAPI !== 'cli') {
    exit("这个脚本只能在命令行运行\n");
}

$root = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
$candy = $root . 'candy';
if (!is_file($candy)) {
    fwrite(STDERR, "[web] 找不到 CLI 入口：{$candy}\n");
    exit(1);
}

$sub = 'start';
foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $arg) {
    if (preg_match('/^(start|stop|restart|reload|status|connections)$/', $arg)) {
        $sub = $arg;
        break;
    }
}

// 走 STDERR：常驻 Web 服务里提前 echo 会让后续 session ini_set 报 headers already sent
fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] 常驻 Web 服务启动：pid=' . getmypid() . ' cmd=worker ' . $sub . PHP_EOL);

$_SERVER['argv'] = [$candy, 'worker', $sub];
$GLOBALS['argv'] = $_SERVER['argv'];
$_SERVER['argc'] = count($_SERVER['argv']);
$GLOBALS['argc'] = $_SERVER['argc'];

require $candy;
