<?php

namespace Workerman\Mqtt\Handle\Property;

use Workerman\Mqtt\Consts\PropertyConst as Property;
use Workerman\Mqtt\Handle\Decoder;

/**
 * UnPackProperty
 * MQTT 5.0属性解包类，用于将二进制格式的MQTT 5.0属性解包成PHP数组
 */
class UnPackProperty
{
    /**
     * 解包CONNECT消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function connect(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$connect[$property])) {
                $key = PacketMap::$connect[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::SESSION_EXPIRY_INTERVAL => self::_unpackSessionExpiryInterval($properties, $key, $body, $length),
                    Property::AUTHENTICATION_METHOD, Property::AUTHENTICATION_DATA => self::_unpackAuthenticationMethod($properties, $key, $body, $length),
                    Property::REQUEST_PROBLEM_INFORMATION, Property::REQUEST_RESPONSE_INFORMATION => self::_unpackRequestInformation($properties, $key, $body, $length),
                    Property::RECEIVE_MAXIMUM, Property::TOPIC_ALIAS_MAXIMUM, Property::MAXIMUM_PACKET_SIZE => self::_unpackReceiveMaximum($properties, $key, $body, $length),
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包遗嘱消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function willProperties(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$willProperties[$property])) {
                $key = PacketMap::$willProperties[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::MESSAGE_EXPIRY_INTERVAL, Property::WILL_DELAY_INTERVAL => self::_unpackMessageExpiryInterval($properties, $key, $body, $length),
                    Property::CONTENT_TYPE, Property::RESPONSE_TOPIC, Property::CORRELATION_DATA => self::_unpackContentType($properties, $key, $body, $length),
                    Property::PAYLOAD_FORMAT_INDICATOR => self::_unpackPayloadFormatIndicator($properties, $key, $body, $length),
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包CONNACK消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function connAck(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$connAck[$property])) {
                $key = PacketMap::$connAck[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::SESSION_EXPIRY_INTERVAL, Property::MAXIMUM_PACKET_SIZE => self::_unpackSessionExpiryInterval($properties, $key, $body, $length),
                    Property::SERVER_KEEP_ALIVE, Property::RECEIVE_MAXIMUM, Property::TOPIC_ALIAS_MAXIMUM => self::_unpackServerKeepAlive($properties, $key, $body, $length),
                    Property::ASSIGNED_CLIENT_IDENTIFIER, Property::AUTHENTICATION_METHOD, Property::AUTHENTICATION_DATA, Property::RESPONSE_INFORMATION, Property::SERVER_REFERENCE, Property::REASON_STRING => self::_unpackAssignedClientIdentifier($properties, $key, $body, $length),
                    Property::MAXIMUM_QOS, Property::RETAIN_AVAILABLE, Property::WILDCARD_SUBSCRIPTION_AVAILABLE, Property::SUBSCRIPTION_IDENTIFIER_AVAILABLE, Property::SHARED_SUBSCRIPTION_AVAILABLE => self::_unpackMaximumQos($properties, $key, $body, $length),
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包PUBLISH消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function publish(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$publish[$property])) {
                $key = PacketMap::$publish[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::MESSAGE_EXPIRY_INTERVAL => self::_unpackMessageExpiryInterval($properties, $key, $body, $length),
                    Property::TOPIC_ALIAS => self::_unpackTopicAlias($properties, $key, $body, $length),
                    Property::CONTENT_TYPE, Property::RESPONSE_TOPIC, Property::CORRELATION_DATA => self::_unpackContentType($properties, $key, $body, $length),
                    Property::PAYLOAD_FORMAT_INDICATOR => self::_unpackPayloadFormatIndicator($properties, $key, $body, $length),
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    Property::SUBSCRIPTION_IDENTIFIER => self::_unpackSubscriptionIdentifier($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包PUBACK、PUBREC、PUBREL、PUBCOMP、SUBACK、UNSUBACK消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function pubAndSub(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$pubAndSub[$property])) {
                $key = PacketMap::$pubAndSub[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::REASON_STRING => self::_unpackReasonString($properties, $key, $body, $length),
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包SUBSCRIBE消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function subscribe(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$subscribe[$property])) {
                $key = PacketMap::$subscribe[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    Property::SUBSCRIPTION_IDENTIFIER => self::_unpackSubscriptionIdentifier($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包UNSUBSCRIBE消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function unSubscribe(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$unSubscribe[$property])) {
                $body = substr($body, 1);
                match ($property) {
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, null, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包DISCONNECT消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function disConnect(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$disConnect[$property])) {
                $key = PacketMap::$disConnect[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::SESSION_EXPIRY_INTERVAL => self::_unpackSessionExpiryInterval($properties, $key, $body, $length),
                    Property::SERVER_REFERENCE, Property::REASON_STRING => self::_unpackServerReference($properties, $key, $body, $length),
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包AUTH消息的属性
     *
     * @param int $length 属性长度
     * @param string $body 消息体
     * @return array 解包后的属性数组
     */
    public static function auth(int $length, string &$body): array
    {
        $properties = [];
        do {
            $property = ord($body[0]);
            if (isset(PacketMap::$auth[$property])) {
                $key = PacketMap::$auth[$property];
                $body = substr($body, 1);
                match ($property) {
                    Property::AUTHENTICATION_METHOD, Property::AUTHENTICATION_DATA, Property::REASON_STRING => self::_unpackAuthenticationMethod($properties, $key, $body, $length),
                    Property::USER_PROPERTY => self::_unpackUserProperty($properties, $key, $body, $length),
                    default => throw new \InvalidArgumentException("Property [0x" . dechex($property) . "] not exist"),
                };
            } else {
                $errType = dechex($property);
                throw new \InvalidArgumentException("Property [0x{$errType}] not exist");
            }
        } while ($length > 0);

        return $properties;
    }

    /**
     * 解包SESSION_EXPIRY_INTERVAL属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackSessionExpiryInterval(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::longInt($body);
        $length -= 5;
    }

    /**
     * 解包AUTHENTICATION_METHOD/AUTHENTICATION_DATA属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackAuthenticationMethod(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::readString($body);
        $length -= 3;
        $length -= strlen($properties[$key]);
    }

    /**
     * 解包REQUEST_PROBLEM_INFORMATION/REQUEST_RESPONSE_INFORMATION属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackRequestInformation(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::byte($body);
        $length -= 2;
    }

    /**
     * 解包RECEIVE_MAXIMUM/TOPIC_ALIAS_MAXIMUM/MAXIMUM_PACKET_SIZE属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackReceiveMaximum(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::shortInt($body);
        $length -= 3;
    }

    /**
     * 解包SERVER_KEEP_ALIVE/RECEIVE_MAXIMUM/TOPIC_ALIAS_MAXIMUM属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackServerKeepAlive(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::shortInt($body);
        $length -= 3;
    }

    /**
     * 解包ASSIGNED_CLIENT_IDENTIFIER等字符串属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackAssignedClientIdentifier(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::readString($body);
        $length -= 3;
        $length -= strlen($properties[$key]);
    }

    /**
     * 解包MAXIMUM_QOS等字节属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackMaximumQos(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::byte($body);
        $length -= 2;
    }

    /**
     * 解包MESSAGE_EXPIRY_INTERVAL/WILL_DELAY_INTERVAL属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackMessageExpiryInterval(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::longInt($body);
        $length -= 5;
    }

    /**
     * 解包CONTENT_TYPE/RESPONSE_TOPIC/CORRELATION_DATA属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackContentType(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::readString($body);
        $length -= 3;
        $length -= strlen($properties[$key]);
    }

    /**
     * 解包PAYLOAD_FORMAT_INDICATOR属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackPayloadFormatIndicator(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::byte($body);
        $length -= 2;
    }

    /**
     * 解包TOPIC_ALIAS属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackTopicAlias(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::shortInt($body);
        $length -= 3;
    }

    /**
     * 解包SUBSCRIPTION_IDENTIFIER属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackSubscriptionIdentifier(array &$properties, string $key, string &$body, int &$length): void
    {
        $length -= 1;
        $properties[$key] = Decoder::varInt($body, $len);
        $length -= $len;
    }

    /**
     * 解包REASON_STRING属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackReasonString(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::readString($body);
        $length -= 3;
        $length -= strlen($properties[$key]);
    }

    /**
     * 解包SERVER_REFERENCE属性
     *
     * @param array $properties 属性数组
     * @param string $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackServerReference(array &$properties, string $key, string &$body, int &$length): void
    {
        $properties[$key] = Decoder::readString($body);
        $length -= 3;
        $length -= strlen($properties[$key]);
    }

    /**
     * 解包USER_PROPERTY属性
     *
     * @param array $properties 属性数组
     * @param string|null $key 属性键
     * @param string $body 消息体
     * @param int $length 属性长度
     * @return void
     */
    private static function _unpackUserProperty(array &$properties, ?string $key, string &$body, int &$length): void
    {
        $userKey = Decoder::readString($body);
        $userValue = Decoder::readString($body);
        if ($key !== null) {
            $properties[$key] = $userValue;
        } else {
            $properties[$userKey] = $userValue;
        }
        $length -= 5;
        $length -= strlen($userKey);
        $length -= strlen($userValue);
    }
}
