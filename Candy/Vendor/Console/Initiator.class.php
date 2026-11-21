<?php
/***
 * Candy框架 控制台类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare (strict_types = 1);
namespace Console;

use \Console\Command;
use \Console\Command\{Clear,Help,Lists,RunServer,Server,Process,SwooleServer,Webdav,Wserver,Ftp,Dns,NoSql,VendorPublish,Version,Composer,Doctor,Ip};
use \Console\Command\Apidoc\Init as ApidocInit;
use \Console\Command\Catlog\Init as CatlogInit;
use \Console\Command\Check\Init as CheckInit;
use \Console\Command\Signer\Init as SignerInit;
use \Console\Command\PayloadCrypto\Init as PayloadCryptoInit;
use \Console\Command\Help as HelpCommand;
use \Console\Command\Build\{Bin,Pharbuild};
use \Console\Command\Make\App as MakeApp;
use \Console\Command\Make\Command as MakeCommand;
use \Console\Command\Make\Addon as MakeAddon;
use \Console\Command\Make\{Controller,Model,Service,Validate,Schema};
use \Console\Command\Migrate\{Make as MigrateMake,Run as MigrateRun,Rollback as MigrateRollback,Status as MigrateStatus};
use \Console\Command\Seed\{Make as SeedMake,Run as SeedRun,Status as SeedStatus};
use \Console\Command\Schedule\{Run as ScheduleRun,Lists as ScheduleLists};
use \Console\Command\Queue\{Work as QueueWork,Status as QueueStatus};
use \Console\Input;
use \Console\Input\Argument as InputArgument;
use \Console\Input\Definition as InputDefinition;
use \Console\Input\Option as InputOption;
use \Console\Output;
use \Console\Output\driver\Buffer;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final Class Initiator {
	protected $app;

    /** @var Command[] */
    protected $commands = [];
	
	protected $wantHelps = false;

    protected $catchExceptions = true;
    protected $autoExit        = true;
    protected $definition;
    protected $defaultCommand  = 'list';
	
	//默认命令（框架库自带；项目/后台新增命令见 Source/Inc/Command.inc.php）
	protected $defaultCommands = [
        'help'				=> Help::class,
        'list'				=> Lists::class,
        'clear'				=> Clear::class,
        'mailbox'			=> \Console\Command\Mailbox::class,
        'build:bin'			=> Bin::class,
        'build:phar'		=> Pharbuild::class,
        'make:app'			=> MakeApp::class,
        'make:control'		=> Controller::class,
        'make:command'		=> MakeCommand::class,
        'make:model'		=> Model::class,
        'make:validate'		=> Validate::class,
        'make:schema'		=> Schema::class,
        // 数据库迁移（框架级；项目可在 Source/Inc/Command.inc.php 覆盖）
        'migrate:make'		=> MigrateMake::class,
        'migrate:run'		=> MigrateRun::class,
        'migrate:rollback'	=> MigrateRollback::class,
        'migrate:status'	=> MigrateStatus::class,
        // 数据填充（框架级）
        'seed:make'			=> SeedMake::class,
        'seed:run'			=> SeedRun::class,
        'seed:status'		=> SeedStatus::class,
        // 计划任务（FPM 下用一条系统 cron 驱动 schedule:run）
        'schedule:run'		=> ScheduleRun::class,
        'schedule:list'		=> ScheduleLists::class,
        // 任务队列（FPM 可用，基于 PHP redis 扩展）
        'queue:work'			=> QueueWork::class,
        'queue:status'		=> QueueStatus::class,
        'run'				=> RunServer::class,
        'worker'			=> Server::class,
        'process'			=> Process::class,
        'swoole'			=> SwooleServer::class,
        'webdav'			=> Webdav::class,
        'ws'	    		=> Wserver::class,
        'ftp'		    	=> Ftp::class,
        'dns'		    	=> Dns::class,
        'nosql'		    	=> NoSql::class,
        'version'			=> Version::class,
        // 服务依赖预检（与 /sys/service 页面共用 Candy\Extend\Tools\Service\Doctor）
        'doctor'			=> Doctor::class,
        // IP 黑白名单管理（与 /sys/service 页面共用 Candy\Core\Security）
        'ip'				=> Ip::class,
        'vendor:publish'	=> VendorPublish::class,
        'composer'      	=> Composer::class,
        'apidoc:init'		=> ApidocInit::class,
        'catlog:init'		=> CatlogInit::class,
        'check:init'		=> CheckInit::class,
        'signer:init'		=> SignerInit::class,
        'payload-crypto:init'	=> PayloadCryptoInit::class,
    ];
	
	//非标准命令
	protected $nonStandardCommands = [];
	
	/**
     * 启动器
     * @var array
     */
    protected static $startCallbacks = [];
	
	public function __construct()
    {
        $this->app = new \Candy\Core\Container();
		
        $this->initialize();
		
        $this->definition = $this->getDefaultInputDefinition();

        //加载指令
        $this->loadCommands();
		
        $this->start();
    }
	
	/**
     * 初始化
     */
    protected function initialize(): void
    {
        
    }

    /**
     * 添加初始化器
     * @param Closure $callback
     */
    public static function starting(\Closure $callback): void
    {
        static::$startCallbacks[] = $callback;
    }

    /**
     * 清空启动器
     */
    public static function flushStartCallbacks(): void
    {
        static::$startCallbacks = [];
    }

    /**
     * 设置执行用户
     * @param $user
     */
    public static function setUser(string $user): void
    {
        if (extension_loaded('posix')) {
            $user = posix_getpwnam($user);

            if (!empty($user)) {
                posix_setgid($user['gid']);
                posix_setuid($user['uid']);
            }
        }
    }
	
	/**
     * 启动
     */
    protected function start(): void
    {
		if(count(static::$startCallbacks) > 0)
			foreach (static::$startCallbacks as $callback) {
				$callback($this);
			}
    }
	
	/**
     * 加载指令
     * @access protected
     */
    protected function loadCommands(): void
    {
		$Command = loadConfig('Command');
		$StandardCommands = loadConfig('StandardCommand');
        $Commands = array_merge($this->defaultCommands, $Command, $StandardCommands);
		
        $this->addCommands($Commands);
    }
	
	/**
     * 获取默认输入定义
     * @access protected
     * @return InputDefinition
     */
    protected function getDefaultInputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new InputArgument('Command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this console version'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--ansi', '', InputOption::VALUE_NONE, 'Force ANSI output'),
            new InputOption('--no-ansi', '', InputOption::VALUE_NONE, 'Disable ANSI output'),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
        ]);
    }
	
	/**
     * 系统内执行命令
     * @return int
     * @throws \Exception
     * @api
     */
    public function cmd(string $command,string $argv = '')
    {
		if(function_exists("shell_exec")){
			return shell_exec('php candy '. $command .' '. $argv);
		}else{
			$argv = empty($argv) ? [$command] : array_merge([$command], explode(' ', $argv));
			$input  = new Input($argv);
			$output  = new Output();
			$this->configureIO($input, $output);
			
			try {
				$exitCode = $this->doRun($input, $output);
			} catch (\Exception $e) {

				$output->renderException($e);

				$exitCode = $e->getCode();
				if (is_numeric($exitCode)) {
					$exitCode = (int) $exitCode;
					if (0 === $exitCode) {
						$exitCode = 1;
					}
				} else {
					$exitCode = 1;
				}
			}
			
			if ($this->autoExit) {
				if ($exitCode > 255) {
					$exitCode = 255;
				}

				exit($exitCode);
			}
			
			return $exitCode;
		}
	}
	
	/**
     * 执行当前的指令
     * @return int
     * @throws \Exception
     * @api
     */
    public function run(): int
    {
		$input	= new Input();
		$output	= new Output();
		$this->configureIO($input, $output);
		
		try {
            $exitCode = $this->doRun($input, $output);
        } catch (\Exception $e) {

            $output->renderException($e);

            $exitCode = $e->getCode();
            if (is_numeric($exitCode)) {
                $exitCode = (int) $exitCode;
                if (0 === $exitCode) {
                    $exitCode = 1;
                }
            } else {
                $exitCode = 1;
            }
        }
		
		if ($this->autoExit) {
            if ($exitCode > 255) {
                $exitCode = 255;
            }

            exit($exitCode);
        }

        return $exitCode;
	}
	
	/**
     * 执行指令
     * @param Input $input
     * @param Output $output
     * @return int
     */
    public function doRun(Input $input, Output $output): int
    {
        if (true === $input->hasParameterOption(['--version', '-V'])) {
            $output->writeln($this->getLongVersion());
            return 0;
        }
		
        $name = $this->getCommandName($input);
		
        if (true === $input->hasParameterOption(['--help', '-h'])) {
            if (!$name) {
                $name  = 'help';
                $input = new Input(['help']);
            } else {
                $this->wantHelps = true;
            }
        }

        if (!$name) {
            $name  = $this->defaultCommand;
            $input = new Input([$this->defaultCommand]);
        }
		
        $command = $this->find($name);
		
        return $this->doRunCommand($command, $input, $output);
    }

    /**
     * 设置输入参数定义
     * @param InputDefinition $definition
     */
    public function setDefinition(InputDefinition $definition): void
    {
        $this->definition = $definition;
    }

    /**
     * 获取输入参数定义
     * @return InputDefinition The InputDefinition instance
     */
    public function getDefinition(): InputDefinition
    {
        return $this->definition;
    }

    /**
     * Gets the help message.
     * @return string A help message.
     */
    public function getHelp(): string
    {
        return $this->getLongVersion();
    }

    /**
     * 是否捕获异常
     * @param bool $boolean
     * @api
     */
    public function setCatchExceptions(bool $boolean): void
    {
        $this->catchExceptions = $boolean;
    }

    /**
     * 是否自动退出
     * @param bool $boolean
     * @api
     */
    public function setAutoExit(bool $boolean): void
    {
        $this->autoExit = $boolean;
    }

	/**
     * 获取完整的版本号
     * @return string
     */
    public function getLongVersion(): string
    {
        return sprintf('version <comment>%s</comment>', CANDY_VERSION);
    }
	
    /**
     * 添加指令集
     * @param array $commands
     */
    public function addCommands(array $commands): void
    {
        foreach ($commands as $key => $command) {
            if (is_subclass_of($command, Command::class)) {
                // 注册指令
                $this->addCommand($command, is_numeric($key) ? '' : $key);
            }else{
				$this->nonStandardCommands[$key] = $command;
			}
        }
    }

    /**
     * 添加一个指令
     * @param string|Command $command 指令对象或者指令类名
     * @param string $name 指令名 留空则自动获取
     */
    public function addCommand($command, string $name = '')
    {
        if ($name) {
            $this->commands[$name] = $command;
            return;
        }

        if (is_string($command)) {
            $command = $this->app->invokeClassForObject($command);
        }
		
        $command->setConsole($this);

        if (!$command->isEnabled()) {
            $command->setConsole(null);
            return;
        }

        $command->setApp($this->app);

        if (null === $command->getDefinition()) {
            throw new LogicException(sprintf('Command class "%s" is not correctly initialized. You probably forgot to call the parent constructor.', get_class($command)));
        }

        $this->commands[$command->getName()] = $command;

        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }

        return $command;
    }

    /**
     * 获取指令
     * @param string $name 指令名称
     * @return Command
     * @throws InvalidArgumentException
     */
    public function getCommand(string $name): Command
    {
        if (!isset($this->commands[$name])) {
            throw new \InvalidArgumentException(sprintf('The command "%s" does not exist.', $name));
        }

        $command = $this->commands[$name];
		
        if (is_string($command)) {
            $command = $this->app->invokeClass($command);
            /** @var Command $command */
            $command->setConsole($this);
            $command->setApp($this->app);
        }

        if ($this->wantHelps) {
            $this->wantHelps = false;

            /** @var HelpCommand $helpCommand */
            $helpCommand = $this->getCommand('help');
            $helpCommand->setCommand($command);

            return $helpCommand;
        }

        return $command;
    }

    /**
     * 某个指令是否存在
     * @param string $name 指令名称
     * @return bool
     */
    public function hasCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * 获取所有的命名空间
     * @return array
     */
    public function getNamespaces(): array
    {
        $namespaces = [];
        foreach ($this->commands as $key => $command) {
            if (is_string($command)) {
                $namespaces = array_merge($namespaces, $this->extractAllNamespaces($key));
            } else {
                $namespaces = array_merge($namespaces, $this->extractAllNamespaces($command->getName()));

                foreach ($command->getAliases() as $alias) {
                    $namespaces = array_merge($namespaces, $this->extractAllNamespaces($alias));
                }
            }
        }

        return array_values(array_unique(array_filter($namespaces)));
    }

    /**
     * 查找注册命名空间中的名称或缩写。
     * @param string $namespace
     * @return string
     * @throws InvalidArgumentException
     */
    public function findNamespace(string $namespace): string
    {
        $allNamespaces = $this->getNamespaces();
        $expr          = preg_replace_callback('{([^:]+|)}', function ($matches) {
            return preg_quote($matches[1]) . '[^:]*';
        }, $namespace);
        $namespaces    = preg_grep('{^' . $expr . '}', $allNamespaces);
        
        if (empty($namespaces)) {
            $message = sprintf('There are no commands defined in the "%s" namespace.', $namespace);

            if ($alternatives = $this->findAlternatives($namespace, $allNamespaces)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }

                $message .= implode("\n    ", $alternatives);
            }

            throw new \InvalidArgumentException($message);
        }

        $exact = in_array($namespace, $namespaces, true);
        if (count($namespaces) > 1 && !$exact) {
            throw new \InvalidArgumentException(sprintf('The namespace "%s" is ambiguous (%s).', $namespace, $this->getAbbreviationSuggestions(array_values($namespaces))));
        }

        return $exact ? $namespace : reset($namespaces);
    }
	
    /**
     * 查找指令
     * @param string $name 名称或者别名
     * @return Command
     * @throws \Exception
     */
    public function find(string $name): Command
    {
        $allCommands = array_keys($this->commands);
        
        $expr = preg_replace_callback('{([^:]+|)}', function ($matches) {
            return preg_quote($matches[1]) . '[^:]*';
        }, $name);

        $commands = preg_grep('{^' . $expr . '}', $allCommands);
        
        if (empty($commands) || count(preg_grep('{^' . $expr . '$}', $commands)) < 1) {
            if (false !== $pos = strrpos($name, ':')) {
                $this->findNamespace(substr($name, 0, $pos));
            }

            $message = sprintf('Command "%s" is not defined.', $name);

            if ($alternatives = $this->findAlternatives($name, $allCommands)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }
                $message .= implode("\n    ", $alternatives);
            }else if(isset($this->nonStandardCommands[$name])){
				//处理非常规命令函数
				$callable = $this->nonStandardCommands[$name];
				
				//解析对象和参数
				$argv = $_SERVER['argv'];
				array_shift($argv);	//起始文件
				array_shift($argv);	//命令本身
				
				//变量数组
				$args = [];
				
				if(is_array($callable)){
					//带参数
					[$callable, $args] = $callable;
				}
				
				if(!empty($argv)){
					$args = array_merge($args, $argv);
				}
				
				//类名或者函数名
				if(is_string($callable)){
					//类操作
					if(stripos($callable, '::') || stripos($callable, '->') || str_starts_with($callable, 'new')){
						$lf = stripos($callable, '->') ? '->' : '::';
						if(stripos($callable, '::static')  || str_starts_with($callable, 'new')){
							if(empty($command2)){
								throw new \Exception(sprintf('Plese give the %s\'s method name for running.', $name));
							}
							[$class, $delete] = explode($lf, $callable);
							$method = $command2;
						}else{
							[$class, $method] = explode($lf, $callable);
						}
						//类全名
						$class = replenishNamespace($class);
						try {
							$reflect = new \ReflectionMethod($class, $method);
							//执行
							$obj = stripos($callable, '::') ? null : new $class;
							$reflect->invokeArgs($obj, $args);
						} catch (\Exception $e) {
							throw new \Exception('method not exists: ' . $class . '::' . $method . "()\n");
						}
					}else{
						//函数
						if(function_exists($callable)){
							$callable(...$args);
						}else{
							throw new \Exception("function not exists: {$callable}()\n");
						}
					}
					exit;
				}else{
					$vars = [];
					//匿名函数或对象
					if($callable instanceof \Closure){
						//匿名函数
						$callable(...$args);
					}else{
						//对象
						if(is_array($callable)){
							[$callable, $method] = $callable;
							
							if(is_array($callable)){
								[$method, $vars] = $method;
							}
						}
						
						if(is_string($callable)){
							//传递的类名
							try {
								$callable = replenishNamespace($callable);
								$reflect = \Candy\Core\Container::getInstance()->invokeReflectionClass($callable);
								
								if(!empty($vars))
									$vars = static::bindParams($reflect->getConstructor(), $vars);
								
								$callable = $reflect->newInstanceArgs($vars);
							} catch (\Exception $e) {
								throw new \Exception('method not exists: ' . $class . '::' . $method . "()\n");
							}
						}
						$callable->$method(...$args);
					}
					exit;
				}
			}
			
			//内置处理类
			if($name == 'xcore'){
				//解析对象和参数
				$argv = $_SERVER['argv'];
				array_shift($argv); //除去path
				array_shift($argv); //除去command
				if(!empty($argv)){
					$function = array_shift($argv);
				}
				if(empty($argv)){
					\Extend\Edition::$function();
				}else{
					\Extend\Edition::$function($argv);
				}
				exit;
			}
			
            throw new \Exception($message);
        }

        $exact = in_array($name, $commands, true);
        if (count($commands) > 1 && !$exact) {
            $suggestions = $this->getAbbreviationSuggestions(array_values($commands));

            throw new \Exception(sprintf('Command "%s" is ambiguous (%s).', $name, $suggestions));
        }

        return $this->getCommand($exact ? $name : reset($commands));
    }

    /**
     * 获取所有的指令
     * @param string $namespace 命名空间
     * @return Command[]
     * @api
     */
    public function all(?string $namespace = null): array
    {
        if (null === $namespace) {
            return $this->commands;
        }

        $commands = [];
        foreach ($this->commands as $name => $command) {
            if ($this->extractNamespace($name, substr_count($namespace, ':') + 1) === $namespace) {
                $commands[$name] = $command;
            }
        }

        return $commands;
    }
	
	/**
     * 配置基于用户的参数和选项的输入和输出实例。
     * @access protected
     * @param Input $input 输入实例
     * @param Output $output 输出实例
     */
    protected function configureIO(Input $input, Output $output): void
    {
        if (true === $input->hasParameterOption(['--ansi'])) {
            $output->setDecorated(true);
        } elseif (true === $input->hasParameterOption(['--no-ansi'])) {
            $output->setDecorated(false);
        }

        if (true === $input->hasParameterOption(['--no-interaction', '-n'])) {
            $input->setInteractive(false);
        }

        if (true === $input->hasParameterOption(['--quiet', '-q'])) {
            $output->setVerbosity(Output::VERBOSITY_QUIET);
        } elseif ($input->hasParameterOption('-vvv') || $input->hasParameterOption('--verbose=3') || $input->getParameterOption('--verbose') === 3) {
            $output->setVerbosity(Output::VERBOSITY_DEBUG);
        } elseif ($input->hasParameterOption('-vv') || $input->hasParameterOption('--verbose=2') || $input->getParameterOption('--verbose') === 2) {
            $output->setVerbosity(Output::VERBOSITY_VERY_VERBOSE);
        } elseif ($input->hasParameterOption('-v') || $input->hasParameterOption('--verbose=1') || $input->hasParameterOption('--verbose') || $input->getParameterOption('--verbose')) {
            $output->setVerbosity(Output::VERBOSITY_VERBOSE);
        }
    }
	
    /**
     * 执行指令
     * @access protected
     * @param Command $command 指令实例
     * @param Input $input 输入实例
     * @param Output $output 输出实例
     * @return int
     * @throws \Exception
     */
    protected function doRunCommand(Command $command, Input $input, Output $output): int
    {
        return $command->run($input, $output);
    }

    /**
     * 获取指令的基础名称
     * @access protected
     * @param Input $input
     * @return string
     */
    protected function getCommandName(Input $input): string
    {
        return $input->getFirstArgument() ?: '';
    }

    /**
     * 获取可能的建议
     * @access private
     * @param array $abbrevs
     * @return string
     */
    private function getAbbreviationSuggestions(array $abbrevs): string
    {
        return sprintf('%s, %s%s', $abbrevs[0], $abbrevs[1], count($abbrevs) > 2 ? sprintf(' and %d more', count($abbrevs) - 2) : '');
    }

    /**
     * 返回命名空间部分
     * @param string $name 指令
     * @param int $limit 部分的命名空间的最大数量
     * @return string
     */
    public function extractNamespace(string $name, int $limit = 0): string
    {
        $parts = explode(':', $name);
        array_pop($parts);

        return implode(':', 0 === $limit ? $parts : array_slice($parts, 0, $limit));
    }

    /**
     * 查找可替代的建议
     * @access private
     * @param string $name
     * @param array|\Traversable $collection
     * @return array
     */
    private function findAlternatives(string $name, $collection): array
    {
        $threshold    = 1e3;
        $alternatives = [];

        $collectionParts = [];
        foreach ($collection as $item) {
            $collectionParts[$item] = explode(':', $item);
        }

        foreach (explode(':', $name) as $i => $subname) {
            foreach ($collectionParts as $collectionName => $parts) {
                $exists = isset($alternatives[$collectionName]);
                if (!isset($parts[$i]) && $exists) {
                    $alternatives[$collectionName] += $threshold;
                    continue;
                } elseif (!isset($parts[$i])) {
                    continue;
                }

                $lev = levenshtein($subname, $parts[$i]);
                if ($lev <= strlen($subname) / 3 || '' !== $subname && false !== strpos($parts[$i], $subname)) {
                    $alternatives[$collectionName] = $exists ? $alternatives[$collectionName] + $lev : $lev;
                } elseif ($exists) {
                    $alternatives[$collectionName] += $threshold;
                }
            }
        }

        foreach ($collection as $item) {
            $lev = levenshtein($name, $item);
            if ($lev <= strlen($name) / 3 || false !== strpos($item, $name)) {
                $alternatives[$item] = isset($alternatives[$item]) ? $alternatives[$item] - $lev : $lev;
            }
        }

        $alternatives = array_filter($alternatives, function ($lev) use ($threshold) {
            return $lev < 2 * $threshold;
        });
        asort($alternatives);

        return array_keys($alternatives);
    }

    /**
     * 返回所有的命名空间
     * @access private
     * @param string $name
     * @return array
     */
    private function extractAllNamespaces(string $name): array
    {
        $parts      = explode(':', $name, -1);
        $namespaces = [];

        foreach ($parts as $part) {
            if (count($namespaces)) {
                $namespaces[] = end($namespaces) . ':' . $part;
            } else {
                $namespaces[] = $part;
            }
        }

        return $namespaces;
    }
}
