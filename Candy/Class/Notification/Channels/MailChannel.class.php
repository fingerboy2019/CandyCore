<?php
/**
 * 邮件通知渠道
 *
 * 复用 Candy\Extend\Network\Mail\Smtp。收件人可以是：
 * - 字符串邮箱
 * - ['email' => '...'] 或 ['username' => '...']
 * - 数组列表（多收件人）
 *
 * @package Candy\Extend\Notification\Channels
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:30:00
 */

declare(strict_types=1);

namespace Candy\Extend\Notification\Channels;

use Candy\Extend\Notification\{ChannelInterface, NotificationMessage};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class MailChannel implements ChannelInterface
{
    /**
     * 发送邮件
     *
     * @param mixed                $notifiable 收件人
     * @param NotificationMessage  $message    消息
     * @param array<string, mixed> $config     渠道配置：host/port/user/pass/secure/from/from_name
     * @return array{ok: bool, message: string}
     */
    public function send(mixed $notifiable, NotificationMessage $message, array $config = []): array
    {
        $to = $this->resolveAddress($notifiable);
        if ($to === []) {
            return ['ok' => false, 'message' => '收件人为空'];
        }

        $host = (string) ($config['host'] ?? '');
        if ($host === '') {
            return ['ok' => false, 'message' => '未配置 SMTP host'];
        }

        try {
            $smtp = new \Candy\Extend\Network\Mail\Smtp(
                $host,
                (int) ($config['port'] ?? 25),
                (bool) ($config['auth'] ?? true),
                (string) ($config['user'] ?? $config['username'] ?? ''),
                (string) ($config['pass'] ?? $config['password'] ?? ''),
                (string) ($config['secure'] ?? '')
            );

            $from = (string) ($config['from'] ?? $config['user'] ?? $config['username'] ?? '');
            $fromName = (string) ($config['from_name'] ?? '');
            $ok = $smtp->sendMail(
                implode(',', $to),
                $from,
                $message->getSubject(),
                $message->getHtml(),
                'HTML'
            );

            return $ok
                ? ['ok' => true, 'message' => '发送成功']
                : ['ok' => false, 'message' => 'Smtp::sendMail 返回 false'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 解析收件人地址
     *
     * @param mixed $notifiable 收件人
     * @return array<int, string>
     */
    private function resolveAddress(mixed $notifiable): array
    {
        if (is_string($notifiable)) {
            return $notifiable === '' ? [] : [$notifiable];
        }

        if (is_array($notifiable)) {
            if (isset($notifiable['email']) && is_string($notifiable['email'])) {
                return $notifiable['email'] === '' ? [] : [$notifiable['email']];
            }
            // 数组列表
            if (array_is_list($notifiable)) {
                $out = [];
                foreach ($notifiable as $item) {
                    foreach ($this->resolveAddress($item) as $addr) {
                        $out[] = $addr;
                    }
                }

                return $out;
            }
            foreach (['mail', 'address', 'to'] as $key) {
                if (isset($notifiable[$key]) && is_string($notifiable[$key]) && $notifiable[$key] !== '') {
                    return [$notifiable[$key]];
                }
            }
        }

        return [];
    }
}
