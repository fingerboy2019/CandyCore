<?php
/***
 * 推送服务入口（GatewayWorker 三进程）
 *
 * 由 /sys/service 页面通过 Service\Manager 拉起：
 *   php Candy/Class/Tools/Service/Entry/push.php start
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 16:00:00
 */

if (PHP_SAPI !== 'cli') {
    exit("这个脚本只能在命令行运行\n");
}

$root = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;

require_once $root . 'Candy/Autoload.php';

$cmd = 'start';
foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $arg) {
    if (in_array($arg, ['start', 'stop', 'restart', 'reload', 'status', 'connections'], true)) {
        $cmd = $arg;
        break;
    }
}

\Candy\Extend\Tools\Push\Boot::run($cmd);
