<?php

namespace Workerman\Mqtt\Handle;

use Workerman\Mqtt\Consts\MQTTConst;

/**
 * Decoder
 * MQTT消息解码工具类，用于将MQTT协议格式的数据解码成PHP数组
 */
class Decoder
{
    /**
     * 获取命令类型
     *
     * @param string $buffer MQTT消息缓冲区
     * @return int 命令类型
     */
    public static function getCmd(string $buffer)
    {
        return ord($buffer[0]) >> 4;
    }

    /**
     * 从缓冲区读取字符串
     *
     * @param string $buffer MQTT消息缓冲区
     * @return string 读取的字符串
     */
    public static function readString(string &$buffer)
    {
        $length = unpack('n', $buffer)[1];
        if ($length + 2 > strlen($buffer)) {
            echo 'buffer:' . bin2hex($buffer) . " length:$length not enough for unpackString\n";
        }

        $string = substr($buffer, 2, $length);
        $buffer = substr($buffer, $length + 2);

        return $string;
    }

    /**
     * 从缓冲区读取短整型
     *
     * @param string $buffer MQTT消息缓冲区
     * @return mixed 读取的短整型值
     */
    public static function shortInt(string &$buffer)
    {
        $tmp = unpack('n', $buffer);
        $buffer = substr($buffer, 2);

        return $tmp[1];
    }

    /**
     * 从缓冲区读取长整型
     *
     * @param string $buffer MQTT消息缓冲区
     * @return int 读取的长整型值
     */
    public static function longInt(string &$buffer)
    {
        $tmp = unpack('N', $buffer);
        $buffer = substr($buffer, 4);

        return $tmp[1];
    }

    /**
     * 从缓冲区读取字节
     *
     * @param string $buffer MQTT消息缓冲区
     * @return int 读取的字节值
     */
    public static function byte(string &$buffer)
    {
        $tmp = ord($buffer[0]);
        $buffer = substr($buffer, 1);

        return $tmp;
    }

    /**
     * 从缓冲区读取可变长度整数
     *
     * @param string $remaining 剩余的MQTT消息
     * @param int $len 读取的长度
     * @return int 读取的可变长度整数值
     */
    public static function varInt(string&$remaining, &$len)
    {
        $body_length = static::getBodyLength($remaining, $head_bytes);
        $len = $head_bytes;

        $result = $shift = 0;
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($remaining[$i]);
            $result |= ($byte & 0x7F) << $shift++ * 7;
        }

        $remaining = substr($remaining, $head_bytes, $body_length);

        return $result;
    }

    /**
     * 获取消息体长度
     *
     * @param string $buffer MQTT消息缓冲区
     * @param int $head_bytes 消息头字节数
     * @return int 消息体长度
     */
    public static function getBodyLength(string $buffer, &$head_bytes)
    {
        $head_bytes = $multiplier = 1;
        $value = 0;
        do {
            if (!isset($buffer[$head_bytes])) {
                throw new \Exception('Malformed Remaining Length');
                //                $head_bytes = 0;
                //                return 0;
            }
            $digit = ord($buffer[$head_bytes]);
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            ++$head_bytes;
        } while (($digit & 128) != 0);

        return $value;
    }

    /**
     * 获取消息体
     *
     * @param string $buffer MQTT消息缓冲区
     * @return string 消息体
     */
    public static function getBody(string $buffer)
    {
        $body_length = static::getBodyLength($buffer, $head_bytes);

        return substr($buffer, $head_bytes, $body_length);
    }

    /**
     * 获取MQTT协议级别
     *
     * @param string $data MQTT连接消息
     * @return int MQTT协议级别
     */
    public static function getLevel(string $data)
    {
        $cmd = static::getCmd($data);

        if ($cmd !== MQTTConst::CMD_CONNECT) {
            throw new \InvalidArgumentException(
                sprintf('packet must be of type connect, cmd value equal %s given', $cmd)
            );
        }

        $remaining = static::getBody($data);
        $length = unpack('n', $remaining)[1];

        return ord($remaining[$length + 2]);
    }
}
