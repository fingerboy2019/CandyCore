<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Workerman\Coroutine\Context;

use ArrayObject;
use WeakMap;
use Fiber as BaseFiber;

/**
 * 基于Fiber的上下文实现
 * 
 * 使用PHP 8.1+的Fiber特性和WeakMap实现上下文功能，
 * 每个Fiber都有自己独立的上下文，同时支持非Fiber环境下的上下文
 */
class Fiber implements ContextInterface
{
    /**
     * 协程上下文映射表
     *
     * @var WeakMap<BaseFiber, ArrayObject> 使用WeakMap存储每个Fiber对应的上下文，避免内存泄漏
     */
    private static WeakMap $contexts;

    /**
     * 非协程环境下的上下文
     *
     * @var ArrayObject 用于存储非Fiber环境下的上下文数据
     */
    private static ArrayObject $nonFiberContext;

    /**
     * 从上下文中获取指定名称的值
     *
     * @param string|null $name 要获取的值的名称，null表示获取整个上下文数组
     * @param mixed $default 如果名称不存在时返回的默认值
     * @return mixed 上下文中的值或默认值
     */
    public static function get(?string $name = null, mixed $default = null): mixed
    {
        $fiber = BaseFiber::getCurrent();
        if ($fiber === null) {
            // 非协程环境
            return $name !== null ? (static::$nonFiberContext[$name] ?? $default) : static::$nonFiberContext;
        }
        // 协程环境
        if ($name === null) {
            return static::$contexts[$fiber] ??= new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        }
        return static::$contexts[$fiber][$name] ?? $default;
    }

    /**
     * 在上下文中设置指定名称的值
     *
     * @param string $name 要设置的值的名称
     * @param mixed $value 要设置的值
     * @return void
     */
    public static function set(string $name, $value): void
    {
        $fiber = BaseFiber::getCurrent();
        if ($fiber === null) {
            // 非协程环境
            static::$nonFiberContext[$name] = $value;
            return;
        }
        // 协程环境
        static::$contexts[$fiber] ??= new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        static::$contexts[$fiber][$name] = $value;
    }

    /**
     * 检查上下文中是否存在指定名称
     *
     * @param string $name 要检查的名称
     * @return bool 如果名称存在返回true，否则返回false
     */
    public static function has(string $name): bool
    {
        $fiber = BaseFiber::getCurrent();
        if ($fiber === null) {
            // 非协程环境
            return static::$nonFiberContext->offsetExists($name);
        }
        // 协程环境
        return isset(static::$contexts[$fiber]) && static::$contexts[$fiber]->offsetExists($name);
    }

    /**
     * 使用数据初始化上下文
     *
     * @param ArrayObject|null $data 用于初始化上下文的数据，null表示创建一个空上下文
     * @return void
     */
    public static function reset(?ArrayObject $data = null): void
    {
        // 确保数据对象设置了ARRAY_AS_PROPS标志，允许使用对象属性方式访问数组元素
        if ($data) {
            $data->setFlags(ArrayObject::ARRAY_AS_PROPS);
        } else {
            $data = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        }
        $fiber = BaseFiber::getCurrent();
        if ($fiber === null) {
            // 非协程环境
            static::$nonFiberContext = $data;
            return;
        }
        // 协程环境
        static::$contexts[$fiber] = $data;
    }

    /**
     * 销毁上下文
     *
     * @return void
     */
    public static function destroy(): void
    {
        $fiber = BaseFiber::getCurrent();
        if ($fiber === null) {
            // 非协程环境，重置上下文为新的空ArrayObject
            static::$nonFiberContext = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
            return;
        }
        // 协程环境，从WeakMap中移除当前协程的上下文
        unset(static::$contexts[$fiber]);
    }

    /**
     * 初始化上下文系统
     *
     * 创建WeakMap和非协程上下文对象
     *
     * @return void
     */
    public static function initContext(): void
    {
        static::$contexts = new WeakMap();
        static::$nonFiberContext = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
    }

}

// 初始化上下文系统
Fiber::initContext();