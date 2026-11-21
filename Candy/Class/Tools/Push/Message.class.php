<?php
/***
 * 推送消息体
 *
 * 统一结构，避免各应用自己拼：
 *   {msg_id, title, body, payload, ts}
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:44:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Message
{
    /**
     * 构造一条消息
     *
     * @param string               $body    正文
     * @param array<string, mixed> $payload 附加数据
     * @param string               $title   标题
     * @return array<string, mixed>
     */
    public static function make(string $body, array $payload = [], string $title = ''): array
    {
        return [
            'msg_id'  => bin2hex(random_bytes(8)),
            'title'   => $title,
            'body'    => $body,
            'payload' => $payload,
            'ts'      => time(),
        ];
    }

    /**
     * 归一化任意输入为消息体（字符串 → 正文；数组 → 补齐字段）
     *
     * @param mixed $input 消息
     * @return array<string, mixed>
     */
    public static function normalize(mixed $input): array
    {
        if (is_string($input)) {
            return self::make($input);
        }
        if (!is_array($input)) {
            return self::make('');
        }
        $body = (string) ($input['body'] ?? $input['msg'] ?? '');
        $out = self::make(
            $body,
            is_array($input['payload'] ?? null) ? $input['payload'] : [],
            (string) ($input['title'] ?? '')
        );
        if (isset($input['msg_id']) && $input['msg_id'] !== '') {
            $out['msg_id'] = (string) $input['msg_id'];
        }
        if (isset($input['ts'])) {
            $out['ts'] = (int) $input['ts'];
        }
        return $out;
    }

    /**
     * 序列化为下发帧
     *
     * @param array<string, mixed> $msg 消息
     * @return string
     */
    public static function encode(array $msg): string
    {
        $frame = ['act' => 'message'] + $msg;
        return (string) json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 序列化大小（字节）
     *
     * @param array<string, mixed> $msg 消息
     * @return int
     */
    public static function bytes(array $msg): int
    {
        return strlen(self::encode($msg));
    }

    /**
     * 是否超出允许大小
     *
     * @param array<string, mixed> $msg 消息
     * @return bool
     */
    public static function tooLarge(array $msg): bool
    {
        $max = (int) (Config::get()['message']['max_bytes'] ?? 8192);
        return $max > 0 && self::bytes($msg) > $max;
    }
}
