<?php
/***
 * NoSQL 服务的启动入口
 *
 * `Manager::start()` 固定以 `[php, <entry>, ...args]` 拉起进程，而 NoSQL 服务要用
 * `php <站点根>/candy nosql start` 启动。这里只负责把 argv 摆成 candy 期望的形状。
 *
 * 配置（端口 / 用户 / 落盘目录 / 限流）由 NoSql 命令自己从
 *   Candy/Class/Tools/Inc/NoSQL.inc.php（框架默认）← Source/Inc/NoSQL.inc.php（项目覆盖）
 * 读取；这里不做任何配置解析，避免在框架加载前 include 配置文件。
 *
 * 用法：
 *   php Candy/Class/Tools/Service/Entry/nosql.php start
 *   php Candy/Class/Tools/Service/Entry/nosql.php status
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 15:10:00
 */

if (PHP_SAPI !== 'cli') {
    exit("这个脚本只能在命令行运行\n");
}

$root  = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
$candy = $root . 'candy';
if (!is_file($candy)) {
    fwrite(STDERR, "[nosql] 找不到 CLI 入口：{$candy}\n");
    exit(1);
}

$sub = 'start';
foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $arg) {
    if (preg_match('/^(start|stop|restart|reload|status)$/', $arg)) {
        $sub = $arg;
        break;
    }
}

fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] NoSQL 服务：pid=' . getmypid() . ' cmd=nosql ' . $sub . PHP_EOL);

$_SERVER['argv'] = [$candy, 'nosql', $sub];
$GLOBALS['argv'] = $_SERVER['argv'];
$_SERVER['argc'] = count($_SERVER['argv']);
$GLOBALS['argc'] = $_SERVER['argc'];

require $candy;
