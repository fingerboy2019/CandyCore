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

namespace Workerman\Coroutine\Barrier;

use Swoole\Coroutine\Barrier as SwooleBarrier;
/**
 * 基于Swoole扩展的屏障实现
 * 
 * 封装了Swoole\Coroutine\Barrier，用于让多个协程在继续执行前等待彼此到达某个点
 */
class Swoole implements BarrierInterface
{

    /**
     * 等待屏障被释放
     *
     * @param object $barrier 屏障对象引用
     * @param int $timeout 超时时间，单位秒，-1表示永不超时
     * @return void
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        SwooleBarrier::wait($barrier, $timeout);
    }

    /**
     * 创建一个新的屏障实例
     *
     * @return object 屏障实例
     */
    public static function create(): object
    {
        return SwooleBarrier::make();
    }

}