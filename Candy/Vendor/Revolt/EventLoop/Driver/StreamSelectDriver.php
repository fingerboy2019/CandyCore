<?php

declare(strict_types=1);

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\Internal\TimerQueue;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * 使用 stream_select() 实现的事件循环驱动程序。
 *
 * 这是一个纯 PHP 实现，不需要任何扩展，但性能可能不如其他基于扩展的驱动程序。
 */
final class StreamSelectDriver extends AbstractDriver
{
    /**
     * 可读流资源数组。
     *
     * @var array<int, resource>
     */
    private array $readStreams = [];

    /**
     * 可读流回调数组。
     *
     * @var array<int, array<string, StreamReadableCallback>>
     */
    private array $readCallbacks = [];

    /**
     * 可写流资源数组。
     *
     * @var array<int, resource>
     */
    private array $writeStreams = [];

    /**
     * 可写流回调数组。
     *
     * @var array<int, array<string, StreamWritableCallback>>
     */
    private array $writeCallbacks = [];

    /**
     * 定时器队列。
     *
     * @var TimerQueue
     */
    private readonly TimerQueue $timerQueue;

    /**
     * 信号回调数组。
     *
     * @var array<int, array<string, SignalCallback>>
     */
    private array $signalCallbacks = [];

    /**
     * 信号队列。
     *
     * @var \SplQueue<int>
     */
    private readonly \SplQueue $signalQueue;

    /**
     * 是否支持信号处理。
     *
     * @var bool
     */
    private bool $signalHandling;

    /**
     * stream_select() 错误处理程序。
     *
     * @var \Closure
     */
    private readonly \Closure $streamSelectErrorHandler;

    /**
     * 是否忽略 stream_select() 结果。
     *
     * @var bool
     */
    private bool $streamSelectIgnoreResult = false;

    /**
     * 构造函数。
     */
    public function __construct()
    {
        parent::__construct();

        $this->signalQueue = new \SplQueue();
        $this->timerQueue = new TimerQueue();
        $this->signalHandling = \extension_loaded("pcntl")
            && \function_exists('pcntl_signal_dispatch')
            && \function_exists('pcntl_signal');

        $this->streamSelectErrorHandler = function (int $errno, string $message): void {
            // Casing changed in PHP 8 from 'unable' to 'Unable'
            if (\stripos($message, "stream_select(): unable to select [4]: ") === 0) { // EINTR
                $this->streamSelectIgnoreResult = true;

                return;
            }

            if (\str_contains($message, 'FD_SETSIZE')) {
                $message = \str_replace(["\r\n", "\n", "\r"], " ", $message);
                $pattern = '(stream_select\(\): You MUST recompile PHP with a larger value of FD_SETSIZE. It is set to (\d+), but you have descriptors numbered at least as high as (\d+)\.)';

                if (\preg_match($pattern, $message, $match)) {
                    $helpLink = 'https://revolt.run/extensions';

                    $message = 'You have reached the limits of stream_select(). It has a FD_SETSIZE of ' . $match[1]
                        . ', but you have file descriptors numbered at least as high as ' . $match[2] . '. '
                        . "You can install one of the extensions listed on {$helpLink} to support a higher number of "
                        . "concurrent file descriptors. If a large number of open file descriptors is unexpected, you "
                        . "might be leaking file descriptors that aren't closed correctly.";
                }
            }

            throw new \Exception($message, $errno);
        };
    }

    /**
     * 析构函数。
     *
     * 清理所有信号回调。
     */
    public function __destruct()
    {
        foreach ($this->signalCallbacks as $signalCallbacks) {
            foreach ($signalCallbacks as $signalCallback) {
                $this->deactivate($signalCallback);
            }
        }
    }

    /**
     * 注册一个信号处理回调。
     *
     * @param int      $signal  要监听的信号编号。
     * @param \Closure $closure 当信号被触发时调用的回调函数。
     *
     * @return string 回调标识符。
     *
     * @throws UnsupportedFeatureException 如果 pcntl 扩展不可用。
     */
    public function onSignal(int $signal, \Closure $closure): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException("Signal handling requires the pcntl extension");
        }

        return parent::onSignal($signal, $closure);
    }

    /**
     * 获取底层事件循环句柄。
     *
     * 对于 StreamSelectDriver，此方法始终返回 null，因为它不使用任何底层扩展句柄。
     *
     * @return null
     */
    public function getHandle(): mixed
    {
        return null;
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
     *
     * @throws \Throwable
     */
    protected function dispatch(bool $blocking): void
    {
        if ($this->signalHandling) {
            \pcntl_signal_dispatch();

            while (!$this->signalQueue->isEmpty()) {
                $signal = $this->signalQueue->dequeue();

                foreach ($this->signalCallbacks[$signal] as $callback) {
                    $this->enqueueCallback($callback);
                }

                $blocking = false;
            }
        }

        $this->selectStreams(
            $this->readStreams,
            $this->writeStreams,
            $blocking ? $this->getTimeout() : 0.0
        );

        $now = $this->now();

        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }

    /**
     * 激活回调函数。
     *
     * @param array<DriverCallback> $callbacks 要激活的回调函数数组。
     */
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->readCallbacks[$streamId][$callback->id] = $callback;
                $this->readStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof StreamWritableCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->writeCallbacks[$streamId][$callback->id] = $callback;
                $this->writeStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof TimerCallback) {
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                if (!isset($this->signalCallbacks[$callback->signal])) {
                    \set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new UnsupportedFeatureException(
                            \sprintf("Failed to register signal handler; Errno: %d; %s", $errno, $errstr)
                        );
                    });

                    // Avoid bug in Psalm handling of first-class callables by assigning to a temp variable.
                    $handler = $this->handleSignal(...);

                    try {
                        \pcntl_signal($callback->signal, $handler);
                    } finally {
                        \restore_error_handler();
                    }
                }

                $this->signalCallbacks[$callback->signal][$callback->id] = $callback;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
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
        if ($callback instanceof StreamReadableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->readCallbacks[$streamId][$callback->id]);
            if (empty($this->readCallbacks[$streamId])) {
                unset($this->readCallbacks[$streamId], $this->readStreams[$streamId]);
            }
        } elseif ($callback instanceof StreamWritableCallback) {
            $streamId = (int) $callback->stream;
            unset($this->writeCallbacks[$streamId][$callback->id]);
            if (empty($this->writeCallbacks[$streamId])) {
                unset($this->writeCallbacks[$streamId], $this->writeStreams[$streamId]);
            }
        } elseif ($callback instanceof TimerCallback) {
            $this->timerQueue->remove($callback);
        } elseif ($callback instanceof SignalCallback) {
            if (isset($this->signalCallbacks[$callback->signal])) {
                unset($this->signalCallbacks[$callback->signal][$callback->id]);

                if (empty($this->signalCallbacks[$callback->signal])) {
                    unset($this->signalCallbacks[$callback->signal]);
                    \set_error_handler(static fn () => true);
                    try {
                        \pcntl_signal($callback->signal, \SIG_DFL);
                    } finally {
                        \restore_error_handler();
                    }
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error("Unknown callback type");
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * 使用 stream_select() 等待流事件。
     *
     * @param array<int, resource> $read    可读流数组。
     * @param array<int, resource> $write   可写流数组。
     * @param float                $timeout 超时时间（秒）。
     */
    private function selectStreams(array $read, array $write, float $timeout): void
    {
        if (!empty($read) || !empty($write)) { // Use stream_select() if there are any streams in the loop.
            if ($timeout >= 0) {
                $seconds = (int) $timeout;
                $microseconds = (int) (($timeout - $seconds) * 1_000_000);
            } else {
                $seconds = null;
                $microseconds = null;
            }

            // Failed connection attempts are indicated via except on Windows
            // @link https://github.com/reactphp/event-loop/blob/8bd064ce23c26c4decf186c2a5a818c9a8209eb0/src/StreamSelectLoop.php#L279-L287
            // @link https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
            $except = null;
            if (\DIRECTORY_SEPARATOR === '\\') {
                $except = $write;
            }

            \set_error_handler($this->streamSelectErrorHandler);

            try {
                /** @psalm-suppress InvalidArgument */
                $result = \stream_select($read, $write, $except, $seconds, $microseconds);
            } finally {
                \restore_error_handler();
            }

            if ($this->streamSelectIgnoreResult || $result === 0) {
                $this->streamSelectIgnoreResult = false;
                return;
            }

            if (!$result) {
                throw new \Exception('Unknown error during stream_select');
            }

            foreach ($read as $stream) {
                $streamId = (int) $stream;
                if (!isset($this->readCallbacks[$streamId])) {
                    continue; // All read callbacks disabled.
                }

                foreach ($this->readCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            /** @var array<int, resource>|null $except */
            if ($except !== null) {
                foreach ($except as $key => $socket) {
                    $write[$key] = $socket;
                }
            }

            foreach ($write as $stream) {
                $streamId = (int) $stream;
                if (!isset($this->writeCallbacks[$streamId])) {
                    continue; // All write callbacks disabled.
                }

                foreach ($this->writeCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            return;
        }

        if ($timeout < 0) { // Only signal callbacks are enabled, so sleep indefinitely.
            /** @psalm-suppress ArgumentTypeCoercion */
            \usleep(\PHP_INT_MAX);
            return;
        }

        if ($timeout > 0) { // Sleep until next timer expires.
            /** @psalm-suppress ArgumentTypeCoercion $timeout is positive here. */
            \usleep((int) ($timeout * 1_000_000));
        }
    }

    /**
     * 获取到下一个定时器到期的时间。
     *
     * @return float 到下一个定时器到期的秒数，如果没有挂起的定时器则返回 -1。
     */
    private function getTimeout(): float
    {
        $expiration = $this->timerQueue->peek();

        if ($expiration === null) {
            return -1;
        }

        $expiration -= $this->now();

        return $expiration > 0 ? $expiration : 0.0;
    }

    /**
     * 处理信号。
     *
     * 将信号加入队列，以便在信号分发时不会挂起。
     *
     * @param int $signal 信号编号。
     */
    private function handleSignal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs
        $this->signalQueue->enqueue($signal);
    }
}
