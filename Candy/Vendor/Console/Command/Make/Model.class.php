<?php
/***
 * Candy框架 Model类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Command\Make;

use \Console\{Command,Input,Output};
use \Console\Input\Argument;

class Model extends Command
{
    protected $type = "Model";

    protected function configure(): void
    {
        parent::configure();
        $this->setName('make:model')
			->addArgument('appName', Argument::OPTIONAL, "The name of the app")
			->addArgument('modelName', Argument::OPTIONAL, "The name of the model")
            ->setDescription('Create a new model class.');
    }
	
	protected function execute(Input $input, Output $output)
    {
		$appName = ucfirst(trim($input->getArgument('appName') ?? ''));
		$modelName = ucfirst(trim($input->getArgument('modelName') ?? ''));
		
		if(empty($modelName)){
			$modelName = $appName;
			$appName = 'Home';
		}
		
		$classname = $this->getClassName($appName, $modelName);
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
		
		$output->writeln('<info>' . $this->type . ':' . $classname . ' created successfully.</info>');
	}
	
	protected function buildClass(string $name): string
    {
        $namespace = trim(implode('\\', array_slice(explode('\\', $name), 0, -1)), '\\');
        $class = str_replace($namespace . '\\', '', $name);
        return str_replace(['{%className%}', '{%namespace%}'], [$class,$namespace,], base64_decode('PD9waHAKZGVmaW5lZCgnQ0FORFknKSBPUiBkaWUoJ1lvdSBBcmUgQSBCYWQgR3V5LiBvX08/Pz8nKTsKbmFtZXNwYWNlIHslbmFtZXNwYWNlJX07CgpDbGFzcyB7JWNsYXNzTmFtZSV9CnsKICAgIC8vCn0K'));
    }

    protected function getNamespace(string $app): string
    {
        return 'APP\\Model\\' . $app;
    }
	
	protected function getClassName(string $app, string $model): string
    {
        return 'APP\\Model\\'. $app . '\\' . $model;
    }
	
	protected function getPathName(string $name): string
    {
		[$base, $model, $app, $path] = explode('\\', $name);
		$name = implode('/', array_merge([$app], [$model.'s'], explode('\\', $path)));
        return C('CANDYROOT') . 'Application/' . $name . '.class.php';
    }
}
