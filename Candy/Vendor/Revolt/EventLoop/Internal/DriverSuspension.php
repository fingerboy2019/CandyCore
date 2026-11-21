<?php

/** @noinspection PhpPropertyOnlyWrittenInspection */

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Suspension;

/**
 * 驱动程序挂起实现类，用于事件循环的异步操作。
 *
 * @internal
 *
 * @template T
 * @implements Suspension<T>
 */
final class DriverSuspension implements Suspension
{
    /**
     * 被挂起的协程。
     */
    private ?\Fiber $suspendedFiber = null;

    /**
     * 协程的弱引用。
     *
     * @var \WeakReference<\Fiber>|null
     */
    private readonly ?\WeakReference $fiberRef;

    /**
     * 发生的错误。
     */
    private ?\Error $error = null;

    /**
     * 是否挂起等待中。
     */
    private bool $pending = false;

    /**
     * 主线程是否已结束。
     */
    private bool $deadMain = false;

    /**
     * 构造函数。
     *
     * @param \Closure $run 运行事件循环的闭包。
     * @param \Closure $queue 队列回调的闭包。
     * @param \Closure $interrupt 中断事件循环的闭包。
     * @param \WeakMap<object, \WeakReference<DriverSuspension>> $suspensions 挂起对象的弱映射。
     */
    public function __construct(
        private readonly \Closure $run,
        private readonly \Closure $queue,
        private readonly \Closure $interrupt,
        private readonly \WeakMap $suspensions,
    ) {
        $fiber = \Fiber::getCurrent();

        $this->fiberRef = $fiber ? \WeakReference::create($fiber) : null;
    }

    /**
     * 恢复挂起的协程。
     *
     * @param mixed $value 传递给挂起协程的值。
     */
    public function resume(mixed $value = null): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }

        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling resume()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $value): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->resume($value);
                }
            });
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => $value);
        }
    }

    /**
     * 挂起当前协程，等待恢复。
     *
     * @return mixed 恢复协程时传递的值。
     */
    public function suspend(): mixed
    {
        // Throw exception when trying to use old dead {main} suspension
        if ($this->deadMain) {
            throw new \Error(
                'Suspension cannot be suspended after an uncaught exception is thrown from the event loop',
            );
        }

        if ($this->pending) {
            throw new \Error('Must call resume() or throw() before calling suspend() again');
        }

        $fiber = $this->fiberRef?->get();

        if ($fiber !== \Fiber::getCurrent()) {
            throw new \Error('Must not call suspend() from another fiber');
        }

        $this->pending = true;
        $this->error = null;

        // Awaiting from within a fiber.
        if ($fiber) {
            $this->suspendedFiber = $fiber;

            try {
                $value = \Fiber::suspend();
                $this->suspendedFiber = null;
            } catch (\FiberError $error) {
                $this->pending = false;
                $this->suspendedFiber = null;
                $this->error = $error;

                throw $error;
            }

            // Setting $this->suspendedFiber = null in finally will set the fiber to null if a fiber is destroyed
            // as part of a cycle collection, causing an error if the suspension is subsequently resumed.

            return $value;
        }

        // Awaiting from {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            // This is now a dead {main} suspension.
            $this->deadMain = true;

            // Unset suspension for {main} using queue closure.
            unset($this->suspensions[$this->queue]);

            $result && $result(); // Unwrap any uncaught exceptions from the event loop

            \gc_collect_cycles(); // Collect any circular references before dumping pending suspensions.

            $info = '';
            foreach ($this->suspensions as $suspensionRef) {
                if ($suspension = $suspensionRef->get()) {
                    \assert($suspension instanceof self);
                    $fiber = $suspension->fiberRef?->get();
                    if ($fiber === null) {
                        continue;
                    }

                    $reflectionFiber = new \ReflectionFiber($fiber);
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }

            throw new \Error('Event loop terminated without resuming the current suspension (the cause is either a fiber deadlock, or an incorrectly unreferenced/canceled watcher):' . $info);
        }

        return $result();
    }

    /**
     * 向挂起的协程抛出异常。
     *
     * @param \Throwable $throwable 要抛出的异常。
     */
    public function throw(\Throwable $throwable): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }

        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling throw()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $throwable): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->throw($throwable);
                }
            });
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => throw $throwable);
        }
    }

    /**
     * 格式化堆栈跟踪信息。
     *
     * @param array $trace 堆栈跟踪数组。
     * @return string 格式化后的堆栈跟踪字符串。
     */
    private function formatStacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, \array_keys($trace)));
    }
}
