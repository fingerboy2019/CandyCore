<?php
/***
 * Candy框架 workerman Server类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare (strict_types = 1);
namespace Console\Command;

use \Console\{Command,Input,Output};
use \Console\Input\{Option,Argument};

class Wserver extends Command
{
    protected string $container = 'ws';

    public function configure(): void
    {
		$this->setName('WsServer')
			 ->addOption('config', 'c', Option::VALUE_OPTIONAL, 'The server config path for WsServer.', null)
			 ->addArgument('command', Argument::REQUIRED, "The command for WsServer.")
			 ->addOption('daemon', 'd', Option::VALUE_NONE, "Daemon mode for WsServer.")
			 ->addOption('gracefully', 'g', Option::VALUE_NONE, "Daemon mode for WsServer.")
			 ->setDescription('WsServer Built-in Server for Candy.');
	}

    public function execute(Input $input, Output $output)
    {
        $config = $input->getOption('config');
		$command = $input->getArgument('command');
		if(empty($config)){
            $config = C('INCPATH') . 'WsServer.inc.php';
        }
        $available_commands = ['start','stop','restart','reload','status','connections'];
        if(in_array($command, $available_commands)){
            if(PHP_SAPI == 'cli'){
                // 检查扩展
                if(extension_loaded('pcntl')  && extension_loaded('posix')){
                    $this->showInfo();
                        if(is_file($config) === false){
                            //无配置也可以运行 提示就好
                            $output->writeln("Working without configs file, Only used the default configuration.");
                            $servers = [];
                        }else{
                            $servers = loadConfig('WsServer');
                        }

                        //启动自定义进程
		                $this->startProcess();

                    new \Socket\Server($servers);
                } else if(extension_loaded('pcntl') === false){
                    $output->writeln('<error>Please install pcntl extension. See http://doc3.workerman.net/appendices/install-extension.html</error>');
                } else if(extension_loaded('posix') === false){
                    $output->writeln('<error>Please install posix extension. See http://doc3.workerman.net/appendices/install-extension.html</error>');
                }
            }else{
                $output->writeln('<error>The server must run on php cli.</error>');
            }
        }
    }

}
