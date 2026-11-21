<?php

namespace PHPSocketIO;

/**
 * 默认适配器类
 * 
 * 该类负责管理 Socket.IO 中的房间和客户端连接，提供添加、删除和广播等功能。
 * 
 * @package PHPSocketIO
 */
class DefaultAdapter
{
    /**
     * 命名空间实例
     * @var Nsp
     */
    public $nsp = null;
    
    /**
     * 房间映射
     * @var array
     */
    public $rooms = [];
    
    /**
     * 会话 ID 映射
     * @var array
     */
    public $sids = [];
    
    /**
     * 编码器实例
     * @var Parser\Encoder
     */
    public $encoder = null;

    /**
     * 构造函数
     * 
     * @param Nsp $nsp 命名空间实例
     */
    public function __construct($nsp)
    {
        $this->nsp = $nsp;
        $this->encoder = new Parser\Encoder();
        Debug::debug('DefaultAdapter __construct');
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        Debug::debug('DefaultAdapter __destruct');
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
        }
    }

    /**
     * 从所有房间删除客户端
     * 
     * @param string $id 客户端 ID
     */
    public function delAll($id)
    {
        $rooms = array_keys($this->sids[$id] ?? []);
        foreach ($rooms as $room) {
            $this->del($id, $room);
        }
        unset($this->sids[$id]);
    }

    /**
     * 广播消息
     * 
     * @param array $packet 数据包
     * @param array $opts 选项
     * @param bool $remote 是否远程广播
     */
    public function broadcast($packet, $opts, $remote = false)
    {
        $rooms = $opts['rooms'] ?? [];
        $except = $opts['except'] ?? [];
        $flags = $opts['flags'] ?? [];
        $packetOpts = [
            'preEncoded' => true,
            'volatile' => $flags['volatile'] ?? null,
            'compress' => $flags['compress'] ?? null
        ];
        $packet['nsp'] = $this->nsp->name;
        $encodedPackets = $this->encoder->encode($packet);
        if ($rooms) {
            $ids = [];
            foreach ($rooms as $i => $room) {
                if (! isset($this->rooms[$room])) {
                    continue;
                }

                $room = $this->rooms[$room];
                foreach ($room as $id => $item) {
                    if (isset($ids[$id]) || isset($except[$id])) {
                        continue;
                    }
                    if (isset($this->nsp->connected[$id])) {
                        $ids[$id] = true;
                        $this->nsp->connected[$id]->packet($encodedPackets, $packetOpts);
                    }
                }
            }
        } else {
            foreach ($this->sids as $id => $sid) {
                if (isset($except[$id])) {
                    continue;
                }
                if (isset($this->nsp->connected[$id])) {
                    $socket = $this->nsp->connected[$id];
                    $volatile = $flags['volatile'] ?? null;
                    $socket->packet($encodedPackets, true, $volatile);
                }
            }
        }
    }

    /**
     * 获取房间中的客户端
     * 
     * @param array $rooms 房间列表
     * @param callable $fn 回调函数
     */
    public function clients($rooms, $fn)
    {
        $sids = [];
        foreach ($rooms as $room) {
            $sids = array_merge($sids, $this->rooms[$room]);
        }
        $fn();
    }
}
