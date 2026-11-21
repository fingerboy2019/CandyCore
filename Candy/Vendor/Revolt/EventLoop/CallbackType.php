<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * 事件循环回调类型枚举。
 *
 * 定义了事件循环支持的各种回调类型。
 */
enum CallbackType
{
    /**
     * 延迟执行的回调。
     */
    case Defer;
    
    /**
     * 一次性延迟执行的回调。
     */
    case Delay;
    
    /**
     * 重复执行的回调。
     */
    case Repeat;
    
    /**
     * 可读事件回调。
     */
    case Readable;
    
    /**
     * 可写事件回调。
     */
    case Writable;
    
    /**
     * 信号事件回调。
     */
    case Signal;
}
