<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * 当系统不支持某个功能时必须抛出此异常。
 *
 * 这可能发生在 ext-pcntl 缺失且循环驱动程序不支持其他发送信号方式的情况下。
 */
final class UnsupportedFeatureException extends \Exception
{
}
