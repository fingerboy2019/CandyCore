<?php
/***
 * Candy框架 Command类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Command\Make;

use \Console\Command as Commands;
use \Console\{Input,Output};
use \Console\Input\Argument;

class Command extends Commands
{
    protected $type = "Command";

    protected function configure(): void
    {
        parent::configure();
        $this->setName('make:command')
            ->addArgument('commandName', Argument::OPTIONAL, "The name of the command")
            ->setDescription('Create a new command class.');
    }
	
	protected function execute(Input $input, Output $output)
    {
        $commandName = trim($input->getArgument('commandName'));
		
        $classname = $this->getClassName($commandName);
        $pathname = $this->getPathName($classname);
		
        if (is_file($pathname)) {
            $output->writeln('<error>' . $this->type . ':' . $classname . ' already exists!</error>');
            return false;
        }
		
        if (!is_dir(dirname($pathname))) {
            mkdir(dirname($pathname), 0755, true);
        }

        file_put_contents($pathname, $this->buildClass($classname));

        $output->writeln('<info>' . $this->type . ':' . $classname . ' created successfully.</info>');
    }
	
    protected function buildClass(string $commandName): string
    {
        $namespace   = trim(implode('\\', array_slice(explode('\\', $commandName), 0, -1)), '\\');
		
        $class = str_replace($namespace . '\\', '', $commandName);
		
		//自动修改配置文件
		$Command = loadConfig('Command');
		$Command[strtolower($class)] = $commandName;
		editInc('Command', $Command);
		
        return str_replace(['{%commandName%}', '{%className%}', '{%namespace%}', '{%app_namespace%}'], [
            $commandName,
            $class,
            $namespace,
            $this->getNamespace($commandName),
        ], base64_decode('PD9waHAKZGVjbGFyZSAoc3RyaWN0X3R5cGVzID0gMSk7Cm5hbWVzcGFjZSB7JW5hbWVzcGFjZSV9Owp1c2UgQ2FuZHlcRXh0ZW5kXENvbnNvbGVce0NvbW1hbmQsSW5wdXQsT3V0cHV0fTsKdXNlIENhbmR5XEV4dGVuZFxDb25zb2xlXElucHV0XHtBcmd1bWVudDAsT3B0aW9ufTsKCmNsYXNzIHslY2xhc3NOYW1lJX0gZXh0ZW5kcyBDb21tYW5kCnsKICAgIHByb3RlY3RlZCBmdW5jdGlvbiBjb25maWd1cmUoKTogdm9pZAogICAgewogICAgICAgIC8vIOaMh+S7pOmFjee9rgogICAgICAgICR0aGlzLT5zZXROYW1lKCd7JWNvbW1hbmROYW1lJX0nKQogICAgICAgICAgICAtPnNldERlc2NyaXB0aW9uKCd0aGUgeyVjb21tYW5kTmFtZSV9IGNvbW1hbmQnKTsKICAgIH0KCiAgICBwcm90ZWN0ZWQgZnVuY3Rpb24gZXhlY3V0ZShJbnB1dCAkaW5wdXQsIE91dHB1dCAkb3V0cHV0KQogICAgewogICAgICAgIC8vIOaMh+S7pOi+k+WHugogICAgICAgICRvdXRwdXQtPndyaXRlbG4oJ3slY29tbWFuZE5hbWUlfScpOwogICAgfQp9Cg=='));
    }

    protected function getNamespace(string $app): string
    {
        return 'APP\\Command\\' . $app;
    }

    protected function getPathName(string $name): string
    {
		
		$paths = explode('\\', $name);
		$filename = end($paths);
		array_pop($paths);
		$base = array_shift($paths);
		if(count($paths) > 1){
			$command = array_shift($paths);
			$app = array_shift($paths);
			if(empty($paths)){
				$name = implode('\\', [$base, $app, $command, $filename]);
			}else{
				$name = implode('\\', array_merge([$base], [$app], [$command], $paths, [$filename]));
			}
		}
		
        return $this->getBasePath($name) . ucfirst(strtolower($filename)) . '.class.php';
    }
	
	protected function getClassName(string $name): string
    {
        if (strpos($name, '\\') !== false) {
            return $name;
        }

        if (strpos($name, '@')) {
            [$app, $name] = explode('@', $name);
        } else {
            $app = '';
        }

        if (strpos($name, '/') !== false) {
            $name = str_replace('/', '\\', $name);
        }
		
        return $this->getNamespace($app) . (empty($app) ? '' : '\\') . ucfirst(strtolower($name));
    }
	
	protected function getBasePath(string $name): string
    {
		return dirname
		(C('CANDYROOT') . str_replace(['APP', '\\'], ['Application','/'], $name)) . '/';
    }
}
