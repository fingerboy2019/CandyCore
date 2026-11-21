<?php
/**
 * @author workbunny/Chaz6chez
 * @email chaz6chez1993@outlook.com
 */
declare(strict_types=1);

namespace Workerman\Coroutine\WaitGroup;

use Swoole\Coroutine\WaitGroup;
use Throwable;

/**
 * 基于Swoole扩展的WaitGroup实现
 * 
 * 封装了Swoole\Coroutine\WaitGroup，用于等待多个协程完成
 */
class Swoole implements WaitGroupInterface
{

    /**
     * Swoole WaitGroup实例
     *
     * @var WaitGroup Swoole原生WaitGroup实例
     */
    protected WaitGroup $waitGroup;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->waitGroup = new WaitGroup();
    }

    /**
     * 增加等待计数
     *
     * @param int $delta 增加的计数，默认值为1，最小值为1
     * @return bool 操作是否成功
     */
    public function add(int $delta = 1): bool
    {
        $this->waitGroup->add(max($delta, 1));

        return true;
    }

    /**
     * 减少等待计数
     *
     * 当计数大于0时，调用Swoole WaitGroup的done方法
     *
     * @return bool 操作是否成功
     */
    public function done(): bool
    {
        if ($this->count() > 0) {
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
        return $this->waitGroup->count();
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
            $this->waitGroup->wait(max($timeout, $timeout > 0 ? 0.001 : -1));
            return true;
        } catch (Throwable) {
            return false;
        }
    }

}
