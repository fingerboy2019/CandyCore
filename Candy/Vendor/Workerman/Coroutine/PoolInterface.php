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

/**
 * 协程池接口，定义了协程连接池必须实现的方法
 * 
 * 协程池用于管理和复用连接资源，提高连接的使用效率
 * 主要用于数据库连接池、Redis连接池等场景
 */
interface PoolInterface
{

    /**
     * 从池中获取一个连接
     *
     * @return mixed 连接对象，如果池为空可能会创建新连接或等待
     */
    public function get(): mixed;

    /**
     * 将连接放回池中
     *
     * @param object $connection 要放回池中的连接对象
     * @return void
     */
    public function put(object $connection): void;

    /**
     * 创建一个新连接
     *
     * @return object 新创建的连接对象
     */
    public function createConnection(): object;

    /**
     * 关闭连接并从池中移除
     *
     * @param object $connection 要关闭的连接对象
     * @return void
     */
    public function closeConnection(object $connection): void;

    /**
     * 获取池中连接的数量
     *
     * @return int 池中连接的数量
     */
    public function getConnectionCount(): int;

    /**
     * 关闭池中所有连接
     *
     * @return void
     */
    public function closeConnections(): void;

}
