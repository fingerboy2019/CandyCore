<?php
/**
 * Workerman Channel 客户端适配。
 *
 * $Date: 2026-08-18 20:25:00 $
 */
namespace Workerman\Channel;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;
use Workerman\Protocols\Frame;

/**
 * Channel客户端类
 * 
 * 用于与Channel服务器建立连接，订阅事件，发布事件，以及使用队列功能
 */
class Client
{
    /**
     * 收到消息时的回调函数
     * @var callable 回调函数，参数为：事件名称，事件数据
     */
    public static $onMessage = null;

    /**
     * 连接成功时的回调函数
     * @var callable 回调函数，无参数
     */
    public static $onConnect = null;

    /**
     * 连接关闭时的回调函数
     * @var callable 回调函数，无参数
     */
    public static $onClose = null;

    /**
     * 与Channel服务器的连接
     * @var \Workerman\Connection\TcpConnection|resource
     */
    protected static $_remoteConnection = null;

    /**
     * Channel服务器IP地址
     * @var string
     */
    protected static $_remoteIp = null;

    /**
     * Channel服务器端口
     * @var int
     */
    protected static $_remotePort = null;

    /**
     * 重连定时器
     * @var Timer
     */
    protected static $_reconnectTimer = null;

    /**
     * 心跳定时器
     * @var Timer
     */
    protected static $_pingTimer = null;

    /**
     * 所有事件回调函数
     * @var array 键为事件名称，值为回调函数
     */
    protected static $_events = [];

    /**
     * 所有队列回调函数
     * @var array 键为队列名称，值为回调函数
     */
    protected static $_queues = [];

    /**
     * 是否在Workerman环境中运行
     * @var bool
     */
    protected static $_isWorkermanEnv = true;

    /**
     * 心跳间隔时间（秒）
     * @var int
     */
    public static $pingInterval = 55;

    /**
     * 连接到Channel服务器
     * 
     * @param string $ip Channel服务器IP地址或Unix域套接字地址
     *                   TCP格式示例：192.168.1.100
     *                   Unix域套接字格式示例：unix:///tmp/workerman-channel.sock
     * @param int $port 使用TCP连接时的端口号
     */
    public static function connect($ip = '127.0.0.1', $port = 2206)
    {
        if (self::$_remoteConnection) {
            return;
        }

        self::$_remoteIp = $ip;
        self::$_remotePort = $port;

        if (!in_array(PHP_SAPI, ['cli', 'micro']) || !class_exists('Workerman\Worker', false)) {
            self::$_isWorkermanEnv = false;
        }

        // For workerman environment.
        if (self::$_isWorkermanEnv) {
            if (strpos($ip, 'unix://') === false) {
                $conn = new AsyncTcpConnection('frame://' . self::$_remoteIp . ':' . self::$_remotePort);
            } else {
                $conn = new AsyncTcpConnection($ip);
                $conn->protocol = Frame::class;
            }

            $conn->onClose = [self::class, 'onRemoteClose'];
            $conn->onConnect = [self::class, 'onRemoteConnect'];
            $conn->onMessage = [self::class , 'onRemoteMessage'];
            $conn->connect();

            if (empty(self::$_pingTimer)) {
                self::$_pingTimer = Timer::add(self::$pingInterval, [self::class, 'ping']);
            }
            // Not workerman environment.
        } else {
            $remote = strpos($ip, 'unix://') === false ? 'tcp://'.self::$_remoteIp.':'.self::$_remotePort : $ip;
            $conn = stream_socket_client($remote, $code, $message, 5);
            if (!$conn) {
                throw new \Exception($message);
            }
        }

        self::$_remoteConnection = $conn;
    }

    /**
     * 处理来自Channel服务器的消息
     * 
     * @param \Workerman\Connection\TcpConnection $connection 连接对象
     * @param string $data 消息数据
     * @throws \Exception 当事件或队列没有对应的回调函数时抛出异常
     */
    public static function onRemoteMessage($connection, $data)
    {
        $data = @unserialize($data, ['allowed_classes' => false]);
        if (!is_array($data)) {
            return;
        }
        $type = $data['type'];
        $event = $data['channel'];
        $event_data = $data['data'];

        $callback = null;

        if ($type == 'event') {
	        if (!empty(self::$_events[$event])) {
		        call_user_func(self::$_events[$event], $event_data);
	        } elseif (!empty(Client::$onMessage)) {
		        call_user_func(Client::$onMessage, $event, $event_data);
	        } else {
		        throw new \Exception("event:$event have not callback");
	        }
        } else {
	        if (isset(self::$_queues[$event])) {
		        call_user_func(self::$_queues[$event], $event_data);
	        } else {
		        throw new \Exception("queue:$event have not callback");
	        }
        }
    }

    /**
     * 发送心跳包给Channel服务器
     * 
     * @return void
     */
    public static function ping()
    {
        if(self::$_remoteConnection)
        {
            self::$_remoteConnection->send('');
        }
    }

    /**
     * 当与Channel服务器连接关闭时的处理函数
     * 
     * @return void
     */
    public static function onRemoteClose()
    {
        Timer::add(0.5, function() {
            echo "Waring channel connection closed and try to reconnect\n";
        }, [], false);
        self::$_remoteConnection = null;
        self::clearTimer();
        self::$_reconnectTimer = Timer::add(1, [self::class, 'connect'], [self::$_remoteIp, self::$_remotePort]);
        if (self::$onClose) {
            call_user_func(Client::$onClose);
        }
    }

    /**
     * 当与Channel服务器连接成功时的处理函数
     * 
     * @return void
     */
    public static function onRemoteConnect()
    {
        $all_event_names = array_keys(self::$_events);
        if($all_event_names)
        {
            self::subscribe($all_event_names);
        }
        self::clearTimer();

        if (self::$onConnect) {
            call_user_func(Client::$onConnect);
        }
    }

    /**
     * 清除重连定时器
     * 
     * @return void
     * @throws \Exception 当不在Workerman环境中时抛出异常
     */
    public static function clearTimer()
    {
        if (!self::$_isWorkermanEnv) {
            throw new \Exception('Channel\\Client not support clearTimer method when it is not in the workerman environment.');
        }
        if(self::$_reconnectTimer)
        {
            Timer::del(self::$_reconnectTimer);
            self::$_reconnectTimer = null;
        }
    }

    /**
     * 注册事件回调函数
     * 
     * @param string $event 事件名称
     * @param callable $callback 回调函数，参数为事件数据
     * @throws \Exception 当回调函数不可调用时抛出异常
     */
    public static function on($event, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception('callback is not callable for event.');
        }
        self::$_events[$event] = $callback;
        self::subscribe($event);
    }

    /**
     * 订阅事件
     * 
     * @param string|array $events 事件名称或事件名称数组
     * @return void
     */
    public static function subscribe($events)
    {
        $events = (array)$events;
        self::send(['type' => 'subscribe', 'channels'=>$events]);
        foreach ($events as $event) {
            if(!isset(self::$_events[$event])) {
                self::$_events[$event] = null;
            }
        }
    }

    /**
     * 取消订阅事件
     * 
     * @param string|array $events 事件名称或事件名称数组
     * @return void
     */
    public static function unsubscribe($events)
    {
        $events = (array)$events;
        self::send(['type' => 'unsubscribe', 'channels'=>$events]);
        foreach($events as $event) {
            unset(self::$_events[$event]);
        }
    }

    /**
     * 发布事件
     * 
     * @param string|array $events 事件名称或事件名称数组
     * @param mixed $data 事件数据
     * @param bool $is_loop 是否循环发布到订阅者（默认false，即发布给所有订阅者）
     */
    public static function publish($events, $data , $is_loop = false)
    {
        $type = $is_loop == true ? 'publishLoop' : 'publish';
        self::sendAnyway(['type' => $type, 'channels' => (array)$events, 'data' => $data]);
    }
    
    /**
     * 监听队列频道
     * 
     * @param string|array $channels 队列名称或队列名称数组
     * @param callable $callback 回调函数，参数为队列数据
     * @param bool $autoReserve 回调完成后是否自动继续监听（默认true）
     *                          有时你可能不想立即继续监听，或者在异步任务中
     *                          你想在完成回调中手动调用reserve()，这时应将$autoReserve设为false
     * @throws \Exception 当回调函数不可调用时抛出异常
     */
    public static function watch($channels, $callback, $autoReserve=true)
    {
        if (!is_callable($callback)) {
            throw new \Exception('callback is not callable for watch.');
        }

        if ($autoReserve) {
        	$callback = static function($data) use ($callback) {
		        try {
			        call_user_func($callback, $data);
		        } catch (\Exception $e) {
			        throw $e;
		        } catch (\Error $e) {
			        throw $e;
		        } finally {
			        self::reserve();
		        }
	        };
        }

	    $channels = (array)$channels;
        self::send(['type' => 'watch', 'channels'=>$channels]);

        foreach ($channels as $channel) {
        	self::$_queues[$channel] = $callback;
        }

        if ($autoReserve) {
	        self::reserve();
        }
    }

    /**
     * 停止监听队列频道
     * 
     * @param string|array $channels 队列名称或队列名称数组
     * @throws \Exception
     */
    public static function unwatch($channels)
    {
	    $channels = (array)$channels;
        self::send(['type' => 'unwatch', 'channels'=>$channels]);
        foreach ($channels as $channel) {
	        if (isset(self::$_queues[$channel])) {
		        unset(self::$_queues[$channel]);
	        }
        }
    }

	/**
     * 向队列中添加数据
     * 
     * @param string|array $channels 队列名称或队列名称数组
     * @param mixed $data 要添加到队列的数据
     * @throws \Exception
     */
    public static function enqueue($channels, $data)
    {
        self::sendAnyway(['type' => 'enqueue', 'channels' => (array)$channels, 'data' => $data]);
    }

	/**
     * 手动开始监听队列
     * 
     * @throws \Exception
     */
    public static function reserve()
    {
	    self::send(['type' => 'reserve']);
    }

    /**
     * 在Workerman环境中发送数据
     * 
     * @param array $data 要发送的数据
     * @throws \Exception 当不在Workerman环境中时抛出异常
     */
    protected static function send($data)
    {
        if (!self::$_isWorkermanEnv) {
            throw new \Exception("Channel\\Client not support {$data['type']} method when it is not in the workerman environment.");
        }
        self::connect(self::$_remoteIp, self::$_remotePort);
        self::$_remoteConnection->send(serialize($data));
    }

    /**
     * 从任何环境中发送数据
     * 
     * @param array $data 要发送的数据
     * @throws \Exception
     */
    protected static function sendAnyway($data)
    {
        self::connect(self::$_remoteIp, self::$_remotePort);
        $body = serialize($data);
        if (self::$_isWorkermanEnv) {
            self::$_remoteConnection->send($body);
        } else {
            $buffer = pack('N', 4+strlen($body)) . $body;
            fwrite(self::$_remoteConnection, $buffer);
        }
    }

}
