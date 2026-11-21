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

use Workerman\Connection\ConnectionInterface;

/**
 * Mqtt Protocol.
 * MQTT协议实现类，用于处理MQTT消息的编码和解码
 *
 * @author    walkor<walkor@workerman.net>
 */
class Mqtt implements ProtocolInterface
{
    /**
     * CONNECT命令，客户端请求连接服务器
     */
    public const CMD_CONNECT = 1;

    /**
     * CONNACK命令，服务器确认连接请求
     */
    public const CMD_CONNACK = 2;

    /**
     * PUBLISH命令，发布消息
     */
    public const CMD_PUBLISH = 3;

    /**
     * PUBACK命令，发布确认
     */
    public const CMD_PUBACK = 4;

    /**
     * PUBREC命令，发布收到
     */
    public const CMD_PUBREC = 5;

    /**
     * PUBREL命令，发布释放
     */
    public const CMD_PUBREL = 6;

    /**
     * PUBCOMP命令，发布完成
     */
    public const CMD_PUBCOMP = 7;

    /**
     * SUBSCRIBE命令，订阅主题
     */
    public const CMD_SUBSCRIBE = 8;

    /**
     * SUBACK命令，订阅确认
     */
    public const CMD_SUBACK = 9;

    /**
     * UNSUBSCRIBE命令，取消订阅
     */
    public const CMD_UNSUBSCRIBE = 10;

    /**
     * UNSUBACK命令，取消订阅确认
     */
    public const CMD_UNSUBACK = 11;

    /**
     * PINGREQ命令，心跳请求
     */
    public const CMD_PINGREQ = 12;

    /**
     * PINGRESP命令，心跳响应
     */
    public const CMD_PINGRESP = 13;

    /**
     * DISCONNECT命令，断开连接
     */
    public const CMD_DISCONNECT = 14;

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
        // mqtt version check
        if ($options['protocol_level'] === 5) {
            $className = Mqtt5::class;
        }
        if (!class_exists('\Workerman\Protocols\Mqtt')) {
            class_alias($className, '\Workerman\Protocols\Mqtt');
        }
        // context
        if ($options['bindto']) {
            $context['socket'] = ['bindto' => $options['bindto']];
        }
        if ($options['ssl'] and is_array($options['ssl'])) {
            $context['ssl'] = $options['ssl'];
        }
        // address override
        if (str_starts_with($address, 'mqtts')) {
            $options['ssl'] = empty($options['ssl']) ? true : $options['ssl'];
            $address = str_replace('mqtts', 'mqtt', $address);
        }
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
        return $package;
    }

    /** @inheritdoc  */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        $length = strlen($buffer);
        $body_length = static::getBodyLength($buffer, $head_bytes);
        $total_length = $body_length + $head_bytes;
        if ($length < $total_length) {
            return 0;
        }

        return $total_length;
    }

    /**
     * 编码MQTT消息
     *
     * @param mixed $data 要编码的数据
     * @param ConnectionInterface $connection 连接实例
     * @return string 编码后的MQTT消息
     */
    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        $cmd = $data['cmd'];
        switch ($data['cmd']) {
            // ['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
            case static::CMD_CONNECT:
                $body = self::packString($data['protocol_name']) . chr($data['protocol_level']);
                $connect_flags = 0;
                if (!empty($data['clean_session'])) {
                    $connect_flags |= 1 << 1;
                }
                if (!empty($data['will'])) {
                    $connect_flags |= 1 << 2;
                    $connect_flags |= $data['will']['qos'] << 3;
                    if ($data['will']['retain']) {
                        $connect_flags |= 1 << 5;
                    }
                }
                if (!empty($data['password'])) {
                    $connect_flags |= 1 << 6;
                }
                if (!empty($data['username'])) {
                    $connect_flags |= 1 << 7;
                }
                $body .= chr($connect_flags);

                $keepalive = !empty($data['keepalive']) && (int) $data['keepalive'] >= 0 ? (int) $data['keepalive'] : 0;
                $body .= pack('n', $keepalive);

                $body .= static::packString($data['client_id']);
                if (!empty($data['will'])) {
                    $body .= static::packString($data['will']['topic']);
                    $body .= static::packString($data['will']['content']);
                }
                if (!empty($data['username']) || $data['username'] === '0') {
                    $body .= static::packString($data['username']);
                }
                if (!empty($data['password']) || $data['password'] === '0') {
                    $body .= static::packString($data['password']);
                }
                $head = self::packHead($cmd, strlen($body));

                return $head . $body;
                //['cmd'=>2, 'session_present'=>0/1, 'code'=>x]
            case static::CMD_CONNACK:
                $body = !empty($data['session_present']) ? chr(1) : chr(0);
                $code = !empty($data['code']) ? $data['code'] : 0;
                $body .= chr($code);
                $head = static::packHead($cmd, strlen($body));

                return $head . $body;
                // ['cmd'=>3, 'message_id'=>x, 'topic'=>x, 'content'=>x, 'qos'=>0/1/2, 'dup'=>0/1, 'retain'=>0/1]
            case static::CMD_PUBLISH:
                $body = static::packString($data['topic']);
                $qos = $data['qos'] ?? 0;
                if ($qos) {
                    $body .= pack('n', $data['message_id']);
                }
                $body .= $data['content'];
                $dup = $data['dup'] ?? 0;
                $retain = $data['retain'] ?? 0;
                $head = static::packHead($cmd, strlen($body), $dup, $qos, $retain);

                return $head . $body;
                // ['cmd'=>x, 'message_id'=>x]
            case static::CMD_PUBACK:
            case static::CMD_PUBREC:
            case static::CMD_PUBREL:
            case static::CMD_PUBCOMP:
                $body = pack('n', $data['message_id']);
                if ($cmd === static::CMD_PUBREL) {
                    $head = static::packHead($cmd, strlen($body), 0, 1);
                } else {
                    $head = static::packHead($cmd, strlen($body));
                }

                return $head . $body;

                // ['cmd'=>8, 'message_id'=>x, 'topics'=>[topic=>qos, ..]]]
            case static::CMD_SUBSCRIBE:
                $id = $data['message_id'];
                $body = pack('n', $id);
                foreach ($data['topics'] as $topic => $qos) {
                    $body .= self::packString($topic);
                    $body .= chr($qos);
                }
                $head = static::packHead($cmd, strlen($body), 0, 1);

                return $head . $body;
                // ['cmd'=>9, 'message_id'=>x, 'codes'=>[x,x,..]]
            case static::CMD_SUBACK:
                $codes = $data['codes'];
                $body = pack('n', $data['message_id']) . call_user_func_array('pack', array_merge(['C*'], $codes));
                $head = static::packHead($cmd, strlen($body));

                return $head . $body;

                // ['cmd' => 10, 'message_id' => $message_id, 'topics' => $topics];
            case static::CMD_UNSUBSCRIBE:
                $body = pack('n', $data['message_id']);
                foreach ($data['topics'] as $topic) {
                    $body .= static::packString($topic);
                }
                $head = static::packHead($cmd, strlen($body), 0, 1);

                return $head . $body;
                // ['cmd'=>11, 'message_id'=>x]
            case static::CMD_UNSUBACK:
                $body = pack('n', $data['message_id']);
                $head = static::packHead($cmd, strlen($body));

                return $head . $body;

                // ['cmd'=>x]
            case static::CMD_PINGREQ:
            case static::CMD_PINGRESP:
            case static::CMD_DISCONNECT:
                return static::packHead($cmd, 0);
            default:
                return '';
        }
    }

    /**
     * 解码MQTT消息
     *
     * @param string $buffer 要解码的MQTT消息
     * @param ConnectionInterface $connection 连接实例
     * @return array 解码后的消息数组
     */
    public static function decode(string $buffer, ConnectionInterface $connection): array
    {
        $cmd = static::getCmd($buffer);
        $body = static::getBody($buffer);
        switch ($cmd) {
            case static::CMD_CONNECT:
                $protocol_name = static::readString($body);
                $protocol_level = ord($body[0]);
                $clean_session = ord($body[1]) >> 1 & 0x1;
                $will_flag = ord($body[1]) >> 2 & 0x1;
                $will_qos = ord($body[1]) >> 3 & 0x3;
                $will_retain = ord($body[1]) >> 5 & 0x1;
                $password_flag = ord($body[1]) >> 6 & 0x1;
                $username_flag = ord($body[1]) >> 7 & 0x1;
                $body = substr($body, 2);
                $tmp = unpack('n', $body, $body);
                $keepalive = $tmp[1];
                $body = substr($body, 2);
                $client_id = static::readString($body);
                if ($will_flag) {
                    $will_topic = static::readString($body);
                    $will_content = static::readString($body);
                }
                $username = $password = '';
                if ($username_flag) {
                    $username = static::readString($body);
                }
                if ($password_flag) {
                    $password = static::readString($body);
                }
                // ['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
                $package = [
                    'cmd'            => $cmd,
                    'protocol_name'  => $protocol_name,
                    'protocol_level' => $protocol_level,
                    'clean_session'  => $clean_session,
                    'will'           => [],
                    'username'       => $username,
                    'password'       => $password,
                    'keepalive'      => $keepalive,
                    'client_id'      => $client_id,
                ];
                if ($will_flag) {
                    $package['will'] = [
                        'qos'     => $will_qos,
                        'retain'  => $will_retain,
                        'topic'   => $will_topic,
                        'content' => $will_content,
                    ];
                } else {
                    unset($package['will']);
                }

                return $package;
            case static::CMD_CONNACK:
                $session_present = ord($body[0]) & 0x01;
                $code = ord($body[1]);

                return ['cmd' => $cmd, 'session_present' => $session_present, 'code' => $code];
            case static::CMD_PUBLISH:
                $dup = ord($buffer[0]) >> 3 & 0x1;
                $qos = ord($buffer[0]) >> 1 & 0x3;
                $retain = ord($buffer[0]) & 0x1;
                $topic = static::readString($body);
                if ($qos) {
                    $message_id = static::readShortInt($body);
                }
                $package = ['cmd' => $cmd, 'topic' => $topic, 'content' => $body, 'dup' => $dup, 'qos' => $qos, 'retain' => $retain];
                if ($qos) {
                    $package['message_id'] = $message_id;
                }

                return $package;
            case static::CMD_PUBACK:
            case static::CMD_PUBREC:
            case static::CMD_PUBREL:
            case static::CMD_UNSUBACK:
            case static::CMD_PUBCOMP:
                $message_id = static::readShortInt($body);

                return ['cmd' => $cmd, 'message_id' => $message_id];
            case static::CMD_SUBSCRIBE:
                $message_id = static::readShortInt($body);
                $topics = [];
                while ($body) {
                    $topic = static::readString($body);
                    $qos = ord($body[0]);
                    $topics[$topic] = $qos;
                    $body = substr($body, 1);
                }

                return ['cmd' => $cmd, 'message_id' => $message_id, 'topics' => $topics];
            case static::CMD_SUBACK:
                $message_id = static::readShortInt($body);
                $tmp = unpack('C*', $body);
                $codes = array_values($tmp);

                return ['cmd' => $cmd, 'message_id' => $message_id, 'codes' => $codes];
            case static::CMD_UNSUBSCRIBE:
                $message_id = static::readShortInt($body);
                $topics = [];
                while ($body) {
                    $topic = static::readString($body);
                    $topics[] = $topic;
                }

                return ['cmd' => $cmd, 'message_id' => $message_id, 'topics' => $topics];
            case static::CMD_PINGREQ:
            case static::CMD_PINGRESP:
            case static::CMD_DISCONNECT:
                return ['cmd' => $cmd];
        }

        return [];
    }

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
     * 写入消息体长度
     *
     * @param int $len 消息体长度
     * @return string 编码后的长度
     */
    protected static function writeBodyLength(int $len): string
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

    /**
     * 获取命令类型
     *
     * @param string $buffer MQTT消息
     * @return int 命令类型
     */
    public static function getCmd(string $buffer): int
    {
        return ord($buffer[0]) >> 4;
    }

    /**
     * 获取消息体长度
     *
     * @param string $buffer MQTT消息
     * @param int|null $head_bytes 消息头字节数
     * @return int 消息体长度
     */
    public static function getBodyLength(string $buffer, ?int &$head_bytes): int
    {
        $head_bytes = $multiplier = 1;
        $value = 0;
        do {
            if (!isset($buffer[$head_bytes])) {
                $head_bytes = 0;

                return 0;
            }
            $digit = ord($buffer[$head_bytes]);
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $head_bytes++;
        } while (($digit & 128) != 0);

        return (int) $value;
    }

    /**
     * 获取消息体
     *
     * @param string $buffer MQTT消息
     * @return string 消息体
     */
    public static function getBody($buffer): string
    {
        $body_length = static::getBodyLength($buffer, $head_bytes);

        return substr($buffer, $head_bytes, $body_length);
    }

    /**
     * 从缓冲区读取字符串
     *
     * @param string $buffer 缓冲区
     * @return string 读取的字符串
     */
    public static function readString(&$buffer): string
    {
        $tmp = unpack('n', $buffer);
        $length = $tmp[1];
        if ($length + 2 > strlen($buffer)) {
            echo 'buffer:' . bin2hex($buffer) . " lenth:$length not enough for unpackString\n";
        }

        $string = substr($buffer, 2, $length);
        $buffer = substr($buffer, $length + 2);

        return $string;
    }

    /**
     * 从缓冲区读取无符号短整型
     *
     * @param string $buffer 缓冲区
     * @return mixed 读取的无符号短整型
     */
    public static function readShortInt(&$buffer): mixed
    {
        $tmp = unpack('n', $buffer);
        $buffer = substr($buffer, 2);

        return $tmp[1];
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
    public static function packHead($cmd, $body_length, $dup = 0, $qos = 0, $retain = 0): string
    {
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
}
