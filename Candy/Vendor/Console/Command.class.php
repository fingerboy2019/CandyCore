<?php
/***
 * Candy框架 命令行基类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
 
declare (strict_types = 1);
namespace Console;

use \Candy\Core\Container;
use \Console\Input\{Argument,Definition,Option};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

Class Command {
	
	/** @var  Console */
    private $console;
    private $name;
    private $processTitle;
    private $aliases = [];
    private $definition;
    private $help;
    private $description;
    private $ignoreValidationErrors          = false;
    private $consoleDefinitionMerged         = false;
    private $consoleDefinitionMergedWithArgs = false;
    private $synopsis                        = [];
    private $usages                          = [];
    private $ableFunctions                   = [
        'stream_socket_server', 'stream_socket_client', 
        'pcntl_signal_dispatch', 'pcntl_signal', 'pcntl_alarm', 'pcntl_fork',
        'posix_getuid', 'posix_getpwuid', 'posix_kill', 'posix_setsid',
        'posix_getpid', 'posix_getpwnam', 'posix_getgrnam', 'posix_getgid',
        'posix_setgid', 'posix_initgroups', 'posix_setuid', 'posix_isatty'
    ];

    /** @var  Input */
    protected $input;

    /** @var  Output */
    protected $output;

    /** @var App */
    protected $app;

    /**
     * 构造方法
     * @throws LogicException
     * @api
     */
    public function __construct()
    {
        $this->definition = new Definition();

        $this->configure();

        if (!$this->name) {
            throw new LogicException(sprintf('The command defined in "%s" cannot have an empty name.', get_class($this)));
        }
    }

    /**
     * 忽略验证错误
     */
    public function ignoreValidationErrors(): void
    {
        $this->ignoreValidationErrors = true;
    }

    /**
     * 设置控制台
     * @param Console $console
     */
    public function setConsole(?Initiator $console = null): void
    {
        $this->console = $console;
    }

    /**
     * 获取控制台
     * @return Initiator
     * @api
     */
    public function getConsole(): Initiator
    {
        return $this->console;
    }

    /**
     * 设置app
     * @param App $app
     */
    public function setApp(Container $app): void
    {
        $this->app = $app;
    }

    /**
     * 获取app
     * @return App
     */
    public function getApp(): Container
    {
        return $this->app;
    }

    /**
     * 是否有效
     * @return bool
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * 配置指令
     */
    protected function configure()
    {
    }
    
    /**
     * 自定义进程开启
     *
     * 每个 Worker 的 onWorkerStart 前自动 Bootstrap::bootRuntime（注入 Config.inc DB）
     * Process.inc 可选 app：字符串绑定应用；false/null 仅加载配置不绑 APP_PATH；缺省 Admin
     */
    public function startProcess(): bool
    {
        $runProcess = false;
        // 框架内置 worker（globaldata / scheduler）不需要任何项目配置：
        // 内置 + Source/Inc/Process.inc.php，项目同名键覆盖，写成 false 可单独关掉某个内置 worker。
        $registryClass = '\\Candy\\Extend\\Worker\\Registry';
        if (class_exists($registryClass)) {
            $process = $registryClass::all();
        } else {
            $path = C('INCPATH') . 'Process.inc.php';
            $process = is_file($path) ? loadConfig('Process') : [];
        }
        if (!empty($process)) {
            $runProcess = true;
            foreach ($process as $handlerName => $proces) {
                $procesName = $handlerName;
                //启动进程
                $handlerName = isset($proces['init']) ? (is_array($proces['init']) ? new $proces['handler'](...$proces['init']) : new $proces['handler']($proces['init'])) : new $proces['handler']();
                $handlerName->name = $procesName;
                if(isset($proces['count'])){
                    $handlerName->count = $proces['count'];
                }else{
                    $handlerName->count = 1;
                }
                if(isset($proces['reloadable']) && $proces['reloadable'] == false){
                    $handlerName->reloadable = false;
                }

                // 子进程启动时注入 DB / 应用路径（Web Bootstrap 不会跑进 Process Worker）
                $bootApp = array_key_exists('app', $proces) ? $proces['app'] : 'Admin';
                $this->wrapWorkerBootRuntime($handlerName, $bootApp);

                //定时自动reload 解决低配进程阻塞问题
				if(isset($proces['reload']) && intval($proces['reload']) > 0){
					\Workerman\Timer::add($proces['reload'], function(){
						echo "all workers is reload by timertaks.\n";
						// 给父进程也就是主进程发送reload信号
						posix_kill(posix_getppid(), SIGUSR1);
					});
				}
            }
        }

        $autoRunFiles = glob(C('SOURCEPATH') . 'Server/run_*.php');
        foreach($autoRunFiles as $start_file)
        {
            $runProcess = true;
            require $start_file;
        }
        
        return $runProcess;
    }

    /**
     * 包装 Worker.onWorkerStart：先 bootRuntime，再执行业务回调
     *
     * @param object $worker Workerman Worker 实例
     * @param string|null|false $bootApp 应用名；false/null 仅加载 Config
     * @return void
     * @access private
     */
    private function wrapWorkerBootRuntime(object $worker, string|null|false $bootApp): void
    {
        $userStart = $worker->onWorkerStart ?? null;
        $worker->onWorkerStart = static function ($w) use ($userStart, $bootApp): void {
            \Candy\Core\Bootstrap::bootRuntime($bootApp);
            if ($userStart === null) {
                return;
            }
            // 兼容 onWorkerStart() / onWorkerStart(Worker $worker)
            if (is_array($userStart) && is_object($userStart[0] ?? null) && is_string($userStart[1] ?? null)) {
                $ref = new \ReflectionMethod($userStart[0], $userStart[1]);
                $ref->invokeArgs($userStart[0], $ref->getNumberOfParameters() > 0 ? [$w] : []);
                return;
            }
            if ($userStart instanceof \Closure) {
                $ref = new \ReflectionFunction($userStart);
                $userStart(...($ref->getNumberOfParameters() > 0 ? [$w] : []));
                return;
            }
            if (is_callable($userStart)) {
                $userStart($w);
            }
        };
    }

    /**
     * 执行指令
     * @param Input  $input
     * @param Output $output
     * @return null|int
     * @throws LogicException
     * @see setCode()
     */
    protected function execute(Input $input, Output $output)
    {
        return $this->app->invoke([$this, 'handle']);
    }

    /**
     * 用户验证
     * @param Input  $input
     * @param Output $output
     */
    protected function interact(Input $input, Output $output)
    {
    }

    /**
     * 初始化
     * @param Input  $input  An InputInterface instance
     * @param Output $output An OutputInterface instance
     */
    protected function initialize(Input $input, Output $output)
    {
    }

    /**
     * 执行
     * @param Input  $input
     * @param Output $output
     * @return int
     * @throws Exception
     * @see setCode()
     * @see execute()
     */
    public function run(Input $input, Output $output): int
    {
        //检查禁用函数
        if($this->disableFunctionCheck()){
            $this->input  = $input;
            $this->output = $output;

            $this->getSynopsis(true);
            $this->getSynopsis(false);

            $this->mergeConsoleDefinition();
            
            try {
                $input->bind($this->definition);
            } catch (Exception $e) {
                if (!$this->ignoreValidationErrors) {
                    throw $e;
                }
            }
            
            $this->initialize($input, $output);
            
            if (null !== $this->processTitle) {
                if (function_exists('cli_set_process_title')) {
                    if (false === @cli_set_process_title($this->processTitle)) {
                        if ('Darwin' === PHP_OS) {
                            $output->writeln('<comment>Running "cli_get_process_title" as an unprivileged user is not supported on MacOS.</comment>');
                        } else {
                            $error = error_get_last();
                            trigger_error($error['message'], E_USER_WARNING);
                        }
                    }
                } elseif (function_exists('setproctitle')) {
                    setproctitle($this->processTitle);
                } elseif (Output::VERBOSITY_VERY_VERBOSE === $output->getVerbosity()) {
                    $output->writeln('<comment>Install the proctitle PECL to be able to change the process title.</comment>');
                }
            }
            
            if ($input->isInteractive()) {
                $this->interact($input, $output);
            }
            
            $input->validate();

            //注册框架基本类库路径
            \Workerman\Autoloader::setRootPath(rtrim(C('CANDYROOT'), '/'));
            
            //创建log目录
            \Candy\Extend\Dir::create(C('LOGPATH'));
            
            //初始化标志
            defined('ISLOADING') OR define('ISLOADING', true);
            
            //自定义记录文件地址
            \Workerman\Worker::$logFile = C('LOGPATH').'/workerman.log';
            \Workerman\Worker::$pidFile = C('LOGPATH').'/workerman.pid';
            \Workerman\Worker::$stdoutFile = C('LOGPATH').'/workerman.log';
            
            $statusCode = $this->execute($input, $output);
            
            return is_numeric($statusCode) ? (int) $statusCode : 0;
        }else{
            $output->writeln('<error>Please able the functions "' . implode(',', $this->ableFunctions) . '" to run the command.</error>');
        }
    }

    /**
     * 合并参数定义
     * @param bool $mergeArgs
     */
    public function mergeConsoleDefinition(bool $mergeArgs = true): void
    {
        if (null === $this->console
            || (true === $this->consoleDefinitionMerged
                && ($this->consoleDefinitionMergedWithArgs || !$mergeArgs))
        ) {
            return;
        }

        if ($mergeArgs) {
            $currentArguments = $this->definition->getArguments();
            $this->definition->setArguments($this->console->getDefinition()->getArguments());
            $this->definition->addArguments($currentArguments);
        }

        $this->definition->addOptions($this->console->getDefinition()->getOptions());

        $this->consoleDefinitionMerged = true;
        if ($mergeArgs) {
            $this->consoleDefinitionMergedWithArgs = true;
        }
    }

    /**
     * 设置参数定义
     * @param array|Definition $definition
     * @return Command
     * @api
     */
    public function setDefinition($definition): Command
    {
        if ($definition instanceof Definition) {
            $this->definition = $definition;
        } else {
            $this->definition->setDefinition($definition);
        }

        $this->consoleDefinitionMerged = false;

        return $this;
    }

    /**
     * 获取参数定义
     * @return Definition
     * @api
     */
    public function getDefinition(): Definition
    {
        return $this->definition;
    }

    /**
     * 获取当前指令的参数定义
     * @return Definition
     */
    public function getNativeDefinition(): Definition
    {
        return $this->getDefinition();
    }

    /**
     * 添加参数
     * @param string $name        名称
     * @param int    $mode        类型
     * @param string $description 描述
     * @param mixed  $default     默认值
     * @return Command
     */
    public function addArgument(string $name, ?int $mode = null, string $description = '', $default = null): Command
    {
        $this->definition->addArgument(new Argument($name, $mode, $description, $default));

        return $this;
    }

    /**
     * 添加选项
     * @param string $name        选项名称
     * @param string $shortcut    别名
     * @param int    $mode        类型
     * @param string $description 描述
     * @param mixed  $default     默认值
     * @return Command
     */
    public function addOption(string $name, ?string $shortcut = null, ?int $mode = null, string $description = '', $default = null): Command
    {
        $this->definition->addOption(new Option($name, $shortcut, $mode, $description, $default));
        return $this;
    }

    /**
     * 设置指令名称
     * @param string $name
     * @return Command
     * @throws InvalidArgumentException
     */
    public function setName(string $name): Command
    {
        $this->validateName($name);

        $this->name = $name;

        return $this;
    }

    /**
     * 设置进程名称
     *
     * PHP 5.5+ or the proctitle PECL library is required
     *
     * @param string $title The process title
     *
     * @return $this
     */
    public function setProcessTitle($title): Command
    {
        $this->processTitle = $title;

        return $this;
    }

    /**
     * 获取指令名称
     * @return string
     */
    public function getName(): string
    {
        return $this->name ?: '';
    }

    /**
     * 设置描述
     * @param string $description
     * @return Command
     */
    public function setDescription(string $description): Command
    {
        $this->description = $description;

        return $this;
    }

    /**
     *  获取描述
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description ?: '';
    }

    /**
     * 设置帮助信息
     * @param string $help
     * @return Command
     */
    public function setHelp(string $help): Command
    {
        $this->help = $help;

        return $this;
    }

    /**
     * 获取帮助信息
     * @return string
     */
    public function getHelp(): string
    {
        return $this->help ?: '';
    }

    /**
     * 描述信息
     * @return string
     */
    public function getProcessedHelp(): string
    {
        $name = $this->name;

        $placeholders = [
            '%command.name%',
            '%command.full_name%',
        ];
        $replacements = [
            $name,
            $_SERVER['PHP_SELF'] . ' ' . $name,
        ];

        return str_replace($placeholders, $replacements, $this->getHelp());
    }

    /**
     * 设置别名
     * @param string[] $aliases
     * @return Command
     * @throws InvalidArgumentException
     */
    public function setAliases(iterable $aliases): Command
    {
        foreach ($aliases as $alias) {
            $this->validateName($alias);
        }

        $this->aliases = $aliases;

        return $this;
    }

    /**
     * 获取别名
     * @return array
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * 获取简介
     * @param bool $short 是否简单的
     * @return string
     */
    public function getSynopsis(bool $short = false): string
    {
        $key = $short ? 'short' : 'long';

        if (!isset($this->synopsis[$key])) {
            $this->synopsis[$key] = trim(sprintf('%s %s', $this->name, $this->definition->getSynopsis($short)));
        }

        return $this->synopsis[$key];
    }

    /**
     * 添加用法介绍
     * @param string $usage
     * @return $this
     */
    public function addUsage(string $usage): Command
    {
        if (0 !== strpos($usage, $this->name)) {
            $usage = sprintf('%s %s', $this->name, $usage);
        }

        $this->usages[] = $usage;

        return $this;
    }

    /**
     * 获取用法介绍
     * @return array
     */
    public function getUsages(): array
    {
        return $this->usages;
    }

    /**
     * 验证指令名称
     * @param string $name
     * @throws InvalidArgumentException
     */
    private function validateName(string $name): void
    {
        if (!preg_match('/^[^\:]++(\:[^\:]++)*$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Command name "%s" is invalid.', $name));
        }
    }

    /**
     * 输出表格
     * @param Table $table
     * @return string
     */
    protected function table(Table $table): string
    {
        $content = $table->render();
        $this->output->writeln($content);
        return $content;
    }

    /**
     * 禁用函数检查
     * @return bool true=所有函数可用，false=存在被禁用的函数
     */
    private function disableFunctionCheck(): bool
    {
        // 获取PHP配置中被禁用的函数（逗号分隔的字符串）
        $disabledFunctions = ini_get('disable_functions');
        if (empty($disabledFunctions)) {
            return true; // 没有禁用任何函数
        }

        // 转换为数组并去重
        $disabledList = array_unique(explode(',', $disabledFunctions));

        // 检查目标函数是否存在被禁用的情况
        foreach ($this->ableFunctions as $func) {
            if (in_array(trim($func), $disabledList, true)) {
                return false; // 存在被禁用的函数
            }
        }

        return true; // 所有需要的函数都可用
    }

    protected function showInfo()
    {
        echo <<<'EOT'
   ____                   _         ____   _   _  ____  
  / ___| __ _  _ __    __| | _   _ |  _ \ | | | ||  _ \ 
 | |    / _` || '_ \  / _` || | | || |_) || |_| || |_) |
 | |___| (_| || | | || (_| || |_| ||  __/ |  _  ||  __/ 
  \____|\__,_||_| |_| \__,_| \__, ||_|    |_| |_||_|    
                             |___/      

EOT;
        printf("System       Name:       %s\n", strtolower(PHP_OS));
        printf("Container    Name:       {$this->container}\n");
        printf("PHP          Version:    %s\n", PHP_VERSION);
    }
}
