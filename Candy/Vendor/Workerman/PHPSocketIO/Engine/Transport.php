<?php

namespace PHPSocketIO\Engine;

use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Debug;

/**
 * Engine.IO 传输基类
 * 
 * 该类是所有Engine.IO传输方式的基类，定义了传输层的通用接口和行为。
 * 继承自Emitter，触发各种事件如'packet'、'close'、'error'等。
 * 
 * @package PHPSocketIO\Engine
 * @extends Emitter
 */
class Transport extends Emitter
{
    /**
     * 传输状态：opening, open, closing, closed
     * @var string
     */
    public $readyState = 'opening';
    
    /**
     * HTTP请求对象
     * @var object
     */
    public $req = null;
    
    /**
     * HTTP响应对象
     * @var object
     */
    public $res = null;
    
    /**
     * 是否应该关闭
     * @var bool|null
     */
    public $shouldClose = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        Debug::debug('Transport __construct no access !!!!');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Transport __destruct');
    }

    /**
     * 空操作方法
     * 
     * @return void
     */
    public function noop()
    {
    }

    /**
     * 处理HTTP请求
     * 
     * @param object $req HTTP请求对象
     * @return void
     */
    public function onRequest($req)
    {
        $this->req = $req;
    }

    /**
     * 关闭传输
     * 
     * @param callable|null $fn 关闭回调函数
     * @return void
     */
    public function close(?callable $fn = null): void
    {
        $this->readyState = 'closing';
        $fn = $fn ?: [$this, 'noop'];
        $this->doClose($fn);
    }

    /**
     * 处理错误
     * 
     * @param string $msg 错误消息
     * @param string $desc 错误描述
     * @return void
     */
    public function onError(string $msg, string $desc = '')
    {
        if ($this->listeners('error')) {
            $this->emit('error', "TransportError: {$desc}");
        } else {
            echo("ignored transport error $msg $desc\n");
        }
    }

    /**
     * 处理数据包
     * 
     * @param array $packet 数据包
     * @return void
     */
    public function onPacket($packet): void
    {
        $this->emit('packet', $packet);
    }

    /**
     * 处理数据
     * 
     * @param string $data 原始数据
     * @return void
     */
    public function onData($data)
    {
        $this->onPacket(Parser::decodePacket($data));
    }

    /**
     * 处理关闭事件
     * 
     * @return void
     */
    public function onClose()
    {
        $this->req = $this->res = null;
        $this->readyState = 'closed';
        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * 销毁传输对象
     * 
     * @return void
     */
    public function destroy(): void
    {
        $this->req = null;
        $this->res = null;
        $this->readyState = 'closed';
        $this->removeAllListeners();
        $this->shouldClose = null;
    }
}
