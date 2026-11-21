<?php
namespace Beanbun\Queue;

use Beanbun\Lib\Client;
use Beanbun\Lib\Server;
use Workerman\Worker;

/**
 * 内存队列实现类
 * 
 * 基于内存的队列实现，使用Client和Server类实现分布式队列功能
 */
class MemoryQueue implements QueueInterface
{
    /**
     * 全局数据客户端实例
     * @var Client
     */
    public $globalData = null;
    
    /**
     * 最大队列大小
     * @var int
     */
    public $maxQueueSize = 10000;
    
    /**
     * 最大已入队数量
     * @var int
     */
    public $maxQueuedCount = 0;
    
    /**
     * 是否使用布隆过滤器
     * @var bool
     */
    public $bloomFilter = true;

    /**
     * 服务器实例数组
     * @var array
     */
    protected static $server = [];

    /**
     * 队列名称
     * @var string
     */
    protected $name = '';
    
    /**
     * 队列键名
     * @var string
     */
    protected $key = '';
    
    /**
     * 已入队键名
     * @var string
     */
    protected $queuedKey = '';
    
    /**
     * 爬取算法（depth深度优先，breadth广度优先）
     * @var string
     */
    protected $algorithm = 'depth';

    /**
     * 启动队列服务器
     * 
     * @param string $ip 服务器IP地址
     * @param int $port 服务器端口
     * @return void
     */
    public static function server($ip = '0.0.0.0', $port = 2207)
    {
        global $argv;
        $key = "$ip:$port";
        if ($argv[1] == 'start') {
            if (isset(self::$server[$key])) {
                echo "\n";
                exit;
            }
            echo "Memory queue is starting...\n";
            fclose(STDOUT);
            $STDOUT = fopen(__DIR__ . '/server.log', "a");

            self::$server[$key] = '';
        } elseif ($argv[1] == 'stop') {
            unset(self::$server[$key]);
        }

        $globalServer = new Server($ip, $port);
        Worker::$daemonize = true;
        Worker::$stdoutFile = __DIR__ . '/server.log';
        @Worker::runAll();
    }

    /**
     * 构造函数
     * 
     * @param array $config 配置数组
     */
    public function __construct($config)
    {
        $this->globalData = new Client($config['host'] . ':' . $config['port']);

        $this->name = $config['name'];
        $this->key = $config['name'] . 'Queue';
        $this->queuedKey = $config['name'] . 'Queued';
        if (isset($config['algorithm'])) {
            $this->algorithm = $config['algorithm'] != 'breadth' ? 'depth' : 'breadth';
        }
        if (isset($config['bloomFilter']) && !$config['bloomFilter']) {
            $this->bloomFilter = false;
        }

        $this->globalData->add($this->key, []);
        if ($this->bloomFilter) {
            $this->globalData->bfNew($this->queuedKey, [400000, 14]);
        } else {
            $this->globalData->add($this->queuedKey, []);
        }

        $this->globalData->add('beanbun', []);

        if (!isset($this->globalData->beanbun[$this->name])) {
            $name = $this->name;
            $this->globalData->up('beanbun', function ($value) use ($name) {
                if (!in_array($name, $value)) {
                    $value[] = $name;
                }
                return $value;
            });
        }
    }

    /**
     * 向队列中添加URL
     * 
     * @param string|array $url URL或包含URL和选项的数组
     * @param array $options 选项数组
     * @return void
     */
    public function add($url, $options = [])
    {
        if ($this->maxQueueSize != 0 && $this->count() >= $this->maxQueueSize) {
            return;
        }

        $queue = [
            'url' => $url,
            'options' => $options,
        ];

        if ($this->isQueued($queue)) {
            return;
        }

        $this->globalData->push($this->key, $queue);
    }

    /**
     * 获取下一个要爬取的URL
     * 
     * @return string|array|null 下一个URL或包含URL和选项的数组，若队列为空则返回null
     */
    public function next()
    {
        if ($this->algorithm == 'depth') {
            $queue = $this->globalData->shift($this->key);
        } else {
            $queue = $this->globalData->pop($this->key);
        }

        if ($this->isQueued($queue)) {
            return $this->next();
        } else {
            return $queue;
        }
    }

    /**
     * 获取队列中URL的总数
     * 
     * @return int URL总数
     */
    public function count()
    {
        return $this->globalData->count($this->key);
    }

    /**
     * 标记URL为已入队
     * 
     * @param string|array $queue URL或包含URL和选项的数组
     * @return void
     */
    public function queued($queue)
    {
        if ($this->bloomFilter) {
            $this->globalData->bfAdd($this->queuedKey, md5(serialize($queue)));
        } else {
            $this->globalData->push($this->queuedKey, serialize($queue));
        }
    }

    /**
     * 检查URL是否已入队
     * 
     * @param string|array $queue URL或包含URL和选项的数组
     * @return bool 是否已入队
     */
    public function isQueued($queue)
    {
        if ($this->bloomFilter) {
            return $this->globalData->bfIn($this->queuedKey, md5(serialize($queue)));
        } else {
            return in_array(serialize($queue), $this->globalData->{$this->queuedKey});
        }
    }

    /**
     * 获取已入队URL的数量
     * 
     * @return int 已入队URL数量
     */
    public function queuedCount()
    {
        if ($this->bloomFilter) {
            return 0;
        } else {
            return $this->globalData->count($this->queuedKey);
        }
    }

    /**
     * 清理队列
     * 
     * @return void
     */
    public function clean()
    {
        unset($this->globalData->{$this->key});
        unset($this->globalData->{$this->queuedKey});
        $name = $this->name;
        $this->globalData->up('beanbun', function ($value) use ($name) {
            $key = array_search($name, $value);
            if ($key !== false) {
                unset($value[$key]);
            }
            return $value;
        });
    }
}
