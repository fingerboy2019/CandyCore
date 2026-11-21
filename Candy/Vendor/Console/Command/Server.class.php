<?php
/***
 * Candy框架 workerman Server类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare (strict_types = 1);
namespace Console\Command;

use \Workerman\Events\Fiber;
use \Console\{Command,Input,Output};
use \Console\Input\{Option,Argument};

class Server extends Command
{
	protected string $container = 'worker';

	public function configure(): void
    {
		$this->setName('WorkermanServer')
			 ->addOption('config', 'c', Option::VALUE_OPTIONAL, 'The server config path for workerman.', null)
			 ->addArgument('command', Argument::REQUIRED, "The command for workerman.")
			 ->addOption('daemon', 'd', Option::VALUE_NONE, "Daemon mode for workerman.")
			 ->addOption('gracefully', 'g', Option::VALUE_NONE, "Daemon mode for workerman.")
			 ->setDescription('Workerman Built-in Server for Candy.');
	}
	
	public function execute(Input $input, Output $output)
    {
		$config = $input->getOption('config');
		$command = $input->getArgument('command');

		if(empty($config)){
			// 配置不再要求项目侧生成：项目有覆盖就用覆盖，没有就用框架默认文件
			$paths = function_exists('incPaths') ? incPaths('Server') : [];
			$config = (string) ($paths['project'] ?? '');
			if ($config === '' || !is_file($config)) {
				$config = (string) ($paths['framework'] ?? (C('INCPATH') . 'Server.inc.php'));
			}
		}

		if(is_file($config)){
			$servers = loadConfig('Server');
			if(empty($servers)){
				$output->writeln('<error>The configuration file no content.</error>');
			}else{
				$available_commands = ['start','stop','restart','reload','status','connections'];
				if(in_array($command, $available_commands)){
					if(PHP_SAPI == 'cli'){
						// 检查扩展
						if(extension_loaded('pcntl')  && extension_loaded('posix')){
							$this->runServer($servers);
						} else if(extension_loaded('pcntl') === false){
							$output->writeln('<error>Please install pcntl extension. See http://doc3.workerman.net/appendices/install-extension.html</error>');
						} else if(extension_loaded('posix') === false){
							$output->writeln('<error>Please install posix extension. See http://doc3.workerman.net/appendices/install-extension.html</error>');
						}
					}else{
						$output->writeln('<error>The server must run on php cli.</error>');
					}
				}else{
					$output->writeln("<info>Usage: php candy worker [options] [--] <command> [<mode>]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\nconnections\tGet worker connections.\n</info>");
				}
			}
		}else{
			$output->writeln('<error>找不到 WebServer 配置：Candy/Class/Tools/Inc/Server.inc.php</error>');
			$output->writeln('<info>框架默认配置缺失（框架文件不完整）。自定义请建 Source/Inc/Server.inc.php 覆盖，无需 init 生成。</info>');
		}
	}
	
	/**
     * 运行服务器
     */
    protected function runServer(array $servers): void
    {
		//如果是win服务器多线程需要多脚本
		if(isWinOS() && (count($servers) > 1 || isset($servers['autoRunserver'])))
		{
			exit('windows not can use more worker in one file, please use more file to start worker');
		}
		
		//使用WebServer服务
		if(isset($servers['webserver'])){
			//初始化监控文件夹
			$dirs = [];
			
			//开启pathinfo
			define('PATHINFO', true);
			
			//强制跳转列表
			$urlCompelList = [];
			
			//端口号
			$ports = [];
			$portSsl = [];
			
			//配置服务
			foreach($servers['webserver']['server'] as $sever){
				$ip = $sever['ip'] ?? '0.0.0.0';
				if(empty($sever['list']))
					exit('No domain list can\'t running.');
				
				//域名
				foreach($sever['list'] as $config){
					// root 未配置时默认站点根目录：列表数组是「整体替换」，
					// 项目只写 domain/port 时不会带上框架默认的 root
					if (empty($config['root'])) {
						$config['root'] = C('CANDYROOT');
					}
					$context = [];
					if(isset($config['ssl'])){
						$context['ssl'] = $config['ssl'];
						$port = $config['port'] ?? 443;
						if($port == '80'){
							exit('ssl type can\'t use port 80.');
						}
					}else{
						$port = $config['port'] ?? 80;
					}
					$workername = 'web'.$port;
					$isSsl = isset($context['ssl']);
					if (isset($portSsl[$port]) && $portSsl[$port] !== $isSsl) {
						exit('同一 Workerman 监听端口不能混用 HTTP 和 HTTPS。');
					}
					$portSsl[$port] = $isSsl;
					if(!in_array($port, $ports)){
						$context['coroutine'] = (bool)($config['coroutine'] ?? false);
						$$workername = new \WebServer\Server('http://'.$ip.':'.$port, $context);
						if(isset($context['ssl'])){
							$$workername->transport = 'ssl';
						}

						// WebServer 默认保持同步模式；配置 coroutine=true 时启用 Workerman Fiber
						// 请求上下文隔离。业务代码仍须通过 I()/R()/cookie()/session() 访问请求数据。
						
						//进程个数
						$$workername->count = $config['count'] ?? 4;

						// __MAXREQ_CMD__ 单 worker 请求数上限（未配 = 不限制）
						// 先取出实例再设属性，避免 $$workername->prop 混写的解析歧义
						if (isset($config['max_request'])) {
							$__wsInstance = $$workername;
							$__wsInstance->maxRequest = (int) $config['max_request'];
							unset($__wsInstance);
						}

						// __REUSEPORT_CMD__ 多 worker 监听同端口时减少惊群（未配 = false 不开启）
						// workerman 原生属性，Linux 3.9+ 有效；count=1 时无意义，调大 count 后才需要
						if (isset($config['reuse_port'])) {
							$__wsInstance = $$workername;
							$__wsInstance->reusePort = (bool) $config['reuse_port'];
							unset($__wsInstance);
						}
						
						// 传入完整站点配置（root / additionHeader / custom404）
						$$workername->addRoot($config['domain'], $config);
						
						//开启gzip压缩 1-9
						if(isset($config['gzip'])){
							$$workername->gzip = $config['gzip'];
						}
						
						//开启缓存时间
						if(isset($config['max-age'])){
							$$workername->max_age = $config['max-age'];
						}
						
						//Worker启动回调
						if(isset($config['onWorkerStart']) && is_callable($config['onWorkerStart'])){
							$$workername->onWorkerStart = $config['onWorkerStart'];
						}
						
						//连接回调
						if(isset($config['onConnect']) && is_callable($config['onConnect'])){
							$$workername->onConnect = $config['onConnect'];
						}
						
						//连接关闭回调
						if(isset($config['onClose']) && is_callable($config['onClose'])){
							$$workername->onClose = $config['onClose'];
						}
						
						$ports[] = $port;
						
						//开启强制跳转服务端口 默认1进程
						if(isset($config['compel']) && $config['compel'] === true){
							$urlCompelList[] = [$config['domain'], $config['root']];
						}
					}else{
						$$workername->addRoot($config['domain'], $config);
					}
					if (isset($context['ssl'])) {
						$$workername->addSslCertificate((string)$config['domain'], (array)$context['ssl']);
					}
				}
			}
			
			if(!empty($urlCompelList)){
				if(!in_array(80, $ports)){
					$web80 = new \WebServer\Server('http://0.0.0.0:80');
					$web80->count = 1;
					$ports[] = 80;
				}
				
				//开启强制跳转服务端口 默认1进程
				if(!empty($urlCompelList)){
					foreach($urlCompelList as $compel){
						[$domain, $root] = $compel;
						$web80->addRoot($domain, $root);
						$web80::$urlCompelList[] = $domain;
					}
				}
			}
			
			//win主机只允许运行一个端口
			if(isWinOS() && count($ports) > 1){
				exit('WinOS just use one port for running.');
			}
			// 开发模式（DEV 或 DEBUG=1）才启用文件监视做热更新；
			// 生产环境不创建监视器 —— inotify 句柄与定时器都是纯开销，且会因改文件触发意外 reload。
			// ⚠️ 这两个常量由 Bootstrap::applyRuntimeFlags() 在运行时定义，而本命令在 boot 之前执行，
			//    所以必须先主动应用一次，否则 $devMode 恒为 false、FileMonitor 永远不会被创建。
			if (class_exists(\Candy\Core\Bootstrap::class)) {
				\Candy\Core\Bootstrap::applyRuntimeFlags();
			}
			$devMode = defined('DEV') || (defined('DEBUG') && DEBUG == 1);

			if(isWinOS() == false){
				if($devMode){
					$dirs = [
						CANDYPATH . 'Addons',
						CANDYPATH . 'Application',
						CANDYPATH . 'Source'
					];
					//开发模式下关注核心文件夹
					if(defined('DEV')){
						$dirs[] = CANDYPATH . 'Candy';
					}
					$monitor = \FileMonitor\Monitor::instance(1);
					$monitor->addMonitorDir($dirs);
				}

				// 定时自动 reload：与开发模式无关，按配置显式开启（默认不开）
				if(isset($servers['reload']) && intval($servers['reload']) > 0){
					\Workerman\Timer::add($servers['reload'], function(){
						echo "all workers is reload by timertaks.\n";
						// 给父进程也就是主进程发送reload信号
						posix_kill(posix_getppid(), SIGUSR1);
					});
				}
			}
		}
		
		// 项目进程组（globaldata / scheduler 等）由「计划任务调度」服务独立负责，
		// Web 服务不再顺带启动它 —— 否则会抢 2207 等端口，导致 Web 服务起来即退出。
		
		$this->showInfo();

		// 每个服务独立的 pid / 日志文件（Runtime/service/web.pid 等）
		if (class_exists('\\Candy\\Extend\\Tools\\Service\\Manager')) {
			\Candy\Extend\Tools\Service\Manager::applyHandles('web');
		}

		// 服务页要读 pid：在 fork / daemonize 之前把自己的 pid 落到 Runtime/service/web.pid
		$__daemon = false;
		foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $__one) {
			if ($__one === '-d' || $__one === '--daemon') {
				$__daemon = true;
				break;
			}
		}
		if (!$__daemon) {
			$__pidDir = CANDYROOT . 'Runtime' . DIRECTORY_SEPARATOR . 'service';
			if (!is_dir($__pidDir)) {
				@mkdir($__pidDir, 0775, true);
			}
			@file_put_contents($__pidDir . DIRECTORY_SEPARATOR . 'web.pid', (string) getmypid());
		}

		\Workerman\Worker::runAll();
	}
	
}
