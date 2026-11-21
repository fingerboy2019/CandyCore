<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    workbunny/Chaz6chez
 * @email     chaz6chez1993@outlook.com
 * @copyright workbunny/Chaz6chez
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
declare(strict_types=1);

namespace Workerman\Coroutine;

use BadMethodCallException;
use Workerman\Worker;
use Workerman\Coroutine\Coroutine\CoroutineInterface;
use Workerman\Coroutine\WaitGroup\Fiber as FiberWaitGroup;
use Workerman\Coroutine\WaitGroup\Swoole as SwooleWaitGroup;
use Workerman\Coroutine\WaitGroup\Swow as SwowWaitGroup;
use Workerman\Coroutine\WaitGroup\WaitGroupInterface;
use Workerman\Events\Fiber;
use Workerman\Events\Swoole;
use Workerman\Events\Swow;

/**
 * WaitGroup类，根据不同的事件循环自动选择对应的WaitGroup驱动
 * 
 * 支持三种WaitGroup驱动：
 * 1. Swoole - 基于Swoole扩展的WaitGroup
 * 2. Swow - 基于Swow扩展的WaitGroup
 * 3. Fiber - 基于PHP 8.1+ Fiber的WaitGroup
 *
 * @method bool add(int $delta = 1) 增加等待计数
 * @method bool done() 减少等待计数
 * @method int count() 获取当前等待计数
 * @method bool wait(int|float $timeout = -1) 等待计数变为0
 */
class WaitGroup
{
    /**
     * WaitGroup驱动类名
     *
     * @var class-string<WaitGroupInterface> 具体的WaitGroup驱动类名
     */
    protected static string $driverClass;

    /**
     * WaitGroup驱动实例
     *
     * @var WaitGroupInterface 具体的WaitGroup驱动实例
     */
    protected WaitGroupInterface $driver;

    /**
     * 构造方法
     */
    public function __construct()
    {
        $this->driver = new (self::driverClass());
    }

    /**
     * 获取驱动类名
     *
     * 根据当前的事件循环选择对应的WaitGroup驱动类
     *
     * @return class-string<WaitGroupInterface> 驱动类名
     */
    protected static function driverClass(): string
    {
        return static::$driverClass ??= match (Worker::$eventLoopClass ?? null) {
            Swoole::class => SwooleWaitGroup::class,
            Swow::class => SwowWaitGroup::class,
            default => FiberWaitGroup::class,
        };
    }

    /**
     * 代理调用WaitGroupInterface方法
     *
     * 将所有方法调用转发给具体的WaitGroup驱动实例
     *
     * @codeCoverageIgnore 系统魔术方法，忽略覆盖
     * @param string $name 方法名
     * @param array $arguments 方法参数
     * @return mixed 方法返回值
     * @throws BadMethodCallException 如果方法不存在
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!method_exists($this->driver, $name)) {
            throw new BadMethodCallException("Method $name not exists. ");
        }

        return $this->driver->$name(...$arguments);
    }
}
