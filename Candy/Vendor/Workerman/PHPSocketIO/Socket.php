<?php

namespace PHPSocketIO;

use Closure;
use Exception;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Parser;

/**
 * Socket.IO Socket 类
 * 
 * 该类继承自 Event\Emitter，负责处理单个 Socket 连接的事件、房间管理、消息发送等功能。
 * 每个 Socket 实例代表一个客户端连接，管理客户端与服务器之间的通信。
 * 
 * @package PHPSocketIO
 * @extends Emitter
 */
class Socket extends Emitter
{
    /**
     * 命名空间对象
     * @var object|null
     */
    public $nsp = null;
    
    /**
     * Socket.IO 服务器实例
     * @var object|null
     */
    public $server = null;
    
    /**
     * 适配器实例，用于房间管理
     * @var object|null
     */
    public $adapter = null;
    
    /**
     * Socket 唯一标识符
     * @var string|null
     */
    public $id = null;
    
    /**
     * Socket.IO 路径
     * @var string
     */
    public $path = '/';
    
    /**
     * HTTP 请求对象
     * @var object|null
     */
    public $request = null;
    
    /**
     * 客户端对象
     * @var object|null
     */
    public $client = null;
    
    /**
     * 底层连接对象
     * @var object|null
     */
    public $conn = null;
    
    /**
     * Socket 加入的房间列表
     * @var array
     */
    public $rooms = [];
    
    /**
     * 当前广播目标房间列表
     * @var array
     */
    public $_rooms = [];
    
    /**
     * 消息标志，用于控制消息发送行为
     * @var array
     */
    public $flags = [];
    
    /**
     * 回调函数列表，用于处理客户端确认
     * @var array
     */
    public $acks = [];
    
    /**
     * 是否连接
     * @var bool
     */
    public $connected = true;
    
    /**
     * 是否断开连接
     * @var bool
     */
    public $disconnected = false;
    
    /**
     * 握手信息
     * @var array
     */
    public $handshake = [];
    
    /**
     * 用户ID
     * @var string|null
     */
    public $userId = null;
    
    /**
     * 是否为访客
     * @var bool
     */
    public $isGuest = false;
    
    /**
     * 是否已添加用户
     * @var bool|null
     */
    public $addedUser = null;
    
    /**
     * 用户名
     * @var string|null
     */
    public $username = null;

    /**
     * 内置事件列表
     * @var array
     */
    public static $events = [
        'error' => 'error',
        'connect' => 'connect',
        'disconnect' => 'disconnect',
        'newListener' => 'newListener',
        'removeListener' => 'removeListener'
    ];

    /**
     * 消息标志映射
     * @var array
     */
    public static $flagsMap = [
        'json' => 'json',
        'volatile' => 'volatile',
        'broadcast' => 'broadcast'
    ];

    /**
     * 构造函数
     *
     * @param object $nsp 命名空间对象
     * @param object $client 客户端对象
     */
    public function __construct($nsp, $client)
    {
        $this->nsp = $nsp;
        $this->server = $nsp->server;
        $this->adapter = $this->nsp->adapter;
        $this->id = ($nsp->name !== '/') ? $nsp->name . '#' . $client->id : $client->id;
        $this->request = $client->request;
        $this->client = $client;
        $this->conn = $client->conn;
        $this->handshake = $this->buildHandshake();
        Debug::debug('IO Socket __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('IO Socket __destruct');
    }

    /**
     * 构建握手信息
     *
     * @return array 握手信息数组
     */
    public function buildHandshake(): array
    {
        //todo check this->request->_query
        $info = ! empty($this->request->url) ? parse_url($this->request->url) : [];
        $query = [];
        if (isset($info['query'])) {
            parse_str($info['query'], $query);
        }
        return [
            'headers' => $this->request->headers ?? [],
            'time' => date('D M d Y H:i:s') . ' GMT',
            'address' => $this->conn->remoteAddress,
            'xdomain' => isset($this->request->headers['origin']),
            'secure' => ! empty($this->request->connection->encrypted),
            'issued' => time(),
            'url' => $this->request->url ?? '',
            'query' => $query,
        ];
    }

    /**
     * 魔术方法，用于获取广播对象
     *
     * @param string $name 属性名
     * @return mixed|null 广播对象或null
     */
    public function __get($name)
    {
        if ($name === 'broadcast') {
            $this->flags['broadcast'] = true;
            return $this;
        }
        return null;
    }

    /**
     * 触发事件
     *
     * @param string $ev 事件名称
     * @param mixed ...$args 事件参数
     * @return Socket 当前Socket实例
     * @throws Exception 广播时不支持回调函数
     */
    public function emit($ev = null)
    {
        $args = func_get_args();
        if (isset(self::$events[$ev])) {
            call_user_func_array(array(get_parent_class(__CLASS__), 'emit'), $args);
        } else {
            $packet = [];
            $packet['type'] = Parser::EVENT;
            $packet['data'] = $args;
            $flags = $this->flags;
            // 检查最后一个参数是否为回调函数
            if (is_callable(end($args))) {
                if ($this->_rooms || isset($flags['broadcast'])) {
                    throw new Exception('Callbacks are not supported when broadcasting');
                }
                echo('emitting packet with ack id ' . $this->nsp->ids);
                $this->acks[$this->nsp->ids] = array_pop($args);
                $packet['id'] = $this->nsp->ids++;
            }

            if ($this->_rooms || ! empty($flags['broadcast'])) {
                $this->adapter->broadcast(
                    $packet,
                    [
                        'except' => [$this->id => $this->id],
                        'rooms' => $this->_rooms,
                        'flags' => $flags
                    ]
                );
            } else {
                // 发送数据包
                $this->packet($packet);
            }

            // 重置标志
            $this->_rooms = [];
            $this->flags = [];
        }
        return $this;
    }

    /**
     * 设置广播目标房间
     *
     * @param string $name 房间名称
     * @return Socket 当前Socket实例
     * @api public
     */
    public function to($name): Socket
    {
        if (! isset($this->_rooms[$name])) {
            $this->_rooms[$name] = $name;
        }
        return $this;
    }

    /**
     * 设置广播目标房间（to方法的别名）
     *
     * @param string $name 房间名称
     * @return Socket 当前Socket实例
     * @api public
     */
    public function in($name): Socket
    {
        return $this->to($name);
    }

    /**
     * 发送message事件
     *
     * @param mixed ...$args 消息参数
     * @return Socket 当前Socket实例
     * @api public
     */
    public function send(): Socket
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        call_user_func_array([$this, 'emit'], $args);
        return $this;
    }

    /**
     * 发送message事件（send方法的别名）
     *
     * @param mixed ...$args 消息参数
     * @return Socket 当前Socket实例
     * @api public
     */
    public function write(): Socket
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        call_user_func_array([$this, 'emit'], $args);
        return $this;
    }

    /**
     * 发送数据包
     *
     * @param array $packet 数据包
     * @param bool $preEncoded 是否已编码
     * @return void
     * @api private
     */
    public function packet($packet, $preEncoded = false)
    {
        if (! $this->nsp || ! $this->client) {
            return;
        }
        $packet['nsp'] = $this->nsp->name;
        $this->client->packet($packet, $preEncoded, false);
    }

    /**
     * 加入房间
     *
     * @param string $room 房间名称
     * @return Socket 当前Socket实例
     * @api private
     */
    public function join($room): Socket
    {
        if (! $this->connected) {
            return $this;
        }
        if (isset($this->rooms[$room])) {
            return $this;
        }
        $this->adapter->add($this->id, $room);
        $this->rooms[$room] = $room;
        return $this;
    }

    /**
     * 离开房间
     *
     * @param string $room 房间名称
     * @return Socket 当前Socket实例
     * @api private
     */
    public function leave($room): Socket
    {
        $this->adapter->del($this->id, $room);
        unset($this->rooms[$room]);
        return $this;
    }

    /**
     * 离开所有房间
     *
     * @return void
     * @api private
     */
    public function leaveAll()
    {
        $this->adapter->delAll($this->id);
        $this->rooms = [];
    }

    /**
     * 连接成功时调用
     *
     * 由命名空间在中间件执行成功后调用
     *
     * @return void
     * @api private
     */
    public function onconnect()
    {
        $this->nsp->connected[$this->id] = $this;
        $this->join($this->id);
        $this->packet(
            [
                'type' => Parser::CONNECT
            ]
        );
    }

    /**
     * 处理接收到的数据包
     *
     * 由客户端调用，处理各种类型的数据包
     *
     * @param array $packet 数据包
     * @throws Exception
     * @api private
     */
    public function onpacket($packet)
    {
        match ($packet['type']) {
            Parser::BINARY_EVENT, Parser::EVENT => $this->onevent($packet),
            Parser::BINARY_ACK, Parser::ACK => $this->onack($packet),
            Parser::DISCONNECT => $this->ondisconnect(),
            Parser::ERROR => $this->emit('error', $packet['data']),
            default => null,
        };
    }

    /**
     * 处理事件数据包
     *
     * @param array $packet 事件数据包
     * @return void
     * @api private
     */
    public function onevent($packet)
    {
        $args = $packet['data'] ?? [];
        if (! empty($packet['id']) || (isset($packet['id']) && $packet['id'] === 0)) {
            $args[] = $this->ack($packet['id']);
        }
        call_user_func_array([get_parent_class(__CLASS__), 'emit'], $args);
    }

    /**
     * 创建确认回调函数
     *
     * @param int $id 数据包ID
     * @return Closure 确认回调函数
     * @api private
     */
    public function ack($id): Closure
    {
        $sent = false;
        return function () use (&$sent, $id) {
            $self = $this;
            // 防止重复回调
            if ($sent) {
                return;
            }
            $args = func_get_args();
            $type = $this->hasBin($args) ? Parser::BINARY_ACK : Parser::ACK;
            $self->packet(
                [
                    'id' => $id,
                    'type' => $type,
                    'data' => $args
                ]
            );
        };
    }

    /**
     * 处理确认数据包
     *
     * @param array $packet 确认数据包
     * @return void
     * @api private
     */
    public function onack($packet)
    {
        $ack = $this->acks[$packet['id']];
        if (is_callable($ack)) {
            call_user_func($ack, $packet['data']);
            unset($this->acks[$packet['id']]);
        } else {
            echo('bad ack ' . $packet['id']);
        }
    }

    /**
     * 处理客户端断开连接数据包
     *
     * @throws Exception
     * @return void
     * @api private
     */
    public function ondisconnect()
    {
        $this->onclose('client namespace disconnect');
    }

    /**
     * 处理客户端错误
     *
     * @param mixed $err 错误信息
     * @throws Exception
     * @return void
     * @api private
     */
    public function onerror($err)
    {
        if ($this->listeners('error')) {
            $this->emit('error', $err);
        }
    }

    /**
     * 处理连接关闭
     *
     * 由客户端调用，处理连接关闭逻辑
     *
     * @param string $reason 关闭原因
     * @return Socket 当前Socket实例
     * @throws Exception
     * @api private
     */
    public function onclose($reason)
    {
        if (! $this->connected) {
            return $this;
        }
        $this->emit('disconnect', $reason);
        $this->leaveAll();
        $this->nsp->remove($this);
        $this->client->remove($this);
        $this->connected = false;
        $this->disconnected = true;
        unset($this->nsp->connected[$this->id]);
        // 清理资源
        $this->nsp = null;
        $this->server = null;
        $this->adapter = null;
        $this->request = null;
        $this->client = null;
        $this->conn = null;
        $this->removeAllListeners();
    }

    /**
     * 发送错误数据包
     *
     * @param mixed $err 错误信息
     * @return void
     * @api private
     */
    public function error($err)
    {
        $this->packet(
            [
                'type' => Parser::ERROR, 'data' => $err
            ]
        );
    }

    /**
     * 断开客户端连接
     *
     * @param bool $close 是否关闭底层连接
     * @return Socket 当前Socket实例
     * @throws Exception
     * @api public
     */
    public function disconnect(bool $close = false): Socket
    {
        if (! $this->connected) {
            return $this;
        }
        if ($close) {
            $this->client->disconnect();
        } else {
            $this->packet(
                [
                    'type' => Parser::DISCONNECT
                ]
            );
            $this->onclose('server namespace disconnect');
        }
        return $this;
    }

    /**
     * 设置压缩标志
     *
     * @param bool $compress 是否压缩发送的数据
     * @return Socket 当前Socket实例
     * @api public
     */
    public function compress($compress): Socket
    {
        $this->flags['compress'] = $compress;
        return $this;
    }

    /**
     * 检查数据中是否包含二进制数据
     *
     * @param array $args 要检查的数据
     * @return bool 是否包含二进制数据
     * @protected
     */
    protected function hasBin($args): bool
    {
        $hasBin = false;

        array_walk_recursive(
            $args,
            function ($item, $key) use (&$hasBin) {
                if (! ctype_print($item)) {
                    $hasBin = true;
                }
            }
        );

        return $hasBin;
    }
}
