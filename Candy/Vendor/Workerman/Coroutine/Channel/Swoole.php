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

use Swoole\Coroutine\Channel;

/**
 * 基于Swoole扩展的通道实现
 * 
 * 该类是Workerman协程系统的Swoole通道驱动，封装了Swoole\Coroutine\Channel
 */
class Swoole implements ChannelInterface
{

    /**
     * Swoole通道实例
     *
     * @var Channel Swoole原生通道实例
     */
    protected Channel $channel;


    /**
     * 构造函数
     *
     * @param int $capacity 通道容量，默认值为1
     */
    public function __construct(protected int $capacity = 1)
    {
        $this->channel = new Channel($capacity);
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
        return $this->channel->push($data, $timeout);
    }

    /**
     * 从通道弹出数据
     *
     * @param float $timeout 超时时间，单位秒，-1表示永不超时
     * @return mixed 弹出的数据，如果通道关闭或超时返回false
     */
    public function pop(float $timeout = -1): mixed
    {
        return $this->channel->pop($timeout);
    }

    /**
     * 获取通道中当前数据的长度
     *
     * @return int 通道中数据的数量
     */
    public function length(): int
    {
        return $this->channel->length();
    }

    /**
     * 获取通道的容量
     *
     * @return int 通道的最大容量
     */
    public function getCapacity(): int
    {
        return $this->channel->capacity;
    }

    /**
     * 检查是否有消费者在等待从通道弹出数据
     *
     * @return bool 是否有等待的消费者
     */
    public function hasConsumers(): bool
    {
        return $this->channel->stats()['consumer_num'] > 0;
    }

    /**
     * 检查是否有生产者在等待向通道推送数据
     *
     * @return bool 是否有等待的生产者
     */
    public function hasProducers(): bool
    {
        return $this->channel->stats()['producer_num'] > 0;
    }

    /**
     * 关闭通道
     *
     * @return void
     */
    public function close(): void
    {
        $this->channel->close();
    }

}
