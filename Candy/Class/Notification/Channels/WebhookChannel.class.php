<?php
/**
 * Webhook 通知渠道
 *
 * 把消息以 JSON POST 到指定 URL，适配企业微信/钉钉/自建网关。
 *
 * @package Candy\Extend\Notification\Channels
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:30:00
 */

declare(strict_types=1);

namespace Candy\Extend\Notification\Channels;

use Candy\Extend\Notification\{ChannelInterface, NotificationMessage};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class WebhookChannel implements ChannelInterface
{
    /**
     * 发送 Webhook
     *
     * @param mixed                $notifiable 忽略（URL 来自 config）
     * @param NotificationMessage  $message    消息
     * @param array<string, mixed> $config     url / timeout / headers / wrap
     * @return array{ok: bool, message: string}
     */
    public function send(mixed $notifiable, NotificationMessage $message, array $config = []): array
    {
        $url = (string) ($config['url'] ?? '');
        if ($url === '' && is_string($notifiable)) {
            $url = $notifiable;
        }
        if ($url === '') {
            return ['ok' => false, 'message' => '未配置 webhook url'];
        }

        $payload = $message->toArray();
        // 企业微信/钉钉需要包一层，可用 wrap 指定外层键
        $wrap = (string) ($config['wrap'] ?? '');
        if ($wrap === 'wecom') {
            $payload = ['msgtype' => 'markdown', 'markdown' => ['content' => $message->getText()]];
        } elseif ($wrap === 'dingtalk') {
            $payload = ['msgtype' => 'text', 'text' => ['content' => $message->getSubject() . "\n" . $message->getText()]];
        } elseif ($wrap !== '') {
            $payload = [$wrap => $payload];
        }

        $headers = array_merge(['Content-Type: application/json; charset=utf-8'], (array) ($config['headers'] ?? []));

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => (int) ($config['timeout'] ?? 10),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);

            if ($body === false) {
                return ['ok' => false, 'message' => 'curl 失败：' . $err];
            }

            return $code >= 200 && $code < 300
                ? ['ok' => true, 'message' => 'HTTP ' . $code]
                : ['ok' => false, 'message' => 'HTTP ' . $code . ' ' . mb_substr((string) $body, 0, 200)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
