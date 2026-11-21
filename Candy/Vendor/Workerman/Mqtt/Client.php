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

namespace Workerman\Mqtt;

use InvalidArgumentException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Mqtt\Consts\MQTTConst;
use Workerman\Mqtt\Consts\ReasonCodeConst;
use Workerman\Mqtt\Protocols\Mqtt;
use Workerman\Mqtt\Protocols\Mqtt5;
use Workerman\Mqtt\Protocols\ProtocolInterface;
use Workerman\Mqtt\Protocols\Ws;
use Workerman\Timer;

/**
 * Class Client
 * MQTT客户端类，用于连接MQTT服务器，发布和订阅消息
 */
class Client
{
    /**
     * 初始状态
     */
    public const STATE_INITIAL = 1;

    /**
     * 连接中状态
     */
    public const STATE_CONNECTING = 2;

    /**
     * 等待连接确认状态
     */
    public const STATE_WAITCONACK = 3;

    /**
     * 连接已建立状态
     */
    public const STATE_ESTABLISHED = 4;

    /**
     * 已断开连接状态
     */
    public const STATE_DISCONNECT = 5;

    /**
     * 默认客户端ID前缀
     */
    public const DEFAULT_CLIENT_ID_PREFIX = 'workerman-mqtt-client';

    /**
     * 最大主题长度
     */
    public const MAX_TOPIC_LENGTH = 65535;

    /**
     * 连接成功回调函数
     * @var callable
     */
    public $onConnect = null;

    /**
     * 重连成功回调函数
     * @var callable
     */
    public $onReconnect = null;

    /**
     * 收到消息回调函数
     * @var callable
     */
    public $onMessage = null;

    /**
     * 连接关闭回调函数
     * @var callable
     */
    public $onClose = null;

    /**
     * 错误回调函数
     * @var callable
     */
    public $onError = null;

    /**
     * 当前状态
     * @var int
     */
    protected int $_state = 1;

    /**
     * 消息ID
     * @var int
     */
    protected int $_messageId = 1;


    /**
     * TCP连接实例
     * @var AsyncTcpConnection|null
     */
    protected ?AsyncTcpConnection $_connection = null;

    /**
     * 是否首次连接
     * @var bool
     */
    protected bool $_firstConnect = true;

    /**
     * 需要重新订阅的主题列表，格式：['topic'=>qos, ...]
     * @var array
     */
    protected array $_resubscribeTopics = [];

    /**
     * 重新订阅时的属性
     * @var array
     */
    protected array $_resubscribeProperties = [];

    /**
     * 连接超时定时器ID
     * @var int
     */
    protected int $_checkConnectionTimeoutTimer = 0;

    /**
     * 心跳定时器ID
     * @var int
     */
    protected int $_pingTimer = 0;

    /**
     * 是否收到心跳响应
     * @var bool
     */
    protected bool $_recvPingResponse = true;

    /**
     * 是否禁止重连
     * @var bool
     */
    protected bool $_doNotReconnect = false;

    /**
     * 待处理的外出消息
     * @var array
     */
    protected array $_outgoing = [];

    /**
     * 协议类名称
     * @var class-string
     */
    protected string $_protocol = Mqtt::class;

    /**
     * @var array
     */
    protected array $_options = [
        'clean_session'    => 1, // set to 0 to receive QoS 1 and 2 messages while offline
        'username'         => '', // the username required by your broker
        'password'         => '', // the password required by your broker
        'keepalive'        => 50, // default 50 seconds, set to 0 to disable
        'protocol_name'    => 'MQTT', // protocol name MQTT or MQIsdp
        'protocol_level'   => 4, // protocol level, MQTT is 4 and MQIsdp is 3
        'reconnect_period' => 1, // reconnect period default 1 second, set to 0 to disable
        'connect_timeout'  => 30, // 30 seconds, time to wait before a CONNACK is received
        'resubscribe'      => true, // default true, if connection is broken and reconnects, subscribed topics are automatically subscribed again.
        'bindto'           => '', // bindto option, used to specify the IP address that PHP will use to access the network
        'ssl'              => false, // ssl context, see http://php.net/manual/en/context.ssl.php
        'debug'            => false, // debug
        'properties'       => [],  // properties, MQTT5 need
        'uri'              => '/mqtt', // mqtt over websocket default uri
    ];

    /**
     * 错误码映射表
     * @var array
     */
    protected static array $_errorCodeStringMap = [
        1   => 'Connection Refused, unacceptable protocol version',
        2   => 'Connection Refused, identifier rejected',
        3   => 'Connection Refused, Server unavailable',
        4   => 'Connection Refused, bad user name or password',
        5   => 'Connection Refused, not authorized',
        100 => 'Connection closed',
        101 => 'Connection timeout',
        102 => 'Connection fail',
        103 => 'Connection buffer full and close connection',
        140 => 'No connection to broker',
        240 => 'Invalid topic',
        241 => 'Invalid qos',
    ];

    /**
     * 协议映射表
     * @var array|string[]
     */
    protected static array $_protocolMap = [
        'ws'        => Ws::class,
        'wss'       => Ws::class,
        'mqtt'      => Mqtt::class,
        'mqtts'     => Mqtt::class,
    ];

    /**
     * 注册协议
     *
     * @param string $schema 协议名称，如mqtt、ws等
     * @param string $protocolClassname 协议类名
     * @return void
     */
    public static function registerProtocol(string $schema, string $protocolClassname): void
    {
        if (!is_a($protocolClassname, ProtocolInterface::class, true)) {
            throw new InvalidArgumentException("$protocolClassname must implement ProtocolInterface");
        }
        static::$_protocolMap[$schema] = $protocolClassname;
    }

    /**
     * 获取TCP连接实例
     * @return AsyncTcpConnection|null TCP连接实例
     */
    public function getConnection(): ?AsyncTcpConnection
    {
        return $this->_connection;
    }

    /**
     * 构造函数
     * @param string $address MQTT服务器地址，格式：mqtt://host:port 或 ws://host:port
     * @param array $options 配置选项
     * @throws \Exception 当配置无效时抛出异常
     */
    public function __construct(string $address, array $options = [])
    {
        $context = [];
        $this->setOptions($options);
        // 解析协议
        $addressInfo = parse_url($address);
        $schema = $addressInfo['scheme'] ?? '';
        /** @var ProtocolInterface $protocol */
        $this->_protocol = static::$_protocolMap[$schema] ?? Mqtt::class;
        // 协议初始化
        $this->_protocol = $this->_protocol::init($address, $this->_options, $context);
        // init connection
        $this->_connection = new AsyncTcpConnection($address, $context);
        $this->_connection->transport = $this->_options['ssl'] ? 'ssl': $this->_connection->transport;
        $this->onReconnect = [$this, 'onMqttReconnect'];
        $this->onMessage = function () {};
        // MQTT over websocket
        $this->_connection->websocketType = Ws::BINARY_TYPE_ARRAYBUFFER;
        $this->_connection->websocketClientProtocol = 'mqtt';
        $this->_connection->websocketClientDataProtocolClass = $this->_options['protocol_level'] === 5 ? Mqtt5::class : Mqtt::class;
        // 协议类标识
    }

    /**
     * 连接MQTT服务器
     * @return void
     */
    public function connect(): void
    {
        $this->_doNotReconnect              = false;
        $this->_connection->onConnect       = [$this, 'onConnectionConnect'];
        $this->_connection->onClose         = [$this, 'onConnectionClose'];
        $this->_connection->onError         = [$this, 'onConnectionError'];
        $this->_connection->onMessage       = [$this, 'onConnectionMessage'];
        $this->_connection->onBufferFull    = [$this, 'onConnectionBufferFull'];
        $this->_state                       = static::STATE_CONNECTING;
        $this->_connection->connect();
        $this->setConnectionTimeout($this->_options['connect_timeout']);
        $this->debugDump("Try to connect to {$this->_connection->getRemoteAddress()}{$this->_connection->getRemoteURI()}, protocol: {$this->_protocol}", '->');
    }

    /**
     * 订阅主题
     *
     * @param array<string, string>|string $topic 主题名称或主题数组，数组格式：['topic1' => qos1, 'topic2' => qos2]
     * @param array $options 选项，如qos等
     * @param callable|null $callback 回调函数，格式：function($exception, $granted) {}
     * @param array $properties MQTT5属性
     * @return void
     */
    public function subscribe(array|string $topic, array $options = [], ?callable $callback = null, array $properties = []): void
    {
        if ($this->checkDisconnecting($callback)) {
            return;
        }

        if (is_array($topic)) {
            $topics = $topic;
        } else {
            $qos = !is_callable($options) && isset($options['qos']) ? $options['qos'] : 0;
            if ((int) $this->_options['protocol_level'] === 5) {
                $no_local = !is_callable($options) && isset($options['no_local']) ? $options['no_local'] : null;
                $retain_as_published = !is_callable($options) && isset($options['retain_as_published']) ? $options['retain_as_published'] : null;
                $retain_handling = !is_callable($options) && isset($options['retain_handling']) ? $options['retain_handling'] : null;
                $topics = [$topic => [
                    'qos'                 => $qos,
                    'no_local'            => $no_local,
                    'retain_as_published' => $retain_as_published,
                    'retain_handling'     => $retain_handling,
                ]];
            } else {
                $topics = [$topic => $qos];
            }
        }
        if (static::validateTopics($topics)) {
            $this->triggerError(240, $callback);

            return;
        }
        if ($this->_options['resubscribe']) {
            $this->_resubscribeTopics += $topics;
            $this->_resubscribeProperties += $properties;
        }
        $package = [
            'cmd'        => MQTTConst::CMD_SUBSCRIBE,
            'topics'     => $topics,
            'message_id' => $this->incrMessageId(),
            'properties' => $properties,
        ];
        $this->debugDump(
            'Send SUBSCRIBE package, topic:' . implode(',', array_keys($topics)) .
            " message_id:{$package['message_id']}" . ' properties:' . json_encode($properties),
            '->'
        );
        $this->sendPackage($package);

        if ($callback) {
            $this->_outgoing[$package['message_id']] = function ($exception, $codes = []) use ($callback, $topics) {
                if ($exception) {
                    call_user_func($callback, $exception, []);

                    return;
                }
                $granted = [];
                $topics = array_keys($topics);
                foreach ($topics as $key => $topic) {
                    $granted[$topic] = $codes[$key];
                }
                if ($callback) {
                    call_user_func($callback, null, $granted);
                }
            };
        }
    }

    /**
     * 取消订阅主题
     *
     * @param array|string $topic 主题名称或主题数组
     * @param callable|null $callback 回调函数，格式：function($exception) {}
     * @param array $properties MQTT5属性
     * @return void
     */
    public function unsubscribe(array|string $topic, ?callable $callback = null, array $properties = []): void
    {
        if ($this->checkDisconnecting($callback)) {
            return;
        }
        $topics = is_array($topic) ? $topic : [$topic];
        if (static::validateTopics($topics)) {
            $this->triggerError(240);

            return;
        }
        foreach ($topics as $_topic) {
            if (isset($this->_resubscribeTopics[$_topic])) {
                unset($this->_resubscribeTopics[$_topic]);
            }
        }
        $this->_resubscribeProperties = [];

        $package = [
            'cmd'        => MQTTConst::CMD_UNSUBSCRIBE,
            'topics'     => $topics,
            'message_id' => $this->incrMessageId(),
            'properties' => $properties,
        ];
        if ($callback) {
            $this->_outgoing[$package['message_id']] = $callback;
        }
        $this->debugDump(
            'Send UNSUBSCRIBE package, topic:' . implode(',', $topics) .
            " message_id:{$package['message_id']}" . ' properties:' . json_encode($properties),
            '->'
        );
        $this->sendPackage($package);
    }

    /**
     * 发布消息
     *
     * @param string $topic 主题名称
     * @param mixed $content 消息内容
     * @param array $options 选项，如qos、retain等
     * @param callable|null $callback 回调函数，格式：function($exception) {}
     * @param array $properties MQTT5属性
     * @return void
     */
    public function publish($topic, $content, $options = [], $callback = null, $properties = []): void
    {
        if ($this->checkDisconnecting($callback)) {

            return;
        }
        if ((int) $this->_options['protocol_level'] === 5) {
            if (empty($topic)) {
                if (empty($properties['topic_alias'])) {
                    throw new \RuntimeException('Topic cannot be empty or need to set topic_alias');
                }
            }
        } else {
            static::isValidTopic($topic);
        }
        $qos = 0;
        $retain = 0;
        $dup = 0;
        if (isset($options['qos'])) {
            $qos = $options['qos'];
            if ($this->checkInvalidQos($qos, $callback)) {

                return;
            }
        }
        if (!empty($options['retain'])) {
            $retain = 1;
        }
        if (!empty($options['dup'])) {
            $dup = 1;
        }
        $package = [
            'cmd'        => MQTTConst::CMD_PUBLISH,
            'topic'      => $topic,
            'content'    => $content,
            'retain'     => $retain,
            'qos'        => $qos,
            'dup'        => $dup,
            'properties' => $properties,
        ];
        if ($qos) {
            $package['message_id'] = $this->incrMessageId();
            if ($callback) {
                $this->_outgoing[$package['message_id']] = $callback;
            }
        }
        $this->debugDump(
            "Send PUBLISH package, topic:$topic content:$content retain:$retain qos:$qos dup:$dup message_id:" .
            ($package['message_id'] ?? '') . ' properties:' . json_encode($properties),
            '->'
        );
        $this->sendPackage($package);
    }

    /**
     * 断开连接
     *
     * @param int $code 断开连接原因码
     * @param array $properties MQTT5属性
     * @return void
     */
    public function disconnect(int $code = ReasonCodeConst::NORMAL_DISCONNECTION, array $properties = []): void
    {
        $this->sendPackage(['cmd' => MQTTConst::CMD_DISCONNECT, 'code' => $code, 'properties' => $properties]);
        if ($this->_options['debug']) {
            echo '-> Send DISCONNECT package', PHP_EOL;
        }
        $this->close();
    }

    /**
     * 认证
     *
     * @param int $code 认证结果码
     * @param array $properties MQTT5属性
     * @return void
     */
    public function auth(int $code = ReasonCodeConst::SUCCESS, array $properties = []): void
    {
        $this->sendPackage(['cmd' => MQTTConst::CMD_AUTH, 'code' => $code, 'properties' => $properties]);
    }

    /**
     * 关闭连接
     * @return void
     */
    public function close(): void
    {
        $this->_doNotReconnect = true;
        $this->debugDump('Connection->close() called', '->');
        $this->_connection->destroy();
    }

    /**
     * 重连
     *
     * @param int $after 延迟重连时间，单位：秒
     * @return void
     */
    public function reconnect(int $after = 0): void
    {
        $this->_doNotReconnect = false;
        $this->_connection->onConnect = [$this, 'onConnectionConnect'];
        $this->_connection->onMessage = [$this, 'onConnectionMessage'];
        $this->_connection->onError = [$this, 'onConnectionError'];
        $this->_connection->onClose = [$this, 'onConnectionClose'];
        $this->_connection->reConnect($after);
        $this->setConnectionTimeout($this->_options['connect_timeout'] + $after);
        $this->debugDump("Reconnect after $after seconds", '--');
    }

    /**
     * TCP连接建立回调
     * @return void
     */
    public function onConnectionConnect(): void
    {
        if ($this->_doNotReconnect) {
            $this->close();

            return;
        }
        //['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
        $package = [
            'cmd'            => MQTTConst::CMD_CONNECT,
            'clean_session'  => $this->_options['clean_session'],
            'username'       => $this->_options['username'],
            'password'       => $this->_options['password'],
            'keepalive'      => $this->_options['keepalive'],
            'protocol_name'  => $this->_options['protocol_name'],
            'protocol_level' => $this->_options['protocol_level'],
            'client_id'      => $this->_options['client_id'],
            'properties'     => $this->_options['properties'], // MQTT5 中所需要的属性
        ];
        if (isset($this->_options['will'])) {
            $package['will'] = $this->_options['will'];
        }
        $this->_state = static::STATE_WAITCONACK;
        $this->sendPackage($package, false);
        $this->debugDump('Tcp connection established', '--');
        $this->debugDump(
            "Send CONNECT package client_id:{$this->_options['client_id']} username:{$this->_options['username']} password:{$this->_options['password']} clean_session:{$this->_options['clean_session']} protocol_name:{$this->_options['protocol_name']} protocol_level:{$this->_options['protocol_level']}",
            '->'
        );
    }

    /**
     * MQTT重连回调
     * @return void
     */
    public function onMqttReconnect(): void
    {
        if ($this->_options['clean_session'] && $this->_options['resubscribe'] && $this->_resubscribeTopics) {
            $package = [
                'cmd'        => MQTTConst::CMD_SUBSCRIBE,
                'topics'     => $this->_resubscribeTopics,
                'message_id' => $this->incrMessageId(),
                'properties' => $this->_resubscribeProperties ?? [],
            ];
            $this->sendPackage($package);
            $this->debugDump(
                'Send SUBSCRIBE(Resubscribe) package topics:' .
                implode(',', array_keys($this->_resubscribeTopics)) . " message_id:{$package['message_id']}",
                '->'
            );
        }
    }
    
    /**
     * TCP连接收到消息回调
     *
     * @param ConnectionInterface $connection TCP连接实例
     * @param mixed $data 收到的数据
     * @return void
     */
    public function onConnectionMessage(ConnectionInterface $connection, $data): void
    {
        $data = $this->_protocol::unpack($data, $connection);
        $cmd = $data['cmd'];
        match ($cmd) {
            MQTTConst::CMD_CONNACK => $this->_cmdConnack($data),
            MQTTConst::CMD_PUBLISH => $this->_cmdPublish($data),
            MQTTConst::CMD_PUBREC => $this->_cmdPubrec($data),
            MQTTConst::CMD_PUBREL => $this->_cmdPubrel($data),
            MQTTConst::CMD_PUBACK => $this->_cmdPuback($data),
            MQTTConst::CMD_SUBACK, MQTTConst::CMD_UNSUBACK => $this->_cmdback($cmd, $data),
            MQTTConst::CMD_PINGRESP => $this->_cmdPingresp(),
            default => $this->_cmdUnknown($cmd),
        };
    }

    /**
     * TCP连接关闭回调
     * @return void
     */
    public function onConnectionClose(): void
    {
        $this->debugDump('Connection closed', '--');
        $this->cancelPingTimer();
        $this->cancelConnectionTimeout();
        $this->_recvPingResponse = true;
        $this->_state = static::STATE_DISCONNECT;
        if (!$this->_doNotReconnect && $this->_options['reconnect_period'] > 0) {
            $this->reConnect($this->_options['reconnect_period']);
        }
        $this->flushOutgoing();
        if ($this->onClose) {
            call_user_func($this->onClose, $this);
        }
    }

    /**
     * TCP连接错误回调
     *
     * @param ConnectionInterface $connection TCP连接实例
     * @param int $code 错误码
     * @return void
     */
    public function onConnectionError(ConnectionInterface $connection, $code): void
    {
        // Connection error
        if ($code === 1) {
            $this->triggerError(102);
            // Send fail, connection closed
        } else {
            $this->triggerError(100);
        }
    }

    /**
     * TCP连接缓冲区满回调
     * @return void
     */
    public function onConnectionBufferFull(): void
    {
        $this->debugDump('Connection buffer full and close connection', '--');
        $this->triggerError(103);
        $this->_connection->destroy();
    }

    /**
     * 调试输出
     *
     * @param string $message 调试信息
     * @param string $tag 标签
     * @return void
     */
    protected function debugDump(string $message, string $tag = '--'): void
    {
        if ($this->_options['debug'] ?? false) {
            echo "$tag $message\n";
        }
    }

    /**
     * 发送数据包
     *
     * @param mixed $package 数据包
     * @param bool $checkDisconnecting 是否检查断开状态
     * @return void
     */
    protected function sendPackage(mixed $package, bool $checkDisconnecting = true): void
    {
        if ($checkDisconnecting and $this->checkDisconnecting()) {
            return;
        }
        $this->_connection->send($this->_protocol::pack($package, $this->_connection));
    }

    /**
     * 递增消息ID
     *
     * @return int 新的消息ID
     */
    protected function incrMessageId(): int
    {
        $message_id = $this->_messageId++;
        if ($message_id >= 65535) {
            $this->_messageId = 1;
        }

        return $message_id;
    }

    /**
     * 检查QoS是否无效
     *
     * @param int $qos QoS值
     * @param callable|null $callback 回调函数
     * @return bool 无效返回true，有效返回false
     */
    protected function checkInvalidQos($qos, ?callable $callback = null): bool
    {
        if ($qos !== 0 && $qos !== 1 && $qos !== 2) {
            $this->triggerError(241, $callback);

            return true;
        }

        return false;
    }

    /**
     * 检查主题是否有效
     *
     * @param string $topic 主题名称
     * @return bool 有效返回true，无效返回false
     */
    protected static function isValidTopic($topic): bool
    {
        if (!static::isString($topic)) {
            return false;
        }
        $topic_length = strlen($topic);
        if ($topic_length > static::MAX_TOPIC_LENGTH) {
            return false;
        }

        return true;
    }

    /**
     * 验证主题列表
     *
     * @param array $topics 主题列表
     * @return string|null 无效主题返回主题名称，全部有效返回null
     */
    protected static function validateTopics($topics): string|null
    {
        if (empty($topics)) {
            return 'array()';
        }
        foreach ($topics as $topic => $qos) {
            if (!static::isValidTopic($topic)) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * 检查是否为字符串
     *
     * @param mixed $string 要检查的值
     * @return bool 是字符串返回true，否则返回false
     */
    protected static function isString($string): bool
    {
        return (is_string($string) || is_integer($string)) && strlen($string) > 0;
    }

    /**
     * 触发错误
     *
     * @param int $code 错误码
     * @param callable|null $callback 回调函数
     * @return void
     */
    protected function triggerError($code, ?callable $callback = null): void
    {
        $exception = new \Exception($this->getErrorCodeString($code), $code);
        $this->debugDump('Error: ' . $exception->getMessage(), '--');

        if (!$callback) {
            $callback = $this->onError ?: function ($exception) {
                echo 'Mqtt client: ', $exception->getMessage(), PHP_EOL;
            };
        }
        call_user_func($callback, $exception);
    }

    /**
     * 获取错误码对应的字符串
     *
     * @param int $code 错误码
     * @return mixed|string 错误消息
     */
    protected function getErrorCodeString(int $code)
    {
        return  static::$_errorCodeStringMap[$code] ?? ReasonCodeConst::getReason($code);
    }

    /**
     * 创建随机客户端ID
     *
     * @return string 客户端ID
     */
    protected function createRandomClientId(): string
    {
        mt_srand();

        return static::DEFAULT_CLIENT_ID_PREFIX . '-' . mt_rand();
    }

    /**
     * 设置连接超时定时器
     *
     * @param int $timeout 超时时间，单位：秒
     * @return void
     */
    protected function setConnectionTimeout($timeout): void
    {
        $this->cancelConnectionTimeout();
        $this->_checkConnectionTimeoutTimer = Timer::add($timeout, [$this, 'checkConnectTimeout'], null, false);
    }

    /**
     * 取消连接超时定时器
     * @return void
     */
    protected function cancelConnectionTimeout(): void
    {
        if ($this->_checkConnectionTimeoutTimer) {
            Timer::del($this->_checkConnectionTimeoutTimer);
            $this->_checkConnectionTimeoutTimer = 0;
        }
    }

    /**
     * 设置心跳定时器
     *
     * @param float|int $pingInterval 心跳间隔，单位：秒
     * @return void
     */
    protected function setPingTimer(float|int $pingInterval): void
    {
        $this->cancelPingTimer();
        $this->_pingTimer = Timer::add($pingInterval, function () {
            if (!$this->_recvPingResponse) {
                $this->debugDump('Recv PINGRESP timeout', '<-');
                $this->debugDump('Close connection', '->');
                $this->_connection->destroy();

                return;
            }
            $this->debugDump('Send PINGREQ package', '->');
            $this->_recvPingResponse = false;
            $this->sendPackage(['cmd' => MQTTConst::CMD_PINGREQ], false);
        });
    }

    /**
     * 取消心跳定时器
     * @return void
     */
    protected function cancelPingTimer(): void
    {
        if ($this->_pingTimer) {
            Timer::del($this->_pingTimer);
            $this->_pingTimer = 0;
        }
    }

    /**
     * 检查连接超时
     * @return void
     */
    public function checkConnectTimeout(): void
    {
        if ($this->_state === static::STATE_CONNECTING || $this->_state === static::STATE_WAITCONACK) {
            $this->triggerError(101);
            $this->_connection->destroy();
        }
    }

    /**
     * 检查是否正在断开连接
     *
     * @param callable|null $callback 回调函数
     * @return bool 正在断开返回true，否则返回false
     */
    protected function checkDisconnecting(?callable $callback = null): bool
    {
        if ($this->_state !== static::STATE_ESTABLISHED) {
            $this->triggerError(140, $callback);

            return true;
        }

        return false;
    }

    /**
     * 处理待发送消息
     * @return void
     */
    protected function flushOutgoing(): void
    {
        foreach ($this->_outgoing as $message_id => $callback) {
            $this->triggerError(100, $callback);
        }
        $this->_outgoing = [];
    }

    /**
     * 设置选项
     *
     * @param array $options 选项数组
     * @return void
     */
    protected function setOptions(array $options): void
    {
        if (isset($options['clean_session']) && !$options['clean_session']) {
            $this->_options['clean_session'] = 0;
        }

        if (isset($options['uri'])) {
            $this->_options['uri'] = $options['uri'];
        }

        if (isset($options['username'])) {
            if (!static::isString($options['username'])) {
                throw new InvalidArgumentException('Bad username, expected string or integer but ' . gettype($options['username']) . ' provided.');
            }
            $this->_options['username'] = $options['username'];
        }

        if (isset($options['password'])) {
            if (!static::isString($options['password'])) {
                throw new InvalidArgumentException('Bad password, expected string or integer but ' . gettype($options['password']) . ' provided.');
            }
            $this->_options['password'] = $options['password'];
        }

        if (isset($options['keepalive'])) {
            $keepalive = (int) $options['keepalive'];
            if (!static::isString($keepalive)) {
                throw new InvalidArgumentException('Bad keepalive, expected integer but ' . gettype($keepalive) . ' provided.');
            }
            if ($keepalive < 0) {
                throw new InvalidArgumentException('Bad keepalive, expected integer which not less than 0 but ' . $keepalive . ' provided.');
            }
            $this->_options['keepalive'] = $keepalive;
        }

        if (isset($options['protocol_name'])) {
            $protocol_name = $options['protocol_name'];
            if ($protocol_name !== 'MQTT' && $protocol_name !== 'MQIsdp') {
                throw new InvalidArgumentException('Bad protocol_name of options, expected MQTT or MQIsdp but ' . $protocol_name . ' provided.');
            }
            $this->_options['protocol_name'] = $protocol_name;
        }

        if (isset($options['protocol_level'])) {
            $protocol_level = (int) $options['protocol_level'];
            if ($this->_options['protocol_name'] === 'MQTT' && !in_array($protocol_level, [4, 5])) {
                throw new InvalidArgumentException('Bad protocol_level of options, expected (4 or 5) for protocol_name MQTT but ' . $options['protocol_level'] . ' provided.');
            }
            if ($this->_options['protocol_name'] === 'MQIsdp' && $protocol_level !== 3) {
                throw new InvalidArgumentException('Bad protocol_level of options, expected 3 for protocol_name MQTT but ' . $options['protocol_level'] . ' provided.');
            }
            $this->_options['protocol_level'] = $protocol_level;
        }

        if (isset($options['client_id'])) {
            if (!static::isString($options['client_id'])) {
                throw new InvalidArgumentException('Bad client_id of options, expected string or integer but ' . gettype($options['client_id']) . ' provided.');
            }
            $this->_options['client_id'] = $options['client_id'];
        } else {
            $this->_options['client_id'] = $this->createRandomClientId();
        }

        // 遗嘱消息，当客户端断线后Broker会自动发送遗嘱消息给其它客户端
        if (isset($options['will'])) {
            $will = $options['will'];
            $required = ['qos', 'topic', 'content'];
            foreach ($required as $key) {
                if (!isset($will[$key])) {
                    throw new InvalidArgumentException('Bad will options, $will[' . $key . '] missing.');
                }
            }
            if (!static::isString($will['topic'])) {
                throw new InvalidArgumentException('Bad $will[\'topic\'] of options, expected string or integer but ' . gettype($will['topic']) . ' provided.');
            }
            if (!static::isString($will['content'])) {
                throw new InvalidArgumentException('Bad $will[\'content\'] of options, expected string or integer but ' . gettype($will['content']) . ' provided.');
            }
            if ($this->checkInvalidQos($will['qos'])) {
                throw new InvalidArgumentException('Bad will qos:' . var_export($will['qos'], true));
            }
            $this->_options['will'] = $options['will'];
        }

        // 重连时间间隔，默认 1 秒，0代表不重连
        if (isset($options['reconnect_period'])) {
            $reconnect_period = (int) $options['reconnect_period'];
            if (!static::isString($reconnect_period)) {
                throw new InvalidArgumentException('Bad reconnect_period of options, expected integer but ' . gettype($options['reconnect_period']) . ' provided.');
            }
            if ($reconnect_period < 0) {
                throw new InvalidArgumentException('Bad reconnect_period, expected integer which not less than 0 but ' . $options['reconnect_period'] . ' provided.');
            }
            $this->_options['reconnect_period'] = $reconnect_period;
        }

        if (isset($options['connect_timeout'])) {
            $connect_timeout = (int) $options['connect_timeout'];
            if (!static::isString($connect_timeout)) {
                throw new InvalidArgumentException('Bad connect_timeout of options, expected integer but ' . gettype($options['connect_timeout']) . ' provided.');
            }
            if ($connect_timeout <= 0) {
                throw new InvalidArgumentException('Bad connect_timeout, expected integer which greater than 0 but ' . $options['connect_timeout'] . ' provided.');
            }
            $this->_options['connect_timeout'] = $connect_timeout;
        }

        if (isset($options['resubscribe']) && !$options['resubscribe']) {
            $this->_options['resubscribe'] = false;
        }

        if (!empty($options['bindto'])) {
            $this->_options['bindto'] = $options['bindto'];
        }

        if (isset($options['ssl'])) {
            $this->_options['ssl'] = $options['ssl'];
        }

        if (isset($options['debug'])) {
            $this->_options['debug'] = !empty($options['debug']);
        }

        // MQTT5 中所需要的属性
        if (!empty($options['properties'])) {
            $this->_options['properties'] = $options['properties'];
            if (empty($this->_options['protocol_level'])) {
                $this->_options['protocol_level'] = 5;
            }
        } else {
            $this->_options['properties'] = [];
        }
    }

    /**
     * 处理CONNACK包
     * @param array $data 收到的CONNACK包数据
     * @return void
     */
    private function _cmdConnack(array $data): void
    {
        $code = (int) $data['code'];
        if ($code !== 0) {
            $message = $this->getErrorCodeString($code);
            $this->debugDump("Recv CONNACK package but get error $message", '<-');
            $this->triggerError($code);
            $this->_connection->destroy();
            return;
        }
        $this->debugDump("Recv CONNACK package, MQTT connect success", '<-');
        $this->_state = static::STATE_ESTABLISHED;
        if ($this->_firstConnect) {
            if ($this->onConnect) {
                call_user_func($this->onConnect, $this);
            }
            $this->_firstConnect = false;
        } else {
            if ($this->onReconnect) {
                call_user_func($this->onReconnect, $this);
            }
        }
        if ($this->_options['keepalive']) {
            $this->setPingTimer($this->_options['keepalive']);
        }
        $this->cancelConnectionTimeout();
    }

    /**
     * 处理PUBLISH包
     * @param array $data 收到的PUBLISH包数据
     * @return void
     */
    private function _cmdPublish(array $data): void
    {
        $topic = $data['topic'];
        $content = $data['content'];
        $qos = $data['qos'];
        $message_id = $data['message_id'] ?? '';
        $properties = $data['properties'] ?? [];
        $this->debugDump(
            "Recv PUBLISH package, message_id:$message_id qos:$qos topic:$topic content:$content properties:" . json_encode($properties),
            '<-'
        );
        call_user_func($this->onMessage, $topic, $content, $this, $properties);
        // Connection may be closed in onMessage callback.
        if ($this->_state !== static::STATE_ESTABLISHED) {
            return;
        }
        $extra_package = [];
        if ((int) $this->_options['protocol_level'] === 5) {
            $extra_package = ['properties' => $properties];
        }
        match ($qos) {
            0 => null,
            1 => $this->_cmdPublishQos($qos, $message_id, $extra_package),
            2 => $this->_cmdPublishQos($qos, $message_id, $extra_package),
        };
    }

    /**
     * 发布QoS包
     * @param int $qos QoS等级
     * @param string $message_id 消息ID
     * @param array $extra_package 额外属性包
     */
    private function _cmdPublishQos(int $qos, string $message_id, array $extra_package): void
    {
        $this->debugDump("Send PUBACK package, message_id: $message_id", '->');
        $this->sendPackage([
            'cmd'        => $qos === 1 ? MQTTConst::CMD_PUBACK : MQTTConst::CMD_PUBREC,
            'message_id' => $message_id,
        ] + $extra_package);
    }

    /**
     * 处理PUBREC包
     * @param array $data 收到的PUBREC包数据
     * @return void
     */
    private function _cmdPubrec(array $data): void
    {
        $message_id = $data['message_id'];
        $properties = $data['properties'] ?? [];
        $this->debugDump("Recv PUBREC package, message_id:$message_id", '<-');
        $this->debugDump("Send PUBREL package, message_id: $message_id", '->');
        $extra_package = [];
        if ((int) $this->_options['protocol_level'] === 5) {
            $extra_package = ['properties' => $properties];
        }
        $this->sendPackage([
            'cmd'        => MQTTConst::CMD_PUBREL,
            'message_id' => $message_id,
        ] + $extra_package);
    }

    /**
     * 处理PUBREL包
     * @param array $data 收到的PUBREL包数据
     * @return void
     */
    private function _cmdPubrel(array $data): void
    {
        $message_id = $data['message_id'];
        $properties = $data['properties'] ?? [];
        $this->debugDump("Recv PUBREL package, message_id:$message_id", '<-');
        $this->debugDump("Send PUBACK package, message_id: $message_id", '->');
        $extra_package = [];
        if ((int) $this->_options['protocol_level'] === 5) {
            $extra_package = ['properties' => $properties];
        }
        $this->sendPackage([
            'cmd'        => MQTTConst::CMD_PUBACK,
            'message_id' => $message_id,
        ] + $extra_package);
    }

    /**
     * 处理PUBACK包
     * @param array $data 收到的PUBACK包数据
     * @return void
     */
    private function _cmdPuback(array $data): void
    {
        $message_id = $data['message_id'];
        $properties = $data['properties'] ?? [];
        $this->debugDump("Recv PUBACK package, message_id:$message_id", '<-');
        $this->debugDump("Send PUBCOMP package, message_id: $message_id", '->');
        $extra_package = [];
        if ((int) $this->_options['protocol_level'] === 5) {
            $extra_package = ['properties' => $properties];
        }
        $this->sendPackage([
            'cmd'        => MQTTConst::CMD_PUBCOMP,
            'message_id' => $message_id,
        ] + $extra_package);
    }

    /**
     * 处理SUBACK或UNSUBACK包
     * @param int $cmd 命令码
     * @param array $data 收到的SUBACK或UNSUBACK包数据
     * @return void
     */
    private function _cmdback(int $cmd, array $data): void
    {
        $message_id = $data['message_id'];
        $this->debugDump('Recv ' . ($cmd === MQTTConst::CMD_SUBACK ? 'SUBACK' : 'UNSUBACK') . " package, message_id: $message_id", '<-');
        $callback = $this->_outgoing[$message_id] ?? null;
        unset($this->_outgoing[$message_id]);
        if ($callback) {
            $this->debugDump('Trigger ' . ($cmd === MQTTConst::CMD_SUBACK ? 'SUB' : 'UNSUB') . " callback for message_id: $message_id", '--');
            if ($cmd === MQTTConst::CMD_SUBACK) {
                call_user_func($callback, null, $data['codes']);
            } else {
                call_user_func($callback, null);
            }
        }
    }

    /**
     * 处理PINGRESP包
     * @param array $data 收到的PINGRESP包数据
     * @return void
     */
    private function _cmdPingresp(): void
    {
        $this->_recvPingResponse = true;
        $this->debugDump('Recv PINGRESP package', '<-');
    }

    /**
     * 处理未知命令包
     * @param int $cmd 命令码
     * @return void
     */
    private function _cmdUnknown(int $cmd): void
    {
        $this->debugDump('Recv unknow package, cmd: ' . $cmd, '<-');
        echo 'unknow cmd';
    }
}
