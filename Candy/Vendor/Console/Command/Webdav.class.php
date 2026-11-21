<?php
/**
 * WebDAV 服务器命令行
 *
 * 用法：php candy webdav <start|stop|restart|reload|status> [--host=] [--port=] [--data-dir=] [--users=]
 *
 * 说明：
 * - start/stop/restart/reload/status 均由 Workerman 的 Worker::runAll() 依据 argv 分发，
 *   本命令只负责构建 Worker 并设置独立的 PID/日志/状态文件（避免与主 Web 服务器共享 PID 文件）。
 * - --users 支持两种格式：明文 user1:pass1,user2:pass2，或已有 password_hash（$2y$...）
 *   传入 hash 可避免明文密码出现在进程列表（ps）中。
 *
 * @package Console\Command
 */
namespace Console\Command;

use Console\{Command, Input, Output};
use Console\Input\{Option, Argument};
use Webdav\Server;

class Webdav extends Command
{
    public function configure(): void
    {
        $this->setName('webdav')
             ->addOption('host', null, Option::VALUE_OPTIONAL, 'The server host.', '0.0.0.0')
             ->addOption('port', null, Option::VALUE_OPTIONAL, 'The server port.', 8080)
             ->addOption('data-dir', null, Option::VALUE_OPTIONAL, 'The data directory.', null)
             ->addOption('users', null, Option::VALUE_OPTIONAL, 'Auth users in format user1:pass1,user2:pass2 or user1:$2y$hash,...', '')
             ->addArgument('command', Argument::REQUIRED, "The command for server (start|stop|restart|reload|status)")
             ->setDescription('WebDAV Server for Candy.');
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

        // 独立运行时目录：PID / 日志 / 状态文件，避免与主 Web 服务器（workerman.candy.pid）冲突
        $runtimeDir = (defined('CANDYPATH') ? CANDYPATH . 'Runtime/Webdav' : sys_get_temp_dir() . '/candy-webdav-runtime');
        $runtimeDir = str_replace('\\', '/', $runtimeDir);
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0777, true);
        }
        \Workerman\Worker::$pidFile = $runtimeDir . '/webdav.pid';
        \Workerman\Worker::$statusFile = $runtimeDir . '/webdav.status';
        \Workerman\Worker::$logFile = $runtimeDir . '/webdav.log';

        // Workerman 的 restart = stop + 用当前命令参数重新启动，
        // 因此需要记忆上次启动参数：restart 时未显式传入的选项回退到已保存值
        $argsFile = $runtimeDir . '/args.json';
        $savedArgs = is_file($argsFile) ? (json_decode((string) @file_get_contents($argsFile), true) ?: []) : [];
        $resolve = function (string $name, $default) use ($input, $savedArgs) {
            if ($input->hasOption($name)) {
                return $input->getOption($name);
            }
            return $savedArgs[$name] ?? $default;
        };

        $host = (string) $resolve('host', '0.0.0.0');
        $port = (int) $resolve('port', 8080);
        $dataDir = $resolve('data-dir', null);
        $usersStr = (string) $resolve('users', '');

        // 保存参数，供 restart 恢复
        if (in_array($command, ['start', 'restart'], true)) {
            @file_put_contents($argsFile, json_encode([
                'host' => $host,
                'port' => $port,
                'data-dir' => $dataDir,
                'users' => $usersStr,
            ]));
        }

        // 解析用户认证信息（明文或已有 hash 均可）
        $users = [];
        if ($usersStr !== '') {
            foreach (explode(',', $usersStr) as $userPair) {
                $userPair = trim($userPair);
                if ($userPair === '') {
                    continue;
                }
                [$username, $password] = explode(':', $userPair, 2);
                if ($username !== '' && isset($password)) {
                    $users[$username] = $password;
                }
            }
        }

        $server = new Server($host, $port, $dataDir, $users);

        $output->writeln('<info>WebDAV server ' . $command . ' ...</info>');
        // Worker::runAll() 依据 argv 自动分发 start/stop/restart/reload/status
        $server->run();
    }
}
