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

use RuntimeException;

/**
 * 协程锁实现
 * 
 * 用于在协程环境中实现互斥锁功能，通过通道(Channel)实现
 * 支持多个不同key的锁，每个key对应一个独立的通道
 */
class Locker
{
    /**
     * 存储不同key对应的通道
     *
     * @var Channel[] 以key为索引，存储对应的Channel实例，每个Channel的容量为1
     */
    protected static array $channels = [];

    /**
     * 加锁
     *
     * @param string $key 锁的唯一标识，不同的key对应不同的锁
     * @return bool 加锁是否成功
     */
    public static function lock(string $key): bool
    {
        // 如果key对应的通道不存在，则创建一个容量为1的通道
        if (!isset(static::$channels[$key])) {
            static::$channels[$key] = new Channel(1);
        }
        // 向通道推送数据，由于通道容量为1，只有当通道为空时才能推送成功
        return static::$channels[$key]->push(true);
    }

    /**
     * 解锁
     *
     * @param string $key 锁的唯一标识
     * @return bool 解锁是否成功
     * @throws RuntimeException 如果key对应的锁不存在
     */
    public static function unlock(string $key): bool
    {
        // 获取key对应的通道
        if ($channel = static::$channels[$key] ?? null) {
            // 必须在pop之前检查是否有生产者，因为Swow的pop会唤醒生产者，导致判断不准确
            $hasProducers = $channel->hasProducers();
            // 从通道中弹出数据，释放锁
            $result = $channel->pop();
            // 如果没有等待的生产者，则关闭通道并移除key
            if (!$hasProducers) {
                $channel->close();
                unset(static::$channels[$key]);
            }
            return $result;
        }
        // 如果key对应的锁不存在，抛出异常
        throw new RuntimeException("Unlock failed, because the key $key is not locked");
    }

}