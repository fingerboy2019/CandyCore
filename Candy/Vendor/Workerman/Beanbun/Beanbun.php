<?php
namespace Beanbun;

use Beanbun\Exception\BeanbunException;
use Beanbun\Lib\Helper;
use Exception;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Beanbun爬虫框架核心类
 * 
 * 用于创建和管理爬虫实例，处理URL队列，执行爬虫任务
 */
class Beanbun
{
    /**
     * 版本号
     */
    const VERSION = '1.0.4';

    /**
     * 工作进程ID
     * @var int|null
     */
    public $id = null;
    
    /**
     * 爬虫名称
     * @var string|null
     */
    public $name = null;
    
    /**
     * 工作进程数量
     * @var int
     */
    public $count = 1;
    
    /**
     * 最大队列数量
     * @var int
     */
    public $max = 0;
    
    /**
     * 种子URL列表
     * @var array
     */
    public $seed = [];
    
    /**
     * 是否以守护进程模式运行
     * @var bool
     */
    public $daemonize = true;
    
    /**
     * URL过滤器数组
     * @var array
     */
    public $urlFilter = [];
    
    /**
     * 爬虫间隔时间（秒）
     * @var int
     */
    public $interval = 5;
    
    /**
     * 下载超时时间（秒）
     * @var int
     */
    public $timeout = 5;
    
    /**
     * 用户代理类型
     * @var string
     */
    public $userAgent = 'pc';
    
    /**
     * 日志文件路径
     * @var string
     */
    public $logFile = '';
    
    /**
     * 命令行参数
     * @var array
     */
    public $commands = [];

    /**
     * 当前队列信息
     * @var string|array
     */
    public $queue = '';
    
    /**
     * 当前URL
     * @var string
     */
    public $url = '';
    
    /**
     * 请求方法
     * @var string
     */
    public $method = '';
    
    /**
     * 请求选项
     * @var array
     */
    public $options = [];
    
    /**
     * 下载的页面内容
     * @var string
     */
    public $page = '';

    /**
     * 工作进程启动回调
     * @var callable
     */
    public $startWorker = '';
    
    /**
     * 下载页面之前的回调
     * @var callable
     */
    public $beforeDownloadPage = '';
    
    /**
     * 下载页面回调
     * @var callable
     */
    public $downloadPage = '';
    
    /**
     * 下载页面之后的回调
     * @var callable
     */
    public $afterDownloadPage = '';
    
    /**
     * 发现URL回调
     * @var callable
     */
    public $discoverUrl = '';
    
    /**
     * 发现URL之后的回调
     * @var callable
     */
    public $afterDiscover = '';
    
    /**
     * 工作进程停止回调
     * @var callable
     */
    public $stopWorker = '';
    
    /**
     * 异常处理回调
     * @var callable
     */
    public $exceptionHandler = '';

    /**
     * 钩子列表
     * @var array
     */
    public $hooks = [
        'startWorkerHooks',
        'beforeDownloadPageHooks',
        'downloadPageHooks',
        'afterDownloadPageHooks',
        'discoverUrlHooks',
        'afterDiscoverHooks',
        'stopWorkerHooks',
    ];
    
    /**
     * 工作进程启动钩子数组
     * @var array
     */
    public $startWorkerHooks = [];
    
    /**
     * 下载页面之前的钩子数组
     * @var array
     */
    public $beforeDownloadPageHooks = [];
    
    /**
     * 下载页面钩子数组
     * @var array
     */
    public $downloadPageHooks = [];
    
    /**
     * 下载页面之后的钩子数组
     * @var array
     */
    public $afterDownloadPageHooks = [];
    
    /**
     * 发现URL钩子数组
     * @var array
     */
    public $discoverUrlHooks = [];
    
    /**
     * 发现URL之后的钩子数组
     * @var array
     */
    public $afterDiscoverHooks = [];
    
    /**
     * 工作进程停止钩子数组
     * @var array
     */
    public $stopWorkerHooks = [];

    /**
     * 队列实例
     * @var object|null
     */
    protected $queues = null;
    
    /**
     * 下载器实例
     * @var object|string|null
     */
    protected $downloader = null;
    
    /**
     * Worker实例
     * @var Worker|null
     */
    protected $worker = null;
    
    /**
     * 定时器ID
     * @var int|null
     */
    protected $timer_id = null;
    
    /**
     * 队列工厂回调
     * @var callable|null
     */
    protected $queueFactory = null;
    
    /**
     * 队列参数
     * @var array
     */
    protected $queueArgs = [];
    
    /**
     * 下载器工厂回调
     * @var callable|string|null
     */
    protected $downloaderFactory = null;
    
    /**
     * 下载器参数
     * @var array
     */
    protected $downloaderArgs = [];
    
    /**
     * 日志工厂回调
     * @var callable|null
     */
    protected $logFactory = null;

    /**
     * 添加定时器
     * 
     * @param int $interval 时间间隔（秒）
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @param bool $persistent 是否持久化
     * @return int 定时器ID
     */
    public static function timer($interval, $callback, $args = [], $persistent = true)
    {
        return Timer::add($interval, $callback, $args, $persistent);
    }

    /**
     * 删除定时器
     * 
     * @param int $time_id 定时器ID
     * @return void
     */
    public static function timerDel($time_id)
    {
        Timer::del($time_id);
    }

    /**
     * 运行所有Worker
     * 
     * @return void
     */
    public static function run()
    {
        @Worker::runAll();
    }

    /**
     * 构造函数
     * 
     * @param array $config 配置数组
     */
    public function __construct($config = [])
    {
        global $argv;
        $this->commands = $argv;
        $this->name = isset($config['name'])
        ? $config['name']
        : current(explode('.', $this->commands[0]));
        $this->logFile = isset($config['logFile']) ? $config['logFile'] : __DIR__ . '/' . $this->name . '_access.log';
        $this->setQueue();
        $this->setDownloader();
        $this->setLog();
    }

    /**
     * 处理命令行命令
     * 
     * @return void
     */
    public function command()
    {
        switch ($this->commands[1]) {
            case 'start':
                foreach ((array) $this->seed as $url) {
                    if (is_string($url)) {
                        $this->queue()->add($url);
                    } elseif (is_array($url)) {
                        $this->queue()->add($url[0], $url[1]);
                    }
                }
                $this->queues = null;
                echo "Beanbun is starting...\n";
                fclose(STDOUT);
                $STDOUT = fopen($this->logFile, "a");
                break;
            case 'clean':
                $this->queue()->clean();
                unlink($this->logFile);
                die();
                break;
            case 'stop':
                break;
            default:
                break;
        }
    }

    /**
     * 执行爬虫
     * 
     * @return void
     */
    public function start()
    {
        if (!isset($this->commands[1])) {
            $this->daemonize = false;
        }

        if ($this->daemonize) {
            $this->check();

            $worker = new Worker;
            $worker->count = $this->count;
            $worker->name = $this->name;
            $worker->onWorkerStart = [$this, 'onWorkerStart'];
            $worker->onWorkerStop = [$this, 'onWorkerStop'];
            $this->worker = $worker;

            Worker::$daemonize = true;
            Worker::$stdoutFile = $this->logFile;
            \Beanbun\Lib\Db::closeAll();

            $this->queueArgs['name'] = $this->name;
            $this->initHooks();
            $this->command();

            self::run();
        } else {
            $this->initHooks();
            $this->seed = (array) $this->seed;
            while (count($this->seed)) {
                $this->crawler();
            }
        }
    }

    /**
     * 检查环境是否满足要求
     * 
     * @return void
     */
    public function check()
    {
        $error = false;
        $text = '';
        $version_ok = $pcntl_loaded = $posix_loaded = true;
        if (!version_compare(phpversion(), "5.3.3", ">=")) {
            $text .= "PHP Version >= 5.3.3                 \033[31;40m [fail] \033[0m\n";
            $error = true;
        }

        if (!in_array("pcntl", get_loaded_extensions())) {
            $text .= "Extension posix check                \033[31;40m [fail] \033[0m\n";
            $error = true;
        }

        if (!in_array("posix", get_loaded_extensions())) {
            $text .= "Extension posix check                \033[31;40m [fail] \033[0m\n";
            $error = true;
        }

        $check_func_map = [
            "stream_socket_server",
            "stream_socket_client",
            "pcntl_signal_dispatch",
        ];

        if ($disable_func_string = ini_get("disable_functions")) {
            $disable_func_map = array_flip(explode(",", $disable_func_string));
        }

        foreach ($check_func_map as $func) {
            if (isset($disable_func_map[$func])) {
                $text .= "\033[31;40mFunction " . implode(', ', $check_func_map) . "may be disabled. Please check disable_functions in php.ini\033[0m\n";
                $error = true;
                break;
            }
        }

        if ($error) {
            echo $text;
            exit;
        }
    }

    /**
     * 初始化钩子
     * 
     * @return void
     */
    public function initHooks()
    {
        $this->startWorkerHooks[] = function ($beanbun) {
            $beanbun->id = $beanbun->worker->id;
            $beanbun->log("Beanbun worker {$beanbun->id} is starting ...");
        };

        if ($this->startWorker) {
            $this->startWorkerHooks[] = $this->startWorker;
        }

        $this->startWorkerHooks[] = function ($beanbun) {
            $beanbun->queue()->maxQueueSize = $beanbun->max;
            $beanbun->timer_id = Beanbun::timer($beanbun->interval, [$beanbun, 'crawler']);
        };

        $this->beforeDownloadPageHooks[] = [$this, 'defaultBeforeDownloadPage'];

        if ($this->beforeDownloadPage) {
            $this->beforeDownloadPageHooks[] = $this->beforeDownloadPage;
        }

        if ($this->downloadPage) {
            $this->downloadPageHooks[] = $this->downloadPage;
        } else {
            $this->downloadPageHooks[] = [$this, 'defaultDownloadPage'];
        }

        if ($this->afterDownloadPage) {
            $this->afterDownloadPageHooks[] = $this->afterDownloadPage;
        }

        if ($this->discoverUrl) {
            $this->discoverUrlHooks[] = $this->discoverUrl;
        } elseif ($this->daemonize) {
            $this->discoverUrlHooks[] = [$this, 'defaultDiscoverUrl'];
        }

        if ($this->afterDiscover) {
            $this->afterDiscoverHooks[] = $this->afterDiscover;
        }

        if ($this->daemonize) {
            $this->afterDiscoverHooks[] = function ($beanbun) {
                $beanbun->queue()->queued($beanbun->queue);
            };
        }

        if ($this->stopWorker) {
            $this->stopWorkerHooks[] = $this->stopWorker;
        }

        if (!$this->exceptionHandler) {
            $this->exceptionHandler = [$this, 'defaultExceptionHandler'];
        }
    }

    // 爬虫进程
    /**
     * 工作进程启动处理
     * 
     * @param Worker $worker Worker实例
     * @return void
     */
    public function onWorkerStart($worker)
    {
        foreach ($this->startWorkerHooks as $hook) {
            call_user_func($hook, $this);
        }
    }

    /**
     * 获取队列实例
     * 
     * @return object 队列实例
     */
    public function queue()
    {
        if ($this->queues == null) {
            $this->queues = call_user_func($this->queueFactory, $this->queueArgs);
        }
        return $this->queues;
    }

    /**
     * 设置队列
     * 
     * @param callable|string|null $callback 队列工厂回调或内置队列类型（'memory'或'redis'）
     * @param array $args 队列参数
     * @return void
     */
    public function setQueue($callback = null, $args = [
        'host' => '127.0.0.1',
        'port' => '2207',
    ]) {
        if ($callback === 'memory' || $callback === null) {
            $this->queueFactory = function ($args) {
                return new \Beanbun\Queue\MemoryQueue($args);
            };
        } elseif ($callback == 'redis') {
            $this->queueFactory = function ($args) {
                return new \Beanbun\Queue\RedisQueue($args);
            };
        } else {
            $this->queueFactory = $callback;
        }

        $this->queueArgs = $args;
    }

    /**
     * 获取下载器实例
     * 
     * @return object|string 下载器实例或类名
     */
    public function downloader()
    {
        if ($this->downloader === null) {
            $this->downloader = is_string($this->downloaderFactory) ? $this->downloaderFactory : call_user_func($this->downloaderFactory, $this->downloaderArgs);
        }
        return $this->downloader;
    }

    /**
     * 设置下载器
     * 
     * @param callable|string|null $callback 下载器工厂回调或类名
     * @param array $args 下载器参数
     * @return void
     */
    public function setDownloader($callback = null, $args = [])
    {
        if ($callback === null) {
            $this->downloaderFactory = function ($args) {
                return '\\Candy\\Extend\\Network\\Http';
            };
        } else {
            $this->downloaderFactory = $callback;
        }
        $this->downloaderArgs = $args;
    }

    /**
     * 记录日志
     * 
     * @param string $msg 日志信息
     * @return void
     */
    public function log($msg)
    {
        call_user_func($this->logFactory, $msg, $this);
    }

    /**
     * 设置日志处理函数
     * 
     * @param callable|null $callback 日志处理回调
     * @return void
     */
    public function setLog($callback = null)
    {
        $this->logFactory = $callback === null
        ? function ($msg, $beanbun) {
            echo date('Y-m-d H:i:s') . " {$beanbun->name} : $msg\n";
        }
        : $callback;
    }

    /**
     * 抛出异常
     * 
     * @param string|null $msg 异常信息
     * @throws BeanbunException
     */
    public function error($msg = null)
    {
        throw new BeanbunException($msg);
    }

    /**
     * 执行爬虫任务
     * 
     * @return void
     */
    public function crawler()
    {
        try {
            $allHooks = $this->hooks;
            array_shift($allHooks);
            array_pop($allHooks);

            foreach ($allHooks as $hooks) {
                foreach ($this->$hooks as $hook) {
                    call_user_func($hook, $this);
                }
            }
        } catch (Exception $e) {
            $this->log($e->getMessage());
            if ($this->daemonize) {
                $this->queue()->add($this->queue['url'], $this->queue['options']);
            } else {
                $this->seed[] = $this->queue;
            }
            call_user_func($this->exceptionHandler, $e);
        }

        $this->queue = '';
        $this->url = '';
        $this->method = '';
        $this->page = '';
        $this->options = [];
    }

    /**
     * 工作进程停止处理
     * 
     * @param Worker $worker Worker实例
     * @return void
     */
    public function onWorkerStop($worker)
    {
        foreach ($this->stopWorkerHooks as $hook) {
            call_user_func($hook, $this);
        }
    }

    /**
     * 默认异常处理器
     * 
     * @param Exception $e 异常对象
     * @return void
     */
    public function defaultExceptionHandler(Exception $e)
    {
        if ($e instanceof BeanbunException) {
            if ($e->getMessage()) {
                $this->log($e->getMessage());
            }
        } elseif ($e instanceof Exception) {
            $this->log($e->getMessage());
            if ($this->daemonize) {
                $this->queue()->add($this->queue['url'], $this->queue['options']);
            } else {
                $this->seed[] = $this->queue;
            }
        }
    }

    /**
     * 默认的下载页面之前处理
     * 
     * @return void
     * @throws BeanbunException
     */
    public function defaultBeforeDownloadPage()
    {
        if ($this->daemonize) {
            if ($this->max > 0 && $this->queue()->queuedCount() >= $this->max) {
                $this->log("Download to the upper limit, Beanbun worker {$this->id} stop downloading.");
                self::timerDel($this->timer_id);
                $this->error();
            }

            $this->queue = $queue = $this->queue()->next();
        } else {
            $queue = array_shift($this->seed);
        }

        if (is_null($queue) || !$queue) {
            sleep(30);
            $this->error();
        }

        if (!is_array($queue)) {
            $this->queue = $queue = [
                'url' => $queue,
                'options' => [],
            ];
        }

        $options = array_merge([
            'headers' => [],
            'reserve' => true,
            'timeout' => $this->timeout,
        ], (array) $queue['options']);

        if ($this->daemonize && $options['reserve'] && $this->queue()->isQueued($queue)) {
            $this->error();
        }

        $this->url = $queue['url'];
        $this->method = isset($options['method']) ? $options['method'] : 'GET';
        $this->options = $options;
        if (!isset($this->options['headers']['User-Agent'])) {
            $this->options['headers']['User-Agent'] = Helper::randUserAgent($this->userAgent);
        }
    }

    /**
     * 默认的下载页面处理
     * 
     * @return void
     * @throws BeanbunException
     */
    public function defaultDownloadPage()
    {
		//判断下载器类型
		$download = $this->downloader();
		if(is_string($download)){
			$response = $download::request($this->method, $this->url, $this->options);
			$this->page = $response['content'];
		} else {
			$response = $download->request($this->method, $this->url, $this->options);
			$this->page = $response->getBody();
		}
		
        if ($this->page) {
            $worker_id = isset($this->id) ? $this->worker->id : '';
            $this->log("Beanbun worker {$worker_id} download {$this->url} success.");
        } else {
            $this->error();
        }
    }

    /**
     * 默认的发现URL处理
     * 
     * @return void
     * @throws BeanbunException
     */
    public function defaultDiscoverUrl()
    {
        $countUrlFilter = count($this->urlFilter);
        if ($countUrlFilter === 1 && !$this->urlFilter[0]) {
            $this->error();
        }

        $urls = Helper::getUrlByHtml($this->page, $this->url);

        if ($countUrlFilter > 0) {
            foreach ($urls as $url) {
                foreach ($this->urlFilter as $urlPattern) {
                    if (preg_match($urlPattern, $url)) {
                        $this->queue()->add($url);
                    }
                }
            }
        } else {
            foreach ($urls as $url) {
                $this->queue()->add($url);
            }
        }
    }

    /**
     * 执行中间件
     * 
     * @param object|callable $middleware 中间件对象或回调
     * @param string $action 方法名
     * @return void
     */
    public function middleware($middleware, $action = 'handle')
    {
        if (is_object($middleware)) {
            $middleware->$action($this);
        } else {
            call_user_func($middleware, $this);
        }
    }
}
