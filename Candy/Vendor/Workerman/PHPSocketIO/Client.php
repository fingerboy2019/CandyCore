<?php

namespace PHPSocketIO;

use Exception;
use PHPSocketIO\Parser\Decoder;
use PHPSocketIO\Parser\Encoder;
use PHPSocketIO\Parser\Parser;

/**
 * Socket.IO 客户端类
 * 
 * 该类负责处理 Socket.IO 客户端的连接、数据包传输和命名空间管理等功能。
 * 
 * @package PHPSocketIO
 */
class Client
{
    /**
     * Socket.IO 服务器实例
     * @var Server
     */
    public $server = null;
    
    /**
     * 连接实例
     * @var Connection
     */
    public $conn = null;
    
    /**
     * 编码器实例
     * @var Encoder
     */
    public $encoder = null;
    
    /**
     * 解码器实例
     * @var Decoder
     */
    public $decoder = null;
    
    /**
     * 客户端 ID
     * @var string
     */
    public $id = null;
    
    /**
     * HTTP 请求对象
     * @var Request
     */
    public $request = null;
    
    /**
     * 命名空间映射
     * @var array
     */
    public $nsps = [];
    
    /**
     * 连接缓冲区
     * @var array
     */
    public $connectBuffer = [];
    
    /**
     * Socket 实例列表
     * @var array|mixed|null
     */
    public $sockets;

    /**
     * 构造函数
     * 
     * @param Server $server Socket.IO 服务器实例
     * @param Connection $conn 连接实例
     */
    public function __construct($server, $conn)
    {
        $this->server = $server;
        $this->conn = $conn;
        $this->encoder = new Encoder();
        $this->decoder = new Decoder();
        $this->id = $conn->id;
        $this->request = $conn->request;
        $this->setup();
        Debug::debug('Client __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Client __destruct');
    }

    /**
     * 设置事件监听器
     * 
     * @api private
     */
    public function setup()
    {
        $this->decoder->on('decoded', [$this, 'ondecoded']);
        $this->conn->on('data', [$this, 'ondata']);
        $this->conn->on('error', [$this, 'onerror']);
        $this->conn->on('close', [$this, 'onclose']);
    }

    /**
     * 连接客户端到命名空间
     * 
     * @param string $name 命名空间名称
     * @api private
     */
    public function connect($name)
    {
        if (! isset($this->server->nsps[$name])) {
            $this->packet(['type' => Parser::ERROR, 'nsp' => $name, 'data' => 'Invalid namespace']);
            return;
        }
        $nsp = $this->server->of($name);
        if ('/' !== $name && ! isset($this->nsps['/'])) {
            $this->connectBuffer[$name] = $name;
            return;
        }
        $nsp->add($this, $nsp, [$this, 'nspAdd']);
    }

    /**
     * 添加命名空间
     * 
     * @param Socket $socket Socket 实例
     * @param Nsp $nsp 命名空间实例
     */
    public function nspAdd($socket, $nsp)
    {
        $this->sockets[$socket->id] = $socket;
        $this->nsps[$nsp->name] = $socket;
        if ('/' === $nsp->name && $this->connectBuffer) {
            foreach ($this->connectBuffer as $name) {
                $this->connect($name);
            }
            $this->connectBuffer = [];
        }
    }

    /**
     * 断开所有命名空间的连接并关闭传输
     * 
     * @api private
     */
    public function disconnect()
    {
        foreach ($this->sockets as $socket) {
            $socket->disconnect();
        }
        $this->sockets = [];
        $this->close();
    }

    /**
     * 移除 Socket 实例
     * 
     * 由每个 `Socket` 实例调用
     * 
     * @param Socket $socket Socket 实例
     * @api private
     */
    public function remove($socket)
    {
        if (isset($this->sockets[$socket->id])) {
            $nsp = $this->sockets[$socket->id]->nsp->name;
            unset($this->sockets[$socket->id]);
            unset($this->nsps[$nsp]);
        }
    }

    /**
     * 关闭底层连接
     * 
     * @api private
     */
    public function close()
    {
        if (empty($this->conn)) {
            return;
        }
        if ('open' === $this->conn->readyState) {
            $this->conn->close();
            $this->onclose('forced server close');
        }
    }

    /**
     * 向传输层写入数据包
     * 
     * @param array $packet 数据包对象
     * @param bool $preEncoded 是否已经编码
     * @param bool $volatile 是否为挥发性消息
     * @api private
     */
    public function packet($packet, $preEncoded = false, $volatile = false)
    {
        if (! empty($this->conn) && 'open' === $this->conn->readyState) {
            if (! $preEncoded) {
                // not broadcasting, need to encode
                $encodedPackets = $this->encoder->encode($packet);
                $this->writeToEngine($encodedPackets, $volatile);
            } else { // a broadcast pre-encodes a packet
                $this->writeToEngine($packet);
            }
        }
    }

    /**
     * 写入到引擎
     * 
     * @param array|string $encodedPackets 编码后的数据包
     * @param bool $volatile 是否为挥发性消息
     */
    public function writeToEngine($encodedPackets, $volatile = false)
    {
        if ($volatile) {
            echo new Exception('volatile');
        }
        if ($volatile && ! $this->conn->transport->writable) {
            return;
        }
        if (isset($encodedPackets['nsp'])) {
            unset($encodedPackets['nsp']);
        }
        foreach ($encodedPackets as $packet) {
            $this->conn->write($packet);
        }
    }

    /**
     * 处理传入的传输数据
     * 
     * @param string $data 传入的数据
     * @api private
     */
    public function ondata($data)
    {
        try {
            // todo chek '2["chat message","2"]' . "\0" . ''
            $this->decoder->add(trim($data));
        } catch (Exception $e) {
            $this->onerror($e);
        }
    }

    /**
     * 当解析器完全解码数据包时调用
     * 
     * @param array $packet 解码后的数据包
     * @api private
     */
    public function ondecoded($packet)
    {
        if (Parser::CONNECT == $packet['type']) {
            $this->connect($packet['nsp']);
        } else {
            if (isset($this->nsps[$packet['nsp']])) {
                $this->nsps[$packet['nsp']]->onpacket($packet);
            }
        }
    }

    /**
     * 处理错误
     * 
     * @param Exception $err 错误对象
     * @api private
     */
    public function onerror($err)
    {
        foreach ($this->sockets as $socket) {
            $socket->onerror($err);
        }
        $this->onclose('client error');
    }

    /**
     * 传输关闭时调用
     * 
     * @param string $reason 关闭原因
     * @api private
     */
    public function onclose($reason)
    {
        if (empty($this->conn)) {
            return;
        }
        // ignore a potential subsequent `close` event
        $this->destroy();

        // `nsps` and `sockets` are cleaned up seamlessly
        foreach ($this->sockets as $socket) {
            $socket->onclose($reason);
        }
        $this->sockets = null;
    }

    /**
     * 清理事件监听器
     * 
     * @api private
     */
    public function destroy()
    {
        if (! $this->conn) {
            return;
        }
        $this->conn->removeAllListeners();
        $this->decoder->removeAllListeners();
        $this->encoder->removeAllListeners();
        $this->server = $this->conn = $this->encoder = $this->decoder = $this->request = $this->nsps = null;
    }
}
