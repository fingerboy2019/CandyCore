<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * 定时器回调的实现类。
 *
 * @internal
 */
final class TimerCallback extends DriverCallback
{
    /**
     * 构造函数。
     *
     * @param string   $id         回调标识符。
     * @param float    $interval   时间间隔（秒）。
     * @param \Closure $callback   回调函数。
     * @param float    $expiration 到期时间戳。
     * @param bool     $repeat     是否重复执行。
     */
    public function __construct(
        string $id,
        public readonly float $interval,
        \Closure $callback,
        public float $expiration,
        public readonly bool $repeat = false
    ) {
        parent::__construct($id, $callback);
    }
}
