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
 * 内存通道实现
 * 
 * 这是一个基础的内存通道，实现了简单的FIFO队列功能
 * 当通道满时push会立即返回false，当通道空时pop会立即返回false
 * 不支持协程等待机制
 */
class Memory implements ChannelInterface
{
    /**
     * 通道数据存储数组
     *
     * @var array 存储通道中的数据，使用FIFO队列方式
     */
    private array $data = [];
    
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
     * @param int $capacity 通道容量，0表示无限容量
     */
    public function __construct(int $capacity = 0)
    {
        $this->capacity = $capacity;
    }

    /**
     * 向通道推送数据
     *
     * @param mixed $data 要推送的数据
     * @param float $timeout 超时时间，在此实现中忽略
     * @return bool 是否推送成功，通道满或关闭时返回false
     */
    public function push(mixed $data, float $timeout = -1): bool
    {
        if ($this->closed) {
            return false;
        }
        if ($this->capacity > 0 && count($this->data) >= $this->capacity) {
            // 通道已满
            return false;
        }
        $this->data[] = $data;
        return true;
    }

    /**
     * 从通道弹出数据
     *
     * @param float $timeout 超时时间，在此实现中忽略
     * @return mixed 弹出的数据，通道空或关闭时返回false
     */
    public function pop(float $timeout = -1): mixed
    {
        if (count($this->data) > 0) {
            return array_shift($this->data);
        }
        return false;
    }

    /**
     * 获取通道中当前数据的长度
     *
     * @return int 通道中数据的数量
     */
    public function length(): int
    {
        return count($this->data);
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
     * 在此实现中，总是返回false，因为不支持协程等待
     *
     * @return bool 是否有等待的消费者
     */
    public function hasConsumers(): bool
    {
        return false;
    }

    /**
     * 检查是否有生产者在等待向通道推送数据
     *
     * 在此实现中，总是返回false，因为不支持协程等待
     *
     * @return bool 是否有等待的生产者
     */
    public function hasProducers(): bool
    {
        return false;
    }

    /**
     * 关闭通道
     *
     * @return void
     */
    public function close(): void
    {
        $this->closed = true;
    }
}
