<?php

namespace PHPSocketIO\Engine\Transports;

use PHPSocketIO\Engine\Transport;
use PHPSocketIO\Engine\Parser;

/**
 * 轮询传输类
 *
 * 该类实现了基于HTTP长轮询的传输机制，用于不支持WebSocket的环境
 * 或作为WebSocket连接建立前的备选方案。
 *
 * @package PHPSocketIO\Engine\Transports
 */
class Polling extends Transport
{
    /**
     * 传输名称
     * @var string
     */
    public $name = 'polling';
    
    /**
     * 接收到的数据块
     * @var string
     */
    public $chunks = '';
    
    /**
     * 关闭回调函数
     * @var callable|null
     */
    public $shouldClose = null;
    
    /**
     * 是否可写入
     * @var bool
     */
    public $writable = false;
    
    /**
     * 是否支持二进制数据
     * @var bool|null
     */
    public $supportsBinary = null;
    
    /**
     * 数据响应对象
     * @var object|null
     */
    public $dataRes = null;
    
    /**
     * 数据请求对象
     * @var object|null
     */
    public $dataReq = null;

    /**
     * 处理HTTP请求
     *
     * 根据请求方法分发到不同的处理函数
     *
     * @param object $req HTTP请求对象
     * @return void
     */
    public function onRequest($req)
    {
        $res = $req->res;

        if ('GET' === $req->method) {
            $this->onPollRequest($req, $res);
        } elseif ('POST' === $req->method) {
            $this->onDataRequest($req, $res);
        } else {
            $res->writeHead(500);
            $res->end();
        }
    }

    /**
     * 处理轮询请求
     *
     * 处理客户端的GET轮询请求，用于接收服务器发送的数据
     *
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @return void
     */
    public function onPollRequest(object $req, object $res): void
    {
        if ($this->req) {
            $this->onError('overlap from client');
            $res->writeHead(500);
            return;
        }

        $this->req = $req;
        $this->res = $res;

        $req->onClose = [$this, 'pollRequestOnClose'];
        $req->cleanup = [$this, 'pollRequestClean'];

        $this->writable = true;
        $this->emit('drain');

        if ($this->writable && $this->shouldClose) {
            echo('triggering empty send to append close packet');
            $this->send([['type' => 'noop']]);
        }
    }

    /**
     * 轮询请求关闭回调
     *
     * 当轮询请求被关闭时调用
     *
     * @return void
     */
    public function pollRequestOnClose(): void
    {
        $this->onError('poll connection closed prematurely');
        $this->pollRequestClean();
    }

    /**
     * 清理轮询请求资源
     *
     * 释放轮询请求相关的资源
     *
     * @return void
     */
    public function pollRequestClean(): void
    {
        if (isset($this->req)) {
            $this->req = null;
            $this->res = null;
        }
    }

    /**
     * 处理数据请求
     *
     * 处理客户端的POST请求，用于接收客户端发送的数据
     *
     * @param object $req HTTP请求对象
     * @param object $res HTTP响应对象
     * @return void
     */
    public function onDataRequest($req, $res): void
    {
        if (isset($this->dataReq)) {
            $this->onError('data request overlap from client');
            $res->writeHead(500);
            return;
        }

        $this->dataReq = $req;
        $this->dataRes = $res;
        $req->onClose = [$this, 'dataRequestOnClose'];
        $req->onData = [$this, 'dataRequestOnData'];
        $req->onEnd = [$this, 'dataRequestOnEnd'];
    }

    /**
     * 清理数据请求资源
     *
     * 释放数据请求相关的资源
     *
     * @return void
     */
    public function dataRequestCleanup(): void
    {
        $this->chunks = '';
        $this->dataReq = null;
        $this->dataRes = null;
    }

    /**
     * 数据请求关闭回调
     *
     * 当数据请求被关闭时调用
     *
     * @return void
     */
    public function dataRequestOnClose(): void
    {
        $this->dataRequestCleanup();
        $this->onError('data request connection closed prematurely');
    }

    /**
     * 数据请求数据回调
     *
     * 当接收到数据请求的数据时调用
     *
     * @param object $req HTTP请求对象
     * @param string $data 接收到的数据
     * @return void
     */
    public function dataRequestOnData($req, $data): void
    {
        $this->chunks .= $data;
    }

    /**
     * 数据请求结束回调
     *
     * 当数据请求结束时调用，处理完整的数据
     *
     * @return void
     */
    public function dataRequestOnEnd(): void
    {
        $this->onData($this->chunks);

        $headers = [
            'Content-Type' => 'text/html',
            'Content-Length' => 2,
            'X-XSS-Protection' => '0',
        ];

        $this->dataRes->writeHead(200, '', $this->headers($this->dataReq, $headers));
        $this->dataRes->end('ok');
        $this->dataRequestCleanup();
    }

    /**
     * 处理接收到的数据
     *
     * 解析并处理客户端发送的数据
     *
     * @param string $data 接收到的数据
     * @return bool|null 关闭连接时返回false，否则返回null
     */
    public function onData($data)
    {
        $packets = Parser::decodePayload($data);
        if (isset($packets['type'])) {
            if ('close' === $packets['type']) {
                $this->onClose();
                return false;
            } else {
                $packets = [$packets];
            }
        }

        foreach ($packets as $packet) {
            $this->onPacket($packet);
        }
    }

    /**
     * 关闭连接
     *
     * 处理连接关闭逻辑
     *
     * @return void
     */
    public function onClose()
    {
        if ($this->writable) {
            $this->send([['type' => 'noop']]);
        }
        parent::onClose();
    }

    /**
     * 发送数据包
     *
     * 将数据包编码并发送给客户端
     *
     * @param array $packets 数据包数组
     * @return void
     */
    public function send($packets): void
    {
        $this->writable = false;
        if ($this->shouldClose) {
            echo('appending close packet to payload');
            $packets[] = ['type' => 'close'];
            call_user_func($this->shouldClose);
            $this->shouldClose = null;
        }
        $data = Parser::encodePayload($packets, $this->supportsBinary);
        $this->write($data);
    }

    /**
     * 写入数据
     *
     * 将数据写入到响应中
     *
     * @param string $data 要写入的数据
     * @return void
     */
    public function write($data): void
    {
        $this->doWrite($data);
        if (! empty($this->req->cleanup)) {
            call_user_func($this->req->cleanup);
        }
    }

    /**
     * 执行关闭操作
     *
     * 处理连接关闭逻辑
     *
     * @param callable $fn 关闭回调函数
     * @return void
     */
    public function doClose(callable $fn): void
    {
        if (! empty($this->dataReq)) {
            $this->dataReq->destroy();
        }

        if ($this->writable) {
            $this->send([['type' => 'close']]);
            call_user_func($fn);
        } else {
            $this->shouldClose = $fn;
        }
    }
}
