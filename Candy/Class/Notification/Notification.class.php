<?php
/**
 * 通知门面
 *
 * 统一入口，把一份消息按指定渠道投递给收件人。渠道失败互不影响，
 * 返回每个渠道的结果，由调用方决定是否重试/告警。
 *
 * ```php
 * use Candy\Extend\Notification\{Notification, NotificationMessage};
 *
 * $result = Notification::to(['email' => 'a@b.com'])
 *     ->via(['mail', 'database'])
 *     ->send(NotificationMessage::create('工单已分配', '请查看工单 #123')
 *         ->url('https://xxx/#/Tickets/detail.html?id=123'));
 * // ['mail' => ['ok'=>true,'message'=>'...'], 'database' => [...]]
 * ```
 *
 * 渠道配置来自 `Source/Inc/Notification.inc.php`（可选）：
 * ```php
 * return [
 *     'mail'     => ['host' => 'smtp.163.com', 'port' => 465, 'secure' => 'ssl',
 *                    'user' => 'x@163.com', 'pass' => '...', 'from' => 'x@163.com'],
 *     'webhook'  => ['url' => 'https://...', 'wrap' => 'wecom'],
 *     'database' => ['table' => 'notifications'],
 * ];
 * ```
 *
 * @package Candy\Extend\Notification
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:30:00
 */

declare(strict_types=1);

namespace Candy\Extend\Notification;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Notification
{
    /** 定义文件（相对 CANDYROOT） */
    public const CONFIG_PATH = 'Source/Inc/Notification.inc.php';

    /** @var array<string, class-string<ChannelInterface>> 内置渠道 */
    private const CHANNELS = [
        'mail'     => Channels\MailChannel::class,
        'webhook'  => Channels\WebhookChannel::class,
        'database' => Channels\DatabaseChannel::class,
    ];

    /** @var array<string, class-string<ChannelInterface>> 自定义渠道 */
    private static array $custom = [];

    /** @var array<string, mixed> 渠道配置缓存 */
    private static ?array $config = null;

    /** @var mixed 收件人 */
    private mixed $notifiable = null;

    /** @var array<int, string> 渠道 */
    private array $channels = [];

    /**
     * 指定收件人
     *
     * @param mixed $notifiable 收件人（邮箱 / 用户数组 / id）
     * @return self
     */
    public static function to(mixed $notifiable): self
    {
        $self = new self();
        $self->notifiable = $notifiable;

        return $self;
    }

    /**
     * 指定渠道
     *
     * @param string|array<int, string> $channels 渠道名，如 mail / webhook / database
     * @return self
     */
    public function via(string|array $channels): self
    {
        $this->channels = array_values(array_filter((array) $channels, static fn ($c) => $c !== ''));

        return $this;
    }

    /**
     * 发送
     *
     * @param NotificationMessage $message 消息
     * @return array<string, array{ok: bool, message: string}> 每个渠道的结果
     */
    public function send(NotificationMessage $message): array
    {
        $channels = $this->channels !== [] ? $this->channels : ['mail'];
        $config = self::config();
        $result = [];

        foreach ($channels as $name) {
            $class = self::$custom[$name] ?? self::CHANNELS[$name] ?? null;
            if ($class === null || !class_exists($class)) {
                $result[$name] = ['ok' => false, 'message' => '渠道不存在：' . $name];
                continue;
            }

            try {
                $channel = new $class();
                $result[$name] = $channel->send($this->notifiable, $message, (array) ($config[$name] ?? []));
            } catch (\Throwable $e) {
                $result[$name] = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * 注册自定义渠道
     *
     * @param string $name      渠道名
     * @param string $className 实现 ChannelInterface 的类
     * @return void
     */
    public static function extend(string $name, string $className): void
    {
        self::$custom[$name] = $className;
    }

    /**
     * 读取渠道配置
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = rtrim((string) C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR . self::CONFIG_PATH;
        if (!is_file($path)) {
            return self::$config = [];
        }

        $cfg = require $path;

        return self::$config = is_array($cfg) ? $cfg : [];
    }

    /**
     * 清空配置缓存（测试/热重载用）
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$config = null;
        self::$custom = [];
    }
}
