<?php
/**
 * DNS 服务器命令行
 *
 * 用法：php candy dns <start|stop|restart|reload|status> [--host=] [--port=] [--zones=]
 *
 * 说明：
 * - start/stop/restart/reload/status 均由 Workerman 的 Worker::runAll() 依据 argv 分发，
 *   本命令只负责构建 Worker 并设置独立的 PID/日志/状态文件（避免与主 Web 服务器共享 PID 文件）。
 * - --zones 指定权威记录配置文件（PHP 数组或 JSON），默认读取 Source/Inc/Dns.inc.php。
 * - 若 Source/Func/global.func.php 实现了 getDnsInfoByType()，优先使用该回调（向后兼容）。
 *
 * @package Console\Command
 */
namespace Console\Command;

use Console\{Command, Input, Output};
use Console\Input\{Option, Argument};
use Dns\DnsServer;

class Dns extends Command
{
    public function configure(): void
    {
        $this->setName('dns')
             ->addOption('host', null, Option::VALUE_OPTIONAL, 'The server host.', '0.0.0.0')
             ->addOption('port', null, Option::VALUE_OPTIONAL, 'The server port.', 53)
             ->addOption('zones', null, Option::VALUE_OPTIONAL, 'The authoritative zones config file (php/json).', '')
             ->addArgument('command', Argument::REQUIRED, "The command for server (start|stop|restart|reload|status)")
             ->setDescription('DNS Server for Candy.');
    }

    public function execute(Input $input, Output $output)
    {
        $command = $input->getArgument('command');
        $available_commands = ['start', 'stop', 'restart', 'reload', 'status'];

        if (!in_array($command, $available_commands)) {
            $output->writeln('<error>Invalid command. Available: ' . implode(', ', $available_commands) . '</error>');
            return;
        }

        if (PHP_SAPI !== 'cli') {
            $output->writeln('<error>The server must run on php cli.</error>');
            return;
        }

        // 独立运行时目录：PID / 日志 / 状态文件
        $runtimeDir = (defined('CANDYPATH') ? CANDYPATH . 'Runtime/Dns' : sys_get_temp_dir() . '/candy-dns-runtime');
        $runtimeDir = str_replace('\\', '/', $runtimeDir);
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0777, true);
        }
        \Workerman\Worker::$pidFile = $runtimeDir . '/dns.pid';
        \Workerman\Worker::$statusFile = $runtimeDir . '/dns.status';
        \Workerman\Worker::$logFile = $runtimeDir . '/dns.log';

        // restart 时恢复上次启动参数
        $argsFile = $runtimeDir . '/args.json';
        $savedArgs = is_file($argsFile) ? (json_decode((string) @file_get_contents($argsFile), true) ?: []) : [];
        $resolve = function (string $name, $default) use ($input, $savedArgs) {
            if ($input->hasOption($name)) {
                return $input->getOption($name);
            }
            return $savedArgs[$name] ?? $default;
        };

        $host = (string) $resolve('host', '0.0.0.0');
        $port = (int) $resolve('port', 53);
        $zones = (string) $resolve('zones', '');

        if (in_array($command, ['start', 'restart'], true)) {
            @file_put_contents($argsFile, json_encode([
                'host' => $host,
                'port' => $port,
                'zones' => $zones,
            ]));
        }

        $server = new DnsServer("{$host}:{$port}", $zones === '' ? null : $zones);

        $output->writeln('<info>DNS server ' . $command . ' ...</info>');
        // 启动/停止均由 Worker::runAll() 依据 argv 分发（DnsServer 继承 Worker，不能调用其实例 run()）
        \Workerman\Worker::runAll();
    }
}
