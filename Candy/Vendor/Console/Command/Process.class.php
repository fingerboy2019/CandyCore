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

class Process extends Command
{
	protected string $container = 'worker';

	public function configure(): void
    {
		$this->setName('ProcessServer')
			 ->addArgument('command', Argument::REQUIRED, "The command for Process.")
			 ->addOption('daemon', 'd', Option::VALUE_NONE, "Daemon mode for Process.")
			 ->addOption('gracefully', 'g', Option::VALUE_NONE, "Daemon mode for Process.")
			 ->setDescription('Workerman Built-in Process for Candy.');
	}
	
	public function execute(Input $input, Output $output)
    {
		$command = $input->getArgument('command');
        
        $available_commands = ['start','stop','restart','reload','status','connections'];
        if(in_array($command, $available_commands)){
            if(PHP_SAPI == 'cli'){
                // 检查扩展
                if(extension_loaded('pcntl')  && extension_loaded('posix')){
                    // 每个服务独立的 pid / 日志文件（避免与其它服务互相覆盖）
                    if (class_exists('\\Candy\\Extend\\Tools\\Service\\Manager')) {
                        \Candy\Extend\Tools\Service\Manager::applyHandles('process');
                    }
                    //启动自定义进程
                    $status = $this->startProcess();

                    if($status){
                        $this->showInfo();
                        \Workerman\Worker::runAll();
                    }else{
                        $output->writeln('<error>No Process Found.</error>');
                    }
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
}