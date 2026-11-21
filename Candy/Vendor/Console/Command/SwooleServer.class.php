<?php
/***
 * Candy框架 Swoole Server类
 * 
 * $Author: 开发团队 $ 
 * $Date: " . date('Y-m-d H:i:s') . " $   
 */

declare (strict_types = 1);
namespace Console\Command;

use \Console\{Command,Input,Output};
use \Console\Input\{Option,Argument};
use \Candy\Extend\Dir;

class SwooleServer extends Command
{
    protected string $container = 'swoole';

    public function configure(): void
    {
        $this->setName('SwooleServer')
             ->addOption('config', 'c', Option::VALUE_OPTIONAL, 'Swoole服务配置文件路径', null)
             ->addArgument('command', Argument::REQUIRED, 'Swoole操作命令（start/stop/restart）')
             ->addOption('daemon', 'd', Option::VALUE_NONE, '守护模式启动')
             ->setDescription('Candy框架内置Swoole服务器管理命令');
    }

    public function execute(Input $input, Output $output)
    {
        // 与 Workerman 同一个配置文件；走 incResolve 才会落到框架默认值（项目没建覆盖也能启动）
        $configPath = $input->getOption('config')
            ?? (function_exists('incResolve') ? (string) incResolve('Server') : '');
        $command = $input->getArgument('command');

        if ($configPath === '' || !is_file($configPath)) {
            $output->writeln('<error>配置文件不存在，请先初始化配置</error>');
            return;
        }

        $config = loadConfig('Server'); // 加载Server配置
        if (empty($config)) {
            $output->writeln('<error>配置文件内容为空</error>');
            return;
        }

        match ($command) {
            'start' => (function() use ($config, $input, $output) {
                $this->startServer($config, $input->getOption('daemon'));
                $output->writeln('<info>Swoole服务器启动成功</info>');
            })(),
            'stop' => (function() use ($output) {
                $this->stopServer();
                $output->writeln('<info>Swoole服务器停止成功</info>');
            })(),
            'restart' => (function() use ($config, $input, $output) {
                $this->stopServer();
                $this->startServer($config, $input->getOption('daemon'));
                $output->writeln('<info>Swoole服务器重启成功</info>');
            })(),
            default => $output->writeln('<error>未知命令，支持start/stop/restart</error>'),
        };
    }

    /**
     * 服务句柄目录：pid 落 Runtime/service，日志落 Runtime/Logs/service
     *
     * 与其他内置服务一致（按服务名隔离），服务操作页才能读到正确的 pid 与日志。
     *
     * @return array{0:string,1:string} [pidDir, logDir]
     */
    protected function serviceDirs(): array
    {
        $root = defined('CANDYROOT') ? CANDYROOT : (string) C('CANDYROOT');
        $sep = DIRECTORY_SEPARATOR;
        $pidDir = $root . 'Runtime' . $sep . 'service' . $sep;
        $logDir = $root . 'Runtime' . $sep . 'Logs' . $sep . 'service' . $sep;
        if (!is_dir($pidDir)) {
            @mkdir($pidDir, 0775, true);
        }
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        return [$pidDir, $logDir];
    }

    protected function startServer(array $config, bool $isDaemon): void
    {
        // 创建日志目录
        Dir::create(C('LOGPATH'));
        [$svcPidDir, $svcLogDir] = $this->serviceDirs();

        // 按 ip:port 归组：一个 Swoole Server 只能 start() 一次，
        // 其余监听地址用 addListener() 挂到同一个实例上（80 + 443 就是靠这个组合）。
        $groups = [];
        $roots = [];
        $compelList = [];
        $swoolePort = (int) ($config['webserver']['swoole']['port'] ?? 0);

        // swoole.port 只是「单站点场景下与常驻 Web 服务错开」的便捷覆盖：
        // 配了多个不同端口（如 80 + 443 的完整虚拟主机）时，必须尊重各自配置，不再统一覆盖。
        $listPorts = [];
        foreach ((array) ($config['webserver']['server'] ?? []) as $g) {
            foreach ((array) ($g['list'] ?? []) as $one) {
                $listPorts[(int) ($one['port'] ?? (isset($one['ssl']) ? 443 : 80))] = true;
            }
        }
        if (count($listPorts) > 1) {
            $swoolePort = 0;
        }

        foreach ((array) ($config['webserver']['server'] ?? []) as $serverGroup) {
            foreach ((array) ($serverGroup['list'] ?? []) as $serverConfig) {
                $ip = (string) ($serverConfig['ip'] ?? '0.0.0.0');
                $isSsl = isset($serverConfig['ssl']);
                $port = (int) ($serverConfig['port'] ?? ($isSsl ? 443 : 80));
                // 与「常驻 Web 服务」错开端口：配置了 webserver.swoole.port 时统一用它
                if ($swoolePort > 0) {
                    $port = $swoolePort;
                }
                if ($isSsl && $port === 80) {
                    throw new \RuntimeException('ssl类型不能使用80端口');
                }

                $key = $ip . ':' . $port;
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'ssl'     => $isSsl,
                        'context' => $isSsl ? ['ssl' => (array) $serverConfig['ssl']] : [],
                        'sni'     => [],
                        'worker'  => (int) ($serverConfig['worker_num'] ?? $serverConfig['count'] ?? 1),
                    ];
                } elseif ($groups[$key]['ssl'] !== $isSsl) {
                    throw new \RuntimeException('同一 Swoole 监听地址不能混用 HTTP 和 HTTPS：' . $key);
                }

                if ($isSsl) {
                    $groups[$key]['sni'][(string) $serverConfig['domain']] = (array) $serverConfig['ssl'];
                }
                $roots[] = [(string) $serverConfig['domain'], $serverConfig];
                if (!empty($serverConfig['compel'])) {
                    $compelList[] = (string) $serverConfig['domain'];
                }
            }
        }

        if ($groups === []) {
            throw new \RuntimeException('webserver.server 没有可用的监听配置');
        }

        // 第一个分组建实例，其余分组成 addListener 端口
        $primary = null;
        foreach ($groups as $key => $group) {
            if ($primary === null) {
                $primary = new \WebServer\Swoole($key, $group['context']);
                $primary->setConfig([
                    'worker_num'       => $group['worker'] > 0 ? $group['worker'] : 1,
                    'daemonize'        => $isDaemon,
                    'log_file'         => $svcLogDir . 'swoole.log',
                    'pid_file'         => $svcPidDir . 'swoole.pid',
                    'enable_coroutine' => true,
                ]);
                foreach ($group['sni'] as $domain => $ssl) {
                    $primary->addSslCertificate((string) $domain, $ssl);
                }
                continue;
            }
            $primary->addPort($key, $group['context'], $group['sni']);
        }

        // 站点映射与端口无关：按 Host 分发，全实例共享
        foreach ($roots as $root) {
            [$domain, $serverConfig] = $root;
            $primary->addRoot($domain, $serverConfig);
        }
        $primary->setCompelList($compelList);

        // 服务操作页要读 pid：非守护模式先把自己落盘（守护模式由 Swoole 自己写，pid 相同）
        if (!$isDaemon) {
            @file_put_contents($svcPidDir . 'swoole.pid', (string) getmypid());
        }

        $this->showInfo();
        $primary->start();
    }

    protected function stopServer(): void
    {
        [$svcPidDir] = $this->serviceDirs();
        $pidFile = $svcPidDir . 'swoole.pid';
        if (is_file($pidFile)) {
            $pid = (int)trim((string)file_get_contents($pidFile));
            if ($pid > 0 && posix_kill($pid, SIGTERM)) {
                for ($i = 0; $i < 50 && posix_kill($pid, 0); $i++) {
                    usleep(100000);
                }
            }
            @unlink($pidFile);
        }
    }
}
