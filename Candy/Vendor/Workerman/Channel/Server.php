<?php
/**
 * Workerman Channel 服务端适配。
 *
 * $Date: 2026-08-18 20:25:00 $
 */
namespace Workerman\Channel;

use Workerman\Protocols\Frame;
use Workerman\Worker;

/**
 * Channel服务器类
 * 
 * 用于创建Channel服务器，处理客户端连接，管理事件订阅和发布，以及队列功能
 */
class Server
{
    /**
     * Worker实例
     * @var Worker
     */
    protected $_worker = null;

    /**
     * 所有队列实例
     * @var Queue[] 键为队列名称，值为Queue对象
     */
    protected $_queues = [];

    /**
     * 绑定的IP地址
     * @var string
     */
    private $ip;

    /**
     * 构造函数
     * 
     * @param string $ip 绑定的IP地址或Unix域套接字
     *                   绑定Unix域套接字格式示例：unix:///tmp/channel.sock
     * @param int $port 监听的TCP端口号，仅在使用TCP监听时有效
     */
    public function __construct($ip = '0.0.0.0', $port = 2206)
    {
        if (strpos($ip, 'unix:') === false) {
            $worker = new Worker("frame://$ip:$port");
        } else {
            $worker = new Worker($ip);
            $worker->protocol = Frame::class;
        }
        $this->ip = $ip;
        $worker->count = 1;
        $worker->name = 'ChannelServer';
        $worker->channels = [];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        $this->_worker = $worker;
    }

    /**
     * 当客户端连接关闭时的处理函数
     * 
     * @param \Workerman\Connection\TcpConnection $connection 关闭的连接对象
     * @return void
     */
    public function onClose($connection)
    {
        if (!empty($connection->channels)) {
	        foreach ($connection->channels as $channel) {
		        unset($this->_worker->channels[$channel][$connection->id]);
		        if (empty($this->_worker->channels[$channel])) {
			        unset($this->_worker->channels[$channel]);
		        }
	        }
        }

        if (!empty($connection->watchs)) {
        	foreach ($connection->watchs as $channel) {
        		if (isset($this->_queues[$channel])) {
        			$this->_queues[$channel]->removeWatch($connection);
        			if ($this->_queues[$channel]->isEmpty()) {
        				unset($this->_queues[$channel]);
			        }
		        }
	        }
        }
    }

    /**
     * 处理客户端消息
     * 
     * @param \Workerman\Connection\TcpConnection $connection 发送消息的连接对象
     * @param string $data 消息数据
     * @return void
     */
    public function onMessage($connection, $data)
    {
        if(!$data)
        {
            return;
        }
        $worker = $this->_worker;
        $data = @unserialize($data, ['allowed_classes' => false]);
        if (!is_array($data)) {
            return;
        }
        $type = $data['type'];
        switch($type) {
            case 'subscribe':
                foreach($data['channels'] as $channel)
                {
                    $connection->channels[$channel] = $channel;
                    $worker->channels[$channel][$connection->id] = $connection;
                }
                break;
            case 'unsubscribe':
                foreach($data['channels'] as $channel) {
                    if (isset($connection->channels[$channel])) {
                        unset($connection->channels[$channel]);
                    }
                    if (isset($worker->channels[$channel][$connection->id])) {
                        unset($worker->channels[$channel][$connection->id]);
                        if (empty($worker->channels[$channel])) {
                            unset($worker->channels[$channel]);
                        }
                    }
                }
                break;
            case 'publish':
                foreach ($data['channels'] as $channel) {
                    if (empty($worker->channels[$channel])) {
                        continue;
                    }
                    $buffer = serialize(['type' => 'event', 'channel' => $channel, 'data' => $data['data']]);
                    foreach ($worker->channels[$channel] as $connection) {
                        $connection->send($buffer);
                    }
                }
                break;
            case 'publishLoop':
                //choose one subscriber from the list
                foreach ($data['channels'] as $channel) {
                    if (empty($worker->channels[$channel])) {
                        continue;
                    }
                    $buffer = serialize(['type' => 'event', 'channel' => $channel, 'data' => $data['data']]);

                    //这是要点，每次取出一个元素，如果取不到，说明已经到最后，重置到第一个
                    $connection = next($worker->channels[$channel]);
                    if( $connection == false ){
                        $connection = reset($worker->channels[$channel]);
                    }
                    $connection->send($buffer);
                }
                break;
            case 'watch':
            	foreach ($data['channels'] as $channel) {
		            $this->getQueue($channel)->addWatch($connection);
	            }
                break;
            case 'unwatch':
	            foreach ($data['channels'] as $channel) {
		            if (isset($this->_queues[$channel])) {
			            $this->_queues[$channel]->removeWatch($connection);
			            if ($this->_queues[$channel]->isEmpty()) {
				            unset($this->_queues[$channel]);
			            }
		            }
	            }
                break;
            case 'enqueue':
            	foreach ($data['channels'] as $channel) {
		            $this->getQueue($channel)->enqueue($data['data']);
	            }
                break;
            case 'reserve':
				if (isset($connection->watchs)) {
					foreach ($connection->watchs as $channel) {
						if (isset($this->_queues[$channel])) {
							$this->_queues[$channel]->addConsumer($connection);
						}
					}
				}
                break;
            default:
            
        }
    }

    /**
     * 获取队列实例
     * 
     * @param string $channel 队列名称
     * @return Queue 队列实例
     */
    private function getQueue($channel)
    {
        if (isset($this->_queues[$channel])) {
            return $this->_queues[$channel];
        }
        return ($this->_queues[$channel] = new Queue($channel));
    }

}
