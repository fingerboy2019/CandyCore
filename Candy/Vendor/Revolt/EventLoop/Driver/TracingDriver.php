<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;

/**
 * 跟踪驱动程序，用于调试和跟踪事件循环回调的活动。
 *
 * 该驱动程序包装了另一个驱动程序，并记录回调的创建、启用、禁用、取消等操作，
 * 以及相应的堆栈跟踪信息，以便于调试。
 */
final class TracingDriver implements Driver
{
    /**
     * 被包装的底层驱动程序。
     *
     * @var Driver
     */
    private readonly Driver $driver;

    /**
     * 已启用的回调标识符数组。
     *
     * @var array<string, true>
     */
    private array $enabledCallbacks = [];

    /**
     * 未引用的回调标识符数组。
     *
     * @var array<string, true>
     */
    private array $unreferencedCallbacks = [];

    /**
     * 回调创建时的堆栈跟踪信息。
     *
     * @var array<string, string>
     */
    private array $creationTraces = [];

    /**
     * 回调取消时的堆栈跟踪信息。
     *
     * @var array<string, string>
     */
    private array $cancelTraces = [];

    /**
     * 构造函数。
     *
     * @param Driver $driver 要包装的底层驱动程序。
     */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * 运行事件循环。
     */
    public function run(): void
    {
        $this->driver->run();
    }

    /**
     * 停止事件循环。
     */
    public function stop(): void
    {
        $this->driver->stop();
    }

    /**
     * 获取一个新的挂起对象。
     *
     * @return Suspension 挂起对象。
     */
    public function getSuspension(): Suspension
    {
        return $this->driver->getSuspension();
    }

    /**
     * 检查事件循环是否正在运行。
     *
     * @return bool 如果事件循环正在运行则返回 true，否则返回 false。
     */
    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    /**
     * 延迟执行一个回调函数。
     *
     * @param \Closure $closure 要执行的回调函数。
     *
     * @return string 回调标识符。
     */
    public function defer(\Closure $closure): string
    {
        $id = $this->driver->defer(function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 在指定的延迟时间后执行一个回调函数。
     *
     * @param float    $delay   延迟时间（秒）。
     * @param \Closure $closure 要执行的回调函数。
     *
     * @return string 回调标识符。
     */
    public function delay(float $delay, \Closure $closure): string
    {
        $id = $this->driver->delay($delay, function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 以指定的时间间隔重复执行一个回调函数。
     *
     * @param float    $interval 时间间隔（秒）。
     * @param \Closure $closure  要执行的回调函数。
     *
     * @return string 回调标识符。
     */
    public function repeat(float $interval, \Closure $closure): string
    {
        $id = $this->driver->repeat($interval, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 当流可读取时执行一个回调函数。
     *
     * @param mixed    $stream  要监听的流资源。
     * @param \Closure $closure 当流可读取时调用的回调函数。
     *
     * @return string 回调标识符。
     */
    public function onReadable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->onReadable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 当流可写入时执行一个回调函数。
     *
     * @param mixed    $stream  要监听的流资源。
     * @param \Closure $closure 当流可写入时调用的回调函数。
     *
     * @return string 回调标识符。
     */
    public function onWritable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->onWritable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 当接收到指定信号时执行一个回调函数。
     *
     * @param int      $signal  要监听的信号编号。
     * @param \Closure $closure 当信号被触发时调用的回调函数。
     *
     * @return string 回调标识符。
     */
    public function onSignal(int $signal, \Closure $closure): string
    {
        $id = $this->driver->onSignal($signal, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 启用一个回调函数。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public function enable(string $callbackId): string
    {
        try {
            $this->driver->enable($callbackId);
            $this->enabledCallbacks[$callbackId] = true;
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    /**
     * 取消一个回调函数。
     *
     * @param string $callbackId 回调标识符。
     */
    public function cancel(string $callbackId): void
    {
        $this->driver->cancel($callbackId);

        if (!isset($this->cancelTraces[$callbackId])) {
            $this->cancelTraces[$callbackId] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        }

        unset($this->enabledCallbacks[$callbackId], $this->unreferencedCallbacks[$callbackId]);
    }

    /**
     * 禁用一个回调函数。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     */
    public function disable(string $callbackId): string
    {
        $this->driver->disable($callbackId);
        unset($this->enabledCallbacks[$callbackId]);

        return $callbackId;
    }

    /**
     * 引用一个回调函数。
     *
     * 这将在事件仍被监控时保持事件循环运行。回调默认具有此状态。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public function reference(string $callbackId): string
    {
        try {
            $this->driver->reference($callbackId);
            unset($this->unreferencedCallbacks[$callbackId]);
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    /**
     * 取消引用一个回调函数。
     *
     * 这将允许事件循环在没有其他引用的回调时退出，即使此回调仍被监控。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     */
    public function unreference(string $callbackId): string
    {
        $this->driver->unreference($callbackId);
        $this->unreferencedCallbacks[$callbackId] = true;

        return $callbackId;
    }

    /**
     * 设置错误处理程序。
     *
     * @param \Closure|null $errorHandler 错误处理程序，或 null 以使用默认处理程序。
     */
    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    /**
     * 获取当前的错误处理程序。
     *
     * @return \Closure|null 当前的错误处理程序，或 null 如果使用默认处理程序。
     */
    public function getErrorHandler(): ?\Closure
    {
        return $this->driver->getErrorHandler();
    }

    /**
     * 获取底层事件循环句柄。
     *
     * @return mixed 底层事件循环句柄。
     */
    public function getHandle(): mixed
    {
        return $this->driver->getHandle();
    }

    /**
     * 生成当前事件循环状态的调试信息。
     *
     * @return string 格式化的调试信息。
     */
    public function dump(): string
    {
        $dump = "Enabled, referenced callbacks keeping the loop running: ";

        foreach ($this->enabledCallbacks as $callbackId => $_) {
            if (isset($this->unreferencedCallbacks[$callbackId])) {
                continue;
            }

            $dump .= "Callback identifier: " . $callbackId . "\r\n";
            $dump .= $this->getCreationTrace($callbackId);
            $dump .= "\r\n\r\n";
        }

        return \rtrim($dump);
    }

    /**
     * 获取所有回调标识符的列表。
     *
     * @return array<string> 回调标识符数组。
     */
    public function getIdentifiers(): array
    {
        return $this->driver->getIdentifiers();
    }

    /**
     * 获取回调的类型。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return CallbackType 回调类型。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public function getType(string $callbackId): CallbackType
    {
        return $this->driver->getType($callbackId);
    }

    /**
     * 检查回调是否已启用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return bool 如果回调已启用则返回 true，否则返回 false。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public function isEnabled(string $callbackId): bool
    {
        return $this->driver->isEnabled($callbackId);
    }

    /**
     * 检查回调是否被引用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return bool 如果回调被引用则返回 true，否则返回 false。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public function isReferenced(string $callbackId): bool
    {
        return $this->driver->isReferenced($callbackId);
    }

    /**
     * 为调试目的提供对象信息。
     *
     * @return array 对象的调试信息。
     */
    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }

    /**
     * 向事件循环队列添加一个函数调用。
     *
     * @param \Closure $closure 要执行的回调函数。
     * @param mixed    ...$args 传递给回调函数的参数。
     */
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->driver->queue($closure, ...$args);
    }

    /**
     * 获取回调创建时的堆栈跟踪信息。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 堆栈跟踪信息。
     */
    private function getCreationTrace(string $callbackId): string
    {
        return $this->creationTraces[$callbackId] ?? 'No creation trace, yet.';
    }

    /**
     * 获取回调取消时的堆栈跟踪信息。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 堆栈跟踪信息。
     */
    private function getCancelTrace(string $callbackId): string
    {
        return $this->cancelTraces[$callbackId] ?? 'No cancellation trace, yet.';
    }

    /**
     * 格式化通过 `debug_backtrace()` 获取的堆栈跟踪信息。
     *
     * @param list<array{
     *     args?: list<mixed>,
     *     class?: class-string,
     *     file?: string,
     *     function: string,
     *     line?: int,
     *     object?: object,
     *     type?: string
     * }> $trace `debug_backtrace()` 的输出结果。
     *
     * @return string 格式化后的堆栈跟踪信息。
     */
    private function formatStacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"], $e['line'])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, \array_keys($trace)));
    }
}
