<?php

namespace Workerman\Mqtt\Handle;

/**
 * Encoder
 * MQTT消息编码工具类，用于将数据打包成MQTT协议格式
 */
class Encoder
{
    /**
     * 打包字符串
     *
     * @param string $str 要打包的字符串
     * @return string 打包后的字符串
     */
    public static function packString(string $str): string
    {
        $len = strlen($str);

        return pack('n', $len) . $str;
    }

    /**
     * 打包字符串键值对
     *
     * @param string $key 键
     * @param string $value 值
     * @return string 打包后的字符串键值对
     */
    public static function stringPair(string $key, string $value): string
    {
        return static::packString($key) . static::packString($value);
    }

    /**
     * 打包长整型
     *
     * @param int $int 要打包的长整型值
     * @return string 打包后的长整型
     */
    public static function longInt(int $int): string
    {
        return pack('N', $int);
    }

    /**
     * 打包短整型
     *
     * @param int $int 要打包的短整型值
     * @return string 打包后的短整型
     */
    public static function shortInt(int $int): string
    {
        return pack('n', $int);
    }

    /**
     * 打包可变长度整数
     *
     * @param int $int 要打包的整数
     * @return string 打包后的可变长度整数
     */
    public static function varInt(int $int): string
    {
        return static::writeBodyLength($int);
    }

    /**
     * 打包消息头
     *
     * @param int $cmd 命令类型
     * @param int $body_length 消息体长度
     * @param int $dup 重复标志
     * @param int $qos 服务质量
     * @param int $retain 保留标志
     * @return string 打包后的消息头
     */
    public static function packHead(
        int $cmd,
        int $body_length,
        int $dup = 0,
        int $qos = 0,
        int $retain = 0
    ): string {
        $cmd = $cmd << 4;
        if ($dup) {
            $cmd |= 1 << 3;
        }
        if ($qos) {
            $cmd |= $qos << 1;
        }
        if ($retain) {
            $cmd |= 1;
        }

        return chr($cmd) . static::writeBodyLength($body_length);
    }

    /**
     * 写入消息体长度
     *
     * @param int $len 消息体长度
     * @return string 编码后的长度
     */
    protected static function writeBodyLength(int$len): string
    {
        $string = '';
        do {
            $digit = $len % 128;
            $len = $len >> 7;
            // if there are more digits to encode, set the top bit of this digit
            if ($len > 0) {
                $digit = ($digit | 0x80);
            }
            $string .= chr($digit);
        } while ($len > 0);

        return $string;
    }
}
