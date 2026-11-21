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

/**
 * WaitGroup接口，定义了协程同步等待组的基本操作
 * 
 * WaitGroup用于等待一组协程操作完成，通过计数器实现
 * 当计数器为0时，等待的协程会被唤醒
 */
interface WaitGroupInterface
{

    /**
     * 增加等待计数
     *
     * @param int $delta 增加的计数，默认值为1
     * @return bool 操作是否成功
     */
    public function add(int $delta = 1): bool;

    /**
     * 减少等待计数
     *
     * 当计数减为0时，会唤醒所有等待的协程
     *
     * @return bool 操作是否成功
     */
    public function done(): bool;

    /**
     * 获取当前等待计数
     *
     * @return int 当前计数
     */
    public function count(): int;

    /**
     * 等待计数变为0
     *
     * @param int|float $timeout 超时时间，单位秒，-1表示永不超时
     * @return bool 等待是否成功，超时返回false，成功返回true
     */
    public function wait(int|float $timeout = -1): bool;
}
