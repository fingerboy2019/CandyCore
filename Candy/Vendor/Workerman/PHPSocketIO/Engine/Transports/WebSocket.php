<?php

namespace PHPSocketIO\Engine\Transports;

use PHPSocketIO\Engine\Transport;
use PHPSocketIO\Engine\Parser;
use PHPSocketIO\Debug;

/**
 * WebSocket 传输类
 *
 * 该类实现了基于 WebSocket 协议的传输机制，提供全双工通信能力
 * 是 Socket.IO 最常用的传输方式。
 *
 * @package PHPSocketIO\Engine\Transports
 */
class WebSocket extends Transport
{
    /**
     * 会话ID
     * @var string|null
     */
    public $sid = null;
    
    /**
     * 是否可写入
     * @var bool
     */
    public $writable = true;
    
    /**
     * 是否支持消息分帧
     * @var bool
     */
    public $supportsFraming = true;
    
    /**
     * 是否支持二进制数据
     * @var bool
     */
    public $supportsBinary = true;
    
    /**
     * 传输名称
     * @var string
     */
    public $name = 'websocket';
    
    /**
     * WebSocket 连接对象
     * @var object|null
     */
    public $socket = null;

    /**
     * 构造函数
     *
     * 初始化 WebSocket 传输实例，设置事件监听器
     *
     * @param array $req 请求信息数组，包含 WebSocket 连接对象
     */
    public function __construct($req)
    {
        $this->socket = $req->connection;
        $this->socket->onMessage = [$this, 'onData2'];
        $this->socket->onClose = [$this, 'onClose'];
        $this->socket->onError = [$this, 'onError2'];
        Debug::debug('WebSocket __construct');
    }

    /**
     * 析构函数
     *
     * 用于调试，记录对象销毁日志
     */
    public function __destruct()
    {
        Debug::debug('WebSocket __destruct');
    }

    /**
     * 处理接收到的 WebSocket 消息
     *
     * 将接收到的 WebSocket 消息传递给父类的 onData 方法处理
     *
     * @param object $connection WebSocket 连接对象
     * @param string $data 接收到的数据
     * @return void
     */
    public function onData2($connection, $data): void
    {
        call_user_func([get_parent_class($this), 'onData'], $data);
    }

    /**
     * 处理 WebSocket 错误
     *
     * 将 WebSocket 错误传递给父类处理
     *
     * @param object $conection WebSocket 连接对象
     * @param int $code 错误代码
     * @param string $msg 错误消息
     * @return void
     */
    public function onError2($conection, $code, $msg): void
    {
        call_user_func([get_parent_class($this), 'onData'], $code, $msg);
    }

    /**
     * 发送数据包
     *
     * 将数据包编码并通过 WebSocket 发送给客户端
     *
     * @param array $packets 数据包数组
     * @return void
     */
    public function send(array $packets): void
    {
        foreach ($packets as $packet) {
            $data = Parser::encodePacket($packet);
            if ($this->socket) {
                $this->socket->send($data);
                $this->emit('drain');
            }
        }
    }

    /**
     * 执行关闭操作
     *
     * 关闭 WebSocket 连接
     *
     * @param callable|null $fn 关闭回调函数
     * @return void
     */
    public function doClose(?callable $fn = null): void
    {
        if ($this->socket) {
            $this->socket->close();
            $this->socket = null;
            if (! empty($fn)) {
                call_user_func($fn);
            }
        }
    }
}
