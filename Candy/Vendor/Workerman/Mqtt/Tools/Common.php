<?php

namespace Workerman\Mqtt\Tools;

/**
 * Common
 * MQTT通用工具类，提供了一些静态方法来调试MQTT消息
 *
 * @method static string hexDump(string $encode) 以16进制显示
 * @method static string hexDumpAscii(string $encode) 以16进制和相应的ASCII字符显示
 * @method static string printableText(string $encode) 可打印字符
 * @method static string hexStream(string $encode) 16进制流
 * @method static string ascii(string $encode) 以ASCII字符显示
 */
abstract class Common
{
    /**
     * 格式化打印数据
     *
     * @param string $data 要打印的数据
     */
    public static function printf(string $data)
    {
        echo "\033[36m";
        for ($i = 0, $iMax = strlen($data); $i < $iMax; $i++) {
            $ascii = ord($data[$i]);
            if ($ascii > 31) {
                $chr = $data[$i];
            } else {
                $chr = ' ';
            }
            printf("%4d: %08b : 0x%02x : %d : %s\n", $i, $ascii, $ascii, $ascii, $chr);
        }
        echo "\033[0m";
    }

    /**
     * 静态魔术方法，用于调用Debug类的方法
     *
     * @param string $method 方法名
     * @param array $arguments 方法参数
     * @return mixed 方法返回值
     */
    public static function __callStatic($method, $arguments)
    {
        return (new Debug())->setEncode(...$arguments)->{$method}();
    }
}
