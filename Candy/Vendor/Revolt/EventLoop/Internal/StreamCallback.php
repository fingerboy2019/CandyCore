<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * 流回调的抽象基类。
 *
 * @internal
 */
abstract class StreamCallback extends DriverCallback
{
    /**
     * 构造函数。
     *
     * @param string   $id      回调标识符。
     * @param \Closure $closure 回调函数。
     * @param resource $stream  流资源。
     */
    public function __construct(
        string $id,
        \Closure $closure,
        public readonly mixed $stream
    ) {
        parent::__construct($id, $closure);
    }
}
