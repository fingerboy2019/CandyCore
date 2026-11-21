<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * 纤程本地存储。
 *
 * 每个实例为每个纤程单独存储数据。使用示例包括上下文日志记录数据。
 *
 * @template T
 */
final class FiberLocal
{
    /**
     * 用于 {main} 的虚拟纤程。
     *
     * @var \Fiber|null
     */
    private static ?\Fiber $mainFiber = null;
    
    /**
     * 纤程本地存储的弱映射表。
     *
     * @var \WeakMap|null
     */
    private static ?\WeakMap $localStorage = null;

    /**
     * 清除当前纤程的所有本地存储数据。
     */
    public static function clear(): void
    {
        if (self::$localStorage === null) {
            return;
        }

        $fiber = \Fiber::getCurrent() ?? self::$mainFiber;

        if ($fiber === null) {
            return;
        }

        unset(self::$localStorage[$fiber]);
    }

    /**
     * 获取当前纤程的本地存储映射表。
     *
     * @return \WeakMap 当前纤程的本地存储映射表。
     */
    private static function getFiberStorage(): \WeakMap
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $fiber = self::$mainFiber ??= new \Fiber(static function (): void {
                // dummy fiber for main, as we need some object for the WeakMap
            });
        }

        $localStorage = self::$localStorage ??= new \WeakMap();
        return $localStorage[$fiber] ??= new \WeakMap();
    }

    /**
     * 构造函数。
     *
     * @param \Closure():T $initializer 用于初始化纤程本地值的闭包函数。
     */
    public function __construct(private readonly \Closure $initializer)
    {
    }

    /**
     * 设置当前纤程的本地存储值。
     *
     * @param T $value 要存储的值。
     */
    public function set(mixed $value): void
    {
        self::getFiberStorage()[$this] = [$value];
    }

    /**
     * 取消设置当前纤程的本地存储值。
     */
    public function unset(): void
    {
        unset(self::getFiberStorage()[$this]);
    }

    /**
     * 获取当前纤程的本地存储值。
     *
     * 如果值尚未设置，则使用初始化函数创建并存储一个新值。
     *
     * @return T 存储的值。
     */
    public function get(): mixed
    {
        $fiberStorage = self::getFiberStorage();

        if (!isset($fiberStorage[$this])) {
            $fiberStorage[$this] = [($this->initializer)()];
        }

        return $fiberStorage[$this][0];
    }
}
