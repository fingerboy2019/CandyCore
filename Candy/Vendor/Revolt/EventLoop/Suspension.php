<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * 应该用于运行和挂起事件循环，而不是直接与纤程交互。
 *
 * **示例**
 *
 * ```php
 * $suspension = EventLoop::getSuspension();
 *
 * $promise->then(
 *     fn (mixed $value) => $suspension->resume($value),
 *     fn (Throwable $error) => $suspension->throw($error)
 * );
 *
 * $suspension->suspend();
 * ```
 *
 * @template T
 */
interface Suspension
{
    /**
     * 恢复挂起的执行并返回指定的值。
     *
     * @param T $value 从 {@see suspend()} 调用返回的值。
     */
    public function resume(mixed $value = null): void;

    /**
     * 返回提供给 {@see resume()} 的值，或者抛出提供给 {@see throw()} 的异常。
     *
     * @return T
     */
    public function suspend(): mixed;

    /**
     * 从 {@see suspend()} 调用中抛出给定的异常。
     *
     * @param \Throwable $throwable 要抛出的异常。
     */
    public function throw(\Throwable $throwable): void;
}
