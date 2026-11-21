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

namespace Workerman\Coroutine\Channel;

use Fiber as BaseFiber;
use RuntimeException;
use Workerman\Timer;
use WeakMap;
use Workerman\Worker;

/**
 * 基于Fiber的通道实现
 * 
 * 支持协程等待机制的通道，当通道满时push会挂起协程，当通道空时pop会挂起协程
 * 使用WeakMap存储等待的协程，避免内存泄漏
 */
class Fiber implements ChannelInterface
{
    /**
     * 通道数据队列
     *
     * @var array 存储通道中的数据，使用FIFO队列方式
     */
    private array $queue = [];

    /**
     * 等待推送数据的协程映射表
     *
     * @var WeakMap<BaseFiber, bool> 存储因通道满而等待推送数据的协程
     */
    private WeakMap $waitingPush;

    /**
     * 等待弹出数据的协程映射表
     *
     * @var WeakMap<BaseFiber, bool> 存储因通道空而等待弹出数据的协程
     */
    private WeakMap $waitingPop;

    /**
     * 通道容量
     *
     * @var int 通道的最大容量，0表示无限容量
     */
    private int $capacity;

    /**
     * 通道是否已关闭
     *
     * @var bool 通道关闭状态
     */
    private bool $closed = false;

    /**
     * 构造函数
     *
     * @param int $capacity 通道容量，默认值为1
     */
    public function __construct(int $capacity = 1)
    {
        $this->capacity = $capacity;
        $this->waitingPush = new WeakMap();
        $this->waitingPop = new WeakMap();
    }

    /**
     * 向通道推送数据
     *
     * 当通道满时，会挂起当前协程，直到通道有空闲位置或超时
     *
     * @param mixed $data 要推送的数据
     * @param float $timeout 超时时间，单位秒，-1表示永不超时
     * @return bool 是否推送成功
     * @throws RuntimeException 如果不在协程环境中
     */
    public function push(mixed $data, float $timeout = -1): bool
    {
        if ($this->closed) {
            return false;
        }

        if (count($this->queue) >= $this->capacity) {

            if ($timeout == 0) {
                return false;
            }

            $fiber = BaseFiber::getCurrent();
            if ($fiber === null) {
                throw new RuntimeException("Fiber::getCurrent() returned null. Ensure this method is called within a Fiber context.");
            }

            $this->waitingPush[$fiber] = true;

            $timedOut = false;
            $timerId = null;
            if ($timeout > 0 && Worker::isRunning()) {
                $timerId = Timer::delay($timeout, function () use ($fiber, &$timedOut) {
                    $timedOut = true;
                    if ($fiber->isSuspended()) {
                        unset($this->waitingPush[$fiber]);
                        $fiber->resume(false);
                    }
                });
            }

            BaseFiber::suspend();
            unset($this->waitingPush[$fiber]);

            if (!$timedOut && $timerId) {
                Timer::del($timerId);
            }

            if ($timedOut) {
                return false;
            }

            // 如果在等待期间通道被关闭，返回false
            if ($this->closed) {
                return false;
            }

        }

        // 如果有等待的消费者，直接将数据传递给消费者
        foreach ($this->waitingPop as $popFiber => $_) {
            unset($this->waitingPop[$popFiber]);
            if ($popFiber->isSuspended()) {
                $popFiber->resume($data);
                return true;
            }
        }

        // 将数据添加到队列
        $this->queue[] = $data;
        return true;
    }

    /**
     * 从通道弹出数据
     *
     * 当通道空时，会挂起当前协程，直到通道有数据或超时
     *
     * @param float $timeout 超时时间，单位秒，-1表示永不超时
     * @return mixed 弹出的数据，如果通道关闭且队列为空或超时返回false
     * @throws RuntimeException 如果不在协程环境中
     */
    public function pop(float $timeout = -1): mixed
    {
        if ($this->closed && empty($this->queue)) {
            return false;
        }

        if (empty($this->queue)) {
            if ($timeout == 0) {
                return false;
            }

            $fiber = BaseFiber::getCurrent();
            if ($fiber === null) {
                throw new RuntimeException("Fiber::getCurrent() returned null. Ensure this method is called within a Fiber context.");
            }

            $this->waitingPop[$fiber] = true;

            $timedOut = false;
            $timerId = null;
            if ($timeout > 0) {
                Worker::isRunning() && $timerId = Timer::delay($timeout, function () use ($fiber, &$timedOut) {
                    $timedOut = true;
                    if ($fiber->isSuspended()) {
                        unset($this->waitingPop[$fiber]);
                        $fiber->resume(false);
                    }
                });
            }

            $data = BaseFiber::suspend();

            unset($this->waitingPop[$fiber]);

            if (!$timedOut && $timerId !== null) {
                Timer::del($timerId);
            }

            if ($timedOut) {
                return false;
            }

            if ($data === false && $this->closed) {
                return false;
            }

            return $data;
        }

        // 从队列头部取出数据
        $value = array_shift($this->queue);

        // 如果有等待的生产者，唤醒一个生产者
        foreach ($this->waitingPush as $pushFiber => $_) {
            unset($this->waitingPush[$pushFiber]);
            if ($pushFiber->isSuspended()) {
                $pushFiber->resume();
                break;
            }
        }

        return $value;
    }

    /**
     * 获取通道中当前数据的长度
     *
     * @return int 通道中数据的数量
     */
    public function length(): int
    {
        return count($this->queue);
    }

    /**
     * 获取通道的容量
     *
     * @return int 通道的最大容量
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * 检查是否有消费者在等待从通道弹出数据
     *
     * @return bool 是否有等待的消费者
     */
    public function hasConsumers(): bool
    {
        return count($this->waitingPop) > 0;
    }

    /**
     * 检查是否有生产者在等待向通道推送数据
     *
     * @return bool 是否有等待的生产者
     */
    public function hasProducers(): bool
    {
        return count($this->waitingPush) > 0;
    }

    /**
     * 关闭通道
     *
     * 关闭后通道不再接受新的数据推送，已有的数据可以继续弹出
     * 同时唤醒所有等待的协程
     *
     * @return void
     */
    public function close(): void
    {
        $this->closed = true;

        // 唤醒所有等待推送的协程
        foreach ($this->waitingPush as $fiber => $_) {
            unset($this->waitingPush[$fiber]);
            if ($fiber->isSuspended()) {
                $fiber->resume(false);
            }
        }
        $this->waitingPush = new WeakMap();

        // 唤醒所有等待弹出的协程
        foreach ($this->waitingPop as $fiber => $_) {
            unset($this->waitingPop[$fiber]);
            if ($fiber->isSuspended()) {
                $fiber->resume(false);
            }
        }
        $this->waitingPop = new WeakMap();
    }

}