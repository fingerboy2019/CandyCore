<?php

namespace PHPSocketIO;

use Exception;

/**
 * Channel 适配器类
 * 
 * 该类继承自 DefaultAdapter，使用 Channel 组件实现跨进程通信，
 * 支持在多个进程间广播消息。
 * 
 * @package PHPSocketIO
 * @extends DefaultAdapter
 */
class ChannelAdapter extends DefaultAdapter
{
    /**
     * 通道 ID
     * @var string
     */
    protected $_channelId = null;
    
    /**
     * Channel 服务器 IP
     * @var string
     */
    public static $ip = '127.0.0.1';
    
    /**
     * Channel 服务器端口
     * @var int
     */
    public static $port = 2206;

    /**
     * 构造函数
     * 
     * @param Nsp $nsp 命名空间实例
     * @throws Exception
     */
    public function __construct($nsp)
    {
        parent::__construct($nsp);
        $this->_channelId = (function_exists('random_int') ? random_int(1, 10000000) : rand(1, 10000000)) . "-" . (function_exists('posix_getpid') ? posix_getpid() : 1);
        \Workerman\Channel\Client::connect(self::$ip, self::$port);
        \Workerman\Channel\Client::$onMessage = [$this, 'onChannelMessage'];
        \Workerman\Channel\Client::subscribe("socket.io#/#");
        Debug::debug('ChannelAdapter __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('ChannelAdapter __destruct');
    }

    /**
     * 添加客户端到房间
     * 
     * @param string $id 客户端 ID
     * @param string $room 房间名称
     */
    public function add($id, $room)
    {
        $this->sids[$id][$room] = true;
        $this->rooms[$room][$id] = true;
        $channel = "socket.io#/#$room#";
        \Workerman\Channel\Client::subscribe($channel);
    }

    /**
     * 从房间删除客户端
     * 
     * @param string $id 客户端 ID
     * @param string $room 房间名称
     */
    public function del($id, $room)
    {
        unset($this->sids[$id][$room]);
        unset($this->rooms[$room][$id]);
        if (empty($this->rooms[$room])) {
            unset($this->rooms[$room]);
            $channel = "socket.io#/#$room#";
            \Workerman\Channel\Client::unsubscribe($channel);
        }
    }

    /**
     * 从所有房间删除客户端
     * 
     * @param string $id 客户端 ID
     */
    public function delAll($id)
    {
        $rooms = isset($this->sids[$id]) ? array_keys($this->sids[$id]) : [];
        if ($rooms) {
            foreach ($rooms as $room) {
                if (isset($this->rooms[$room][$id])) {
                    unset($this->rooms[$room][$id]);
                    $channel = "socket.io#/#$room#";
                    \Workerman\Channel\Client::unsubscribe($channel);
                }
                if (isset($this->rooms[$room]) && empty($this->rooms[$room])) {
                    unset($this->rooms[$room]);
                }
            }
        }
        unset($this->sids[$id]);
    }

    /**
     * 处理来自 Channel 的消息
     * 
     * @param string $channel 通道名称
     * @param array $msg 消息内容
     */
    public function onChannelMessage($channel, $msg)
    {
        if ($this->_channelId === array_shift($msg)) {
            return;
        }

        $packet = $msg[0];

        $opts = $msg[1];

        if (! $packet) {
            echo "invalid  channel:$channel packet \n";
            return;
        }

        if (empty($packet['nsp'])) {
            $packet['nsp'] = '/';
        }

        if ($packet['nsp'] != $this->nsp->name) {
            echo "ignore different namespace {$packet['nsp']} != {$this->nsp->name}\n";
            return;
        }

        $this->broadcast($packet, $opts, true);
    }

    /**
     * 广播消息
     * 
     * @param array $packet 数据包
     * @param array $opts 选项
     * @param bool $remote 是否为远程消息
     */
    public function broadcast($packet, $opts, $remote = false)
    {
        parent::broadcast($packet, $opts);
        if (! $remote) {
            $packet['nsp'] = '/';

            if (! empty($opts['rooms'])) {
                foreach ($opts['rooms'] as $room) {
                    $chn = "socket.io#/#$room#";
                    $msg = [$this->_channelId, $packet, $opts];
                    \Workerman\Channel\Client::publish($chn, $msg);
                }
            } else {
                $chn = "socket.io#/#";
                $msg = [$this->_channelId, $packet, $opts];
                \Workerman\Channel\Client::publish($chn, $msg);
            }
        }
    }
}
