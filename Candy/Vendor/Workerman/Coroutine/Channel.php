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

use InvalidArgumentException;
use Workerman\Coroutine\Channel\ChannelInterface;
use Workerman\Coroutine\Channel\Memory as ChannelMemory;
use Workerman\Coroutine\Channel\Swoole as ChannelSwoole;
use Workerman\Coroutine\Channel\Swow as ChannelSwow;
use Workerman\Coroutine\Channel\Fiber as ChannelFiber;
use Workerman\Events\Fiber;
use Workerman\Events\Swoole;
use Workerman\Events\Swow;
use Workerman\Worker;

/**
 * 通道类，根据不同的事件循环自动选择对应的通道驱动
 * 
 * 支持四种通道驱动：
 * 1. Swoole - 基于Swoole扩展的通道
 * 2. Swow - 基于Swow扩展的通道
 * 3. Fiber - 基于PHP 8.1+ Fiber的通道
 * 4. Memory - 基础内存通道（不支持协程等待）
 */
class Channel implements ChannelInterface
{

    /**
     * 通道驱动实例
     *
     * @var ChannelInterface 具体的通道驱动实例
     */
    protected ChannelInterface $driver;

    /**
     * 构造函数
     *
     * @param int $capacity 通道容量，默认值为1
     * @throws InvalidArgumentException 如果容量小于1
     */
    public function __construct(int $capacity = 1)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException("The capacity must be greater than 0");
        }
        $this->driver = match (Worker::$eventLoopClass) {
            Swoole::class => new ChannelSwoole($capacity),
            Swow::class => new ChannelSwow($capacity),
            Fiber::class => new ChannelFiber($capacity),
            default => new ChannelMemory($capacity),
        };
    }

    /**
     * 向通道推送数据
     *
     * @param mixed $data 要推送的数据
     * @param float $timeout 超时时间，单位秒，-1表示永不超时
     * @return bool 是否推送成功
     */
    public function push(mixed $data, float $timeout = -1): bool
    {
        return $this->driver->push($data, $timeout);
    }

    /**
     * 从通道弹出数据
     *
     * @param float $timeout 超时时间，单位秒，-1表示永不超时
     * @return mixed 弹出的数据，如果通道关闭或超时返回false
     */
    public function pop(float $timeout = -1): mixed
    {
        return $this->driver->pop($timeout);
    }

    /**
     * 获取通道中当前数据的长度
     *
     * @return int 通道中数据的数量
     */
    public function length(): int
    {
        return $this->driver->length();
    }

    /**
     * 获取通道的容量
     *
     * @return int 通道的最大容量
     */
    public function getCapacity(): int
    {
        return $this->driver->getCapacity();
    }

    /**
     * 检查是否有消费者在等待从通道弹出数据
     *
     * @return bool 是否有等待的消费者
     */
    public function hasConsumers(): bool
    {
        return $this->driver->hasConsumers();
    }

    /**
     * 检查是否有生产者在等待向通道推送数据
     *
     * @return bool 是否有等待的生产者
     */
    public function hasProducers(): bool
    {
        return $this->driver->hasProducers();
    }

    /**
     * 关闭通道
     *
     * @return void
     */
    public function close(): void
    {
        $this->driver->close();
    }
}
