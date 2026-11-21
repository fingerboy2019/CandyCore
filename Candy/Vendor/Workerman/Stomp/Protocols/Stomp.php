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
namespace Workerman\Stomp\Protocols;

/**
 * STOMP 协议类
 * 
 * 该类实现了 STOMP (Simple Text Oriented Messaging Protocol) 协议的编码和解码功能，
 * 用于 Workerman 框架处理 STOMP 协议数据。
 * 
 * @package Workerman\Stomp\Protocols
 * @author walkor<walkor@workerman.net>
 */
#[\AllowDynamicProperties]
class Stomp
{
    /**
     * 检查包的完整性
     * 
     * 该方法用于 Workerman 框架判断数据是否完整接收，返回完整包的长度。
     * 
     * @param string $buffer 接收到的数据缓冲区
     * @return int 返回包的完整长度，0 表示需要继续接收数据
     */
    public static function input($buffer)
    {
        if ($buffer[0] == "\n" || $buffer[0] == "\x00") {
            return 1;
        }
        $pos = strpos($buffer, "\n\n");
        if (false === $pos) {
            return 0;
        }
        $head = substr($buffer, 0, $pos);
        $header_data = explode("\n", trim($head, "\n"));
        $command = $header_data[0];
        if (!in_array($command, ['SEND', 'MESSAGE', 'ERROR'])) {
            return $pos + 3;
        }
        unset($header_data[0]);
        foreach ($header_data as $line) {
            [$key, $value] = explode(':', $line, 2);
            if (strtolower($key) === 'content-length') {
                return $value + $pos + 3;
            }
        }
        $end_pos = strpos($buffer, "\x00");
        if (!$end_pos) {
            return 0;
        }
        return $end_pos + 1;
    }

    /**
     * 编码数据
     * 
     * 将数组格式的数据编码为 STOMP 协议格式的字符串。
     * 
     * @param array $data 要编码的数据，格式为 ['cmd' => 'COMMAND', 'headers' => [], 'body' => '']
     * @return string 返回编码后的 STOMP 协议字符串
     */
    public static function encode(array $data)
    {
        if ($data['cmd'] === 'HEARTBEAT') return "\n";
        $headers = '';
        if (isset($data['headers'])) {
            foreach ($data['headers'] as $key => $value) {
                $headers .= "$key:$value\n";
            }
        }
        $body = $data['body'] ??= '';
        return $data['cmd']."\n$headers\n".$body."\x00";
    }

    /**
     * 解码数据
     * 
     * 将 STOMP 协议格式的字符串解码为数组格式。
     * 
     * @param string $buffer 要解码的 STOMP 协议字符串
     * @return array 返回解码后的数组，格式为 ['cmd' => 'COMMAND', 'headers' => [], 'body' => '']
     */
    public static function decode($buffer)
    {
        if ($buffer[0] == "\n" || $buffer[0] == "\x00") {
            return ['cmd' => 'HEARTBEAT', 'headers' => [], 'body' => ''];
        }
        [$head, $body] = explode("\n\n", $buffer, 2);
        $header_data = explode("\n", trim($head, "\n"));
        $command = $header_data[0];
        unset($header_data[0]);
        $headers = [];
        foreach ($header_data as $line) {
            [$key, $value] = explode(':', $line, 2);
            if (!isset($headers[$key])) {
                $headers[$key] = $value;
            }
        }
        return ['cmd' => $command, 'headers' => $headers, 'body' => substr($body, 0, -1)];
    }

}
