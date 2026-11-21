<?php

declare(strict_types=1);

namespace Revolt;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * 允许全局访问事件循环的访问器类。
 *
 * @see Driver
 */
final class EventLoop
{
    /**
     * 事件循环驱动实例
     *
     * @var Driver
     */
    private static Driver $driver;

    /**
     * 设置事件循环使用的驱动程序。
     *
     * @param Driver $driver 要使用的事件循环驱动实例
     */
    public static function setDriver(Driver $driver): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (isset(self::$driver) && self::$driver->isRunning()) {
            throw new \Error("Can't swap the event loop driver while the driver is running");
        }

        try {
            /** @psalm-suppress InternalClass */
            self::$driver = new class () extends AbstractDriver {
                protected function activate(array $callbacks): void
                {
                    throw new \Error("Can't activate callback during garbage collection.");
                }

                protected function dispatch(bool $blocking): void
                {
                    throw new \Error("Can't dispatch during garbage collection.");
                }

                protected function deactivate(DriverCallback $callback): void
                {
                    // do nothing
                }

                public function getHandle(): mixed
                {
                    return null;
                }

                protected function now(): float
                {
                    return (float) \hrtime(true) / 1_000_000_000;
                }
            };

            \gc_collect_cycles();
        } finally {
            self::$driver = $driver;
        }
    }

    /**
     * 排队执行一个微任务。
     *
     * 当事件循环获得控制权时，排队的回调必须立即执行。执行回调时必须保持排队顺序。
     * 递归调度可能会导致无限循环，使用时需谨慎。
     *
     * 不会创建事件回调，因此无法标记为禁用或取消引用。
     * 如果需要这些功能，请使用 {@see EventLoop::defer()}。
     *
     * @param \Closure(...):void $closure 要排队执行的回调函数
     * @param mixed ...$args 回调函数的参数
     */
    public static function queue(\Closure $closure, mixed ...$args): void
    {
        self::getDriver()->queue($closure, ...$args);
    }

    /**
     * 延迟执行一个回调函数。
     *
     * 在一个事件循环周期(tick)中，延迟回调必须在任何其他类型的回调之前执行。执行回调时必须保持启用顺序。
     *
     * 创建的回调会立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 延迟回调不会在它们被启用的同一周期中被调用。
     *
     * @param \Closure(string):void $closure 要延迟执行的回调函数。`$callbackId`会在回调调用前失效。
     *
     * @return string 用于取消、启用或禁用回调的唯一标识符。
     */
    public static function defer(\Closure $closure): string
    {
        return self::getDriver()->defer($closure);
    }

    /**
     * 延迟一段时间后执行一个回调函数。
     *
     * 延迟时间是最小的近似值，不保证精确性。调用顺序必须由定时器的到期时间决定，
     * 但具有相同到期时间的定时器可以以任何顺序执行。
     *
     * 创建的回调会立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 回调不会在它们被启用的同一周期中被调用。
     *
     * @param float $delay 延迟的时间，单位为秒。
     * @param \Closure(string):void $closure 要延迟执行的回调函数。`$callbackId`会在回调调用前失效。
     *
     * @return string 用于取消、启用或禁用回调的唯一标识符。
     */
    public static function delay(float $delay, \Closure $closure): string
    {
        return self::getDriver()->delay($delay, $closure);
    }

    /**
     * 重复执行一个回调函数。
     *
     * 执行间隔是最小的近似值，不保证精确性。调用顺序必须由定时器的到期时间决定，
     * 但具有相同到期时间的定时器可以以任何顺序执行。第一次执行会在第一个间隔时间后调度。
     *
     * 创建的回调会立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 回调不会在它们被启用的同一周期中被调用。
     *
     * @param float $interval 执行间隔时间，单位为秒。
     * @param \Closure(string):void $closure 要重复执行的回调函数。
     *
     * @return string 用于取消、启用或禁用回调的唯一标识符。
     */
    public static function repeat(float $interval, \Closure $closure): string
    {
        return self::getDriver()->repeat($interval, $closure);
    }

    /**
     * 当流资源变得可读或关闭读取时执行回调函数。
     *
     * 警告：本地关闭资源（例如使用 `fclose`）可能不会触发回调。
     * 当本地关闭资源时，请确保调用 `cancel` 取消回调。
     * 驱动程序可以选择在无效资源上有回调时通知用户，但由于高性能影响，不强制要求这样做。
     * 因此，关闭资源上的回调是未定义行为。
     *
     * 同一流上的多个回调可以以任何顺序执行。
     *
     * 创建的回调会立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 回调不会在它们被启用的同一周期中被调用。
     *
     * @param resource $stream 要监控的流资源。
     * @param \Closure(string, resource):void $closure 要执行的回调函数。
     *
     * @return string 用于取消、启用或禁用回调的唯一标识符。
     */
    public static function onReadable(mixed $stream, \Closure $closure): string
    {
        return self::getDriver()->onReadable($stream, $closure);
    }

    /**
     * 当流资源变得可写或关闭写入时执行回调函数。
     *
     * 警告：本地关闭资源（例如使用 `fclose`）可能不会触发回调。
     * 当本地关闭资源时，请确保调用 `cancel` 取消回调。
     * 驱动程序可以选择在无效资源上有回调时通知用户，但由于高性能影响，不强制要求这样做。
     * 因此，关闭资源上的回调是未定义行为。
     *
     * 同一流上的多个回调可以以任何顺序执行。
     *
     * 创建的回调会立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 回调不会在它们被启用的同一周期中被调用。
     *
     * @param resource $stream 要监控的流资源。
     * @param \Closure(string, resource):void $closure 要执行的回调函数。
     *
     * @return string 用于取消、启用或禁用回调的唯一标识符。
     */
    public static function onWritable(mixed $stream, \Closure $closure): string
    {
        return self::getDriver()->onWritable($stream, $closure);
    }

    /**
     * 当接收到信号时执行回调函数。
     *
     * 警告：在这个接口的不同实例上安装相同的信号被视为未定义行为。
     * 实现可以尝试检测这种情况（如果可能的话），但不强制要求这样做。
     * 这是由于信号在每个进程中全局注册的技术限制。
     *
     * 同一信号上的多个回调可以以任何顺序执行。
     *
     * 创建的回调会立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 回调不会在它们被启用的同一周期中被调用。
     *
     * @param int $signal 要监控的信号编号。
     * @param \Closure(string, int):void $closure 要执行的回调函数。
     *
     * @return string 用于取消、启用或禁用回调的唯一标识符。
     *
     * @throws UnsupportedFeatureException 如果不支持信号处理。
     */
    public static function onSignal(int $signal, \Closure $closure): string
    {
        return self::getDriver()->onSignal($signal, $closure);
    }

    /**
     * 启用一个回调，使其从下一个事件循环周期开始生效。
     *
     * 回调必须立即被标记为启用，但只有在下次事件循环周期开始前才会被激活（即可以调用）。
     * 回调不会在它们被启用的同一周期中被调用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public static function enable(string $callbackId): string
    {
        return self::getDriver()->enable($callbackId);
    }

    /**
     * 立即禁用一个回调。
     *
     * 回调必须立即被禁用，例如，如果一个延迟回调禁用了另一个延迟回调，
     * 第二个延迟回调不会在这个周期中执行。
     *
     * 禁用回调不会使回调失效。调用此函数不会失败，即使传递了无效的回调标识符。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     */
    public static function disable(string $callbackId): string
    {
        return self::getDriver()->disable($callbackId);
    }

    /**
     * 取消一个回调。
     *
     * 这将使事件循环与所有与回调关联的资源分离。此操作后，回调将永久无效。
     * 调用此函数不会失败，即使传递了无效的标识符。
     *
     * @param string $callbackId 回调标识符。
     */
    public static function cancel(string $callbackId): void
    {
        self::getDriver()->cancel($callbackId);
    }

    /**
     * 引用一个回调。
     *
     * 这将在事件仍被监控时保持事件循环运行。回调默认具有此状态。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     *
     * @throws InvalidCallbackError 如果回调标识符无效。
     */
    public static function reference(string $callbackId): string
    {
        return self::getDriver()->reference($callbackId);
    }

    /**
     * 取消引用一个回调。
     *
     * 当只有取消引用的回调仍被监控时，事件循环应该退出run方法。所有回调默认都是被引用的。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return string 回调标识符。
     */
    public static function unreference(string $callbackId): string
    {
        return self::getDriver()->unreference($callbackId);
    }

    /**
     * 设置一个在发生错误时执行的回调函数。
     *
     * 回调函数接收错误作为第一个也是唯一的参数。回调函数的返回值会被忽略。
     * 如果它无法处理错误，必须抛出该错误。由回调函数抛出或在其调用过程中抛出的错误
     * 必须被抛入 `run` 循环并停止驱动程序。
     *
     * 后续调用此方法将覆盖先前的处理程序。
     *
     * @param null|\Closure(\Throwable):void $errorHandler 要执行的回调函数。`null` 将清除当前处理程序。
     */
    public static function setErrorHandler(?\Closure $errorHandler): void
    {
        self::getDriver()->setErrorHandler($errorHandler);
    }

    /**
     * 获取错误处理程序闭包，如果没有设置则返回 {@code null}。
     *
     * @return null|\Closure(\Throwable):void 先前的处理程序，如果没有则返回 `null`。
     */
    public static function getErrorHandler(): ?\Closure
    {
        return self::getDriver()->getErrorHandler();
    }

    /**
     * 返回所有已注册且未取消的回调标识符。
     *
     * @return string[] 回调标识符数组。
     */
    public static function getIdentifiers(): array
    {
        return self::getDriver()->getIdentifiers();
    }

    /**
     * 返回由给定回调标识符标识的回调类型。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return CallbackType 回调类型。
     */
    public static function getType(string $callbackId): CallbackType
    {
        return self::getDriver()->getType($callbackId);
    }

    /**
     * 返回由给定回调标识符标识的回调当前是否已启用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return bool 如果回调当前已启用则返回 `true`，否则返回 `false`。
     */
    public static function isEnabled(string $callbackId): bool
    {
        return self::getDriver()->isEnabled($callbackId);
    }

    /**
     * 返回由给定回调标识符标识的回调当前是否被引用。
     *
     * @param string $callbackId 回调标识符。
     *
     * @return bool 如果回调当前被引用则返回 `true`，否则返回 `false`。
     */
    public static function isReferenced(string $callbackId): bool
    {
        return self::getDriver()->isReferenced($callbackId);
    }

    /**
     * 获取当前作用域内的事件循环驱动程序。
     *
     * @return Driver 事件循环驱动程序实例。
     */
    public static function getDriver(): Driver
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (!isset(self::$driver)) {
            self::setDriver((new DriverFactory())->create());
        }

        return self::$driver;
    }

    /**
     * 返回一个用于挂起和恢复当前纤程或{main}执行的对象。
     *
     * 来自同一纤程的调用将返回相同的挂起对象。
     *
     * @return Suspension
     */
    public static function getSuspension(): Suspension
    {
        return self::getDriver()->getSuspension();
    }

    /**
     * 运行事件循环。
     *
     * 此函数只能从{main}调用，即不能在纤程内调用。
     *
     * 库应该使用{@link Suspension} API而不是调用此方法。
     *
     * 此方法将不会返回，直到事件循环不再包含任何挂起的、被引用的回调。
     */
    public static function run(): void
    {
        self::getDriver()->run();
    }

    /**
     * 禁用构造函数，因为这是一个静态类。
     */
    private function __construct()
    {
        // intentionally left blank
    }
}
