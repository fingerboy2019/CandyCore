<?php
/***
 * 框架 Worker 进程组（globaldata + scheduler 等）的启动入口
 *
 * 为什么需要这个壳：`Manager::start()` 固定以 `[php, <entry>, ...args]` 拉起进程，
 * 而框架的进程组要用 `php <站点根>/candy process start` 启动（`candy` 是 CLI 入口）。
 * 这个文件负责把argv 摆成 candy 期望的样子，再 `require` 它 —— candy 内部用 `__DIR__`
 * 解析框架根，所以从任何目录 require 都能正确工作。
 *
 * 启动的是「框架内置 worker + 项目自定义 worker」：
 *   globaldata  → Candy\Extend\Worker\GlobalData（框架内置，监听 127.0.0.1:2207）
 *   scheduler   → Workerman\Crontab\Server（框架内置，读 GlobalData 里的 jobs 并秒级调度）
 *   项目扩展（rpc / mcp / im…）写在 Source/Inc/Process.inc.php，可为空。
 * 清单来源：Candy\Extend\Worker\Registry::all()
 *
 * 用法：
 *   php Candy/Class/Tools/Service/Entry/process.php start
 *   php Candy/Class/Tools/Service/Entry/process.php status
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 14:15:00
 */

if (PHP_SAPI !== 'cli') {
    exit("这个脚本只能在命令行运行\n");
}

$root = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
$candy = $root . 'candy';
if (!is_file($candy)) {
    fwrite(STDERR, "[process] 找不到 CLI 入口：{$candy}\n");
    exit(1);
}

// 传进来的子命令（start/stop/restart/status），默认 start
$sub = 'start';
foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $arg) {
    if (preg_match('/^(start|stop|restart|reload|status|connections)$/', $arg)) {
        $sub = $arg;
        break;
    }
}

// 自己写 pid 文件：Workerman 默认只写 Runtime/Logs/workerman.pid（多个命令共用，
// 且宝塔 FPM 的 open_basedir / disable_functions 会让状态页探测不到进程）。
// 这里在 fork 之前把 master pid 落到 Runtime/service/process.pid，服务页就能直接读。
// 注意：带 -d 时会被 Workerman daemonize，pid 会变，所以只在非 -d 时写（手动手动启动用这种）。
if ($sub === 'start') {
    $daemon = false;
    foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $one) {
        if ($one === '-d' || $one === '--daemon') {
            $daemon = true;
            break;
        }
    }
    if (!$daemon) {
        $pidDir = $root . 'Runtime' . DIRECTORY_SEPARATOR . 'service';
        if (!is_dir($pidDir)) {
            @mkdir($pidDir, 0775, true);
        }
        @file_put_contents($pidDir . DIRECTORY_SEPARATOR . 'process.pid', (string) getmypid());
    }
}

echo '[' . date('Y-m-d H:i:s') . '] 框架 worker 启动：pid=' . getmypid() . ' cmd=process ' . $sub . PHP_EOL;

// Workerman 读 $_SERVER['argv'][1] 作为启动命令；candy 也按 CLI 参数解析
$_SERVER['argv'] = [$candy, 'process', $sub];
$GLOBALS['argv'] = $_SERVER['argv'];
$_SERVER['argc'] = count($_SERVER['argv']);
$GLOBALS['argc'] = $_SERVER['argc'];

require $candy;
