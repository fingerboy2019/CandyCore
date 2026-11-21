<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;

/**
 * 使用 PHP UV 扩展实现的事件循环驱动程序。
 */
final class UvDriver extends AbstractDriver
{
    /**
     * 检查 UV 驱动程序是否受支持。
     *
     * @return bool 如果 PHP UV 扩展已加载则返回 true，否则返回 false。
     */
    public static function isSupported(): bool
    {
        return \extension_loaded("uv");
    }

    /**
     * 使用 uv_loop_new() 创建的 uv_loop 资源。
     *
     * @var resource|\UVLoop
     */
    private $handle;
    
    /**
     * 事件数组。
     *
     * @var array<string, resource>
     */
    private array $events = [];
    
    /**
     * UV 回调函数数组。
     *
     * @var array<int, array<array-key, DriverCallback>>
     */
    private array $uvCallbacks = [];
    
    /**
     * 流资源数组。
     *
     * @var array<int, resource>
     */
    private array $streams = [];
    
    /**
     * IO 回调函数。
     *
     * @var \Closure
     */
    private readonly \Closure $ioCallback;
    
    /**
     * 定时器回调函数。
     *
     * @var \Closure
     */
    private readonly \Closure $timerCallback;
    
    /**
     * 信号回调函数。
     *
     * @var \Closure
     */
    private readonly \Closure $signalCallback;

    /**
     * 构造函数。
     *
     * 初始化UV事件循环驱动程序，创建UV循环句柄和各种回调函数。
     */
    public function __construct()
    {
        parent::__construct();

        $this->handle = \uv_loop_new();

        $this->ioCallback = function ($event, $status, $events, $resource): void {
            $callbacks = $this->uvCallbacks[(int) $event];

            // Invoke the callback on errors, as this matches behavior with other loop back-ends.
            // Re-enable callback as libuv disables the callback on non-zero status.
            if ($status !== 0) {
                $flags = 0;
                foreach ($callbacks as $callback) {
                    \assert($callback instanceof StreamCallback);

                    $flags |= $callback->invokable ? $this->getStreamCallbackFlags($callback) : 0;
                }
                \uv_poll_start($event, $flags, $this->ioCallback);
            }

            foreach ($callbacks as $callback) {
                \assert($callback instanceof StreamCallback);

                // $events is ORed with 4 to trigger callback if no events are indicated (0) or on UV_DISCONNECT (4).
                // http://docs.libuv.org/en/v1.x/poll.html
                if (!($this->getStreamCallbackFlags($callback) & $events || ($events | 4) === 4)) {
                    continue;
                }

                $this->enqueueCallback($callback);
            }
        };

        $this->timerCallback = function ($event): void {
            $callback = $this->uvCallbacks[(int) $event][0];

            \assert($callback instanceof TimerCallback);

            $this->enqueueCallback($callback);
        };

        $this->signalCallback = function ($event): void {
            $callback = $this->uvCallbacks[(int) $event][0];

            $this->enqueueCallback($callback);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $callbackId): void
    {
        parent::cancel($callbackId);

        if (!isset($this->events[$callbackId])) {
            return;
        }

        $event = $this->events[$callbackId];
        $eventId = (int) $event;

        if (isset($this->uvCallbacks[$eventId][0])) { // All except IO callbacks.
            unset($this->uvCallbacks[$eventId]);
        } elseif (isset($this->uvCallbacks[$eventId][$callbackId])) {
            $callback = $this->uvCallbacks[$eventId][$callbackId];
            unset($this->uvCallbacks[$eventId][$callbackId]);

            \assert($callback instanceof StreamCallback);

            if (empty($this->uvCallbacks[$eventId])) {
                unset($this->uvCallbacks[$eventId], $this->streams[(int) $callback->stream]);
            }
        }

        unset($this->events[$callbackId]);
    }

    /**
     * 获取底层 UV 循环句柄。
     *
     * @return \UVLoop|resource UV 循环句柄。
     */
    public function getHandle(): mixed
    {
        return $this->handle;
    }

    /**
     * 获取当前事件循环时间（秒）。
     *
     * @return float 当前事件循环时间（秒）。
     */
    protected function now(): float
    {
        \uv_update_time($this->handle);

        /** @psalm-suppress TooManyArguments */
        return \uv_now($this->handle) / 1000;
    }

    /**
     * 调度任何待处理的读/写、定时器和信号事件。
     *
     * 在UV驱动中，该方法调用uv_run函数运行UV事件循环。
     *
     * @param bool $blocking 是否阻塞等待事件。
     */
    protected function dispatch(bool $blocking): void
    {
        /** @psalm-suppress TooManyArguments */
        \uv_run($this->handle, $blocking ? \UV::RUN_ONCE : \UV::RUN_NOWAIT);
    }

    /**
     * 激活（启用）所有给定的回调函数。
     *
     * 在UV驱动中，该方法会根据回调类型（流、定时器、信号）创建相应的UV事件句柄，并设置回调函数。
     *
     * @param array $callbacks 要激活的回调函数数组。
     */
    protected function activate(array $callbacks): void
    {
        $now = $this->now();

        foreach ($callbacks as $callback) {
            $id = $callback->id;

            if ($callback instanceof StreamCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;

                if (isset($this->streams[$streamId])) {
                    $event = $this->streams[$streamId];
                } elseif (isset($this->events[$id])) {
                    $event = $this->streams[$streamId] = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->streams[$streamId] = \uv_poll_init_socket($this->handle, $callback->stream);
                }

                $eventId = (int) $event;
                $this->events[$id] = $event;
                $this->uvCallbacks[$eventId][$id] = $callback;

                $flags = 0;
                foreach ($this->uvCallbacks[$eventId] as $w) {
                    \assert($w instanceof StreamCallback);

                    $flags |= $w->enabled ? ($this->getStreamCallbackFlags($w)) : 0;
                }
                \uv_poll_start($event, $flags, $this->ioCallback);
            } elseif ($callback instanceof TimerCallback) {
                if (isset($this->events[$id])) {
                    $event = $this->events[$id];
                } else {
                    $event = $this->events[$id] = \uv_timer_init($this->handle);
                }

                $this->uvCallbacks[(int) $event] = [$callback];

                \uv_timer_start(
                    $event,
                    (int) \min(\max(0, \ceil(($callback->expiration - $now) * 1000)), \PHP_INT_MAX),
                    $callback->repeat ? (int) \min(\max(0, \ceil($callback->interval * 1000)), \PHP_INT_MAX) : 0,
                    $this->timerCallback
                );
            } elseif ($callback instanceof SignalCallback) {
                if (isset($this->events[$id])) {
                    $event = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->events[$id] = \uv_signal_init($this->handle);
                }

                $this->uvCallbacks[(int) $event] = [$callback];

                /** @psalm-suppress TooManyArguments */
                \uv_signal_start($event, $this->signalCallback, $callback->signal);
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * 停用（禁用）给定的回调函数。
     *
     * 在UV驱动中，该方法会根据回调类型（流、定时器、信号）停止或更新相应的UV事件句柄。
     *
     * @param DriverCallback $callback 要停用的回调函数。
     */
    protected function deactivate(DriverCallback $callback): void
    {
        $id = $callback->id;

        if (!isset($this->events[$id])) {
            return;
        }

        $event = $this->events[$id];

        if (!\uv_is_active($event)) {
            return;
        }

        if ($callback instanceof StreamCallback) {
            $flags = 0;
            foreach ($this->uvCallbacks[(int) $event] as $w) {
                \assert($w instanceof StreamCallback);

                $flags |= $w->invokable ? ($this->getStreamCallbackFlags($w)) : 0;
            }

            if ($flags) {
                \uv_poll_start($event, $flags, $this->ioCallback);
            } else {
                \uv_poll_stop($event);
            }
        } elseif ($callback instanceof TimerCallback) {
            \uv_timer_stop($event);
        } elseif ($callback instanceof SignalCallback) {
            \uv_signal_stop($event);
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error("Unknown callback type");
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * 获取流回调的UV事件标志。
     *
     * 根据流回调的类型（可读或可写）返回相应的UV事件标志。
     *
     * @param StreamCallback $callback 流回调对象。
     * @return int UV事件标志。
     * @throws \Error 如果回调类型无效。
     */
    private function getStreamCallbackFlags(StreamCallback $callback): int
    {
        if ($callback instanceof StreamWritableCallback) {
            return \UV::WRITABLE;
        }

        if ($callback instanceof StreamReadableCallback) {
            return \UV::READABLE;
        }

        throw new \Error('Invalid callback type');
    }
}
