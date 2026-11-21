<?php
/***
 * Candy框架 Controller类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Command\Make;

use \Console\{Command,Input,Output};
use \Console\Input\Argument;

class Controller extends Command
{

    protected $type = "Controller";

    protected function configure(): void
    {
        parent::configure();
        $this->setName('make:controller')
			->addArgument('appName', Argument::OPTIONAL, "The name of the app")
			->addArgument('controllerName', Argument::OPTIONAL, "The name of the Controller")
            ->setDescription('Create a new resource controller class.');
    }
	
	protected function execute(Input $input, Output $output)
    {
		$appName = ucfirst(trim($input->getArgument('appName') ?? ''));
		$controllerName = ucfirst(trim($input->getArgument('controllerName') ?? ''));
		
		if(empty($controllerName)){
			$controllerName = $appName;
			$appName = 'Home';
		}
		
		$classname = $this->getClassName($appName, $controllerName);
		$pathname = $this->getPathName($classname);
		
		if (is_file($pathname)) {
            $output->writeln('<error>' . $this->type . ':' . $classname . ' already exists!</error>');
            return false;
        }
		
		if (!is_dir(dirname($pathname))) {
            mkdir(dirname($pathname), 0755, true);
        }
		
		//创建操作
		file_put_contents($pathname, $this->buildClass($classname));
		
		//同步创建语言库
		file_put_contents(dirname(dirname($pathname)) . '/Languages/cn/' . $controllerName .'.lang.php', base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKCi8v6aG555uu5pON5L2c6K+t6KiA5bqT'));
		
		$output->writeln('<info>' . $this->type . ':' . $classname . ' created successfully.</info>');
	}
	
	protected function buildClass(string $name): string
    {
        $namespace = trim(implode('\\', array_slice(explode('\\', $name), 0, -1)), '\\');
        $class = str_replace($namespace . '\\', '', $name);
        return str_replace(['{%className%}', '{%namespace%}'], [$class,$namespace,],base64_decode('PD9waHAKbmFtZXNwYWNlIHslbmFtZXNwYWNlJX07CgpkZWZpbmVkKCdDQU5EWScpIE9SIGRpZSgnWW91IEFyZSBBIEJhZCBHdXkuIG9fTz8/PycpOwoKY2xhc3MgeyVjbGFzc05hbWUlfQp7CiAgICAKfQo='));
    }
	
    protected function getClassName(string $app, string $control): string
    {
        return 'APP\\Control\\'. $app . '\\' . $control;
    }
	
	protected function getPathName(string $name): string
    {
		[, $control, $app, $path] = explode('\\', $name);
		$name = implode('/', array_merge([$app], [$control.'s'], explode('/', $path)));
		return C('CANDYROOT') . 'Application/' . $name . '.class.php';
    }

}
