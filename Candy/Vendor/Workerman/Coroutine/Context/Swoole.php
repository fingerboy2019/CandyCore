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
use Swoole\Coroutine;

/**
 * 基于Swoole扩展的上下文实现
 * 
 * 使用Swoole扩展提供的协程上下文功能，每个Swoole协程都有自己独立的上下文
 */
class Swoole implements ContextInterface
{

    /**
     * 从上下文中获取指定名称的值
     *
     * @param string|null $name 要获取的值的名称，null表示获取整个上下文数组
     * @param mixed $default 如果名称不存在时返回的默认值
     * @return mixed 上下文中的值或默认值
     */
    public static function get(?string $name = null, mixed $default = null): mixed
    {
        $context = Coroutine::getContext();
        $context->setFlags(ArrayObject::ARRAY_AS_PROPS);
        if ($name === null) {
            return $context;
        }
        return $context[$name] ?? $default;
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
        Coroutine::getContext()[$name] = $value;
    }

    /**
     * 检查上下文中是否存在指定名称
     *
     * @param string $name 要检查的名称
     * @return bool 如果名称存在返回true，否则返回false
     */
    public static function has(string $name): bool
    {
        $context = Coroutine::getContext();
        return $context->offsetExists($name);
    }

    /**
     * 使用数据初始化上下文
     *
     * @param ArrayObject|null $data 用于初始化上下文的数据，null表示创建一个空上下文
     * @return void
     */
    public static function reset(?ArrayObject $data = null): void
    {
        $context = Coroutine::getContext();
        $context->setFlags(ArrayObject::ARRAY_AS_PROPS);
        $context->exchangeArray($data ?: []);
    }

    /**
     * 销毁上下文
     *
     * @return void
     */
    public static function destroy(): void
    {
        $context = Coroutine::getContext();
        $context->exchangeArray([]);
    }

}