<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * 驱动回调的抽象基类。
 *
 * @internal
 */
abstract class DriverCallback
{
    /**
     * 是否可以调用。
     *
     * @var bool
     */
    public bool $invokable = false;

    /**
     * 是否启用。
     *
     * @var bool
     */
    public bool $enabled = true;

    /**
     * 是否引用。
     *
     * @var bool
     */
    public bool $referenced = true;

    /**
     * 构造函数。
     *
     * @param string   $id      回调标识符。
     * @param \Closure $closure 回调函数。
     */
    public function __construct(
        public readonly string $id,
        public readonly \Closure $closure
    ) {
    }

    /**
     * 获取属性（不支持）。
     *
     * @param string $property 属性名。
     *
     * @return never
     *
     * @throws \Error 总是抛出错误，因为不支持获取属性。
     */
    public function __get(string $property): never
    {
        throw new \Error("Unknown property '{$property}'");
    }

    /**
     * 设置属性（不支持）。
     *
     * @param string $property 属性名。
     * @param mixed  $value    属性值。
     *
     * @return never
     *
     * @throws \Error 总是抛出错误，因为不支持设置属性。
     */
    public function __set(string $property, mixed $value): never
    {
        throw new \Error("Unknown property '{$property}'");
    }
}
