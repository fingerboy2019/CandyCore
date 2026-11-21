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

use Throwable;
use Workerman\Coroutine;

/**
 * 协程并行执行类
 * 
 * 用于同时执行多个协程任务，并等待它们全部完成
 * 支持设置最大并发数，防止过多协程导致系统资源耗尽
 */
class Parallel
{
    /**
     * 用于控制并发数的通道
     *
     * @var Channel|null 容量为concurrent的通道，用于控制同时执行的协程数量
     */
    protected ?Channel $channel = null;

    /**
     * 存储待执行的回调函数
     *
     * @var array 以key为索引，存储要并行执行的回调函数
     */
    protected array $callbacks = [];

    /**
     * 存储执行结果
     *
     * @var array 以key为索引，存储每个回调函数的执行结果
     */
    protected array $results = [];

    /**
     * 存储执行过程中的异常
     *
     * @var array 以key为索引，存储每个回调函数执行过程中抛出的异常
     */
    protected array $exceptions = [];

    /**
     * 构造函数
     *
     * @param int $concurrent 最大并发数，-1表示不限制并发数
     */
    public function __construct(int $concurrent = -1)
    {
        // 如果设置了最大并发数，则创建一个对应容量的通道
        if ($concurrent > 0) {
            $this->channel = new Channel($concurrent);
        }
    }

    /**
     * 添加一个要并行执行的协程任务
     *
     * @param callable $callable 要执行的回调函数
     * @param string|null $key 任务的唯一标识，用于区分不同任务的结果，null表示使用数字索引
     * @return void
     */
    public function add(callable $callable, ?string $key = null): void
    {
        if ($key === null) {
            // 如果没有提供key，则使用数字索引
            $this->callbacks[] = $callable;
        } else {
            // 如果提供了key，则使用指定的key
            $this->callbacks[$key] = $callable;
        }
    }

    /**
     * 等待所有协程任务完成并返回结果
     *
     * @return array 所有任务的执行结果，以key为索引
     */
    public function wait(): array
    {
        // 创建一个屏障，用于等待所有协程完成
        $barrier = Barrier::create();
        
        // 遍历所有回调函数，创建协程执行
        foreach ($this->callbacks as $key => $callback) {
            // 如果设置了并发限制，向通道推送数据，限制并发数
            $this->channel?->push(true);
            
            // 创建协程执行回调函数
            Coroutine::create(function () use ($callback, $key, $barrier) {
                try {
                    // 执行回调函数并保存结果
                    $this->results[$key] = $callback();
                } catch (Throwable $throwable) {
                    // 捕获异常并保存
                    $this->exceptions[$key] = $throwable;
                } finally {
                    // 如果设置了并发限制，从通道中弹出数据，释放一个并发槽位
                    $this->channel?->pop();
                }
            });
        }
        
        // 等待所有协程完成
        Barrier::wait($barrier);
        
        // 返回所有任务的执行结果
        return $this->results;
    }

    /**
     * 获取执行过程中的异常
     *
     * @return array 所有任务执行过程中抛出的异常，以key为索引
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

}
