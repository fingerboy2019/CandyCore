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

use Workerman\Coroutine\Barrier\BarrierInterface;
use Workerman\Events\Swoole;
use Workerman\Events\Swow;
use Workerman\Worker;

/**
 * 屏障类，根据不同的事件循环自动选择对应的屏障驱动
 * 
 * 支持三种屏障驱动：
 * 1. Swoole - 基于Swoole扩展的屏障
 * 2. Swow - 基于Swow扩展的屏障
 * 3. Fiber - 基于PHP 8.1+ Fiber的屏障
 */
class Barrier implements BarrierInterface
{

    /**
     * 屏障驱动类名
     *
     * @var string 具体的屏障驱动类名
     */
    protected static string $driver;

    /**
     * 获取驱动类名
     *
     * 根据当前的事件循环选择对应的屏障驱动类
     *
     * @return string 驱动类名
     */
    protected static function getDriver(): string
    {
        return static::$driver ??= match (Worker::$eventLoopClass) {
            Swoole::class => Barrier\Swoole::class,
            Swow::class => Barrier\Swow::class,
            default=> Barrier\Fiber::class,
        };
    }

    /**
     * 等待屏障被释放
     *
     * @param object $barrier 屏障对象引用
     * @param int $timeout 超时时间，单位秒，-1表示永不超时
     * @return void
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        static::getDriver()::wait($barrier, $timeout);
    }

    /**
     * 创建一个新的屏障实例
     *
     * @return object 屏障实例
     */
    public static function create(): object
    {
        return static::getDriver()::create();
    }

}