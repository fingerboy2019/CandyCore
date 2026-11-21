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

namespace Workerman\Coroutine\Barrier;

use Revolt\EventLoop;
use RuntimeException;
use Workerman\Coroutine\Utils\DestructionWatcher;
use Workerman\Timer;
use Fiber as BaseFiber;
use Workerman\Worker;

/**
 * 基于Fiber的屏障实现
 * 
 * 使用PHP 8.1+的Fiber特性实现屏障功能，用于让多个协程在继续执行前等待彼此到达某个点
 */
class Fiber implements BarrierInterface
{

    /**
     * 等待屏障被释放
     *
     * @param object $barrier 屏障对象引用，当屏障被释放后会被设置为null
     * @param int $timeout 超时时间，单位秒，-1表示永不超时
     * @return void
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        $coroutine = BaseFiber::getCurrent();
        $resumed = false;
        $timerId = null;

        // 设置超时定时器
        if ($timeout > 0 && $coroutine) {
            $timerId = Timer::delay($timeout, function() use ($coroutine, &$resumed) {
                if (!$resumed) {
                    $resumed = true;
                    $coroutine->resume();
                }
            });
        }

        // 监听屏障对象的销毁事件，当屏障被销毁时恢复协程
        $coroutine && DestructionWatcher::watch($barrier, function() use ($coroutine, &$resumed, &$timerId) {
            if (!$resumed) {
                $resumed = true;
                // 清除超时定时器
                if ($timerId !== null) {
                    Timer::del($timerId);
                }
                // 在PHP 8.4.0及更早版本中，
                // 不允许在对象的析构函数执行过程中切换纤维，
                // 因此我们实现了延迟恢复
                if ($coroutine instanceof BaseFiber) {
                    Timer::delay(0.00001, function() use ($coroutine) {
                        $coroutine->resume();
                    });
                    return;
                }
                EventLoop::defer(function () use ($coroutine) {
                    $coroutine->resume();
                });
            }
        });
        // 清除屏障引用
        $barrier = null;
        // 挂起当前协程，直到屏障被释放或超时
        $coroutine && BaseFiber::suspend();
    }

    /**
     * 创建一个新的屏障实例
     *
     * @return object 屏障实例
     * @throws RuntimeException 如果不在Workerman运行时中
     */
    public static function create(): object
    {
        if (!Worker::isRunning()) {
            throw new RuntimeException('Fiber barrier only support in workerman runtime');
        }
        return new self();
    }

}
