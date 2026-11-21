<?php

namespace PHPSocketIO;

use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Parser;

/**
 * Socket.IO 命名空间类
 * 
 * 该类继承自 Event\Emitter，负责管理 Socket.IO 命名空间，
 * 包括 Socket 实例管理、事件分发、房间管理等功能。
 * 
 * @package PHPSocketIO
 * @extends Emitter
 */
class Nsp extends Emitter
{
    /**
     * 适配器实例
     * @var Adapter
     */
    public $adapter;
    
    /**
     * 命名空间名称
     * @var string
     */
    public $name = null;
    
    /**
     * Socket.IO 服务器实例
     * @var SocketIO
     */
    public $server = null;
    
    /**
     * 房间列表
     * @var array
     */
    public $rooms = [];
    
    /**
     * 标志
     * @var array
     */
    public $flags = [];
    
    /**
     * Socket 实例列表
     * @var array
     */
    public $sockets = [];
    
    /**
     * 已连接的 Socket 实例
     * @var array
     */
    public $connected = [];
    
    /**
     * 函数列表
     * @var array
     */
    public $fns = [];
    
    /**
     * Socket ID 计数器
     * @var int
     */
    public $ids = 0;
    
    /**
     * 确认回调列表
     * @var array
     */
    public $acks = [];
    
    /**
     * 事件映射
     * @var array
     */
    public static $events = [
        'connect' => 'connect',    // for symmetry with client
        'connection' => 'connection',
        'newListener' => 'newListener'
    ];

    /**
     * 构造函数
     * 
     * @param SocketIO $server Socket.IO 服务器实例
     * @param string $name 命名空间名称
     */
    public function __construct($server, $name)
    {
        $this->name = $name;
        $this->server = $server;
        $this->initAdapter();
        Debug::debug('Nsp __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Nsp __destruct');
    }

    /**
     * 初始化适配器
     */
    public function initAdapter()
    {
        $adapter_name = $this->server->adapter();
        $this->adapter = new $adapter_name($this);
    }

    /**
     * 设置广播目标房间
     * 
     * @param string $name 房间名称
     * @return Nsp 返回当前实例
     */
    public function to($name): Nsp
    {
        if (! isset($this->rooms[$name])) {
            $this->rooms[$name] = $name;
        }
        return $this;
    }

    /**
     * 设置广播目标房间（to 方法的别名）
     * 
     * @param string $name 房间名称
     * @return Nsp 返回当前实例
     */
    public function in($name): Nsp
    {
        return $this->to($name);
    }

    /**
     * 添加客户端
     * 
     * @param Client $client 客户端实例
     * @param Nsp $nsp 命名空间实例
     * @param callable|null $fn 回调函数
     */
    public function add($client, $nsp, $fn)
    {
        $socket_name = $this->server->socket();
        $socket = new $socket_name($this, $client);
        if ('open' === $client->conn->readyState) {
            $this->sockets[$socket->id] = $socket;
            $socket->onconnect();
            if (! empty($fn)) {
                call_user_func($fn, $socket, $nsp);
            }
            $this->emit('connect', $socket);
            $this->emit('connection', $socket);
        } else {
            echo('next called after client was closed - ignoring socket');
        }
    }

    /**
     * 移除客户端
     * 
     * 由每个 `Socket` 实例调用
     * 
     * @param Socket $socket Socket 实例
     * @api private
     */
    public function remove($socket)
    {
        // todo $socket->id
        unset($this->sockets[$socket->id]);
    }


    /**
     * 向所有客户端发送事件
     * 
     * @param string|null $ev 事件名称
     * @param mixed $args 事件数据
     * @return Nsp 返回当前实例
     * @api public
     */
    public function emit($ev = null)
    {
        $args = func_get_args();
        if (isset(self::$events[$ev])) {
            call_user_func_array([get_parent_class(__CLASS__), 'emit'], $args);
        } else {
            // set up packet object

            $parserType = Parser::EVENT; // default
            //if (self::hasBin($args)) { $parserType = Parser::BINARY_EVENT; } // binary

            $packet = ['type' => $parserType, 'data' => $args];

            if (is_callable(end($args))) {
                echo('Callbacks are not supported when broadcasting');
                return;
            }

            $this->adapter->broadcast(
                $packet,
                [
                    'rooms' => $this->rooms,
                    'flags' => $this->flags
                ]
            );

            $this->rooms = [];
            $this->flags = [];
        }
        return $this;
    }

    /**
     * 发送消息
     * 
     * @param mixed $args 消息数据
     * @return Nsp 返回当前实例
     */
    public function send(): Nsp
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        $this->emit($args);
        return $this;
    }

    /**
     * 写入数据（send 方法的别名）
     * 
     * @param mixed $args 数据
     * @return mixed 返回 send 方法的结果
     */
    public function write()
    {
        $args = func_get_args();
        return call_user_func_array([$this, 'send'], $args);
    }

    /**
     * 获取客户端列表
     * 
     * @param callable $fn 回调函数
     * @return Nsp 返回当前实例
     */
    public function clients($fn): Nsp
    {
        $this->adapter->clients($this->rooms, $fn);
        return $this;
    }

    /**
     * 设置压缩标志
     * 
     * @param bool $compress 如果为 `true`，则压缩发送的数据
     * @return Nsp 返回当前实例
     * @api public
     */
    public function compress($compress): Nsp
    {
        $this->flags['compress'] = $compress;
        return $this;
    }
}
