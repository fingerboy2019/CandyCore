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

namespace Workerman\Coroutine\WaitGroup;

use Workerman\Coroutine\Channel\Fiber as Channel;

/**
 * 基于Fiber的WaitGroup实现
 * 
 * 使用Fiber通道实现协程同步等待组，用于等待多个协程完成
 */
class Fiber implements WaitGroupInterface
{

    /**
     * 等待计数
     *
     * @var int 当前等待的协程数量
     */
    protected int $count;

    /**
     * 用于同步的通道
     *
     * @var Channel 容量为1的Fiber通道，用于通知等待的协程
     */
    protected Channel $channel;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->count = 0;
        $this->channel = new Channel(1);
    }

    /**
     * 增加等待计数
     *
     * @param int $delta 增加的计数，默认值为1，最小值为1
     * @return bool 操作是否成功
     */
    public function add(int $delta = 1): bool
    {
        $this->count += max($delta, 1);

        return true;
    }

    /**
     * 减少等待计数
     *
     * 当计数减为0时，会向通道推送数据，唤醒所有等待的协程
     *
     * @return bool 操作是否成功
     */
    public function done(): bool
    {
        $this->count--;
        if ($this->count <= 0) {
            $this->channel->push(true);
        }

        return true;
    }

    /**
     * 获取当前等待计数
     *
     * @return int 当前等待的协程数量
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * 等待计数变为0
     *
     * 如果当前计数大于0，会挂起协程直到计数变为0或超时
     *
     * @param int|float $timeout 超时时间，单位秒，-1表示永不超时
     * @return bool 等待是否成功，超时返回false，成功返回true
     */
    public function wait(int|float $timeout = -1): bool
    {
       if ($this->count() > 0) {
           return $this->channel->pop($timeout);
       }
       return true;
    }

}