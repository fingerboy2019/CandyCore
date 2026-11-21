<?php

namespace Workerman\Channel;

use Workerman\Connection\TcpConnection;

/**
 * 队列类
 * 
 * 用于管理Channel服务器中的队列功能，包括添加监听器、添加消费者、入队和出队操作
 */
class Queue
{

    /**
     * 队列名称
     * @var string
     */
    public $name = 'default';
    
    /**
     * 队列监听器列表
     * @var array 键为连接ID，值为连接对象
     */
    public $watcher = [];
    
    /**
     * 队列消费者列表
     * @var array 键为连接ID，值为连接对象
     */
    public $consumer = [];
    
    /**
     * 队列数据
     * @var \SplQueue
     */
    protected $queue = null;

    /**
     * 构造函数
     * 
     * @param string $name 队列名称
     */
    public function __construct($name)
    {
        $this->name = $name;
        $this->queue = new \SplQueue();
    }

    /**
     * 添加队列监听器
     * 
     * @param TcpConnection $connection 连接对象
     */
    public function addWatch($connection)
    {
    	if (!isset($this->watcher[$connection->id])) {
		    $this->watcher[$connection->id] = $connection;
		    $connection->watchs[] = $this->name;
	    }
    }

    /**
     * 移除队列监听器
     * 
     * @param TcpConnection $connection 连接对象
     */
    public function removeWatch($connection)
    {
        if (isset($connection->watchs) && in_array($this->name, $connection->watchs)) {
        	$idx = array_search($this->name, $connection->watchs);
            unset($connection->watchs[$idx]);
        }
        if (isset($this->watcher[$connection->id])) {
            unset($this->watcher[$connection->id]);
        }
        if (isset($this->consumer[$connection->id])) {
            unset($this->consumer[$connection->id]);
        }
    }

	/**
     * 添加队列消费者
     * 
     * @param TcpConnection $connection 连接对象
     */
    public function addConsumer($connection)
    {
    	if (isset($this->watcher[$connection->id]) && !isset($this->consumer[$connection->id])) {
    		$this->consumer[$connection->id] = $connection;
	    }
	    $this->dispatch();
    }

    /**
     * 向队列中添加数据
     * 
     * @param mixed $data 要添加到队列的数据
     */
    public function enqueue($data)
    {
    	$this->queue->enqueue($data);
    	$this->dispatch();
    }

    /**
     * 分发队列数据给消费者
     * 
     * @return void
     */
    private function dispatch()
    {
    	if ($this->queue->isEmpty() || count($this->consumer) == 0) {
    		return;
	    }

		while (!$this->queue->isEmpty()) {
    		$data = $this->queue->dequeue();
    		$idx = key($this->consumer);
    		$connection = $this->consumer[$idx];
    		unset($this->consumer[$idx]);
	        $connection->send(serialize(['type'=>'queue', 'channel'=>$this->name, 'data' => $data]));
	        if (count($this->consumer) == 0) {
		        break;
	        }
		}
    }

    /**
     * 检查队列是否为空
     * 
     * @return bool 当没有监听器且队列为空时返回true，否则返回false
     */
    public function isEmpty()
    {
        return empty($this->watcher) && $this->queue->isEmpty();
    }

}
