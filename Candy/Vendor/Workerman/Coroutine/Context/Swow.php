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
use Swow\Coroutine;
use WeakMap;

/**
 * 基于Swow扩展的上下文实现
 * 
 * 使用Swow扩展的Coroutine和WeakMap实现上下文功能，每个Swow协程都有自己独立的上下文
 */
class Swow implements ContextInterface
{
    /**
     * 协程上下文映射表
     *
     * @var WeakMap<Coroutine, ArrayObject> 使用WeakMap存储每个Swow协程对应的上下文，避免内存泄漏
     */
    public static WeakMap $contexts;

    /**
     * 从上下文中获取指定名称的值
     *
     * @param string|null $name 要获取的值的名称，null表示获取整个上下文数组
     * @param mixed $default 如果名称不存在时返回的默认值
     * @return mixed 上下文中的值或默认值
     */
    public static function get(?string $name = null, mixed $default = null): mixed
    {
        $fiber = Coroutine::getCurrent();
        if ($name === null) {
            static::$contexts[$fiber] ??= new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
            return static::$contexts[$fiber];
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
        $coroutine = Coroutine::getCurrent();
        static::$contexts[$coroutine] ??= new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        static::$contexts[$coroutine][$name] = $value;
    }

    /**
     * 检查上下文中是否存在指定名称
     *
     * @param string $name 要检查的名称
     * @return bool 如果名称存在返回true，否则返回false
     */
    public static function has(string $name): bool
    {
        $fiber = Coroutine::getCurrent();
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
        $coroutine = Coroutine::getCurrent();
        $data->setFlags(ArrayObject::ARRAY_AS_PROPS);
        static::$contexts[$coroutine] = $data;
    }

    /**
     * 销毁上下文
     *
     * @return void
     */
    public static function destroy(): void
    {
        unset(static::$contexts[Coroutine::getCurrent()]);
    }

    /**
     * 初始化上下文系统
     *
     * 创建WeakMap用于存储协程上下文
     *
     * @return void
     */
    public static function initContext(): void
    {
        self::$contexts = new WeakMap();
    }

}

// 初始化上下文系统
Swow::initContext();