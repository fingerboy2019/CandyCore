<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * 信号回调的实现类。
 *
 * @internal
 */
final class SignalCallback extends DriverCallback
{
    /**
     * 构造函数。
     *
     * @param string   $id      回调标识符。
     * @param \Closure $closure 回调函数。
     * @param int      $signal  信号编号。
     */
    public function __construct(
        string $id,
        \Closure $closure,
        public readonly int $signal
    ) {
        parent::__construct($id, $closure);
    }
}
