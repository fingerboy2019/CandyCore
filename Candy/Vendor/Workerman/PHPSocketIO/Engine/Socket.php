<?php

namespace PHPSocketIO\Engine;

use PHPSocketIO\Event\Emitter;
use Workerman\Timer;
use PHPSocketIO\Debug;

/**
 * Engine.IO Socket 类
 * 
 * 该类负责处理单个Engine.IO连接，管理连接状态、数据包传输、ping/pong心跳检测、
 * 传输升级等功能。继承自Emitter，触发各种事件如'open'、'close'、'message'等。
 * 
 * @package PHPSocketIO\Engine
 * @extends Emitter
 */
class Socket extends Emitter
{
    /**
     * Socket ID
     * @var string
     */
    public $id = 0;
    
    /**
     * Engine.IO服务器实例
     * @var Engine
     */
    public $server = null;
    
    /**
     * 是否正在升级传输方式
     * @var bool
     */
    public $upgrading = false;
    
    /**
     * 是否已升级传输方式
     * @var bool
     */
    public $upgraded = false;
    
    /**
     * 连接状态：opening, open, closing, closed
     * @var string
     */
    public $readyState = 'opening';
    
    /**
     * 写缓冲区
     * @var array
     */
    public $writeBuffer = [];
    
    /**
     * 数据包回调函数列表
     * @var array
     */
    public $packetsFn = [];
    
    /**
     * 已发送回调函数列表
     * @var array
     */
    public $sentCallbackFn = [];
    
    /**
     * HTTP请求对象
     * @var object
     */
    public $request = null;
    
    /**
     * 远程地址
     * @var string
     */
    public $remoteAddress = '';
    
    /**
     * 检查间隔定时器
     * @var mixed
     */
    public $checkIntervalTimer;
    
    /**
     * 升级超时定时器
     * @var mixed
     */
    public $upgradeTimeoutTimer = null;
    
    /**
     * Ping超时定时器
     * @var mixed
     */
    public $pingTimeoutTimer = null;
    
    /**
     * 升级传输对象
     * @var object
     */
    public $upgradeTransport = null;
    
    /**
     * 当前传输对象
     * @var object
     */
    public $transport = null;

    /**
     * 构造函数
     * 
     * @param string $id Socket ID
     * @param Engine $server Engine.IO服务器实例
     * @param object $transport 传输对象
     * @param object $req HTTP请求对象
     */
    public function __construct($id, $server, $transport, $req)
    {
        $this->id = $id;
        $this->server = $server;
        $this->request = $req;
        $this->remoteAddress = $req->connection->getRemoteIp() . ':' . $req->connection->getRemotePort();
        $this->setTransport($transport);
        $this->onOpen();
        Debug::debug('Engine/Socket __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('Engine/Socket __destruct');
    }

    /**
     * 尝试升级传输方式
     * 
     * @param object $transport 新的传输对象
     * @return void
     */
    public function maybeUpgrade(object $transport): void
    {
        $this->upgrading = true;
        $this->upgradeTimeoutTimer = Timer::add(
            $this->server->upgradeTimeout,
            [$this, 'upgradeTimeoutCallback'],
            [$transport],
            false
        );
        $this->upgradeTransport = $transport;
        $transport->on('packet', [$this, 'onUpgradePacket']);
        $transport->once('close', [$this, 'onUpgradeTransportClose']);
        $transport->once('error', [$this, 'onUpgradeTransportError']);
        $this->once('close', [$this, 'onUpgradeTransportClose']);
    }

    /**
     * 处理升级数据包
     * 
     * @param array $packet 数据包
     * @return void
     */
    public function onUpgradePacket(array $packet): void
    {
        if (empty($this->upgradeTransport)) {
            $this->onError('upgradeTransport empty');
            return;
        }
        if ('ping' === $packet['type'] && (isset($packet['data']) && 'probe' === $packet['data'])) {
            $this->upgradeTransport->send([['type' => 'pong', 'data' => 'probe']]);
            if ($this->checkIntervalTimer) {
                Timer::del($this->checkIntervalTimer);
            }
            $this->checkIntervalTimer = Timer::add(0.5, [$this, 'check']);
        } elseif ('upgrade' === $packet['type'] && $this->readyState !== 'closed') {
            $this->upgradeCleanup();
            $this->upgraded = true;
            $this->clearTransport();
            $this->transport->destroy();
            $this->setTransport($this->upgradeTransport);
            $this->emit('upgrade', $this->upgradeTransport);
            $this->upgradeTransport = null;
            $this->setPingTimeout();
            $this->flush();
            if ($this->readyState === 'closing') {
                $this->transport->close([$this, 'onClose']);
            }
        } else {
            $this->upgradeCleanup();
            $this->upgradeTransport->close();
            $this->upgradeTransport = null;
        }
    }

    /**
     * 清理升级相关资源
     * 
     * @return void
     */
    public function upgradeCleanup(): void
    {
        $this->upgrading = false;
        Timer::del($this->checkIntervalTimer);
        Timer::del($this->upgradeTimeoutTimer);
        if (! empty($this->upgradeTransport)) {
            $this->upgradeTransport->removeListener('packet', [$this, 'onUpgradePacket']);
            $this->upgradeTransport->removeListener('close', [$this, 'onUpgradeTransportClose']);
            $this->upgradeTransport->removeListener('error', [$this, 'onUpgradeTransportError']);
        }
        $this->removeListener('close', [$this, 'onUpgradeTransportClose']);
    }

    /**
     * 处理升级传输关闭
     * 
     * @return void
     */
    public function onUpgradeTransportClose(): void
    {
        $this->onUpgradeTransportError('transport closed');
    }

    /**
     * 处理升级传输错误
     * 
     * @param mixed $err 错误信息
     * @return void
     */
    public function onUpgradeTransportError($err): void
    {
        $this->upgradeCleanup();
        if ($this->upgradeTransport) {
            $this->upgradeTransport->close();
            $this->upgradeTransport = null;
        }
    }

    /**
     * 升级超时回调
     * 
     * @param object $transport 传输对象
     * @return void
     */
    public function upgradeTimeoutCallback(object $transport): void
    {
        $this->upgradeCleanup();
        if ('open' === $transport->readyState) {
            $transport->close();
        }
    }

    /**
     * 设置传输对象
     * 
     * @param object $transport 传输对象
     * @return void
     */
    public function setTransport(object $transport)
    {
        $this->transport = $transport;
        $this->transport->once('error', [$this, 'onError']);
        $this->transport->on('packet', [$this, 'onPacket']);
        $this->transport->on('drain', [$this, 'flush']);
        $this->transport->once('close', [$this, 'onClose']);
        // 这个函数将管理数据包事件（也包括消息回调）
        $this->setupSendCallback();
    }

    /**
     * 处理连接打开
     * 
     * @return void
     */
    public function onOpen(): void
    {
        $this->readyState = 'open';

        $this->transport->sid = $this->id;
        $this->sendPacket(
            'open',
            json_encode(
                [
                    'sid' => $this->id,
                    'upgrades' => $this->getAvailableUpgrades(),
                    'pingInterval' => $this->server->pingInterval * 1000,
                    'pingTimeout' => $this->server->pingTimeout * 1000
                ]
            )
        );

        $this->emit('open');
        $this->setPingTimeout();
    }

    /**
     * 处理收到的数据包
     * 
     * @param array $packet 数据包
     * @return void
     */
    public function onPacket(array $packet)
    {
        if ('open' === $this->readyState) {
            // 触发packet事件
            $this->emit('packet', $packet);

            // 收到任何数据包时重置ping超时，传入数据是对方存活的好迹象
            $this->setPingTimeout();
            switch ($packet['type']) {
                case 'ping':
                    $this->sendPacket('pong');
                    $this->emit('heartbeat');
                case 'error':
                    $this->onClose('parse error');
                case 'message':
                    $this->emit('data', $packet['data']);
                    $this->emit('message', $packet['data']);
                    break;
                default:
            }
        } else {
            echo('packet received with closed socket');
        }
    }

    /**
     * 检查连接状态
     * 
     * @return void
     */
    public function check(): void
    {
        if ('polling' == $this->transport->name && $this->transport->writable) {
            $this->transport->send([['type' => 'noop']]);
        }
    }

    /**
     * 处理错误
     * 
     * @param mixed $err 错误信息
     * @return void
     */
    public function onError($err): void
    {
        $this->onClose('transport error', $err);
    }

    /**
     * 设置Ping超时
     * 
     * @return void
     */
    public function setPingTimeout(): void
    {
        if ($this->pingTimeoutTimer) {
            Timer::del($this->pingTimeoutTimer);
        }
        $this->pingTimeoutTimer = Timer::add(
            $this->server->pingInterval + $this->server->pingTimeout,
            [$this, 'pingTimeoutCallback'],
            null,
            false
        );
    }

    /**
     * Ping超时回调
     * 
     * @return void
     */
    public function pingTimeoutCallback(): void
    {
        $this->transport->close();
        $this->onClose('ping timeout');
    }

    /**
     * 清理传输对象
     * 
     * @return void
     */
    public function clearTransport(): void
    {
        $this->transport->close();
        Timer::del($this->pingTimeoutTimer);
    }

    /**
     * 处理连接关闭
     * 
     * @param string $reason 关闭原因
     * @param string|null $description 关闭描述
     * @return void
     */
    public function onClose(string $reason = '', ?string $description = null): void
    {
        if ('closed' !== $this->readyState) {
            Timer::del($this->pingTimeoutTimer);

            if (! empty($this->checkIntervalTimer)) {
                Timer::del($this->checkIntervalTimer);
            }

            $this->checkIntervalTimer = null;

            if (! empty($this->checkIntervalTimer)) {
                Timer::del($this->upgradeTimeoutTimer);
            }

            // 在下一个tick清理writeBuffer，以便开发者在'close'事件中仍能获取writeBuffer
            $this->writeBuffer = [];
            $this->packetsFn = [];
            $this->sentCallbackFn = [];
            $this->clearTransport();
            $this->readyState = 'closed';
            $this->emit('close', $this->id, $reason, $description);
            $this->server = null;
            $this->request = null;
            $this->upgradeTransport = null;
            $this->removeAllListeners();
            if (! empty($this->transport)) {
                $this->transport->removeAllListeners();
                $this->transport = null;
            }
        }
    }

    /**
     * 发送消息
     * 
     * @param mixed $data 消息数据
     * @param array $options 选项
     * @param callable|null $callback 回调函数
     * @return Socket 返回当前实例，支持链式调用
     */
    public function send($data, $options, ?callable $callback): Socket
    {
        $this->sendPacket('message', $data, $callback);
        return $this;
    }

    /**
     * 写入数据（send方法的别名）
     * 
     * @param mixed $data 消息数据
     * @param array|null $options 选项
     * @param callable|null $callback 回调函数
     * @return Socket 返回当前实例，支持链式调用
     */
    public function write($data, ?array $options = [], ?callable $callback = null): Socket
    {
        return $this->send($data, $options, $callback);
    }

    /**
     * 发送数据包
     * 
     * @param string $type 数据包类型
     * @param mixed|null $data 数据
     * @param callable|null $callback 回调函数
     * @return void
     */
    public function sendPacket(string $type, $data = null, $callback = null): void
    {
        if ('closing' !== $this->readyState) {
            $packet = [
                'type' => $type
            ];
            if ($data !== null) {
                $packet['data'] = $data;
            }
            // 触发packetCreate事件
            $this->emit('packetCreate', $packet);
            $this->writeBuffer[] = $packet;
            // 添加发送回调
            if ($callback) {
                $this->packetsFn[] = $callback;
            }
            $this->flush();
        }
    }

    /**
     * 刷新写缓冲区
     * 
     * @return void
     */
    public function flush(): void
    {
        if ('closed' !== $this->readyState && $this->transport->writable
            && $this->writeBuffer
        ) {
            $this->emit('flush', $this->writeBuffer);
            $this->server->emit('flush', $this, $this->writeBuffer);
            $wbuf = $this->writeBuffer;
            $this->writeBuffer = [];
            if ($this->packetsFn) {
                if (! empty($this->transport->supportsFraming)) {
                    $this->sentCallbackFn[] = $this->packetsFn;
                } else {
                    // @todo check
                    $this->sentCallbackFn[] = $this->packetsFn;
                }
            }
            $this->packetsFn = [];
            $this->transport->send($wbuf);
            $this->emit('drain');
            if ($this->server) {
                $this->server->emit('drain', $this);
            }
        }
    }

    /**
     * 获取可用的升级方式
     * 
     * @return array 可用的升级方式列表
     */
    public function getAvailableUpgrades(): array
    {
        return ['websocket'];
    }

    /**
     * 关闭连接
     * 
     * @return void
     */
    public function close(): void
    {
        if ('open' !== $this->readyState) {
            return;
        }

        $this->readyState = 'closing';

        if ($this->writeBuffer) {
            $this->once('drain', [$this, 'closeTransport']);
            return;
        }

        $this->closeTransport();
    }

    /**
     * 关闭传输
     * 
     * @return void
     */
    public function closeTransport(): void
    {
        $this->transport->close([$this, 'onClose']);
    }

    /**
     * 设置发送回调
     * 
     * @return void
     */
    public function setupSendCallback(): void
    {
        // 消息发送成功后执行回调
        $this->transport->on('drain', [$this, 'onDrainCallback']);
    }

    /**
     * 处理drain回调
     * 
     * @return void
     */
    public function onDrainCallback(): void
    {
        if ($this->sentCallbackFn) {
            $seqFn = array_shift($this->sentCallbackFn);
            if (is_callable($seqFn)) {
                echo('executing send callback');
                call_user_func($seqFn, $this->transport);
            } elseif (is_array($seqFn)) {
                echo('executing batch send callback');
                foreach ($seqFn as $fn) {
                    call_user_func($fn, $this->transport);
                }
            }
        }
    }
}
