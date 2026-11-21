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

namespace Workerman\Mqtt\Protocols;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\Ws as BaseWs;

/**
 * Mqtt over ws support
 * MQTT over WebSocket协议实现类，用于支持通过WebSocket传输MQTT消息
 *
 * @author    chaz6chez<chaz6chez1993@outlook.com>
 */
class Ws implements ProtocolInterface
{

    /**
     * WebSocket二进制类型：Blob
     * @var string {@see BaseWs::BINARY_TYPE_BLOB}
     */
    public const BINARY_TYPE_BLOB = BaseWs::BINARY_TYPE_BLOB;

    /**
     * WebSocket二进制类型：ArrayBuffer
     * @var string {@see BaseWs::BINARY_TYPE_ARRAYBUFFER}
     */
    public const BINARY_TYPE_ARRAYBUFFER = BaseWs::BINARY_TYPE_ARRAYBUFFER;


    /**
     * 初始化协议
     *
     * @param string $address 服务器地址
     * @param array $options 配置选项
     * @param array $context 连接上下文
     * @return string 协议类名
     */
    public static function init(string &$address, array &$options, array &$context): string
    {
        $className = self::class;
        if (!class_exists('\Protocols\Ws')) {
            class_alias($className, '\Protocols\Ws');
        }
        if ($options['ssl'] and is_array($options['ssl'])) {
            $context['ssl'] = $options['ssl'];
        }
        $context['socket'] = ['bindto' => $options['bindto']];
        if (str_starts_with($address, 'wss')) {
            $options['ssl'] = empty($options['ssl']) ? true : $options['ssl'];
            $address = str_replace('wss', 'ws', $address);
        }
        $address = $address . ($options['uri'] ?? '/mqtt');
        return $className;
    }

    /**
     * 打包数据
     *
     * @param mixed $data 要打包的数据
     * @param ConnectionInterface $connection 连接实例
     * @return mixed 打包后的数据
     */
    public static function pack(mixed $data, ConnectionInterface $connection): mixed
    {
        /** @var ProtocolInterface $protocol */
        $protocol = $connection->websocketClientDataProtocolClass ?? null;
        if ($protocol) {
            return $protocol::encode($data, $connection);
        }
        return $data;
    }

    /**
     * 解包数据
     *
     * @param mixed $package 要解包的数据
     * @param ConnectionInterface $connection 连接实例
     * @return mixed 解包后的数据
     */
    public static function unpack(mixed $package, ConnectionInterface $connection): mixed
    {
        /** @var ProtocolInterface $protocol */
        $protocol = $connection->websocketClientDataProtocolClass ?? null;
        if ($protocol) {
            return $protocol::decode($package, $connection);
        }
        return $package;
    }

    /**
     * 检查数据包的完整性
     *
     * @param string $buffer 接收到的数据
     * @param ConnectionInterface $connection 连接实例
     * @return int 完整数据包的长度，0表示需要更多数据，负数表示错误
     */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        if ($connection instanceof AsyncTcpConnection) {
            return BaseWs::input($buffer, $connection);
        }
        throw new \RuntimeException('Not support. ');
    }

    /**
     * 解码WebSocket消息
     *
     * @param string $buffer 要解码的WebSocket消息
     * @param ConnectionInterface $connection 连接实例
     * @return mixed 解码后的数据
     */
    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        if ($connection instanceof AsyncTcpConnection) {
            return BaseWs::decode($buffer, $connection);
        }
        throw new \RuntimeException('Not support. ');
    }

    /**
     * 编码WebSocket消息
     *
     * @param mixed $data 要编码的数据
     * @param ConnectionInterface $connection 连接实例
     * @return string 编码后的WebSocket消息
     */
    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        if ($connection instanceof AsyncTcpConnection) {
            try {
                return BaseWs::encode($data, $connection);
            } catch (\Throwable $t) {
                throw new \RuntimeException($t->getMessage(), $t->getCode());
            }
        }
        throw new \RuntimeException('Not support. ');
    }
}
