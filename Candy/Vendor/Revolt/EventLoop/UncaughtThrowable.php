<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

use Revolt\EventLoop\Internal\ClosureHelper;

/**
 * 当事件循环中的回调或错误处理程序抛出未捕获的异常时使用的错误类。
 */
final class UncaughtThrowable extends \Error
{
    /**
     * 创建一个新的未捕获异常错误，该错误由回调函数抛出。
     *
     * @param \Closure $closure 抛出异常的回调函数。
     * @param \Throwable $previous 被抛出的原始异常。
     *
     * @return self
     */
    public static function throwingCallback(\Closure $closure, \Throwable $previous): self
    {
        return new self(
            "Uncaught %s thrown in event loop callback %s; use Revolt\EventLoop::setErrorHandler() to gracefully handle such exceptions%s",
            $closure,
            $previous
        );
    }

    /**
     * 创建一个新的未捕获异常错误，该错误由错误处理程序抛出。
     *
     * @param \Closure $closure 抛出异常的错误处理程序。
     * @param \Throwable $previous 被抛出的原始异常。
     *
     * @return self
     */
    public static function throwingErrorHandler(\Closure $closure, \Throwable $previous): self
    {
        return new self("Uncaught %s thrown in event loop error handler %s%s", $closure, $previous);
    }

    /**
     * 构造函数。
     *
     * @param string $message 错误消息模板。
     * @param \Closure $closure 抛出异常的函数。
     * @param \Throwable $previous 被抛出的原始异常。
     */
    private function __construct(string $message, \Closure $closure, \Throwable $previous)
    {
        parent::__construct(\sprintf(
            $message,
            \str_replace("\0", '@', \get_class($previous)), // replace NUL-byte in anonymous class name
            ClosureHelper::getDescription($closure),
            $previous->getMessage() !== '' ? ': ' . $previous->getMessage() : ''
        ), 0, $previous);
    }
}
