<?php
/**
 * 通知消息对象
 *
 * 与渠道无关的统一消息载体：主题 + 正文 + 结构化数据。
 *
 * ```php
 * $msg = NotificationMessage::create('工单已分配', '请查看工单 #123')
 *     ->line('负责人：张三')
 *     ->data(['ticket_id' => 123, 'url' => '/#/Tickets/detail.html?id=123']);
 * ```
 *
 * @package Candy\Extend\Notification
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:30:00
 */

declare(strict_types=1);

namespace Candy\Extend\Notification;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class NotificationMessage
{
    /** @var string 主题/标题 */
    private string $subject = '';

    /** @var array<int, string> 正文行 */
    private array $lines = [];

    /** @var array<string, mixed> 结构化数据（数据库渠道存这里，Webhook 也带上） */
    private array $data = [];

    /** @var string|null 跳转链接 */
    private ?string $url = null;

    /** @var string 级别：info|success|warning|error */
    private string $level = 'info';

    /**
     * 创建消息
     *
     * @param string $subject 主题
     * @param string $content 首行正文
     * @return self
     */
    public static function create(string $subject = '', string $content = ''): self
    {
        $msg = new self();
        $msg->subject = $subject;
        if ($content !== '') {
            $msg->lines[] = $content;
        }

        return $msg;
    }

    /**
     * 追加一行正文
     *
     * @param string $line 正文行
     * @return self
     */
    public function line(string $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    /**
     * 合并结构化数据
     *
     * @param array<string, mixed> $data 数据
     * @return self
     */
    public function data(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    /**
     * 设置跳转链接
     *
     * @param string $url 链接
     * @return self
     */
    public function url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * 设置级别
     *
     * @param string $level info|success|warning|error
     * @return self
     */
    public function level(string $level): self
    {
        $this->level = $level;

        return $this;
    }

    /**
     * 取主题
     *
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * 取链接
     *
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * 取级别
     *
     * @return string
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    /**
     * 取结构化数据
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 纯文本正文
     *
     * @return string
     */
    public function getText(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * HTML 正文（邮件渠道用）
     *
     * @return string
     */
    public function getHtml(): string
    {
        $body = '';
        foreach ($this->lines as $line) {
            $body .= '<p style="margin:0 0 8px;line-height:1.7;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if ($this->url !== null && $this->url !== '') {
            $href = htmlspecialchars($this->url, ENT_QUOTES, 'UTF-8');
            $body .= '<p style="margin:16px 0 0;"><a href="' . $href . '" style="color:#3b82f6;">查看详情</a></p>';
        }

        $color = match ($this->level) {
            'success' => '#16a34a',
            'warning' => '#d97706',
            'error'   => '#dc2626',
            default   => '#3b82f6',
        };

        return '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;color:#334155;">'
            . '<h3 style="margin:0 0 12px;font-size:16px;color:' . $color . ';">'
            . htmlspecialchars($this->subject, ENT_QUOTES, 'UTF-8') . '</h3>'
            . $body . '</div>';
    }

    /**
     * 转数组（数据库渠道 / Webhook 用）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'text'    => $this->getText(),
            'html'    => $this->getHtml(),
            'data'    => $this->data,
            'url'     => $this->url,
            'level'   => $this->level,
        ];
    }
}
