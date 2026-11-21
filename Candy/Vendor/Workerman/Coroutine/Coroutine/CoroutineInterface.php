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

use Fiber;
use Swow\Coroutine as SwowCoroutine;

/**
 * 协程接口，定义了所有协程驱动必须实现的方法
 * 
 * 这个接口是Workerman协程系统的核心，所有协程驱动（Fiber、Swoole、Swow）都必须实现这些方法
 */
interface CoroutineInterface
{

    /**
     * 创建一个新的协程
     *
     * @param callable $callable 协程要执行的回调函数
     * @param mixed ...$data 传递给回调函数的参数
     * @return CoroutineInterface 协程实例
     */
    public static function create(callable $callable, ...$data): CoroutineInterface;

    /**
     * 启动协程执行
     *
     * @param mixed ...$args 传递给协程回调函数的参数
     * @return mixed 协程执行结果
     */
    public function start(mixed ...$args): mixed;

    /**
     * 恢复被挂起的协程
     *
     * @param mixed ...$args 传递给协程的参数
     * @return mixed 协程执行结果
     */
    public function resume(mixed ...$args): mixed;

    /**
     * 获取协程ID
     *
     * @return int 协程ID
     */
    public function id(): int;

    /**
     * 注册一个回调函数，在当前协程销毁时执行
     *
     * @param callable $callable 要延迟执行的回调函数
     * @return void
     */
    public static function defer(callable $callable): void;

    /**
     * 挂起当前协程
     *
     * @param mixed|null $value 挂起时传递给调用者的值
     * @return mixed 恢复协程时传递的值
     */
    public static function suspend(mixed $value = null): mixed;

    /**
     * 获取当前正在执行的协程
     *
     * @return CoroutineInterface|Fiber|SwowCoroutine|static 当前协程实例，可以是不同驱动的实现
     */
    public static function getCurrent(): CoroutineInterface|Fiber|SwowCoroutine|static;

    /**
     * 检查当前是否在协程环境中
     *
     * @return bool 是否在协程环境中
     */
    public static function isCoroutine(): bool;

}