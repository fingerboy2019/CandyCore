# Redis 类

## 概述

* Candy框架 Redis缓存驱动（全优化最终版 + 多维键支持 + 全驱动对齐）
 * 落地长连接、Pipeline批量、二级缓存、熔断、统一序列化全优化
 * 兼容单机/集群模式、分布式原子锁、连接池复用、异常容错，与全驱动规范完全对齐
 * 升级：支持任意层数多维键 + 批量Pipeline优化 + LRU逻辑与File/Shmop完全对齐
 *
 * @package Candy\Core\Cache
 * @final 最终类，禁止继承

## 主要方法


### /**
 * Candy框架 Redis缓存驱动（全优化最终版 + 多维键支持 + 全驱动对齐）
 * 落地长连接、Pipeline批量、二级缓存、熔断、统一序列化全优化
 * 兼容单机/集群模式、分布式原子锁、连接池复用、异常容错，与全驱动规范完全对齐
 * 升级：支持任意层数多维键 + 批量Pipeline优化 + LRU逻辑与File/Shmop完全对齐
 *
 * @package Candy\Core\Cache
 * @final 最终类，禁止继承
 */

declare(strict_types=1);

namespace Candy\Core\Cache;

use Candy\Core\Cache;
use Candy\Core\Cache\Pack\Binary;
use Throwable;

defined('CANDY') OR die('Access Denied');

final class Redis extends Cache
{
    /**
     * 空值缓存TTL（防穿透）
     */
    private const NULL_CACHE_TTL = 5;
    /**
     * 最大热点缓存数量
     */
    private const MAX_HOT_CACHE_COUNT = 200;
    /**
     * 默认自旋次数（锁优化）
     */
    private const DEFAULT_SPIN_COUNT = 50;
    /**
     * 锁前缀
     */
    private const LOCK_PREFIX = 'redis_lock_';
    /**
     * 熔断触发失败阈值
     */
    private const FUSE_FAILURE_THRESHOLD = 5;
    /**
     * 熔断恢复时间（秒）
     */
    private const FUSE_RECOVERY_TIME = 10;
    /**
     * 每字节的位数
     */
    private const BITS_PER_BYTE = 8;
    /**
     * 分片数量（与File/Shmop对齐，代码风格统一）
     */
    private const SHARD_COUNT = 16;
    
    /**
     * Redis配置参数
     */
    private readonly array $config;

    /**
     * 是否为集群模式
     */
    private readonly bool $isCluster;

    /**
     * 锁超时时间（毫秒），-1为永久阻塞
     */
    private readonly int $lockOutTime;

    /**
     * 锁默认过期时间（秒）
     */
    private readonly int $lockTtl;

    /**
     * 连接池清理间隔（秒）
     */
    private readonly int $retryInterval;

    /**
     * 连接池清理间隔（秒）
     */
    private readonly int $connectionCleanupInterval;

    /**
     * 是否开启本地二级缓存
     */
    private readonly bool $enableLocalCache;

    /**
     * 二级缓存默认TTL（秒）
     */
    private readonly int $localCacheTtl;
    
    private int $lastCleanupTime = 0;
    private array $connectionMetadata = [];
    private array $connectionPool = [];

    // 本地二级缓存（与File/Shmop对齐）
    private array $hotCache = [];
    private array $lruRecords = [];

    // 熔断器
    private int $failureCount = 0;
    private float $fuseOpenTime = 0;
    private int $halfOpenAttempts = 0;
    private bool $isFuseOpen = false;

    // 锁持有
    private array $heldLocks = [];

    /**
     * Redis缓存驱动构造函数
     * @param array $config Redis配置参数
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        // 优先初始化父类
        parent:()


### /**
     * 检查熔断器是否允许请求
     * @return bool 是否允许请求
     */
    private function isFuseAllow():()


### /**
     * 记录请求成功
     */
    private function recordSuccess():()


### /**
     * 记录请求失败
     */
    private function recordFailure():()

* 记录请求成功


### /**
     * 从本地二级缓存获取数据
     * @param string $key 原始key
     * @return mixed 值
     */
    private function getHotCache(string $key):()


### /**
     * 设置本地二级缓存
     * @param string $key 原始key
     * @param mixed $value 值
     * @param int $expireTimestamp 过期时间戳（秒），0为默认TTL
     */
    private function setHotCache(string $key, mixed $value, int $expireTimestamp):()


### /**
     * 删除本地二级缓存
     * @param string $key 原始key
     */
    private function deleteHotCache(string $key):()


### /**
     * 清空本地二级缓存
     */
    private function clearHotCache():()

* 删除本地二级缓存
     * @param string $key 原始key


### /**
     * 更新LRU记录
     * @param string $key 原始key
     * @return void
     */
    private function touchLru(string $key):()

* 清空本地二级缓存


### /**
     * 裁剪LRU记录（淘汰最久未使用的条目）
     * @return void
     */
    private function trimLru():()

* 更新LRU记录
     * @param string $key 原始key
     * @return void

