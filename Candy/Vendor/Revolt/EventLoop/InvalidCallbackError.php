<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

use Revolt\EventLoop\Internal\ClosureHelper;

/**
 * 当回调标识符无效或回调返回非空值时抛出的错误。
 */
final class InvalidCallbackError extends \Error
{
    /**
     * 错误代码：回调返回非空值。
     */
    public const E_NONNULL_RETURN = 1;
    
    /**
     * 错误代码：无效的回调标识符。
     */
    public const E_INVALID_IDENTIFIER = 2;

    /**
     * 当任何回调返回非空值时必须抛出。
     *
     * @param string $callbackId 回调标识符。
     * @param \Closure $closure 回调函数。
     *
     * @return self
     */
    public static function nonNullReturn(string $callbackId, \Closure $closure): self
    {
        return new self(
            $callbackId,
            self::E_NONNULL_RETURN,
            'Non-null return value received from callback ' . ClosureHelper::getDescription($closure)
        );
    }

    /**
     * 当尝试使用无效的回调标识符进行任何操作（除了 disable() 和 cancel()）时必须抛出。
     *
     * 无效的回调标识符是任何尚未由驱动程序发出或已被用户取消的标识符。
     *
     * @param string $callbackId 无效的回调标识符。
     *
     * @return self
     */
    public static function invalidIdentifier(string $callbackId): self
    {
        return new self($callbackId, self::E_INVALID_IDENTIFIER, 'Invalid callback identifier ' . $callbackId);
    }

    /**
     * 原始错误消息。
     *
     * @var string
     */
    private readonly string $rawMessage;

    /**
     * 回调标识符。
     *
     * @var string
     */
    private readonly string $callbackId;

    /**
     * 额外信息数组。
     *
     * @var array<string, string>
     */
    private array $info = [];

    /**
     * 构造函数。
     *
     * @param string $callbackId 回调标识符。
     * @param int $code 错误代码。
     * @param string $message 错误消息。
     */
    private function __construct(string $callbackId, int $code, string $message)
    {
        parent::__construct($message, $code);

        $this->callbackId = $callbackId;
        $this->rawMessage = $message;
    }

    /**
     * 获取回调标识符。
     *
     * @return string 回调标识符。
     */
    public function getCallbackId(): string
    {
        return $this->callbackId;
    }

    /**
     * 向错误添加额外信息。
     *
     * @param string $key 信息键名。
     * @param string $message 信息内容。
     */
    public function addInfo(string $key, string $message): void
    {
        $this->info[$key] = $message;

        $info = '';

        foreach ($this->info as $infoKey => $infoMessage) {
            $info .= "\r\n\r\n" . $infoKey . ': ' . $infoMessage;
        }

        $this->message = $this->rawMessage . $info;
    }
}
