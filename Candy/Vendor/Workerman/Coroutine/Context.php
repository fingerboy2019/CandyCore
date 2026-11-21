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

declare(strict_types=1);

namespace Workerman\Coroutine;

use ArrayObject;
use Workerman\Coroutine\Context\ContextInterface;
use Workerman\Events\Swoole;
use Workerman\Events\Swow;
use Workerman\Worker;

/**
 * 上下文类，根据不同的事件循环自动选择对应的上下文驱动
 * 
 * 支持三种上下文驱动：
 * 1. Swoole - 基于Swoole扩展的上下文
 * 2. Swow - 基于Swow扩展的上下文
 * 3. Fiber - 基于PHP 8.1+ Fiber的上下文
 */
class Context implements ContextInterface
{

    /**
     * 上下文驱动类名
     *
     * @var class-string<ContextInterface> 具体的上下文驱动类名
     */
    protected static string $driver;

    /**
     * 从上下文中获取指定名称的值
     *
     * @param string|null $name 要获取的值的名称，null表示获取整个上下文数组
     * @param mixed $default 如果名称不存在时返回的默认值
     * @return mixed 上下文中的值或默认值
     */
    public static function get(?string $name = null, mixed $default = null): mixed
    {
        return static::$driver::get($name, $default);
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
        static::$driver::set($name, $value);
    }

    /**
     * 检查上下文中是否存在指定名称
     *
     * @param string $name 要检查的名称
     * @return bool 如果名称存在返回true，否则返回false
     */
    public static function has(string $name): bool
    {
        return static::$driver::has($name);
    }

    /**
     * 使用数据初始化上下文
     *
     * @param ArrayObject|null $data 用于初始化上下文的数据，null表示创建一个空上下文
     * @return void
     */
    public static function reset(?ArrayObject $data = null): void
    {
        static::$driver::reset($data);
    }

    /**
     * 销毁上下文
     *
     * @return void
     */
    public static function destroy(): void
    {
        static::$driver::destroy();
    }

    /**
     * 初始化上下文驱动
     *
     * 根据当前的事件循环选择对应的上下文驱动类
     *
     * @return void
     */
    public static function initDriver(): void
    {
        static::$driver ??= match (Worker::$eventLoopClass) {
            Swoole::class => Context\Swoole::class,
            Swow::class => Context\Swow::class,
            default=> Context\Fiber::class,
        };
    }

}

Context::initDriver();