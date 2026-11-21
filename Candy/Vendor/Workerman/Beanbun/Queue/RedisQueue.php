<?php
/**
 * Beanbun Redis 队列适配。
 *
 * $Date: 2026-08-18 20:25:00 $
 */
namespace Beanbun\Queue;

/**
 * Redis队列实现类
 * 
 * 基于Redis的队列实现，支持布隆过滤器和不同的爬取算法
 */
class RedisQueue implements QueueInterface
{
    /**
     * Redis实例
     * @var \Redis
     */
    public $redis = null;
    
    /**
     * 配置数组
     * @var array
     */
    public $config = [];
    
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
     * 布隆过滤器大小
     * @var int
     */
    protected $bfSize;
    
    /**
     * 布隆过滤器哈希次数
     * @var int
     */
    protected $bfHashCount;

    /**
     * 构造函数
     * 
     * @param array $config 配置数组
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->name = $config['name'];
        $this->key = $config['name'] . 'Queue';
        $this->queuedKey = $config['name'] . 'Queued';
        $this->bfSize = isset($config['size']) ? $config['size'] : 400000;
        $this->bfHashCount = isset($config['hash_count']) ? $config['hash_count'] : 14;
        if (isset($config['bloomFilter']) && !$config['bloomFilter']) {
            $this->bloomFilter = false;
        }

        if (isset($config['algorithm'])) {
            $this->algorithm = $config['algorithm'] != 'breadth' ? 'depth' : 'breadth';
        }
        $this->getInstance()->sAdd('beanbun', $this->name);
    }

    /**
     * 获取Redis实例
     * 
     * @return \Redis Redis实例
     */
    public function getInstance()
    {
        if (!$this->redis) {
            $this->redis = new \Redis();
            $this->redis->connect($this->config['host'], $this->config['port']);
        }
        return $this->redis;
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

        $queue = serialize([
            'url' => $url,
            'options' => $options,
        ]);

        if ($this->isQueued($queue)) {
            return;
        }

        $this->getInstance()->rPush($this->key, $queue);
    }

    /**
     * 获取下一个要爬取的URL
     * 
     * @return string|array|null 下一个URL或包含URL和选项的数组，若队列为空则返回null
     */
    public function next()
    {
        if ($this->algorithm == 'depth') {
            $queue = $this->getInstance()->lPop($this->key);
        } else {
            $queue = $this->getInstance()->rPop($this->key);
        }

        if ($this->isQueued($queue)) {
            return $this->next();
        } else {
            return unserialize($queue, ['allowed_classes' => false]);
        }
    }

    /**
     * 获取队列中URL的总数
     * 
     * @return int URL总数
     */
    public function count()
    {
        return $this->getInstance()->lSize($this->key);
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
            $this->bfAdd(md5(serialize($queue)));
        } else {
            $this->getInstance()->sAdd($this->queuedKey, serialize($queue));
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
            return $this->bfHas(md5($queue));
        } else {
            return $this->getInstance()->sIsMember($this->queuedKey, $queue);
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
            return $this->getInstance()->sSize($this->queuedKey);
        }
    }

    /**
     * 清理队列
     * 
     * @return void
     */
    public function clean()
    {
        $this->getInstance()->delete($this->key);
        $this->getInstance()->delete($this->queuedKey);
        $this->getInstance()->sRem('beanbun', $this->name);
    }

    /**
     * 向布隆过滤器添加元素
     * 
     * @param string $item 要添加的元素
     * @return void
     */
    protected function bfAdd($item)
    {
        $index = 0;
        $pipe = $this->getInstance()->pipeline();
        while ($index < $this->bfHashCount) {
            $crc = $this->hash($item, $index);
            $pipe->setbit($this->queuedKey, $crc, 1);
            $index++;
        }
        $pipe->exec();
    }

    /**
     * 检查元素是否在布隆过滤器中
     * 
     * @param string $item 要检查的元素
     * @return bool 是否存在
     */
    protected function bfHas($item)
    {
        $index = 0;
        $pipe = $this->getInstance()->pipeline();
        while ($index < $this->bfHashCount) {
            $crc = $this->hash($item, $index);
            $pipe->getbit($this->queuedKey, $crc);
            $index++;
        }
        $result = $pipe->exec();
        return !in_array(0, $result);
    }

    /**
     * 哈希函数
     * 
     * @param string $item 要哈希的元素
     * @param int $index 哈希索引
     * @return int 哈希值
     */
    protected function hash($item, $index)
    {
        return abs(crc32(md5('m' . $index . $item))) % $this->bfSize;
    }
}
