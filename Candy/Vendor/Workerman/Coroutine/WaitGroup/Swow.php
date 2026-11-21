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

use Swow\Sync\WaitGroup;
use Throwable;

/**
 * 基于Swow扩展的WaitGroup实现
 * 
 * 封装了Swow\Sync\WaitGroup，用于等待多个协程完成
 * 自己维护了计数，以便更精确地控制
 */
class Swow implements WaitGroupInterface
{

    /**
     * Swow WaitGroup实例
     *
     * @var WaitGroup Swow原生WaitGroup实例
     */
    protected WaitGroup $waitGroup;

    /**
     * 等待计数
     *
     * @var int 当前等待的协程数量
     */
    protected int $count;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->waitGroup = new WaitGroup();
        $this->count = 0;
    }

    /**
     * 增加等待计数
     *
     * @param int $delta 增加的计数，默认值为1，最小值为1
     * @return bool 操作是否成功
     */
    public function add(int $delta = 1): bool
    {
        $this->waitGroup->add($delta = max($delta, 1));
        $this->count += $delta;

        return true;
    }

    /**
     * 减少等待计数
     *
     * 当计数大于0时，减少计数并调用Swow WaitGroup的done方法
     *
     * @return bool 操作是否成功
     */
    public function done(): bool
    {
        if ($this->count() > 0) {
            $this->count--;
            $this->waitGroup->done();
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
     * @param int|float $timeout 超时时间，单位秒，-1表示永不超时
     * @return bool 等待是否成功，超时返回false，成功返回true
     */
    public function wait(int|float $timeout = -1): bool
    {
        try {
            $this->waitGroup->wait($timeout > 0 ? (int) ($timeout * 1000) : $timeout);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
