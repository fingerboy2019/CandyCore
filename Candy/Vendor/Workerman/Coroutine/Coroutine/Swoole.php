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

use RuntimeException;
use Swoole\Coroutine;
use WeakReference;

/**
 * 基于Swoole扩展的协程实现
 * 
 * 该类是Workerman协程系统的Swoole驱动实现，利用Swoole扩展提供的协程功能
 */
class Swoole implements CoroutineInterface
{

    /**
     * 协程实例映射表，使用WeakReference存储以避免内存泄漏
     *
     * @var array<int, WeakReference<Swoole>>
     */
    private static array $instances = [];

    /**
     * 协程ID
     *
     * @var int
     */
    private int $id = 0;

    /**
     * 协程要执行的回调函数
     *
     * @var callable|null
     */
    private $callable;

    /**
     * 构造函数
     *
     * @param callable|null $callable 协程要执行的回调函数，为空时创建一个未初始化的协程
     */
    public function __construct(?callable $callable = null)
    {
        $this->callable = $callable;
    }

    /**
     * 创建一个新的Swoole协程
     *
     * @param callable $callable 协程要执行的回调函数
     * @param mixed ...$args 传递给回调函数的参数
     * @return CoroutineInterface 协程实例
     */
    public static function create(callable $callable, ...$args): CoroutineInterface
    {
        $id = Coroutine::create($callable, ...$args);
        if (isset(self::$instances[$id]) && $coroutine = self::$instances[$id]->get()) {
            return $coroutine;
        }
        $coroutine = new self($callable);
        $coroutine->id = $id;
        self::$instances[$id] = WeakReference::create($coroutine);
        return $coroutine;
    }

    /**
     * 启动协程执行
     *
     * @param mixed ...$args 传递给协程回调函数的参数
     * @return CoroutineInterface 协程实例
     * @throws RuntimeException 如果协程已经启动
     */
    public function start(mixed  ...$args): CoroutineInterface
    {
        if ($this->id) {
            throw new RuntimeException('Coroutine has already started');
        }
        $this->id = Coroutine::create($this->callable, ...$args);
        $this->callable = null;
        if (isset(self::$instances[$this->id]) && $coroutine = self::$instances[$this->id]->get()) {
            return $coroutine;
        }
        self::$instances[$this->id] = WeakReference::create($this);
        return $this;
    }

    /**
     * 恢复被挂起的协程
     *
     * @param mixed ...$args 传递给协程的参数
     * @return mixed 协程执行结果
     */
    public function resume(mixed ...$args): mixed
    {
        return Coroutine::resume($this->id, ...$args);
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
     */
    public static function defer(callable $callable): void
    {
        Coroutine::defer($callable);
    }

    /**
     * 挂起当前协程
     *
     * @param mixed|null $value 挂起时传递给调用者的值
     * @return mixed 恢复协程时传递的值
     */
    public static function suspend(mixed $value = null): mixed
    {
        return Coroutine::suspend($value);
    }

    /**
     * 获取当前正在执行的协程
     *
     * @return CoroutineInterface 当前协程实例
     * @throws RuntimeException 如果不在协程环境中
     */
    public static function getCurrent(): CoroutineInterface
    {
        $id = Coroutine::getCid();
        if ($id === -1) {
            throw new RuntimeException('Not in coroutine');
        }
        if (!isset(self::$instances[$id])) {
            $coroutine = new self();
            $coroutine->id = $id;
            self::$instances[$id] = WeakReference::create($coroutine);
        }
        return self::$instances[$id]->get();
    }

    /**
     * 检查当前是否在协程环境中
     *
     * @return bool 是否在协程环境中
     */
    public static function isCoroutine(): bool
    {
        return Coroutine::getCid() > 0;
    }

    /**
     * 析构函数
     *
     * 从实例映射表中移除当前协程实例
     */
    public function __destruct()
    {
        unset(self::$instances[$this->id]);
    }

}
