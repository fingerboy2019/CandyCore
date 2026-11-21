<?php

namespace Workerman\Mqtt\Handle;

use Workerman\Mqtt\Consts\MQTTConst;
use Workerman\Mqtt\Consts\ReasonCodeConst;
use Workerman\Mqtt\Handle\Property\PackProperty;

/**
 * EncodeV5
 * MQTT 5.0消息编码类，用于将MQTT 5.0消息编码成二进制格式
 */
class EncodeV5
{
    /**
     * 编码CONNECT消息
     *
     * @param array $data CONNECT消息数据
     * @return string 编码后的CONNECT消息
     */
    public static function connect(array $data): string
    {
        $body = Encoder::packString($data['protocol_name']) . chr($data['protocol_level']);
        $connect_flags = 0;
        if (!empty($data['clean_session'])) {
            $connect_flags |= 1 << 1;
        }
        if (!empty($data['will'])) {
            $connect_flags |= 1 << 2;
            // qos > 2 是否 support
            $connect_flags |= $data['will']['qos'] << 3;
            if (!empty($data['will']['retain'])) {
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
        $body .= Encoder::shortInt($keepalive);

        // CONNECT Properties for MQTT5
        $body .= PackProperty::connect($data['properties'] ?? []);

        $body .= Encoder::packString($data['client_id']);
        if (!empty($data['will'])) {
            // Will Properties for MQTT5
            $body .= PackProperty::willProperties($data['will']['properties'] ?? []);

            $body .= Encoder::packString($data['will']['topic']);
            $body .= Encoder::packString($data['will']['content']);
        }
        if (!empty($data['username']) || $data['username'] === '0') {
            $body .= Encoder::packString($data['username']);
        }
        if (!empty($data['password']) || $data['password'] === '0') {
            $body .= Encoder::packString($data['password']);
        }
        $head = Encoder::packHead(MQTTConst::CMD_CONNECT, strlen($body));

        return $head . $body;
    }

    /**
     * 编码CONNACK消息
     *
     * @param array $data CONNACK消息数据
     * @return string 编码后的CONNACK消息
     */
    public static function connAck(array $data): string
    {
        $body = !empty($data['session_present']) ? chr(1) : chr(0);
        $code = !empty($data['code']) ? $data['code'] : 0;
        $body .= chr($code);

        // CONNACK Properties  for MQTT5
        $body .= PackProperty::connAck($data['properties'] ?? []);

        $head = Encoder::packHead(MQTTConst::CMD_CONNACK, strlen($body));

        return $head . $body;
    }

    /**
     * 编码PUBLISH消息
     *
     * @param array $data PUBLISH消息数据
     * @return string 编码后的PUBLISH消息
     */
    public static function publish(array $data): string
    {
        $body = Encoder::packString($data['topic']);
        $qos = $data['qos'] ?? 0;
        if ($qos) {
            $body .= Encoder::shortInt($data['message_id']);
        }
        $dup = $data['dup'] ?? 0;
        $retain = $data['retain'] ?? 0;

        // PUBLISH Properties for MQTT5
        $body .= PackProperty::publish($data['properties'] ?? []);

        $body .= $data['content'];
        $head = Encoder::packHead(MQTTConst::CMD_PUBLISH, strlen($body), $dup, $qos, $retain);

        return $head . $body;
    }

    /**
     * 生成带有原因短语的消息
     *
     * @param array $data 消息数据
     * @return string 编码后的消息
     */
    public static function genReasonPhrase(array $data): string
    {
        $body = Encoder::shortInt($data['message_id']);
        $code = !empty($data['code']) ? $data['code'] : ReasonCodeConst::SUCCESS;
        $body .= chr($code);

        // pubAck, pubRec, pubRel, pubComp Properties
        $body .= PackProperty::pubAndSub($data['properties'] ?? []);

        if ($data['cmd'] === MQTTConst::CMD_PUBREL) {
            $head = Encoder::packHead($data['cmd'], strlen($body), 0, 1);
        } else {
            $head = Encoder::packHead($data['cmd'], strlen($body));
        }

        return $head . $body;
    }

    /**
     * 编码SUBSCRIBE消息
     *
     * @param array $data SUBSCRIBE消息数据
     * @return string 编码后的SUBSCRIBE消息
     */
    public static function subscribe(array $data): string
    {
        $body = Encoder::shortInt($data['message_id']);

        // SUBSCRIBE Properties
        $body .= PackProperty::subscribe($data['properties'] ?? []);

        foreach ($data['topics'] as $topic => $options) {
            $body .= Encoder::packString($topic);

            $subscribeOptions = 0;
            if (isset($options['qos'])) {
                $subscribeOptions |= (int) $options['qos'];
            }
            if (isset($options['no_local'])) {
                $subscribeOptions |= (int) $options['no_local'] << 2;
            }
            if (isset($options['retain_as_published'])) {
                $subscribeOptions |= (int) $options['retain_as_published'] << 3;
            }
            if (isset($options['retain_handling'])) {
                $subscribeOptions |= (int) $options['retain_handling'] << 4;
            }
            $body .= chr($subscribeOptions);
        }

        $head = Encoder::packHead(MQTTConst::CMD_SUBSCRIBE, strlen($body), 0, 1);

        return $head . $body;
    }

    /**
     * 编码SUBACK消息
     *
     * @param array $data SUBACK消息数据
     * @return string 编码后的SUBACK消息
     */
    public static function subAck(array $data): string
    {
        $codes = $data['codes'];
        $body = Encoder::shortInt($data['message_id']);

        // SUBACK Properties
        $body .= PackProperty::pubAndSub($data['properties'] ?? []);

        $body .= call_user_func_array(
            'pack',
            array_merge(['C*'], $codes)
        );
        $head = Encoder::packHead(MQTTConst::CMD_SUBACK, strlen($body));

        return $head . $body;
    }

    /**
     * 编码UNSUBSCRIBE消息
     *
     * @param array $data UNSUBSCRIBE消息数据
     * @return string 编码后的UNSUBSCRIBE消息
     */
    public static function unSubscribe(array $data): string
    {
        $body = Encoder::shortInt($data['message_id']);

        // UNSUBSCRIBE Properties
        $body .= PackProperty::unSubscribe($data['properties'] ?? []);

        foreach ($data['topics'] as $topic) {
            $body .= Encoder::packString($topic);
        }
        $head = Encoder::packHead(MQTTConst::CMD_UNSUBSCRIBE, strlen($body), 0, 1);

        return $head . $body;
    }

    /**
     * 编码UNSUBACK消息
     *
     * @param array $data UNSUBACK消息数据
     * @return string 编码后的UNSUBACK消息
     */
    public static function unSubAck(array $data): string
    {
        $body = Encoder::shortInt($data['message_id']);

        // UNSUBACK Properties
        $body .= PackProperty::pubAndSub($data['properties'] ?? []);

        $body .= call_user_func_array(
            'pack',
            array_merge(['C*'], $data['codes'])
        );
        $head = Encoder::packHead(MQTTConst::CMD_UNSUBACK, strlen($body));

        return $head . $body;
    }

    /**
     * 编码DISCONNECT消息
     *
     * @param array $data DISCONNECT消息数据
     * @return string 编码后的DISCONNECT消息
     */
    public static function disconnect(array $data): string
    {
        $code = !empty($data['code']) ? $data['code'] : ReasonCodeConst::NORMAL_DISCONNECTION;
        $body = chr($code);

        // DISCONNECT Properties
        $body .= PackProperty::disConnect($data['properties'] ?? []);

        $head = Encoder::packHead(MQTTConst::CMD_DISCONNECT, strlen($body));

        return $head . $body;
    }

    /**
     * 编码AUTH消息
     *
     * @param array $data AUTH消息数据
     * @return string 编码后的AUTH消息
     */
    public static function auth(array $data): string
    {
        $code = !empty($data['code']) ? $data['code'] : ReasonCodeConst::SUCCESS;
        $body = chr($code);

        // AUTH Properties
        $body .= PackProperty::auth($data['properties'] ?? []);

        $head = Encoder::packHead(MQTTConst::CMD_AUTH, strlen($body));

        return $head . $body;
    }
}
