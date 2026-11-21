<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\FiberLocal;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UncaughtThrowable;

/**
 * 实现事件循环驱动基本操作的抽象类，用于实现互操作性。
 *
 * 回调函数（已启用或新的回调）必须立即标记为已启用，但只能在下一个 tick 之前激活（即可以调用回调）。
 * 回调函数不能在它们被启用的 tick 中调用。
 *
 * @internal
 */
abstract class AbstractDriver implements Driver
{
    /**
     * 下一个回调标识符。
     *
     * @var string
     */
    private string $nextId = "a";

    /**
     * 事件循环的 Fiber。
     *
     * @var \Fiber
     */
    private \Fiber $fiber;

    /**
     * 回调函数的 Fiber。
     *
     * @var \Fiber
     */
    private \Fiber $callbackFiber;

    /**
     * 错误回调函数。
     *
     * @var \Closure
     */
    private \Closure $errorCallback;

    /**
     * 所有回调函数的数组。
     *
     * @var array<string, DriverCallback>
     */
    private array $callbacks = [];

    /**
     * 启用队列。
     *
     * @var array<string, DriverCallback>
     */
    private array $enableQueue = [];

    /**
     * 延迟启用队列。
     *
     * @var array<string, DriverCallback>
     */
    private array $enableDeferQueue = [];

    /**
     * 错误处理程序。
     *
     * @var null|\Closure(\Throwable):void
     */
    private ?\Closure $errorHandler = null;

    /**
     * 中断函数。
     *
     * @var null|\Closure():mixed
     */
    private ?\Closure $interrupt = null;

    /**
     * 中断回调函数。
     *
     * @var \Closure
     */
    private readonly \Closure $interruptCallback;

    /**
     * 队列回调函数。
     *
     * @var \Closure
     */
    private readonly \Closure $queueCallback;

    /**
     * 运行回调函数。
     *
     * @var \Closure():(null|\Closure(): mixed)
     */
    private readonly \Closure $runCallback;

    /**
     * 内部挂起标记。
     *
     * @var \stdClass
     */
    private readonly \stdClass $internalSuspensionMarker;

    /**
     * 微任务队列。
     *
     * @var \SplQueue<array{\Closure, array}>
     */
    private readonly \SplQueue $microtaskQueue;

    /**
     * 回调队列。
     *
     * @var \SplQueue<DriverCallback>
     */
    private readonly \SplQueue $callbackQueue;

    /**
     * 是否空闲。
     *
     * @var bool
     */
    private bool $idle = false;

    /**
     * 是否已停止。
     *
     * @var bool
     */
    private bool $stopped = false;

    /**
     * 挂起对象的弱引用映射。
     *
     * @var \WeakMap<object, \WeakReference<DriverSuspension>>
     */
    private \WeakMap $suspensions;

    /**
     * 构造函数。
     *
     * 初始化事件循环驱动程序，创建必要的内部结构和纤维。
     * 检查 PHP 版本是否存在纤维相关的垃圾回收器 bug，并在必要时抛出错误。
     *
     * @throws \Error 如果 PHP 版本存在纤维相关的垃圾回收器 bug 且未设置 REVOLT_DRIVER_SUPPRESS_ISSUE_10496 环境变量。
     */
    public function __construct()
    {
        if (\PHP_VERSION_ID < 80117 || \PHP_VERSION_ID >= 80200 && \PHP_VERSION_ID < 80204) {
            // PHP GC is broken on early 8.1 and 8.2 versions, see https://github.com/php/php-src/issues/10496
            /** @psalm-suppress RiskyTruthyFalsyComparison */
            if (!\getenv('REVOLT_DRIVER_SUPPRESS_ISSUE_10496')) {
                throw new \Error('Your version of PHP is affected by serious garbage collector bugs related to fibers. Please upgrade to a newer version of PHP, i.e. >= 8.1.17 or => 8.2.4');
            }
        }

        $this->suspensions = new \WeakMap();

        $this->internalSuspensionMarker = new \stdClass();
        $this->microtaskQueue = new \SplQueue();
        $this->callbackQueue = new \SplQueue();

        $this->createLoopFiber();
        $this->createCallbackFiber();
        $this->createErrorCallback();

        /** @psalm-suppress InvalidArgument */
        $this->interruptCallback = $this->setInterrupt(...);
        $this->queueCallback = $this->queue(...);
        $this->runCallback = function (): ?\Closure {
            do {
                if ($this->fiber->isTerminated()) {
                    $this->createLoopFiber();
                }

                $result = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
                if ($result) { // Null indicates the loop fiber terminated without suspending.
                    return $result;
                }
            } while (\gc_collect_cycles() && !$this->stopped);

            return null;
        };
    }

    /**
     * 启动事件循环。
     *
     * 该方法将开始运行事件循环，处理各种类型的事件（定时器、流、信号等）。
     *
     * @throws \Error 如果事件循环已经在运行。
     * @throws \Error 如果在纤维内部调用该方法（即不在主线程中）。
     * @throws \Error 如果事件循环中断没有抛出异常。
     */
    public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new \Error("The event loop is already running");
        }

        if (\Fiber::getCurrent()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }

        $lambda = ($this->runCallback)();

        if ($lambda) {
            $lambda();

            throw new \Error(
                'Interrupt from event loop must throw an exception: ' . ClosureHelper::getDescription($lambda)
            );
        }
    }

    /**
     * 停止事件循环。
     *
     * 该方法将设置停止标志，事件循环将在下一个迭代中退出。
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * 检查事件循环是否正在运行。
     *
     * @return bool 如果事件循环正在运行或已挂起，则返回 true，否则返回 false。
     */
    public function isRunning(): bool
    {
        return $this->fiber->isRunning() || $this->fiber->isSuspended();
    }

    /**
     * 将回调函数添加到微任务队列。
     *
     * 微任务将在下一个事件循环 tick 开始时执行。
     *
     * @param \Closure $closure 要执行的回调函数。
     * @param mixed ...$args 传递给回调函数的参数。
     */
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->microtaskQueue->enqueue([$closure, $args]);
    }

    /**
     * 延迟执行回调函数。
     *
     * 添加一个回调函数，该函数将在事件循环的下一个 tick 中执行。
     *
     * @param \Closure $closure 要延迟执行的回调函数。
     * @return string 回调函数的唯一标识符。
     */
    public function defer(\Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->enableDeferQueue[$deferCallback->id] = $deferCallback;

        return $deferCallback->id;
    }

    /**
     * 添加一个一次性定时器回调。
     *
     * 该回调将在指定的延迟时间后执行一次。
     *
     * @param float $delay 延迟时间（秒）。必须大于或等于 0。
     * @param \Closure $closure 定时器回调函数。
     * @return string 回调函数的唯一标识符。
     * @throws \Error 如果延迟时间小于 0。
     */
    public function delay(float $delay, \Closure $closure): string
    {
        if ($delay < 0) {
            throw new \Error("Delay must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $delay, $closure, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * 添加一个重复定时器回调。
     *
     * 该回调将在指定的时间间隔后重复执行。
     *
     * @param float $interval 时间间隔（秒）。必须大于或等于 0。
     * @param \Closure $closure 定时器回调函数。
     * @return string 回调函数的唯一标识符。
     * @throws \Error 如果时间间隔小于 0。
     */
    public function repeat(float $interval, \Closure $closure): string
    {
        if ($interval < 0) {
            throw new \Error("Interval must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $interval, $closure, $this->now() + $interval, true);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * 添加一个可读流事件回调。
     *
     * 当流资源变为可读时，该回调将被调用。
     *
     * @param mixed $stream 流资源。
     * @param \Closure $closure 流可读时的回调函数。
     * @return string 回调函数的唯一标识符。
     */
    public function onReadable(mixed $stream, \Closure $closure): string
    {
        $streamCallback = new StreamReadableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    /**
     * 添加一个可写流事件回调。
     *
     * 当流资源变为可写时，该回调将被调用。
     *
     * @param mixed $stream 流资源。
     * @param \Closure $closure 流可写时的回调函数。
     * @return string 回调函数的唯一标识符。
     */
    public function onWritable($stream, \Closure $closure): string
    {
        $streamCallback = new StreamWritableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    /**
     * 添加一个信号事件回调。
     *
     * 当系统发送指定的信号时，该回调将被调用。
     *
     * @param int $signal 信号编号（例如 SIGINT、SIGTERM 等）。
     * @param \Closure $closure 信号触发时的回调函数。
     * @return string 回调函数的唯一标识符。
     */
    public function onSignal(int $signal, \Closure $closure): string
    {
        $signalCallback = new SignalCallback($this->nextId++, $closure, $signal);

        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;

        return $signalCallback->id;
    }

    /**
     * 启用指定的回调函数。
     *
     * 如果回调函数已经启用，则不执行任何操作。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     * @return string 回调函数的唯一标识符。
     * @throws InvalidCallbackError 如果回调函数不存在。
     */
    public function enable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];

        if ($callback->enabled) {
            return $callbackId; // Callback already enabled.
        }

        $callback->enabled = true;

        if ($callback instanceof DeferCallback) {
            $this->enableDeferQueue[$callback->id] = $callback;
        } elseif ($callback instanceof TimerCallback) {
            $callback->expiration = $this->now() + $callback->interval;
            $this->enableQueue[$callback->id] = $callback;
        } else {
            $this->enableQueue[$callback->id] = $callback;
        }

        return $callbackId;
    }

    /**
     * 取消指定的回调函数。
     *
     * 该方法会先禁用回调函数，然后从事件循环中移除它。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     */
    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

    /**
     * 禁用指定的回调函数。
     *
     * 如果回调函数不存在或已经禁用，则不执行任何操作。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     * @return string 回调函数的唯一标识符。
     */
    public function disable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->enabled) {
            return $callbackId; // Callback already disabled.
        }

        $callback->enabled = false;
        $callback->invokable = false;
        $id = $callback->id;

        if ($callback instanceof DeferCallback) {
            // Callback was only queued to be enabled.
            unset($this->enableDeferQueue[$id]);
        } elseif (isset($this->enableQueue[$id])) {
            // Callback was only queued to be enabled.
            unset($this->enableQueue[$id]);
        } else {
            $this->deactivate($callback);
        }

        return $callbackId;
    }

    /**
     * 引用指定的回调函数。
     *
     * 引用的回调函数会阻止事件循环在没有其他活动回调时退出。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     * @return string 回调函数的唯一标识符。
     * @throws InvalidCallbackError 如果回调函数不存在。
     */
    public function reference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $this->callbacks[$callbackId]->referenced = true;

        return $callbackId;
    }

    /**
     * 取消引用指定的回调函数。
     *
     * 取消引用的回调函数不会阻止事件循环在没有其他活动回调时退出。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     * @return string 回调函数的唯一标识符。
     */
    public function unreference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $this->callbacks[$callbackId]->referenced = false;

        return $callbackId;
    }

    /**
     * 获取当前纤维的挂起对象。
     *
     * 该方法用于在事件循环中创建异步挂起点，允许纤维等待异步操作完成。
     *
     * @return Suspension 当前纤维的挂起对象。
     */
    public function getSuspension(): Suspension
    {
        $fiber = \Fiber::getCurrent();

        // User callbacks are always executed outside the event loop fiber, so this should always be false.
        \assert($fiber !== $this->fiber);

        // Use queue closure in case of {main}, which can be unset by DriverSuspension after an uncaught exception.
        $key = $fiber ?? $this->queueCallback;

        $suspension = ($this->suspensions[$key] ?? null)?->get();
        if ($suspension) {
            return $suspension;
        }

        $suspension = new DriverSuspension(
            $this->runCallback,
            $this->queueCallback,
            $this->interruptCallback,
            $this->suspensions,
        );

        $this->suspensions[$key] = \WeakReference::create($suspension);

        return $suspension;
    }

    /**
     * 设置错误处理程序。
     *
     * 错误处理程序将在事件回调中抛出异常时被调用。
     *
     * @param \Closure|null $errorHandler 错误处理程序回调函数，或 null 表示使用默认处理程序。
     */
    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * 获取当前的错误处理程序。
     *
     * @return \Closure|null 当前的错误处理程序，或 null 表示使用默认处理程序。
     */
    public function getErrorHandler(): ?\Closure
    {
        return $this->errorHandler;
    }

    /**
     * 获取用于调试的信息数组。
     *
     * 该方法用于 var_dump() 等调试函数时提供有用的调试信息。
     *
     * @return array 包含回调函数信息的调试数组。
     */
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return \array_map(fn (DriverCallback $callback) => [
            'type' => $this->getType($callback->id),
            'enabled' => $callback->enabled,
            'referenced' => $callback->referenced,
        ], $this->callbacks);
        // @codeCoverageIgnoreEnd
    }

    /**
     * 获取所有回调函数的标识符。
     *
     * @return array 包含所有回调函数标识符的数组。
     */
    public function getIdentifiers(): array
    {
        return \array_keys($this->callbacks);
    }

    /**
     * 获取指定回调函数的类型。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     * @return CallbackType 回调函数的类型。
     * @throws InvalidCallbackError 如果回调函数不存在。
     */
    public function getType(string $callbackId): CallbackType
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return match ($callback::class) {
            DeferCallback::class => CallbackType::Defer,
            TimerCallback::class => $callback->repeat ? CallbackType::Repeat : CallbackType::Delay,
            StreamReadableCallback::class => CallbackType::Readable,
            StreamWritableCallback::class => CallbackType::Writable,
            SignalCallback::class => CallbackType::Signal,
        };
    }

    /**
     * 检查指定的回调函数是否已启用。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     * @return bool 如果回调函数已启用，则返回 true，否则返回 false。
     * @throws InvalidCallbackError 如果回调函数不存在。
     */
    public function isEnabled(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->enabled;
    }

    /**
     * 检查指定的回调函数是否已引用。
     *
     * @param string $callbackId 回调函数的唯一标识符。
     * @return bool 如果回调函数已引用，则返回 true，否则返回 false。
     * @throws InvalidCallbackError 如果回调函数不存在。
     */
    public function isReferenced(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->referenced;
    }

    /**
     * 激活（启用）所有给定的回调函数。
     *
     * @param array $callbacks 要激活的回调函数数组。
     */
    abstract protected function activate(array $callbacks): void;

    /**
     * 调度任何待处理的读/写、定时器和信号事件。
     *
     * @param bool $blocking 是否阻塞等待事件。
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * 停用（禁用）给定的回调函数。
     *
     * @param DriverCallback $callback 要停用的回调函数。
     */
    abstract protected function deactivate(DriverCallback $callback): void;

    /**
     * 将回调函数加入回调队列。
     *
     * @param DriverCallback $callback 要加入队列的回调函数。
     */
    final protected function enqueueCallback(DriverCallback $callback): void
    {
        $this->callbackQueue->enqueue($callback);
        $this->idle = false;
    }

    /**
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from an event callback.
     */
    final protected function error(\Closure $closure, \Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            // Explicitly override the previous interrupt if it exists in this case, hiding the exception is worse
            $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                ? throw $exception
                : throw UncaughtThrowable::throwingCallback($closure, $exception);
            return;
        }

        $fiber = new \Fiber($this->errorCallback);

        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->errorHandler, $exception);
    }

    /**
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;

    /**
     * 调用微任务队列中的所有微任务。
     */
    private function invokeMicrotasks(): void
    {
        while (!$this->microtaskQueue->isEmpty()) {
            [$callback, $args] = $this->microtaskQueue->dequeue();

            try {
                // Clear $args to allow garbage collection
                $callback(...$args, ...($args = []));
            } catch (\Throwable $exception) {
                $this->error($callback, $exception);
            } finally {
                FiberLocal::clear();
            }

            unset($callback, $args);

            if ($this->interrupt) {
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            }
        }
    }

    /**
     * 检查事件循环是否为空。
     *
     * @return bool 如果没有启用和引用的回调函数，则返回 true。
     */
    private function isEmpty(): bool
    {
        foreach ($this->callbacks as $callback) {
            if ($callback->enabled && $callback->referenced) {
                return false;
            }
        }

        return true;
    }

    /**
     * 执行事件循环的单个 tick。
     *
     * @param bool $previousIdle 上一个状态是否为空闲。
     */
    private function tick(bool $previousIdle): void
    {
        $this->activate($this->enableQueue);

        foreach ($this->enableQueue as $callback) {
            $callback->invokable = true;
        }

        $this->enableQueue = [];

        foreach ($this->enableDeferQueue as $callback) {
            $callback->invokable = true;
            $this->enqueueCallback($callback);
        }

        $this->enableDeferQueue = [];

        $blocking = $previousIdle
            && !$this->stopped
            && !$this->isEmpty();

        if ($blocking) {
            $this->invokeCallbacks();

            /** @psalm-suppress TypeDoesNotContainType */
            if (!empty($this->enableDeferQueue) || !empty($this->enableQueue)) {
                $blocking = false;
            }
        }

        /** @psalm-suppress RedundantCondition */
        $this->dispatch($blocking);
    }

    /**
     * 调用回调队列中的所有回调函数。
     */
    private function invokeCallbacks(): void
    {
        while (!$this->microtaskQueue->isEmpty() || !$this->callbackQueue->isEmpty()) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $yielded = $this->callbackFiber->isStarted()
                ? $this->callbackFiber->resume()
                : $this->callbackFiber->start();

            if ($yielded !== $this->internalSuspensionMarker) {
                $this->createCallbackFiber();
            }

            if ($this->interrupt) {
                $this->invokeInterrupt();
            }
        }
    }

    /**
     * 设置中断函数。
     *
     * @param \Closure():mixed $interrupt 中断函数。
     */
    private function setInterrupt(\Closure $interrupt): void
    {
        \assert($this->interrupt === null);

        $this->interrupt = $interrupt;
    }

    /**
     * 调用中断函数。
     */
    private function invokeInterrupt(): void
    {
        \assert($this->interrupt !== null);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        /** @noinspection PhpUnhandledExceptionInspection */
        \Fiber::suspend($interrupt);
    }

    /**
     * 创建事件循环纤维。
     */
    private function createLoopFiber(): void
    {
        $this->fiber = new \Fiber(function (): void {
            $this->stopped = false;

            // Invoke microtasks if we have some
            $this->invokeCallbacks();

            /** @psalm-suppress RedundantCondition $this->stopped may be changed by $this->invokeCallbacks(). */
            while (!$this->stopped) {
                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }

                if ($this->isEmpty()) {
                    return;
                }

                $previousIdle = $this->idle;
                $this->idle = true;

                $this->tick($previousIdle);
                $this->invokeCallbacks();
            }
        });
    }

    /**
     * 创建回调函数纤维。
     */
    private function createCallbackFiber(): void
    {
        $this->callbackFiber = new \Fiber(function (): void {
            do {
                $this->invokeMicrotasks();

                while (!$this->callbackQueue->isEmpty()) {
                    /** @var DriverCallback $callback */
                    $callback = $this->callbackQueue->dequeue();

                    if (!isset($this->callbacks[$callback->id]) || !$callback->invokable) {
                        unset($callback);

                        continue;
                    }

                    if ($callback instanceof DeferCallback) {
                        $this->cancel($callback->id);
                    } elseif ($callback instanceof TimerCallback) {
                        if (!$callback->repeat) {
                            $this->cancel($callback->id);
                        } else {
                            // Disable and re-enable, so it's not executed repeatedly in the same tick
                            // See https://github.com/amphp/amp/issues/131
                            $this->disable($callback->id);
                            $this->enable($callback->id);
                        }
                    }

                    try {
                        $result = match (true) {
                            $callback instanceof StreamCallback => ($callback->closure)(
                                $callback->id,
                                $callback->stream
                            ),
                            $callback instanceof SignalCallback => ($callback->closure)(
                                $callback->id,
                                $callback->signal
                            ),
                            default => ($callback->closure)($callback->id),
                        };

                        if ($result !== null) {
                            throw InvalidCallbackError::nonNullReturn($callback->id, $callback->closure);
                        }
                    } catch (\Throwable $exception) {
                        $this->error($callback->closure, $exception);
                    } finally {
                        FiberLocal::clear();
                    }

                    unset($callback);

                    if ($this->interrupt) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        \Fiber::suspend($this->internalSuspensionMarker);
                    }

                    $this->invokeMicrotasks();
                }

                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            } while (true);
        });
    }

    /**
     * 创建错误回调函数。
     *
     * 该方法创建一个用于处理事件回调中抛出的异常的错误回调函数。
     */
    private function createErrorCallback(): void
    {
        $this->errorCallback = function (\Closure $errorHandler, \Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (\Throwable $exception) {
                $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                    ? throw $exception
                    : throw UncaughtThrowable::throwingErrorHandler($errorHandler, $exception);
            }
        };
    }

    /**
     * 序列化方法。
     *
     * 该类不支持序列化，调用此方法将抛出错误。
     *
     * @return never 永远不会返回，总是抛出错误。
     * @throws \Error 总是抛出错误，因为该类不支持序列化。
     */
    final public function __serialize(): never
    {
        throw new \Error(__CLASS__ . ' does not support serialization');
    }

    /**
     * 反序列化方法。
     *
     * 该类不支持反序列化，调用此方法将抛出错误。
     *
     * @param array $data 序列化数据（未使用）。
     * @return never 永远不会返回，总是抛出错误。
     * @throws \Error 总是抛出错误，因为该类不支持反序列化。
     */
    final public function __unserialize(array $data): never
    {
        throw new \Error(__CLASS__ . ' does not support deserialization');
    }
}
