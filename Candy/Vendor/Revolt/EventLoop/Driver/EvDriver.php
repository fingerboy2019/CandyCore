<?php

declare(strict_types=1);

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;

/**
 * 使用 PHP EV 扩展实现的事件循环驱动程序。
 */
final class EvDriver extends AbstractDriver
{
    /**
     * 当前激活的信号处理程序。
     *
     * @var array<string, \EvSignal>|null
     */
    private static ?array $activeSignals = null;

    /**
     * 检查 EV 驱动程序是否受支持。
     *
     * @return bool 如果 PHP EV 扩展已加载则返回 true，否则返回 false。
     */
    public static function isSupported(): bool
    {
        return \extension_loaded("ev");
    }

    /**
     * EV 循环实例。
     *
     * @var \EvLoop
     */
    private \EvLoop $handle;

    /**
     * 事件监听器数组。
     *
     * @var array<string, \EvWatcher>
     */
    private array $events = [];

    /**
     * IO 事件回调函数。
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
     * 信号监听器数组。
     *
     * @var array<string, \EvSignal>
     */
    private array $signals = [];

    /**
     * 构造函数。
     */
    public function __construct()
    {
        parent::__construct();

        $this->handle = new \EvLoop();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function (\EvIo $event): void {
            /** @var StreamCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };

        $this->timerCallback = function (\EvTimer $event): void {
            /** @var TimerCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };

        $this->signalCallback = function (\EvSignal $event): void {
            /** @var SignalCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };
    }

    /**
     * 取消一个回调函数。
     *
     * @param string $callbackId 回调标识符。
     */
    public function cancel(string $callbackId): void
    {
        parent::cancel($callbackId);
        unset($this->events[$callbackId]);
    }

    /**
     * 析构函数。
     *
     * 清理所有事件监听器以避免内存泄漏。
     */
    public function __destruct()
    {
        foreach ($this->events as $event) {
            /** @psalm-suppress all */
            if ($event !== null) { // Events may have been nulled in extension depending on destruct order.
                $event->stop();
            }
        }

        // We need to clear all references to events manually, see
        // https://bitbucket.org/osmanov/pecl-ev/issues/31/segfault-in-ev_timer_stop
        $this->events = [];
    }

    /**
     * 运行事件循环。
     */
    public function run(): void
    {
        $active = self::$activeSignals;

        \assert($active !== null);

        foreach ($active as $event) {
            $event->stop();
        }

        self::$activeSignals = &$this->signals;

        foreach ($this->signals as $event) {
            $event->start();
        }

        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->stop();
            }

            self::$activeSignals = &$active;

            foreach ($active as $event) {
                $event->start();
            }
        }
    }

    /**
     * 停止事件循环。
     */
    public function stop(): void
    {
        $this->handle->stop();
        parent::stop();
    }

    /**
     * 获取底层 EV 循环句柄。
     *
     * @return \EvLoop EV 循环句柄。
     */
    public function getHandle(): \EvLoop
    {
        return $this->handle;
    }

    /**
     * 获取当前时间戳（秒）。
     *
     * @return float 当前时间戳。
     */
    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /**
     * 调度事件循环。
     *
     * @param bool $blocking 是否阻塞直到有事件发生。
     */
    protected function dispatch(bool $blocking): void
    {
        $this->handle->run($blocking ? \Ev::RUN_ONCE : \Ev::RUN_ONCE | \Ev::RUN_NOWAIT);
    }

    /**
     * 激活回调函数。
     *
     * @param array<DriverCallback> $callbacks 要激活的回调函数数组。
     */
    protected function activate(array $callbacks): void
    {
        $this->handle->nowUpdate();
        $now = $this->now();

        foreach ($callbacks as $callback) {
            if (!isset($this->events[$id = $callback->id])) {
                if ($callback instanceof StreamReadableCallback) {
                    \assert(\is_resource($callback->stream));

                    $this->events[$id] = $this->handle->io($callback->stream, \Ev::READ, $this->ioCallback, $callback);
                } elseif ($callback instanceof StreamWritableCallback) {
                    \assert(\is_resource($callback->stream));

                    $this->events[$id] = $this->handle->io(
                        $callback->stream,
                        \Ev::WRITE,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof TimerCallback) {
                    $interval = $callback->interval;
                    $this->events[$id] = $this->handle->timer(
                        \max(0, ($callback->expiration - $now)),
                        $callback->repeat ? $interval : 0,
                        $this->timerCallback,
                        $callback
                    );
                } elseif ($callback instanceof SignalCallback) {
                    $this->events[$id] = $this->handle->signal($callback->signal, $this->signalCallback, $callback);
                } else {
                    // @codeCoverageIgnoreStart
                    throw new \Error("Unknown callback type: " . \get_class($callback));
                    // @codeCoverageIgnoreEnd
                }
            } else {
                $this->events[$id]->start();
            }

            if ($callback instanceof SignalCallback) {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->signals[$id] = $this->events[$id];
            }
        }
    }

    /**
     * 停用回调函数。
     *
     * @param DriverCallback $callback 要停用的回调函数。
     */
    protected function deactivate(DriverCallback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->stop();

            if ($callback instanceof SignalCallback) {
                unset($this->signals[$id]);
            }
        }
    }
}
