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

use Fiber as BaseFiber;
use RuntimeException;
use WeakMap;
use Workerman\Coroutine\Utils\DestructionWatcher;

/**
 * 基于PHP 8.1+ Fiber的协程实现
 * 
 * 该类是Workerman协程系统的核心实现之一，利用PHP 8.1+内置的Fiber特性来实现协程功能
 */
class Fiber implements CoroutineInterface
{
    /**
     * PHP内置Fiber实例
     *
     * @var BaseFiber|null
     */
    private ?BaseFiber $fiber;

    /**
     * Fiber实例映射表，用于通过BaseFiber实例获取对应的Fiber包装类实例
     *
     * @var WeakMap
     */
    private static WeakMap $instances;

    /**
     * 协程ID
     *
     * @var int
     */
    private int $id;

    /**
     * 构造函数
     *
     * @param callable|null $callable 协程要执行的回调函数，为空时创建一个未初始化的协程
     */
    public function __construct(?callable $callable = null)
    {
        static $id = 0;
        $this->id = ++$id;
        if ($callable) {
            $callable = function(...$args) use ($callable) {
                try {
                    $callable(...$args);
                } finally {
                    $this->fiber = null;
                }
            };
            $this->fiber = new BaseFiber($callable);
            self::$instances[$this->fiber] = $this;
        }
    }

    /**
     * 创建并启动一个新的协程
     *
     * @param callable $callable 协程要执行的回调函数
     * @param mixed ...$args 传递给回调函数的参数
     * @return CoroutineInterface 协程实例
     */
    public static function create(callable $callable, ...$args): CoroutineInterface
    {
        $fiber = new Fiber($callable);
        $fiber->start(...$args);
        return $fiber;
    }

    /**
     * 启动协程执行
     *
     * @param mixed ...$args 传递给协程回调函数的参数
     * @return mixed 协程执行结果
     */
    public function start(mixed ...$args): mixed
    {
        return $this->fiber->start(...$args);
    }

    /**
     * 恢复被挂起的协程
     *
     * @param mixed ...$args 传递给协程的参数
     * @return mixed 协程执行结果
     */
    public function resume(mixed ...$args): mixed
    {
        return $this->fiber->resume(...$args);
    }

    /**
     * 挂起当前协程
     *
     * @param mixed|null $value 挂起时传递给调用者的值
     * @return mixed 恢复协程时传递的值
     */
    public static function suspend(mixed $value = null): mixed
    {
        return BaseFiber::suspend($value);
    }

    /**
     * 获取协程ID
     *
     * @return int 协程ID
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * 注册一个回调函数，在当前协程销毁时执行
     *
     * @param callable $callable 要延迟执行的回调函数
     * @return void
     * @throws RuntimeException 如果不在协程环境中
     */
    public static function defer(callable $callable): void
    {
        $baseFiber = BaseFiber::getCurrent();
        if ($baseFiber === null) {
            throw new RuntimeException('Cannot defer outside of a fiber.');
        }
        DestructionWatcher::watch($baseFiber, $callable);
    }

    /**
     * 获取当前正在执行的协程
     *
     * @return CoroutineInterface 当前协程实例
     * @throws RuntimeException 如果不在协程环境中
     */
    public static function getCurrent(): CoroutineInterface
    {
        if (!$baseFiber = BaseFiber::getCurrent()) {
            throw new RuntimeException('Not in fiber context');
        }
        if (!isset(self::$instances[$baseFiber])) {
            $fiber = new Fiber();
            $fiber->fiber = $baseFiber;
            self::$instances[$baseFiber] = $fiber;
        }
        return self::$instances[$baseFiber];
    }

    /**
     * 检查当前是否在协程环境中
     *
     * @return bool 是否在协程环境中
     */
    public static function isCoroutine(): bool
    {
        return BaseFiber::getCurrent() !== null;
    }

    /**
     * 初始化Fiber类
     *
     * 创建WeakMap实例用于存储Fiber实例映射
     *
     * @return void
     */
    public static function init(): void
    {
        self::$instances = new WeakMap();
    }

}

Fiber::init();
