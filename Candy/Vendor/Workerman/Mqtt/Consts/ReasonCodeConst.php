<?php

namespace Workerman\Mqtt\Consts;

/**
 * MQTT 5.0原因码常量类
 * @see https://docs.oasis-open.org/mqtt/mqtt/v5.0/os/mqtt-v5.0-os.html#_Toc3901031
 */
class ReasonCodeConst
{
    /**
     * 成功
     */
    public const SUCCESS = 0x00;

    /**
     * 正常断开连接
     */
    public const NORMAL_DISCONNECTION = 0x00;

    /**
     * 授予QoS 0
     */
    public const GRANTED_QOS_0 = 0x00;

    /**
     * 授予QoS 1
     */
    public const GRANTED_QOS_1 = 0x01;

    /**
     * 授予QoS 2
     */
    public const GRANTED_QOS_2 = 0x02;

    /**
     * 带有遗嘱消息断开连接
     */
    public const DISCONNECT_WITH_WILL_MESSAGE = 0x04;

    /**
     * 没有匹配的订阅者
     */
    public const NO_MATCHING_SUBSCRIBERS = 0x10;

    /**
     * 不存在订阅
     */
    public const NO_SUBSCRIPTION_EXISTED = 0x11;

    /**
     * 继续认证
     */
    public const CONTINUE_AUTHENTICATION = 0x18;

    /**
     * 重新认证
     */
    public const RE_AUTHENTICATE = 0x19;

    /**
     * 未指定错误
     */
    public const UNSPECIFIED_ERROR = 0x80;

    /**
     * 格式错误的数据包
     */
    public const MALFORMED_PACKET = 0x81;

    /**
     * 协议错误
     */
    public const PROTOCOL_ERROR = 0x82;

    /**
     * 实现特定错误
     */
    public const IMPLEMENTATION_SPECIFIC_ERROR = 0x83;

    /**
     * 不支持的协议版本
     */
    public const UNSUPPORTED_PROTOCOL_VERSION = 0x84;

    /**
     * 客户端标识符无效
     */
    public const CLIENT_IDENTIFIER_NOT_VALID = 0x85;

    /**
     * 用户名或密码错误
     */
    public const BAD_USER_NAME_OR_PASSWORD = 0x86;

    /**
     * 未授权
     */
    public const NOT_AUTHORIZED = 0x87;

    /**
     * 服务器不可用
     */
    public const SERVER_UNAVAILABLE = 0x88;

    /**
     * 服务器繁忙
     */
    public const SERVER_BUSY = 0x89;

    /**
     * 被禁止
     */
    public const BANNED = 0x8A;

    /**
     * 服务器正在关闭
     */
    public const SERVER_SHUTTING_DOWN = 0x8B;

    /**
     * 认证方法错误
     */
    public const BAD_AUTHENTICATION_METHOD = 0x8C;

    /**
     * 心跳超时
     */
    public const KEEP_ALIVE_TIMEOUT = 0x8D;

    /**
     * 会话被接管
     */
    public const SESSION_TAKEN_OVER = 0x8E;

    /**
     * 主题过滤器无效
     */
    public const TOPIC_FILTER_INVALID = 0x8F;

    /**
     * 主题名称无效
     */
    public const TOPIC_NAME_INVALID = 0x90;

    /**
     * 数据包标识符已使用
     */
    public const PACKET_IDENTIFIER_IN_USE = 0x91;

    /**
     * 未找到数据包标识符
     */
    public const PACKET_IDENTIFIER_NOT_FOUND = 0x92;

    /**
     * 超过接收最大值
     */
    public const RECEIVE_MAXIMUM_EXCEEDED = 0x93;

    /**
     * 主题别名无效
     */
    public const TOPIC_ALIAS_INVALID = 0x94;

    /**
     * 数据包太大
     */
    public const PACKET_TOO_LARGE = 0x95;

    /**
     * 消息速率过高
     */
    public const MESSAGE_RATE_TOO_HIGH = 0x96;

    /**
     * 超过配额
     */
    public const QUOTA_EXCEEDED = 0x97;

    /**
     * 管理操作
     */
    public const ADMINISTRATIVE_ACTION = 0x98;

    /**
     * 有效载荷格式无效
     */
    public const PAYLOAD_FORMAT_INVALID = 0x99;

    /**
     * 不支持保留消息
     */
    public const RETAIN_NOT_SUPPORTED = 0x9A;

    /**
     * 不支持的QoS级别
     */
    public const QOS_NOT_SUPPORTED = 0x9B;

    /**
     * 使用另一个服务器
     */
    public const USE_ANOTHER_SERVER = 0x9C;

    /**
     * 服务器已移动
     */
    public const SERVER_MOVED = 0x9D;

    /**
     * 不支持共享订阅
     */
    public const SHARED_SUBSCRIPTIONS_NOT_SUPPORTED = 0x9E;

    /**
     * 超过连接速率
     */
    public const CONNECTION_RATE_EXCEEDED = 0x9F;

    /**
     * 最大连接时间
     */
    public const MAXIMUM_CONNECT_TIME = 0xA0;

    /**
     * 不支持订阅标识符
     */
    public const SUBSCRIPTION_IDENTIFIERS_NOT_SUPPORTED = 0xA1;

    /**
     * 不支持通配符订阅
     */
    public const WILDCARD_SUBSCRIPTIONS_NOT_SUPPORTED = 0xA2;

    /**
     * 原因短语映射表
     * @var array
     * @see https://docs.oasis-open.org/mqtt/mqtt/v5.0/os/mqtt-v5.0-os.html#_Toc3901079
     */
    protected static $reasonPhrases = [
        self::SUCCESS                                => 'Success',
        self::UNSPECIFIED_ERROR                      => 'Unspecified error',
        self::MALFORMED_PACKET                       => 'Malformed Packet',
        self::PROTOCOL_ERROR                         => 'Protocol Error',
        self::IMPLEMENTATION_SPECIFIC_ERROR          => 'Implementation specific error',
        self::UNSUPPORTED_PROTOCOL_VERSION           => 'Unsupported Protocol Version',
        self::CLIENT_IDENTIFIER_NOT_VALID            => 'Client Identifier not valid',
        self::BAD_USER_NAME_OR_PASSWORD              => 'Bad User Name or Password',
        self::NOT_AUTHORIZED                         => 'Not authorized',
        self::SERVER_UNAVAILABLE                     => 'Server unavailable',
        self::SERVER_BUSY                            => 'Server busy',
        self::BANNED                                 => 'Banned',
        self::BAD_AUTHENTICATION_METHOD              => 'Bad authentication method',
        self::TOPIC_NAME_INVALID                     => 'Topic Name invalid',
        self::PACKET_TOO_LARGE                       => 'Packet too large',
        self::QUOTA_EXCEEDED                         => 'Quota exceeded',
        self::PAYLOAD_FORMAT_INVALID                 => 'Payload format invalid',
        self::RETAIN_NOT_SUPPORTED                   => 'Retain not supported',
        self::QOS_NOT_SUPPORTED                      => 'QoS not supported',
        self::USE_ANOTHER_SERVER                     => 'Use another server',
        self::SERVER_MOVED                           => 'Server moved',
        self::CONNECTION_RATE_EXCEEDED               => 'Connection rate exceeded',
        self::DISCONNECT_WITH_WILL_MESSAGE           => 'Disconnect with Will Message',
        self::SERVER_SHUTTING_DOWN                   => 'Server shutting down',
        self::KEEP_ALIVE_TIMEOUT                     => 'Keep Alive timeout',
        self::SESSION_TAKEN_OVER                     => 'Session taken over',
        self::TOPIC_FILTER_INVALID                   => 'Topic Filter invalid',
        self::RECEIVE_MAXIMUM_EXCEEDED               => 'Receive Maximum exceeded',
        self::TOPIC_ALIAS_INVALID                    => 'Topic Alias invalid',
        self::MESSAGE_RATE_TOO_HIGH                  => 'Message rate too high',
        self::ADMINISTRATIVE_ACTION                  => 'Administrative action',
        self::SHARED_SUBSCRIPTIONS_NOT_SUPPORTED     => 'Shared Subscriptions not supported',
        self::MAXIMUM_CONNECT_TIME                   => 'Maximum connect time',
        self::SUBSCRIPTION_IDENTIFIERS_NOT_SUPPORTED => 'Subscription Identifiers not supported',
        self::WILDCARD_SUBSCRIPTIONS_NOT_SUPPORTED   => 'Wildcard Subscriptions not supported',
        self::NO_MATCHING_SUBSCRIBERS                => 'No matching subscribers',
        self::NO_SUBSCRIPTION_EXISTED                => 'No subscription existed',
        self::CONTINUE_AUTHENTICATION                => 'Continue authentication',
        self::RE_AUTHENTICATE                        => 'Re-authenticate',
        self::PACKET_IDENTIFIER_IN_USE               => 'Packet Identifier in use',
        self::PACKET_IDENTIFIER_NOT_FOUND            => 'Packet Identifier not found',
    ];

    /**
     * QoS原因短语映射表
     * @var array
     */
    protected static $qosReasonPhrases = [
        self::GRANTED_QOS_0 => 'Granted QoS 0',
        self::GRANTED_QOS_1 => 'Granted QoS 1',
        self::GRANTED_QOS_2 => 'Granted QoS 2',
    ];

    /**
     * 获取原因短语映射表
     *
     * @param bool $isQos 是否获取QoS原因短语
     * @return array 原因短语映射表
     */
    public static function getReasonPhrases(bool $isQos = false): array
    {
        if ($isQos) {
            return static::$qosReasonPhrases;
        }

        return static::$reasonPhrases;
    }

    /**
     * 获取指定值的原因短语
     *
     * @param int $value 原因码值
     * @param bool $isQos 是否获取QoS原因短语
     * @return string 原因短语
     */
    public static function getReasonPhrase(int $value, bool $isQos = false): string
    {
        if ($isQos) {
            return static::$qosReasonPhrases[$value] ?? 'QoS not supported';
        }

        return static::$reasonPhrases[$value] ?? 'Unknown';
    }

    /**
     * 获取原因短语
     *
     * @param int $value 原因码值
     * @return string 原因短语
     */
    public static function getReason(int $value): string
    {
        return static::$reasonPhrases[$value] ?? (static::$qosReasonPhrases[$value] ?? 'QoS not supported');
    }
}
