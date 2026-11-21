<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

// @codeCoverageIgnoreStart
use Revolt\EventLoop\Driver\EvDriver;
use Revolt\EventLoop\Driver\EventDriver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\TracingDriver;
use Revolt\EventLoop\Driver\UvDriver;

/**
 * 事件循环驱动程序工厂类。
 *
 * 负责创建事件循环驱动程序实例，并选择最佳可用的驱动程序。
 */
final class DriverFactory
{
    /**
     * 创建一个新的事件循环实例并选择最佳可用的驱动程序。
     *
     * @return Driver 事件循环驱动程序实例。
     *
     * @throws \Error 如果通过 REVOLT_LOOP_DRIVER 指定了无效的类。
     */
    public function create(): Driver
    {
        $driver = (function () {
            if ($driver = $this->createDriverFromEnv()) {
                return $driver;
            }

            if (UvDriver::isSupported()) {
                return new UvDriver();
            }

            if (EvDriver::isSupported()) {
                return new EvDriver();
            }

            if (EventDriver::isSupported()) {
                return new EventDriver();
            }

            return new StreamSelectDriver();
        })();

        /** @psalm-suppress RiskyTruthyFalsyComparison */
        if (\getenv("REVOLT_DRIVER_DEBUG_TRACE")) {
            return new TracingDriver($driver);
        }

        return $driver;
    }

    /**
     * 从环境变量中创建驱动程序实例。
     *
     * @return Driver|null 如果环境变量中指定了有效的驱动程序，则返回该驱动程序实例，否则返回 null。
     */
    private function createDriverFromEnv(): ?Driver
    {
        $driver = \getenv("REVOLT_DRIVER");

        /** @psalm-suppress RiskyTruthyFalsyComparison */
        if (!$driver) {
            return null;
        }

        if (!\class_exists($driver)) {
            throw new \Error(\sprintf(
                "Driver '%s' does not exist.",
                $driver
            ));
        }

        if (!\is_subclass_of($driver, Driver::class)) {
            throw new \Error(\sprintf(
                "Driver '%s' is not a subclass of '%s'.",
                $driver,
                Driver::class
            ));
        }

        return new $driver();
    }
}
// @codeCoverageIgnoreEnd
