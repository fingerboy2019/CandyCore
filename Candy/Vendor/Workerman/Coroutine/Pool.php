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

use Closure;
use Candy\Core\Log;
use RuntimeException;
use stdClass;
use Throwable;
use WeakMap;
use Workerman\Coroutine;
use Workerman\Coroutine\Utils\DestructionWatcher;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 协程连接池实现
 * 
 * 用于管理和复用连接资源，提高连接的使用效率
 * 支持连接自动创建、销毁、心跳检测和空闲超时回收
 * 同时支持协程和非协程环境
 */
class Pool implements PoolInterface
{
    /**
     * 用于存储空闲连接的通道
     *
     * @var Channel 容量为maxConnections的通道，用于存储可复用的空闲连接
     */
    protected Channel $channel;

    /**
     * 最小连接数
     *
     * @var int 池中保持的最小连接数量，空闲连接不会低于此值
     */
    protected int $minConnections = 1;

    /**
     * 存储所有连接的WeakMap
     *
     * @var WeakMap<object, int> 使用WeakMap存储连接，便于垃圾回收
     */
    protected WeakMap $connections;

    /**
     * 非协程环境下的连接
     *
     * @var ?object 在非协程环境中使用的单例连接
     */
    protected ?object $nonCoroutineConnection = null;

    /**
     * 存储连接最后使用时间的WeakMap
     *
     * @var WeakMap<object, int> 记录每个连接最后被使用的时间戳
     */
    protected WeakMap $lastUsedTimes;

    /**
     * 存储连接最后心跳时间的WeakMap
     *
     * @var WeakMap<object, int> 记录每个连接最后发送心跳的时间戳
     */
    protected WeakMap $lastHeartbeatTimes;

    /**
     * 连接创建回调
     *
     * @var Closure|null 用于创建新连接的回调函数
     */
    protected ?Closure $connectionCreateHandler = null;

    /**
     * 连接销毁回调
     *
     * @var Closure|null 用于销毁连接的回调函数
     */
    protected ?Closure $connectionDestroyHandler = null;

    /**
     * 连接心跳回调
     *
     * @var Closure|null 用于发送心跳的回调函数
     */
    protected ?Closure $connectionHeartbeatHandler = null;

    /**
     * 连接空闲超时时间
     *
     * @var float 连接空闲多长时间后被回收，单位秒
     */
    protected float $idleTimeout = 60;

    /**
     * 心跳间隔时间
     *
     * @var float 发送心跳的间隔时间，单位秒
     */
    protected float $heartbeatInterval = 50;

    /**
     * 获取连接等待超时时间
     *
     * @var float 从池中获取连接的等待超时时间，单位秒
     */
    protected float $waitTimeout = 10;

    /**
     * 日志记录器
     *
     * @var Log|Closure|null 用于记录日志的实例或回调
     */
    protected Log|Closure|null $logger = null;

    /**
     * 可配置属性列表
     *
     * @var array|string[] 允许通过构造函数配置的属性名列表
     */
    private array $configurableProperties = [
        'minConnections',
        'idleTimeout',
        'heartbeatInterval',
        'waitTimeout',
    ];

    /**
     * 构造函数
     *
     * @param int $maxConnections 最大连接数，默认为1
     * @param array $config 配置数组，支持配置minConnections、idleTimeout、heartbeatInterval、waitTimeout
     */
    public function __construct(protected int $maxConnections = 1, protected array $config = [])
    {
        // 处理配置项，支持下划线命名和驼峰命名
        foreach ($config as $key => $value) {
            $camelCaseKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            if (in_array($camelCaseKey, $this->configurableProperties, true)) {
                $this->$camelCaseKey = $value;
            }
        }

        // 初始化通道和WeakMap
        $this->channel = new Channel($maxConnections);
        $this->lastUsedTimes = new WeakMap();
        $this->lastHeartbeatTimes = new WeakMap();
        $this->connections = new WeakMap();

        // 在Worker运行时启动连接检查定时器
        if (Worker::isRunning()) {
            Timer::repeat(1, function () {
                $this->checkConnections();
            });
        }
    }

    /**
     * 设置连接创建回调
     *
     * @param callable $connectionCreateHandler 用于创建新连接的回调函数
     * @return $this 返回当前实例，支持链式调用
     */
    public function setConnectionCreator(callable $connectionCreateHandler): self
    {
        $this->connectionCreateHandler = $connectionCreateHandler;
        return $this;
    }

    /**
     * 设置连接销毁回调
     *
     * @param callable $connectionDestroyHandler 用于销毁连接的回调函数
     * @return $this 返回当前实例，支持链式调用
     */
    public function setConnectionCloser(callable $connectionDestroyHandler): self
    {
        $this->connectionDestroyHandler = $connectionDestroyHandler;
        return $this;
    }

    /**
     * 设置心跳检查回调
     *
     * @param callable $connectionHeartbeatHandler 用于发送心跳的回调函数
     * @return $this 返回当前实例，支持链式调用
     */
    public function setHeartbeatChecker(callable $connectionHeartbeatHandler): self
    {
        $this->connectionHeartbeatHandler = $connectionHeartbeatHandler;
        return $this;
    }

    /**
     * 从池中获取一个连接
     *
     * @return object 连接对象
     * @throws RuntimeException 如果获取连接超时或池耗尽
     * @throws Throwable 其他可能的异常
     */
    public function get(): object
    {
        // 非协程环境下使用单例连接
        if (!Coroutine::isCoroutine()) {
            if (!$this->nonCoroutineConnection) {
                $this->nonCoroutineConnection = $this->createConnection();
            }
            return $this->nonCoroutineConnection;
        }
        
        $num = $this->channel->length();
        // 如果池中没有空闲连接且未达到最大连接数，直接创建新连接
        if ($num === 0 && $this->getConnectionCount() < $this->maxConnections) {
            return $this->createConnection();
        }
        
        // 从通道中获取连接，如果超时则抛出异常
        $connection = $this->channel->pop($this->waitTimeout);
        if (!$connection) {
            throw new RuntimeException("Failed to get a connection from the pool within the wait timeout ($this->waitTimeout seconds). The connection pool is exhausted.");
        }
        
        // 更新连接最后使用时间
        $this->lastUsedTimes[$connection] = time();
        return $connection;
    }

    /**
     * 将连接放回池中
     *
     * @param object $connection 要放回池中的连接对象
     * @return void
     * @throws RuntimeException 如果连接不属于当前池
     * @throws Throwable 其他可能的异常
     */
    public function put(object $connection): void
    {
        // 检查连接是否属于当前池
        if (!isset($this->connections[$connection])) {
            throw new RuntimeException('The connection does not belong to the connection pool.');
        }
        
        // 非协程环境下的连接不需要放回池
        if ($connection === $this->nonCoroutineConnection) {
            return;
        }
        
        try {
            // 将连接放回通道
            $this->channel->push($connection);
        } catch (Throwable $throwable) {
            // 放回失败时关闭连接
            $this->closeConnection($connection);
            throw $throwable;
        }
    }

    /**
     * 检查连接是否有效
     *
     * @param mixed $connection 要检查的连接
     * @return bool 如果连接有效返回true，否则返回false
     */
    protected function isValidConnection($connection): bool
    {
        return is_object($connection);
    }

    /**
     * 创建一个新连接
     *
     * @return object 新创建的连接对象
     * @throws RuntimeException 如果达到最大连接数或创建失败
     * @throws Throwable 其他可能的异常
     */
    public function createConnection(): object
    {
        // 检查是否达到最大连接数
        if ($this->getConnectionCount() >= $this->maxConnections) {
            throw new RuntimeException('CreateConnection failed, maximum connection limit reached.');
        }
        
        // 创建占位符，确保getConnectionCount()返回正确值（防止协程切换导致的并发问题）
        $placeholder = new stdClass;
        $this->connections[$placeholder] = 0;
        
        try {
            // 调用连接创建回调
            $connection = ($this->connectionCreateHandler)();
            
            // 检查连接是否有效
            if (!$this->isValidConnection($connection)) {
                throw new RuntimeException('CreateConnection failed, expected a connection object, but got ' . gettype($connection) . '.');
            }
            
            // 移除占位符，添加真实连接
            unset($this->connections[$placeholder]);
            $this->connections[$connection] = $this->lastUsedTimes[$connection] = $this->lastHeartbeatTimes[$connection] = time();
        } catch (Throwable $throwable) {
            // 发生异常时移除占位符
            unset($this->connections[$placeholder]);
            throw $throwable;
        }
        
        return $connection;
    }

    /**
     * 关闭连接并从池中移除
     *
     * @param object $connection 要关闭的连接对象
     * @return void
     */
    public function closeConnection(object $connection): void
    {
        // 检查连接是否属于当前池
        if (!isset($this->connections[$connection])) {
            return;
        }
        
        // 从所有映射中移除连接
        unset($this->lastUsedTimes[$connection], $this->lastHeartbeatTimes[$connection], $this->connections[$connection]);
        
        // 如果是非协程连接，清空引用
        if ($this->nonCoroutineConnection === $connection) {
            $this->nonCoroutineConnection = null;
        }
        
        // 调用连接销毁回调
        if (!$this->connectionDestroyHandler) {
            return;
        }
        
        try {
            ($this->connectionDestroyHandler)($connection);
        } catch (Throwable $throwable) {
            $this->log($throwable);
        }
    }

    /**
     * 检查连接状态，清理空闲连接
     *
     * @return void
     */
    protected function checkConnections(): void
    {
        $num = $this->channel->length();
        $time = time();
        
        // 遍历所有空闲连接
        for($i = $num; $i > 0; $i--) {
            $connection = $this->channel->pop(0.001);
            if (!$connection) {
                return;
            }
            
            $lastUsedTime = $this->lastUsedTimes[$connection];
            // 如果连接空闲时间超过超时时间且池中连接数超过最小值，关闭连接
            if ($time - $lastUsedTime > $this->idleTimeout && $this->channel->length() >= $this->minConnections) {
                $this->closeConnection($connection);
                continue;
            }
            
            // 尝试发送心跳，成功则将连接放回池
            $this->trySendHeartbeat($connection) && $this->channel->push($connection);
        }
        
        // 为非协程连接发送心跳
        if ($this->nonCoroutineConnection) {
            $this->trySendHeartbeat($this->nonCoroutineConnection);
        }
    }

    /**
     * 尝试发送心跳
     *
     * @param object $connection 要发送心跳的连接
     * @return bool 如果发送成功或不需要发送返回true，否则返回false
     */
    private function trySendHeartbeat($connection): bool
    {
        $lastHeartbeatTime = $this->lastHeartbeatTimes[$connection] ?? 0;
        $time = time();
        
        // 检查是否需要发送心跳
        if ($this->connectionHeartbeatHandler && $time - $lastHeartbeatTime >= $this->heartbeatInterval) {
            try {
                // 调用心跳回调
                ($this->connectionHeartbeatHandler)($connection);
                $this->lastHeartbeatTimes[$connection] = $time;
            } catch (Throwable $throwable) {
                // 发送心跳失败时记录日志并关闭连接
                $this->log($throwable);
                $this->closeConnection($connection);
                return false;
            }
        }
        return true;
    }

    /**
     * 获取池中连接的数量
     *
     * @return int 池中连接的数量
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * 关闭池中所有连接
     *
     * @return void
     */
    public function closeConnections(): void
    {
        $num = $this->channel->length();
        
        // 关闭所有空闲连接
        for ($i = $num; $i > 0; $i--) {
            $connection = $this->channel->pop(0.001);
            if (!$connection) {
                return;
            }
            $this->closeConnection($connection);
        }
        
        // 关闭非协程连接
        $this->nonCoroutineConnection && $this->closeConnection($this->nonCoroutineConnection);
    }

    /**
     * 记录日志
     *
     * @param mixed $message 要记录的消息
     * @return void
     */
    protected function log($message): void
    {
        if (!$this->logger) {
            // 没有日志记录器时直接输出到控制台
            echo $message . PHP_EOL;
            return;
        }
        
        if ($this->logger instanceof Closure) {
            // 日志记录器是回调时调用回调
            ($this->logger)($message);
            return;
        }
        
        // 日志记录器是Log实例时调用input方法
        $this->logger->input((string)$message, 1);
    }

}
