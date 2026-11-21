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

/**
 * 通道接口，定义了所有通道驱动必须实现的方法
 * 
 * 通道是协程之间通信的重要机制，用于协程间的数据传递和同步
 */
interface ChannelInterface
{
    /**
     * 向通道推送数据
     *
     * @param mixed $data 要推送的数据
     * @param float $timeout 超时时间，单位秒，-1表示永不超时
     * @return bool 是否推送成功
     */
    public function push(mixed $data, float $timeout = -1): bool;

    /**
     * 从通道弹出数据
     *
     * @param float $timeout 超时时间，单位秒，-1表示永不超时
     * @return mixed 弹出的数据，如果通道关闭或超时返回false
     */
    public function pop(float $timeout = -1): mixed;

    /**
     * 获取通道中当前数据的长度
     *
     * @return int 通道中数据的数量
     */
    public function length(): int;

    /**
     * 获取通道的容量
     *
     * @return int 通道的最大容量
     */
    public function getCapacity(): int;

    /**
     * 检查是否有消费者在等待从通道弹出数据
     *
     * @return bool 是否有等待的消费者
     */
    public function hasConsumers(): bool;

    /**
     * 检查是否有生产者在等待向通道推送数据
     *
     * @return bool 是否有等待的生产者
     */
    public function hasProducers(): bool;

    /**
     * 关闭通道
     *
     * 关闭后通道不再接受新的数据推送，已有的数据可以继续弹出
     *
     * @return void
     */
    public function close(): void;

}
