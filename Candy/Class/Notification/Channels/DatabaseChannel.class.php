<?php
/**
 * 数据库通知渠道
 *
 * 写入指定表（默认 notifications，自动带表前缀）。表结构要求：
 *   notifiable_type / notifiable_id / type / data / read_at / created_at
 *
 * 未指定 table 且该表不存在时降级为写文件（Runtime/Tmps/notifications/），
 * 保证业务不因缺表而中断。
 *
 * @package Candy\Extend\Notification\Channels
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:30:00
 */

declare(strict_types=1);

namespace Candy\Extend\Notification\Channels;

use Candy\Extend\Notification\{ChannelInterface, NotificationMessage};

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class DatabaseChannel implements ChannelInterface
{
    /**
     * 写入数据库
     *
     * @param mixed                $notifiable 收件人标识
     * @param NotificationMessage  $message    消息
     * @param array<string, mixed> $config     table / type
     * @return array{ok: bool, message: string}
     */
    public function send(mixed $notifiable, NotificationMessage $message, array $config = []): array
    {
        $table = (string) ($config['table'] ?? 'notifications');
        $type = (string) ($config['type'] ?? 'system');

        [$ntype, $nid] = $this->splitNotifiable($notifiable);

        $row = [
            'notifiable_type' => $ntype,
            'notifiable_id'   => $nid,
            'type'            => $type,
            'data'            => json_encode($message->toArray(), JSON_UNESCAPED_UNICODE),
            'created_at'      => date('Y-m-d H:i:s'),
        ];

        try {
            $affected = M($table)->add($row);
            if ($affected) {
                return ['ok' => true, 'message' => '已写入 ' . $table];
            }
        } catch (\Throwable $e) {
            // 表不存在或写入失败 → 降级写文件
        }

        return $this->fallbackToFile($nid, $message, $type);
    }

    /**
     * 拆解收件人标识
     *
     * @param mixed $notifiable 收件人
     * @return array{0: string, 1: string}
     */
    private function splitNotifiable(mixed $notifiable): array
    {
        if (is_array($notifiable)) {
            $type = (string) ($notifiable['type'] ?? 'user');
            $id = (string) ($notifiable['id'] ?? $notifiable['user_id'] ?? '');

            return [$type, $id];
        }

        return ['user', is_scalar($notifiable) ? (string) $notifiable : ''];
    }

    /**
     * 降级写文件
     *
     * @param string               $nid     收件人 id
     * @param NotificationMessage  $message 消息
     * @param string               $type    通知类型
     * @return array{ok: bool, message: string}
     */
    private function fallbackToFile(string $nid, NotificationMessage $message, string $type): array
    {
        try {
            $dir = rtrim((string) C('RUNTIME'), '/\\') . DIRECTORY_SEPARATOR . 'Tmps' . DIRECTORY_SEPARATOR . 'notifications';
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ['ok' => false, 'message' => '无法创建通知目录'];
            }
            $file = $dir . DIRECTORY_SEPARATOR . date('Ymd') . '.log';
            $line = json_encode([
                'to'     => $nid,
                'type'   => $type,
                'at'     => date('Y-m-d H:i:s'),
                'notify' => $message->toArray(),
            ], JSON_UNESCAPED_UNICODE);

            file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

            return ['ok' => true, 'message' => '已降级写入 ' . $file];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
