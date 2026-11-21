<?php
/**
 * 通知渠道接口
 *
 * 实现类负责把一份 NotificationMessage 投递到一个渠道。
 *
 * @package Candy\Extend\Notification
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:30:00
 */

declare(strict_types=1);

namespace Candy\Extend\Notification;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface ChannelInterface
{
    /**
     * 发送通知
     *
     * @param mixed               $notifiable 收件人（邮件地址 / 用户数组 / 任意标识）
     * @param NotificationMessage $message    消息
     * @param array<string, mixed> $config    渠道配置
     * @return array{ok: bool, message: string}
     */
    public function send(mixed $notifiable, NotificationMessage $message, array $config = []): array;
}
