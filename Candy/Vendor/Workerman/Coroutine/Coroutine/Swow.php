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

namespace Workerman\Coroutine\Coroutine;

use Swow\Coroutine;

/**
 * 基于Swow扩展的协程实现
 * 
 * 该类是Workerman协程系统的Swow驱动实现，继承自Swow\Coroutine并实现了CoroutineInterface接口
 */
class Swow extends Coroutine implements CoroutineInterface
{

    /**
     * 延迟执行的回调函数数组
     *
     * @var array<callable> 存储通过defer方法注册的回调函数
     */
    private array $callbacks = [];

    /**
     * 注册一个回调函数，在当前协程销毁时执行
     *
     * @param callable $callable 要延迟执行的回调函数
     * @return void
     */
    public static function defer(callable $callable): void
    {
        $coroutine = static::getCurrent();
        $coroutine->callbacks[] = $callable;
    }

    /**
     * 创建一个新的Swow协程
     *
     * @param callable $callable 协程要执行的回调函数
     * @param mixed ...$args 传递给回调函数的参数
     * @return CoroutineInterface 协程实例
     */
    public static function create(callable $callable, ...$args): CoroutineInterface
    {
        return static::run($callable, ...$args);
    }

    /**
     * 启动协程执行
     *
     * @param mixed ...$args 传递给协程的参数
     * @return mixed 协程执行结果
     */
    public function start(mixed ...$args): mixed
    {
        return $this->resume(...$args);
    }

    /**
     * 获取协程ID
     *
     * @return int 协程ID
     */
    public function id(): int
    {
        return $this->getId();
    }

    /**
     * 挂起当前协程
     *
     * @param mixed|null $value 挂起时传递给调用者的值
     * @return mixed 恢复协程时传递的值
     */
    public static function suspend(mixed $value = null): mixed
    {
        return Coroutine::yield($value);
    }

    /**
     * 检查当前是否在协程环境中
     *
     * 在Swow中，总是返回true，因为Swow本身就是一个协程环境
     *
     * @return bool 是否在协程环境中
     */
    public static function isCoroutine(): bool
    {
        return true;
    }

    /**
     * 析构函数
     *
     * 逆序执行所有通过defer方法注册的回调函数
     */
    public function __destruct()
    {
        foreach (array_reverse($this->callbacks) as $callable) {
            $callable();
        }
    }

}