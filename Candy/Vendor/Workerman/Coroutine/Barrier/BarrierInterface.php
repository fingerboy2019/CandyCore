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

/**
 * 屏障接口，定义了所有屏障驱动必须实现的方法
 * 
 * 屏障是一种同步原语，用于让多个协程在继续执行前等待彼此到达某个点
 * 当所有协程都到达屏障点后，屏障会被释放，所有协程可以继续执行
 */
interface BarrierInterface
{
    /**
     * 等待屏障被释放
     *
     * @param object $barrier 屏障对象引用
     * @param int $timeout 超时时间，单位秒，-1表示永不超时
     * @return void
     */
    public static function wait(object &$barrier, int $timeout = -1): void;

    /**
     * 创建一个新的屏障实例
     *
     * @return object 屏障实例
     */
    public static function create(): object;
}
