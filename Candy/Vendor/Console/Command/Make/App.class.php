<?php
/***
 * Candy框架 App类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Command\Make;

use \Console\{Command,Input,Output};
use \Console\Input\{Argument,Option};

class App extends Command
{
	protected $type = "App";
	
	protected function configure(): void
    {
        parent::configure();
        $this->setName('make:app')
			->addOption('addon', '-a', Option::VALUE_NONE, 'The application is a addon')
			->addOption('plugin', '-p', Option::VALUE_NONE, 'The application is a plugin')
            ->addArgument('appName', Argument::REQUIRED, 'The name of the application')
            ->setDescription('Create a new Application or Addon and Plugin.');
    }
	
	protected function execute(Input $input, Output $output)
    {
		$appName = strtolower(trim($input->getArgument('appName')));
		
		$this->getPathName($appName);
	}
	
	protected function getPathName(string $name): void
    {
		$type = 'App and Plugin';
		if ($this->input->getOption('addon')) {
			$type = 'Addon';
			//创建扩展
            $res = \Candy\Core\Build::bulidSpaces($name, 'addon');
        }else if ($this->input->getOption('plugin')) {
			$type = 'Plugin';
			//仅创建插件
			$res = \Candy\Core\Build::bulidSpaces($name, 'plug');
        }else{
			//创建应用和插件
			$res = \Candy\Core\Build::bulidSpaces($name);
		}
		
		if($res){
			$this->output->writeln('<info>' . $this->type . ': The ' . $type . ' use name of ' . $name . ' created successfully!</info>');
		}else{
			$this->output->writeln('<error>' . $this->type . ': The ' . $type . ' use name of ' . $name . ' already exists!</error>');
		}
    }
}
