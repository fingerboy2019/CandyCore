<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Stomp;

use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Timer;

/**
 * STOMP 客户端类
 * 
 * 该类实现了 STOMP (Simple Text Oriented Messaging Protocol) 客户端功能，
 * 用于与 STOMP 服务器进行通信，支持订阅、发送消息、确认消息等操作。
 * 
 * @package Workerman\Stomp
 */
#[\AllowDynamicProperties]
class Client
{
    /**
     * 初始状态
     */
    const STATE_INITIAL = 1;

    /**
     * 连接中状态
     */
    const STATE_CONNECTING = 2;

    /**
     * 等待连接确认状态
     */
    const STATE_WAITCONACK = 3;

    /**
     * 连接已建立状态
     */
    const STATE_ESTABLISHED = 4;

    /**
     * 断开连接中状态
     */
    const STATE_DISCONNECTING = 5;

    /**
     * 已断开连接状态
     */
    const STATE_DISCONNECT = 6;

    /**
     * 连接成功回调
     * @var callable
     */
    public $onConnect = null;

    /**
     * 重连成功回调
     * @var callable
     */
    public $onReconnect = null;

    /**
     * 连接关闭回调
     * @var callable
     */
    public $onClose = null;

    /**
     * 错误回调
     * @var callable
     */
    public $onError = null;

    /**
     * 连接状态
     * @var int
     */
    protected $_state = 1;

    /**
     * 远程地址
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 异步TCP连接
     * @var AsyncTcpConnection
     */
    protected $_connection = null;

    /**
     * 是否首次连接
     * @var boolean
     */
    protected $_firstConnect = true;

    /**
     * 连接超时检查定时器
     * @var int
     */
    protected $_checkConnectionTimeoutTimer = 0;

    /**
     * 心跳定时器
     * @var int
     */
    protected $_pingTimer = 0;

    /**
     * 是否收到心跳响应
     * @var bool
     */
    protected $_recvPingResponse = true;

    /**
     * 是否不重连
     * @var bool
     */
    protected $_doNotReconnect = false;

    /**
     * 订阅列表
     * @var array
     */
    protected $_subscriptions = [];

    /**
     * 配置选项
     * @var array
     */
    protected $_options = [
        'vhost'            => '/',          // 虚拟主机
        'login'            => 'guest',      // 登录用户名
        'passcode'         => 'guest',      // 登录密码
        'bindto'           => '',           // 绑定地址
        'ssl'              => [],           // SSL配置
        'connect_timeout'  => 30,           // 连接超时时间（秒）
        'reconnect_period' => 2,            // 重连间隔（秒）
        'debug'            => false,        // 是否开启调试
        'heart_beat'       => [0, 0],       // 心跳配置 [发送间隔, 接收间隔]，单位：毫秒
    ];

    /**
     * 构造函数
     * 
     * @param string $address STOMP服务器地址，格式为 "stomp://host:port"
     * @param array $options 配置选项
     */
    public function __construct($address, $options = [])
    {
        class_alias('\Workerman\Stomp\Protocols\Stomp', '\Workerman\Protocols\Stomp');
        $this->setOptions($options);
        $context = [];
        if ($this->_options['bindto']) {
            $context['socket'] = ['bindto' => $this->_options['bindto']];
        }
        if ($this->_options['ssl'] && is_array($this->_options['ssl'])) {
            $context['ssl'] = $this->_options['ssl'];
        }
        
        $this->_remoteAddress = $address;
        $this->_connection    = new AsyncTcpConnection($address, $context);
        $this->onReconnect    = [$this, 'onStompReconnect'];
        $this->onMessage      = function(){};
        if ($this->_options['ssl']) {
            $this->_connection->transport = 'ssl';
        }
    }

    /**
     * 连接到STOMP服务器
     * 
     * @return void
     */
    public function connect()
    {
        $this->_doNotReconnect           = false;
        $this->_connection->onConnect    = [$this, 'onConnectionConnect'];
        $this->_connection->onMessage    = [$this, 'onConnectionMessage'];
        $this->_connection->onError      = [$this, 'onConnectionError'];
        $this->_connection->onClose      = [$this, 'onConnectionClose'];
        $this->_connection->onBufferFull = [$this, 'onConnectionBufferFull'];
        $this->_state                    = static::STATE_CONNECTING;
        $this->_connection->connect();
        $this->setConnectionTimeout($this->_options['connect_timeout']);
        if ($this->_options['debug']) {
            echo "-> Try to connect to {$this->_remoteAddress}", PHP_EOL;
        }
        if ($this->_options['heart_beat'][0]) {
            $this->setPingTimer($this->_options['heart_beat'][0]/1000);
        }
    }

    /**
     * 订阅目标
     * 
     * @param string $destination 目标地址
     * @param callable $callback 消息回调函数
     * @param array $headers 额外的头信息
     * @return string 返回订阅ID
     */
    public function subscribe($destination, $callback, array $headers = [])
    {
        if ($this->checkDisconnecting()) {
            return false;
        }
        $raw_headers            = $headers;
        $headers['id']          = $headers['id'] ??= $this->createClientId();
        $headers['ack']         = $headers['ack'] ??= 'auto';
        $subscription           = $headers['id'];
        $headers['destination'] = $destination;

        $package = [
            'cmd'     => 'SUBSCRIBE',
            'headers' => $headers
        ];

        $this->sendPackage($package);

        $this->_subscriptions[$subscription] = [
            'ack'         => $headers['ack'],
            'callback'    => $callback,
            'headers'     => $raw_headers,
            'destination' => $destination,
        ];
        return $subscription;
    }

    /**
     * 订阅目标（带确认）
     * 
     * 该方法自动设置ack为client，需要手动确认消息。
     * 
     * @param string $destination 目标地址
     * @param callable $callback 消息回调函数
     * @param array $headers 额外的头信息
     * @return string 返回订阅ID
     */
    public function subscribeWithAck($destination, $callback, array $headers = [])
    {
        if (!isset($headers['ack']) || $headers['ack'] === 'auto') {
            $headers['ack'] = 'client';
        }
        return $this->subscribe($destination, $callback, $headers);
    }

    /**
     * 取消订阅
     * 
     * @param string $subscription 订阅ID
     * @param array $headers 额外的头信息
     * @return void
     */
    public function unsubscribe($subscription, array $headers = [])
    {
        if ($this->checkDisconnecting()) {
            return;
        }
        $default_headers = [
            'id'  => $subscription,
        ];
        $headers = array_merge($default_headers, $headers);

        $package = [
            'cmd'     => 'UNSUBSCRIBE',
            'headers' => $headers
        ];

        $this->sendPackage($package);
        unset($this->_subscriptions[$subscription]);
    }

    /**
     * 确认消息
     * 
     * @param string $subscription 订阅ID
     * @param string $message_id 消息ID
     * @param array $headers 额外的头信息
     * @return void
     */
    public function ack($subscription, $message_id, array $headers = [])
    {
        $headers['subscription'] = $subscription;
        $headers['message-id']   = $message_id;
        $this->sendPackage([
            'cmd'     => 'ACK',
            'headers' => $headers
        ]);
    }

    /**
     * 否定确认消息
     * 
     * @param string $subscription 订阅ID
     * @param string $message_id 消息ID
     * @param array $headers 额外的头信息
     * @return void
     */
    public function nack($subscription, $message_id, array $headers = [])
    {
        $headers['subscription'] = $subscription;
        $headers['message-id']   = $message_id;
        $this->sendPackage([
            'cmd'     => 'NACK',
            'headers' => $headers
        ]);
    }

    /**
     * 发送消息
     * 
     * @param string $destination 目标地址
     * @param string $body 消息内容
     * @param array $headers 额外的头信息
     * @return void
     */
    public function send($destination, $body, array $headers = [])
    {
        $headers['destination']    = $destination;
        $headers['content-length'] = strlen($body);
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = 'text/plain';
        }

        $package = [
            'cmd'     => 'SEND',
            'headers' => $headers,
            'body'    => $body
        ];

        $this->sendPackage($package);
    }

    /**
     * 断开连接
     * 
     * @return void
     */
    public function disconnect()
    {
        $this->_state = static::STATE_DISCONNECTING;
        $this->_doNotReconnect = true;
        $this->sendPackage(['cmd' => 'DISCONNECT', ['receipt' => $this->createReceiptId()]]);
    }

    /**
     * 关闭连接
     * 
     * @return void
     */
    public function close()
    {
        $this->_doNotReconnect = true;
        if ($this->_options['debug']) {
            echo "-> Connection->close() called", PHP_EOL;
        }
        $this->_connection->destroy();
    }

    /**
     * 获取连接状态
     * 
     * @return int 返回连接状态常量
     */
    public function getState()
    {
        return $this->_state;
    }

    /**
     * 重新连接
     * 
     * @param int $after 延迟重连时间（秒）
     * @return void
     */
    public function reconnect($after = 0)
    {
        $this->_doNotReconnect        = false;
        $this->_connection->onConnect = [$this, 'onConnectionConnect'];
        $this->_connection->onMessage = [$this, 'onConnectionMessage'];
        $this->_connection->onError   = [$this, 'onConnectionError'];
        $this->_connection->onClose   = [$this, 'onConnectionClose'];
        $this->_connection->reConnect($after);
        $this->setConnectionTimeout($this->_options['connect_timeout'] + $after);
        if ($this->_options['debug']) {
            echo "-- Reconnect after $after seconds", PHP_EOL;
        }
    }

    /**
     * onConnectionConnect
     */
    public function onConnectionConnect()
    {
        if ($this->_doNotReconnect) {
            $this->close();
            return;
        }
        $this->_state = static::STATE_WAITCONACK;
        if ($this->_options['debug']) {
            echo "-- Tcp connection established", PHP_EOL;
        }
        $headers = ['host' => $this->_options['vhost']];
        if ($this->_options['login'] !== null && $this->_options['passcode'] !== null) {
            $headers['login']    = $this->_options['login'];
            $headers['passcode'] = $this->_options['passcode'];
            if ($this->_options['heart_beat']) {
                $headers['heart-beat'] = implode(',', $this->_options['heart_beat']);
            }
        }
        $this->sendPackage([
            'cmd'     => 'CONNECT',
            'headers' => $headers
        ]);
    }

    /**
     * onStompReconnect
     */
    public function onStompReconnect()
    {
        foreach ($this->_subscriptions as $subscription => $item) {
            $callback = $item['callback'];
            $destination = $item['destination'];
            $headers = $item['headers'];
            $headers['id']  = $subscription;
            $headers['ack'] = $item['ack'];
            $this->subscribe($destination, $callback, $headers);
        }
        if ($this->_options['heart_beat'][0]) {
            $this->setPingTimer($this->_options['heart_beat'][0]/1000);
        }
    }

    /**
     * onConnectionMessage
     *
     * @param $connection
     * @param $data
     */
    public function onConnectionMessage($connection, $data)
    {
        if ($this->_options['debug']) {
            $this->echoDebug($data);
        }
        $cmd = $data['cmd'];
        switch ($cmd) {
            case 'CONNECTED':
                $this->_state = static::STATE_ESTABLISHED;
                $this->cancelConnectionTimeout();
                if ($this->_firstConnect) {
                    if ($this->onConnect) {
                        call_user_func($this->onConnect, $this);
                    }
                    $this->_firstConnect = false;
                } else {
                    if ($this->onReconnect) {
                        call_user_func($this->onReconnect, $this);
                    }
                }
                break;
            case 'MESSAGE':
                $headers = $data['headers'];
                $message_id = $headers['message-id'];
                $subscription = $headers['subscription'];

                if (!isset($this->_subscriptions[$subscription])) {
                    return;
                }

                $callback = $this->_subscriptions[$subscription]['callback'];

                $resolver = new AckResolver($this, $subscription, $message_id);
                if ('auto' == $this->_subscriptions[$subscription]['ack']) {
                    $resolver->done();
                }
                call_user_func($callback, $this, $data, $resolver);
                break;
            case 'ERROR':
                $exception = new Exception($data['headers']['message']);
                $exception->frame = $data;
                $this->triggerError($exception);
                break;
            case 'RECEIPT':
                if ($this->_state === static::STATE_DISCONNECTING) {
                    $this->close();
                }
                break;
            case 'HEARTBEAT':
                break;
            default:
                echo "unknown cmd $cmd\n";
        }
    }

    /**
     * @param $package
     * @param string $type
     */
    protected function echoDebug($package, $type = 'recv')
    {
        $headers = [];
        if (isset($package['headers'])) {
            foreach ($package['headers'] as $key => $value) {
                $headers[] = "$key:$value";
            }
        }
        $type = $type == 'recv' ? '<- Recv' : '-> Send';
        $body = isset($package['body']) ? $package['body'] : '';
        echo "$type {$package['cmd']} package, header[".implode(' ', $headers)."] body[$body]" . PHP_EOL;
    }

    /**
     * onConnectionClose
     */
    public function onConnectionClose()
    {
        if ($this->_options['debug']) {
            echo "-- Connection closed", PHP_EOL;
        }
        $this->cancelPingTimer();
        $this->cancelConnectionTimeout();
        $this->_recvPingResponse = true;
        $this->_state = static::STATE_DISCONNECT;
        if (!$this->_doNotReconnect && $this->_options['reconnect_period'] > 0) {
            $this->reConnect($this->_options['reconnect_period']);
        }

        if ($this->onClose) {
            call_user_func($this->onClose, $this);
        }
    }

    /**
     * onConnectionError
     *
     * @param $connection
     * @param $code
     */
    public function onConnectionError($connection, $code)
    {
        // Connection error
        if ($code === 1) {
            $this->triggerError('Connection fail');
        // Send fail, connection closed
        } else {
            $this->triggerError('Connection closed');
        }

    }

    /**
     * onConnectionBufferFull
     */
    public function onConnectionBufferFull()
    {
        if ($this->_options['debug']) {
            echo "-- Connection buffer full and close connection", PHP_EOL;
        }
        $this->triggerError('Connection buffer full and close connection');
        $this->_connection->destroy();
    }


    /**
     * triggerError
     *
     * @param $exception
     * @param $callback
     */
    protected function triggerError($exception, $callback = null)
    {
        if (is_string($exception)) {
            $exception = new Exception($exception);
        }
        if ($this->_options['debug']) {
            echo "-- Error: ".$exception->getMessage() . PHP_EOL;
        }
        if (!$callback) {
            $callback = $this->onError ? $this->onError : function ($exception) {
                echo "Stomp client: ", $exception->getMessage(), PHP_EOL;
            };
        }
        call_user_func($callback, $exception);
    }

    /**
     * createClientId
     *
     * @return string
     */
    protected function createClientId()
    {
        static $id = 0;
        $id ++;
        return 'client-' . $id;
    }

    /**
     * @return int
     */
    public function createReceiptId()
    {
        return mt_rand();
    }

    /**
     * addCheckTimeoutTimer
     */
    protected function setConnectionTimeout($timeout)
    {
        $this->cancelConnectionTimeout();
        $this->_checkConnectionTimeoutTimer = Timer::add($timeout, [$this, 'checkConnectTimeout'], null, false);
    }

    /**
     * cancelConnectionTimeout
     */
    protected function cancelConnectionTimeout()
    {
        if ($this->_checkConnectionTimeoutTimer) {
            Timer::del($this->_checkConnectionTimeoutTimer);
            $this->_checkConnectionTimeoutTimer = 0;
        }
    }

    /**
     * setPingTimer
     */
    protected function setPingTimer($ping_interval)
    {
        $this->cancelPingTimer();
        $connection = $this->_connection;
        $this->_pingTimer = Timer::add($ping_interval, function() use ($connection) {
            if ($this->_state === static::STATE_ESTABLISHED) {
                $this->sendPackage(['cmd' => 'HEARTBEAT']);
            }
        });
    }

    /**
     * cancelPingTimer
     */
    protected function cancelPingTimer()
    {
        if ($this->_pingTimer) {
            Timer::del($this->_pingTimer);
            $this->_pingTimer = 0;
        }
    }

    /**
     * checkConnectTimeout
     */
    public function checkConnectTimeout()
    {
        if ($this->_state === static::STATE_CONNECTING || $this->_state === static::STATE_WAITCONACK) {
            $this->triggerError('Connection timeout');
            $this->_connection->destroy();
        }
    }

    /**
     * checkDisconnecting
     *
     * @param null $callback
     * @return bool
     */
    protected function checkDisconnecting()
    {
        if (!in_array($this->_state, [
            static::STATE_ESTABLISHED,
            static::STATE_WAITCONACK,
            static::STATE_DISCONNECTING
        ])) {
            $this->triggerError('Connection not established');
            return true;
        }
        return false;
    }

    /**
     * sendPackage
     *
     * @param $package
     */
    protected function sendPackage($package)
    {
        if ($this->checkDisconnecting()) {
            return;
        }
        if ($this->_options['debug']) {
            $this->echoDebug($package, 'send');
        }
        $this->_connection->send($package);
    }

    /**
     * set options.
     *
     * @param $options
     * @throws \Exception
     */
    protected function setOptions($options)
    {
        if (isset($options['heart_beat']) && !is_array($options['heart_beat']))
        {
            $options['heart_beat'] = [$options['heart_beat'], 0];
        }
        $this->_options = array_merge($this->_options, $options);
    }
}
