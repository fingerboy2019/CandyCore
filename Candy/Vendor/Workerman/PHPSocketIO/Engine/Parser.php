<?php

namespace PHPSocketIO\Engine;

use Exception;
use PHPSocketIO\Debug;

/**
 * Engine.IO 解析器类
 * 
 * 该类负责编码和解码Engine.IO协议的数据包，支持文本和二进制格式。
 * 
 * @package PHPSocketIO\Engine
 */
class Parser
{
    /**
     * 数据包类型映射
     * @var array
     */
    public static $packets = [
        'open' => 0,     // non-ws
        'close' => 1,    // non-ws
        'ping' => 2,
        'pong' => 3,
        'message' => 4,
        'upgrade' => 5,
        'noop' => 6,
    ];

    /**
     * 数据包类型列表
     * @var array
     */
    public static $packetsList = [
        'open',
        'close',
        'ping',
        'pong',
        'message',
        'upgrade',
        'noop'
    ];

    /**
     * 错误数据包
     * @var array
     */
    public static $err = [
        'type' => 'error',
        'data' => 'parser error'
    ];

    /**
     * 构造函数
     */
    public function __construct()
    {
        Debug::debug('Engine/Parser __construct');
    }

    /**
     * 编码单个数据包
     * 
     * @param array $packet 数据包数组
     * @return string 编码后的字符串
     */
    public static function encodePacket($packet): string
    {
        $data = ! isset($packet['data']) ? '' : $packet['data'];
        return self::$packets[$packet['type']] . $data;
    }

    /**
     * 解码单个数据包
     * 
     * @param string $data 要解码的数据
     * @return array 解码后的数据包数组
     */
    public static function decodePacket(string $data): array
    {
        if ($data[0] === 'b') {
            return self::decodeBase64Packet(substr($data, 1));
        }

        $type = $data[0];
        if (! isset(self::$packetsList[$type])) {
            return self::$err;
        }

        if (isset($data[1])) {
            return ['type' => self::$packetsList[$type], 'data' => substr($data, 1)];
        } else {
            return ['type' => self::$packetsList[$type]];
        }
    }

    /**
     * 解码Base64编码的数据包
     * 
     * @param string $msg Base64编码的数据
     * @return array 解码后的数据包数组
     */
    public static function decodeBase64Packet($msg): array
    {
        $type = self::$packetsList[$msg[0]];
        $data = base64_decode(substr($msg, 1));
        return ['type' => $type, 'data' => $data];
    }

    /**
     * 编码多个消息（负载）
     * 
     * 格式：<length>:data
     * 示例：11:hello world2:hi
     * 
     * 如果内容包含二进制数据，将被编码为Base64字符串
     * 
     * @param array $packets 数据包数组
     * @param bool|null $supportsBinary 是否支持二进制
     * @return string 编码后的负载字符串
     */
    public static function encodePayload($packets, $supportsBinary = null): string
    {
        if ($supportsBinary) {
            return self::encodePayloadAsBinary($packets);
        }

        if (! $packets) {
            return '0:';
        }

        $results = '';
        foreach ($packets as $msg) {
            $results .= self::encodeOne($msg);
        }
        return $results;
    }

    /**
     * 编码单个消息
     * 
     * @param array $packet 数据包数组
     * @return string 编码后的字符串
     */
    public static function encodeOne($packet): string
    {
        $message = self::encodePacket($packet);
        return strlen($message) . ':' . $message;
    }

    /**
     * 解码负载数据
     * 
     * 可能包含二进制内容，这些内容会从Base64表示中解码
     * 
     * @param string $data 要解码的负载数据
     * @param mixed $binaryType 二进制类型
     * @return array|string[] 解码后的数据包数组
     */
    public static function decodePayload($data, $binaryType = null)
    {
        if (! preg_match('/^\d+:\d/', $data)) {
            return self::decodePayloadAsBinary($data, $binaryType);
        }

        if ($data === '') {
            // 解析错误 - 忽略负载
            return self::$err;
        }

        $length = '';//, n, msg;

        for ($i = 0, $l = strlen($data); $i < $l; $i++) {
            $chr = $data[$i];

            if (':' != $chr) {
                $length .= $chr;
            } else {
                if ('' == $length || ($length != ($n = intval($length)))) {
                    // 解析错误 - 忽略负载
                    return self::$err;
                }

                $msg = substr($data, $i + 1);

                if (isset($msg[0])) {
                    $packet = self::decodePacket($msg);

                    if (self::$err['type'] == $packet['type'] && self::$err['data'] == $packet['data']) {
                        // 单个数据包解析错误 - 忽略负载
                        return self::$err;
                    }

                    return $packet;
                }

                // 移动游标
                $i += $n;
                $length = '';
            }
        }

        if ($length !== '') {
            // 解析错误 - 忽略负载
            echo new Exception('parser error');
            return self::$err;
        }
    }

    /**
     * 以二进制格式编码多个消息（负载）
     * 
     * 格式：<1 = 二进制, 0 = 字符串><number from 0-9><number from 0-9>[...]<number 255><data>
     * 示例：1 3 255 1 2 3（如果二进制内容被解释为8位整数）
     * 
     * @param array $packets 数据包数组
     * @return string 编码后的二进制负载
     */
    public static function encodePayloadAsBinary($packets): string
    {
        $results = '';
        foreach ($packets as $msg) {
            $results .= self::encodeOneAsBinary($msg);
        }
        return $results;
    }

    /**
     * 以二进制格式编码单个消息
     * 
     * @param array $p 数据包数组
     * @return string 编码后的二进制字符串
     */
    public static function encodeOneAsBinary($p): string
    {
        $packet = self::encodePacket($p);
        $encodingLength = '' . strlen($packet);
        $sizeBuffer = chr(0);
        for ($i = 0; $i < strlen($encodingLength); $i++) {
            $sizeBuffer .= chr($encodingLength[$i]);
        }
        $sizeBuffer .= chr(255);
        return $sizeBuffer . $packet;
    }

    /**
     * 解码二进制负载数据
     * 
     * 字符串通过将每个字节解释为标记为以0开头的条目的键码来解码
     * 
     * @param string $data 要解码的二进制负载数据
     * @param mixed $binaryType 二进制类型
     * @return array 解码后的数据包数组
     */
    public static function decodePayloadAsBinary($data, $binaryType = null): array
    {
        $bufferTail = $data;
        $buffers = [];

        while (strlen($bufferTail) > 0) {
            $strLen = '';
            $numberTooLong = false;
            for ($i = 1;; $i++) {
                $tail = ord($bufferTail[$i]);
                if ($tail === 255) {
                    break;
                }
                // 310 = Number.MAX_VALUE的字符长度
                if (strlen($strLen) > 310) {
                    $numberTooLong = true;
                    break;
                }
                $strLen .= $tail;
            }
            if ($numberTooLong) {
                return self::$err;
            }
            $bufferTail = substr($bufferTail, strlen($strLen) + 1);

            $msgLength = intval($strLen);

            $msg = substr($bufferTail, 1, $msgLength + 1);
            $buffers[] = $msg;
            $bufferTail = substr($bufferTail, $msgLength + 1);
        }
        $packets = [];
        foreach ($buffers as $i => $buffer) {
            $packets[] = self::decodePacket($buffer);
        }
        return $packets;
    }
}
