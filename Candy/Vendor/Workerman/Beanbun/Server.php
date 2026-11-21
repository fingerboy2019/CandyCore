<?php
namespace Beanbun;

use Beanbun\Exception\BeanbunException;
use Beanbun\Lib\Helper;
use Exception;
use Workerman\Worker;

/**
 * Beanbun Server - 基于Workerman的Beanbun爬虫服务端
 * 该类提供TCP服务，接收客户端发送的爬取任务并执行
 */
class Server extends Beanbun
{
    /**
     * 服务监听地址
     * @var string
     */
    public $listen = 'tcp://0.0.0.0:8888';
    
    /**
     * Worker进程数量
     * @var int
     */
    public $count = 4;
    
    /**
     * Server constructor.
     * @param array $config 配置参数
     */
    public function __construct($config = [])
    {
        // 确保name有默认值
        $defaultName = 'beanbun_server';
        
        // 确保logFile有默认值
        $defaultLogFile = __DIR__ . '/' . $defaultName . '_access.log';
        
        // 服务模式下始终以守护进程运行
        $this->daemonize = true;
        
        // 合并默认配置和用户配置
        $config = array_merge([
            'name' => $defaultName,
            'logFile' => $defaultLogFile
        ], $config);
        
        // 调用父类构造函数
        parent::__construct($config);
        
        // 确保name和logFile有值
        if (empty($this->name)) {
            $this->name = $defaultName;
        }
        
        if (empty($this->logFile)) {
            $this->logFile = $defaultLogFile;
        }
        
        // 覆盖配置参数
        if (isset($config['listen'])) {
            $this->listen = $config['listen'];
        }
        
        if (isset($config['count'])) {
            $this->count = $config['count'];
        }
    }
    
    /**
     * 启动服务
     */
    public function start()
    {
        // 检查环境
        $this->check();
        
        // 创建Worker监听TCP连接
        $worker = new Worker($this->listen);
        $worker->count = $this->count;
        $worker->name = $this->name;
        
        // 设置事件回调
        $worker->onWorkerStart = [$this, 'onWorkerStart'];
        $worker->onWorkerStop = [$this, 'onWorkerStop'];
        $worker->onMessage = [$this, 'onMessage'];
        
        $this->worker = $worker;
        
        // 配置Workerman静态变量
        Worker::$daemonize = $this->daemonize;
        Worker::$stdoutFile = $this->logFile;
        Worker::$logFile = $this->logFile; // 确保日志文件路径被正确设置
        
        // 关闭所有数据库连接
        \Beanbun\Lib\Db::closeAll();
        
        // 初始化队列参数
        $this->queueArgs['name'] = $this->name;
        
        // 初始化钩子
        $this->initHooks();
        
        // 处理命令行参数
        $this->command();
        
        // 启动Workerman
        self::run();
    }
    
    /**
     * 重写初始化钩子方法
     * 移除定时任务，改为消息触发
     */
    public function initHooks()
    {
        $this->startWorkerHooks[] = function ($beanbun) {
            $beanbun->id = $beanbun->worker->id;
            $beanbun->log("Beanbun server worker {$beanbun->id} is starting ...");
            $beanbun->log("Listening on: {$beanbun->listen}");
        };

        if ($this->startWorker) {
            $this->startWorkerHooks[] = $this->startWorker;
        }
        
        // 移除定时任务，改为消息触发
        /*
        $this->startWorkerHooks[] = function ($beanbun) {
            $beanbun->queue()->maxQueueSize = $beanbun->max;
            $beanbun->timer_id = Beanbun::timer($beanbun->interval, [$beanbun, 'crawler']);
        };
        */

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
    
    /**
     * 处理客户端发送的消息
     * @param object $connection 客户端连接
     * @param string $data 客户端发送的数据
     */
    public function onMessage($connection, $data)
    {
        try {
            // 解析客户端发送的数据
            $task = json_decode($data, true);
            
            // 验证任务数据
            if (!$task || !isset($task['url'])) {
                $connection->send(json_encode([
                    'status' => 'error', 
                    'message' => '无效的任务数据，缺少url参数'
                ]));
                return;
            }
            
            $url = $task['url'];
            $options = isset($task['options']) ? $task['options'] : [];
            
            // 记录日志
            $this->log("收到爬取任务: {$url}");
            
            // 将任务添加到队列
            $this->queue()->add($url, $options);
            
            // 执行爬取
            $this->crawler();
            
            // 发送成功响应
            $connection->send(json_encode([
                'status' => 'success', 
                'message' => '任务已接收并处理'
            ]));
            
            // 记录任务完成日志
            $this->log("任务处理完成: {$url}");
            
        } catch (Exception $e) {
            // 记录错误日志
            $this->log("处理任务时发生错误: " . $e->getMessage());
            
            // 发送错误响应
            $connection->send(json_encode([
                'status' => 'error', 
                'message' => $e->getMessage()
            ]));
        }
    }
    
    /**
     * 重写crawler方法
     * 执行单个爬取任务
     */
    public function crawler()
    {
        try {
            // 只执行核心爬取逻辑，跳过startWorker和stopWorker钩子
            $allHooks = $this->hooks;
            array_shift($allHooks); // 移除startWorkerHooks
            array_pop($allHooks);   // 移除stopWorkerHooks

            foreach ($allHooks as $hooks) {
                foreach ($this->$hooks as $hook) {
                    call_user_func($hook, $this);
                }
            }
        } catch (Exception $e) {
            $this->log("爬取过程中发生错误: " . $e->getMessage());
            
            // 异常处理
            call_user_func($this->exceptionHandler, $e);
        } finally {
            // 重置爬取状态
            $this->queue = '';
            $this->url = '';
            $this->method = '';
            $this->page = '';
            $this->options = [];
        }
    }
}
