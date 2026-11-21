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

use Swow\Sync\WaitReference;

/**
 * 基于Swow扩展的屏障实现
 * 
 * 使用Swow\Sync\WaitReference实现屏障功能，用于让多个协程在继续执行前等待彼此到达某个点
 */
class Swow implements BarrierInterface
{
    /**
     * 等待屏障被释放
     *
     * @param object $barrier 屏障对象引用，即Swow\Sync\WaitReference实例
     * @param int $timeout 超时时间，单位秒，-1表示永不超时
     * @return void
     */
    public static function wait(object &$barrier, int $timeout = -1): void
    {
        WaitReference::wait($barrier, $timeout);
    }

    /**
     * 创建一个新的屏障实例
     *
     * @return object 屏障实例，即Swow\Sync\WaitReference实例
     */
    public static function create(): object
    {
        return new WaitReference();
    }

}