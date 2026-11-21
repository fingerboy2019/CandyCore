<?php

namespace Workerman\Mqtt\Consts;

/**
 * MQTTConst
 * MQTT常量类，定义了MQTT协议中的各种常量
 */
class MQTTConst
{
    /**
     * MQTT协议级别 3.1
     */
    public const MQTT_PROTOCOL_LEVEL_3_1 = 3;

    /**
     * MQTT协议级别 3.1.1
     */
    public const MQTT_PROTOCOL_LEVEL_3_1_1 = 4;

    /**
     * MQTT协议级别 5.0
     */
    public const MQTT_PROTOCOL_LEVEL_5_0 = 5;

    /**
     * MQIsdp协议名称
     */
    public const MQISDP_PROTOCOL_NAME = 'MQIsdp';

    /**
     * MQTT协议名称
     */
    public const MQTT_PROTOCOL_NAME = 'MQTT';

    /**
     * MQTT QoS 0，最多一次送达
     */
    public const MQTT_QOS_0 = 0;

    /**
     * MQTT QoS 1，至少一次送达
     */
    public const MQTT_QOS_1 = 1;

    /**
     * MQTT QoS 2，恰好一次送达
     */
    public const MQTT_QOS_2 = 2;

    /**
     * MQTT保留标志 0，不保留消息
     */
    public const MQTT_RETAIN_0 = 0;

    /**
     * MQTT保留标志 1，保留消息
     */
    public const MQTT_RETAIN_1 = 1;

    /**
     * MQTT保留标志 2，保留消息，但只有新订阅者会收到
     */
    public const MQTT_RETAIN_2 = 2;

    /**
     * MQTT重复标志 0，不是重复消息
     */
    public const MQTT_DUP_0 = 0;

    /**
     * MQTT重复标志 1，是重复消息
     */
    public const MQTT_DUP_1 = 1;

    /**
     * MQTT会话存在标志 0，不存在会话
     */
    public const MQTT_SESSION_PRESENT_0 = 0;

    /**
     * MQTT会话存在标志 1，存在会话
     */
    public const MQTT_SESSION_PRESENT_1 = 1;

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
     * AUTH命令，认证交换
     */
    public const CMD_AUTH = 15;
}
