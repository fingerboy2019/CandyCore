<?php

namespace Workerman\Mqtt\Consts;

/**
 * MQTT 5.0属性常量类
 * @see https://docs.oasis-open.org/mqtt/mqtt/v5.0/os/mqtt-v5.0-os.html#_Toc3901029
 */
class PropertyConst
{
    /**
     * 有效载荷格式指示器
     */
    public const PAYLOAD_FORMAT_INDICATOR = 0x01;

    /**
     * 消息过期时间间隔
     */
    public const MESSAGE_EXPIRY_INTERVAL = 0x02;

    /**
     * 内容类型
     */
    public const CONTENT_TYPE = 0x03;

    /**
     * 响应主题
     */
    public const RESPONSE_TOPIC = 0x08;

    /**
     * 关联数据
     */
    public const CORRELATION_DATA = 0x09;

    /**
     * 订阅标识符
     */
    public const SUBSCRIPTION_IDENTIFIER = 0x0B;

    /**
     * 会话过期时间间隔
     */
    public const SESSION_EXPIRY_INTERVAL = 0x11;

    /**
     * 分配的客户端标识符
     */
    public const ASSIGNED_CLIENT_IDENTIFIER = 0x12;

    /**
     * 服务器心跳间隔
     */
    public const SERVER_KEEP_ALIVE = 0x13;

    /**
     * 认证方法
     */
    public const AUTHENTICATION_METHOD = 0x15;

    /**
     * 认证数据
     */
    public const AUTHENTICATION_DATA = 0x16;

    /**
     * 请求问题信息
     */
    public const REQUEST_PROBLEM_INFORMATION = 0x17;

    /**
     * 遗嘱延迟时间间隔
     */
    public const WILL_DELAY_INTERVAL = 0x18;

    /**
     * 请求响应信息
     */
    public const REQUEST_RESPONSE_INFORMATION = 0x19;

    /**
     * 响应信息
     */
    public const RESPONSE_INFORMATION = 0x1A;

    /**
     * 服务器参考
     */
    public const SERVER_REFERENCE = 0x1C;

    /**
     * 原因字符串
     */
    public const REASON_STRING = 0x1F;

    /**
     * 接收最大值
     */
    public const RECEIVE_MAXIMUM = 0x21;

    /**
     * 主题别名最大值
     */
    public const TOPIC_ALIAS_MAXIMUM = 0x22;

    /**
     * 主题别名
     */
    public const TOPIC_ALIAS = 0x23;

    /**
     * 最大QoS级别
     */
    public const MAXIMUM_QOS = 0x24;

    /**
     * 保留消息可用
     */
    public const RETAIN_AVAILABLE = 0x25;

    /**
     * 用户属性
     */
    public const USER_PROPERTY = 0x26;

    /**
     * 最大数据包大小
     */
    public const MAXIMUM_PACKET_SIZE = 0x27;

    /**
     * 通配符订阅可用
     */
    public const WILDCARD_SUBSCRIPTION_AVAILABLE = 0x28;

    /**
     * 订阅标识符可用
     */
    public const SUBSCRIPTION_IDENTIFIER_AVAILABLE = 0x29;

    /**
     * 共享订阅可用
     */
    public const SHARED_SUBSCRIPTION_AVAILABLE = 0x2A;
}
