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

namespace Workerman;

use Workerman\Coroutine\Coroutine\CoroutineInterface;
use Workerman\Coroutine\Coroutine\Fiber;
use Workerman\Worker;
use Workerman\Coroutine\Coroutine\Swoole as SwooleCoroutine;
use Workerman\Coroutine\Coroutine\Swow as SwowCoroutine;
use Workerman\Events\Swoole as SwooleEvent;
use Workerman\Events\Swow as SwowEvent;

/**
 * 协程类，Workerman协程的核心实现，根据不同的事件循环自动选择对应的协程驱动
 * 
 * 支持三种协程驱动：
 * 1. Fiber - PHP 8.1+ 内置协程支持
 * 2. Swoole - 基于Swoole扩展的协程
 * 3. Swow - 基于Swow扩展的协程
 */
class Coroutine implements CoroutineInterface
{
    /**
     * 协程驱动类名
     *
     * @var class-string<CoroutineInterface>
     */
    protected static string $driverClass;

    /**
     * 协程驱动实例
     *
     * @var CoroutineInterface
     */
    public CoroutineInterface $driver;

    /**
     * 构造函数
     *
     * @param callable $callable 协程要执行的回调函数
     */
    public function __construct(callable $callable)
    {
        $this->driver = new static::$driverClass($callable);
    }

    /**
     * 创建一个新的协程
     *
     * @param callable $callable 协程要执行的回调函数
     * @param mixed ...$args 传递给回调函数的参数
     * @return CoroutineInterface 协程实例
     */
    public static function create(callable $callable, ...$args): CoroutineInterface
    {
        return static::$driverClass::create($callable, ...$args);
    }

    /**
     * 启动协程
     *
     * @param mixed ...$args 传递给协程回调函数的参数
     * @return mixed 协程执行结果
     */
    public function start(mixed ...$args): mixed
    {
        return $this->driver->start(...$args);
    }

    /**
     * 恢复协程执行
     *
     * @param mixed ...$args 传递给协程的参数
     * @return mixed 协程执行结果
     */
    public function resume(mixed ...$args): mixed
    {
        return $this->driver->resume(...$args);
    }

    /**
     * 获取协程ID
     *
     * @return int 协程ID
     */
    public function id(): int
    {
        return $this->driver->id();
    }

    /**
     * 延迟执行回调函数，在当前协程让出控制权后执行
     *
     * @param callable $callable 要延迟执行的回调函数
     * @return void
     */
    public static function defer(callable $callable): void
    {
        static::$driverClass::defer($callable);
    }

    /**
     * 挂起当前协程
     *
     * @param mixed $value 挂起时传递给调用者的值
     * @return mixed 恢复协程时传递的值
     */
    public static function suspend(mixed $value = null): mixed
    {
        return static::$driverClass::suspend($value);
    }

    /**
     * 获取当前正在执行的协程
     *
     * @return CoroutineInterface 当前协程实例
     */
    public static function getCurrent(): CoroutineInterface
    {
        return static::$driverClass::getCurrent();
    }

    /**
     * 检查当前是否在协程环境中
     *
     * @return bool 是否在协程环境中
     */
    public static function isCoroutine(): bool
    {
        return static::$driverClass::isCoroutine();
    }

    /**
     * 初始化协程驱动
     *
     * 根据当前使用的事件循环自动选择对应的协程驱动：
     * - SwooleEvent 对应 SwooleCoroutine
     * - SwowEvent 对应 SwowCoroutine
     * - 默认使用 Fiber
     *
     * @return void
     */
    public static function init(): void
    {
        static::$driverClass = match (Worker::$eventLoopClass ?? null) {
            SwooleEvent::class => SwooleCoroutine::class,
            SwowEvent::class => SwowCoroutine::class,
            default => Fiber::class,
        };
    }

}
Coroutine::init();
