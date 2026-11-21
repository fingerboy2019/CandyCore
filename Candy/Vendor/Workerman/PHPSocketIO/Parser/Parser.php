<?php

namespace PHPSocketIO\Parser;

/**
 * Socket.IO 解析器类
 * 
 * 该类定义了Socket.IO数据包的类型常量和类型映射，用于Socket.IO通信中的数据包类型标识。
 * 
 * @package PHPSocketIO\Parser
 */
class Parser
{
    /**
     * 连接类型数据包
     * 
     * 用于客户端与服务器建立连接时发送的数据包。
     * 
     * @var int
     * @api public
     */
    const CONNECT = 0;

    /**
     * 断开连接类型数据包
     * 
     * 用于客户端与服务器断开连接时发送的数据包。
     * 
     * @var int
     * @api public
     */
    const DISCONNECT = 1;

    /**
     * 事件类型数据包
     * 
     * 用于发送事件数据的数据包，包含事件名称和事件参数。
     * 
     * @var int
     * @api public
     */
    const EVENT = 2;

    /**
     * 确认类型数据包
     * 
     * 用于确认收到事件或请求的数据包，通常包含确认ID和返回数据。
     * 
     * @var int
     * @api public
     */
    const ACK = 3;

    /**
     * 错误类型数据包
     * 
     * 用于发送错误信息的数据包。
     * 
     * @var int
     * @api public
     */
    const ERROR = 4;

    /**
     * 二进制事件类型数据包
     * 
     * 用于发送包含二进制数据的事件数据包。
     * 
     * @var int
     * @api public
     */
    const BINARY_EVENT = 5;

    /**
     * 二进制确认类型数据包
     * 
     * 用于确认收到包含二进制数据的事件或请求的数据包。
     * 
     * @var int
     * @api public
     */
    const BINARY_ACK = 6;

    /**
     * 数据包类型映射数组
     * 
     * 包含所有Socket.IO数据包类型的字符串表示，索引对应类型常量值。
     * 
     * @var array
     */
    public static $types = [
        'CONNECT',
        'DISCONNECT',
        'EVENT',
        'BINARY_EVENT',
        'ACK',
        'BINARY_ACK',
        'ERROR'
    ];
}
