<?php
/**
 * NoSQL 服务器命令行
 *
 * 用法：php candy nosql <start|stop|restart|reload|status> [--host=] [--port=] [--users=] [--dump-dir=] [--max-rps=] [--max-rps-per-user=]
 *
 * 说明：
 * - start/stop/restart/reload/status 均由 Workerman 的 Worker::runAll() 依据 argv 分发。
 * - --users 为明文 user1:pass1,user2:pass2（认证协议需服务端持有明文密码复算
 *   hash(sha256, 密码+挑战码)，不支持 password_hash）。
 * - --dump-dir 指定周期性落盘目录（不指定则不落盘）；--max-rps / --max-rps-per-user 限流。
 * - 认证防爆破（默认 5 次失败锁定 300 秒，计数持久化到 Runtime/NoSql/auth.json，跨重启生效）。
 *
 * @package Console\Command
 */
namespace Console\Command;

use Console\{Command, Input, Output};
use Console\Input\{Option, Argument};
use NoSQL\Server;

class NoSql extends Command
{
    public function configure(): void
    {
        $this->setName('nosql')
             ->addOption('host', null, Option::VALUE_OPTIONAL, 'The server host.', '0.0.0.0')
             ->addOption('port', null, Option::VALUE_OPTIONAL, 'The server port.', 2404)
             ->addOption('users', null, Option::VALUE_OPTIONAL, 'Auth users in format user1:pass1,user2:pass2', '')
             ->addOption('dump-dir', null, Option::VALUE_OPTIONAL, 'Periodic dump directory (empty = no dump).', '')
             ->addOption('max-rps', null, Option::VALUE_OPTIONAL, 'Global requests per second limit.', 1000)
             ->addOption('max-rps-per-user', null, Option::VALUE_OPTIONAL, 'Per-user requests per second limit.', 100)
             ->addArgument('command', Argument::REQUIRED, "The command for server (start|stop|restart|reload|status)")
             ->setDescription('NoSQL Server for Candy.');
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

        // 独立运行时目录：PID / 日志 / 状态 / 认证计数
        $runtimeDir = (defined('CANDYPATH') ? CANDYPATH . 'Runtime/NoSql' : sys_get_temp_dir() . '/candy-nosql-runtime');
        $runtimeDir = str_replace('\\', '/', $runtimeDir);
        // 用框架的目录函数创建（统一权限、递归补父目录），再校验可写
        if (!\Candy\Extend\Dir::create($runtimeDir, 0775)) {
            $output->writeln('<error>无法创建 NoSQL 运行目录：' . $runtimeDir . '</error>');
            return;
        }
        if (!is_writable($runtimeDir)) {
            @chmod($runtimeDir, 0775);
        }
        if (!is_writable($runtimeDir)) {
            $output->writeln('<error>NoSQL 运行目录不可写：' . $runtimeDir
                . '（属主/权限不对，需 chown www:www）</error>');
            return;
        }
        \Workerman\Worker::$pidFile = $runtimeDir . '/nosql.pid';
        \Workerman\Worker::$statusFile = $runtimeDir . '/nosql.status';
        \Workerman\Worker::$logFile = $runtimeDir . '/nosql.log';

        // restart 时恢复上次启动参数
        $argsFile = $runtimeDir . '/args.json';
        $savedArgs = is_file($argsFile) ? (json_decode((string) @file_get_contents($argsFile), true) ?: []) : [];
        // 配置默认值：Candy/Class/Tools/Inc/NoSQL.inc.php（框架）← Source/Inc/NoSQL.inc.php（项目覆盖）
        // 优先级：命令行显式传参 > 上次启动参数（restart）> 配置文件 > 内置默认
        $incCfg = function_exists('loadConfig') ? (array) loadConfig('NoSQL') : [];
        $declared = ['host' => '0.0.0.0', 'port' => 2404, 'users' => '', 'dump-dir' => '',
                     'max-rps' => 1000, 'max-rps-per-user' => 100];
        $cfgMap = ['host' => 'host', 'port' => 'port', 'dump-dir' => 'dump_dir',
                   'max-rps' => 'max_rps', 'max-rps-per-user' => 'max_rps_per_user'];
        $resolve = function (string $name, $default) use ($input, $savedArgs, $incCfg, $cfgMap) {
            // ① 命令行显式传（与声明默认不同才算显式）
            if ($input->hasOption($name)) {
                $val = $input->getOption($name);
                $def = ['host' => '0.0.0.0', 'port' => 2404, 'users' => '', 'dump-dir' => '',
                        'max-rps' => 1000, 'max-rps-per-user' => 100][$name] ?? null;
                if ((string) $val !== (string) $def) {
                    return $val;
                }
            }
            // ② restart 沿用上次参数
            if (isset($savedArgs[$name])) {
                return $savedArgs[$name];
            }
            // ③ 配置文件
            if ($name === 'users') {
                if (!empty($incCfg['users']) && is_array($incCfg['users'])) {
                    $pairs = [];
                    foreach ($incCfg['users'] as $u => $p) {
                        if ((string) $u !== '' && (string) $p !== '') {
                            $pairs[] = $u . ':' . $p;
                        }
                    }
                    if ($pairs) {
                        return implode(',', $pairs);
                    }
                }
            } elseif (isset($cfgMap[$name], $incCfg[$cfgMap[$name]])) {
                return $incCfg[$cfgMap[$name]];
            }
            return $default;
        };

        $host = (string) $resolve('host', '0.0.0.0');
        $port = (int) $resolve('port', 2404);
        $usersStr = (string) $resolve('users', '');
        $dumpDir = (string) $resolve('dump-dir', '');
        $maxRps = (int) $resolve('max-rps', 1000);
        $maxRpsPerUser = (int) $resolve('max-rps-per-user', 100);

        if (in_array($command, ['start', 'restart'], true)) {
            @file_put_contents($argsFile, json_encode([
                'host' => $host,
                'port' => $port,
                'users' => $usersStr,
                'dump-dir' => $dumpDir,
                'max-rps' => $maxRps,
                'max-rps-per-user' => $maxRpsPerUser,
            ]));
        }

        // 解析用户（明文，认证协议需要）
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

        $config = [
            'users' => $users,
            'max_rps' => $maxRps,
            'max_rps_per_user' => $maxRpsPerUser,
        ];
        if ($dumpDir !== '') {
            $config['driver_config'] = ['dumpFile' => rtrim($dumpDir, '/') . '/'];
        }

        $server = new Server("{$host}:{$port}", $config);

        $output->writeln('<info>NoSQL server ' . $command . ' ...</info>');
        \Workerman\Worker::runAll();
    }
}
