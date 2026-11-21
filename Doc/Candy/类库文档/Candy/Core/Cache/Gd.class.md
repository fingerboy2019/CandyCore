# Gd 类

## 概述

* Candy框架 GlobalData缓存驱动（全优化最终版 + 多维键支持 + 全驱动对齐）
 * 支持分布式原子锁、连接池、CAS并发安全、异常容错，与全驱动规范完全对齐
 * 升级：支持任意层数多维键 key.key1.key2.key3... + LRU逻辑与全驱动完全对齐 + 批量操作优化
 * 保留：二维分层桶结构、CAS原子更新、轻量级自旋锁
 *
 * @package Candy\Core\Cache
 * @final 最终类，禁止继承

## 主要方法


### /**
 * Candy框架 GlobalData缓存驱动（全优化最终版 + 多维键支持 + 全驱动对齐）
 * 支持分布式原子锁、连接池、CAS并发安全、异常容错，与全驱动规范完全对齐
 * 升级：支持任意层数多维键 key.key1.key2.key3... + LRU逻辑与全驱动完全对齐 + 批量操作优化
 * 保留：二维分层桶结构、CAS原子更新、轻量级自旋锁
 *
 * @package Candy\Core\Cache
 * @final 最终类，禁止继承
 */

declare(strict_types=1);

namespace Candy\Core\Cache;

use Candy\Core\Cache;
use Candy\Core\Cache\Pack\Binary;
use GlobalData\Client;
use Throwable;

defined('CANDY') OR die('Access Denied');

final class Gd extends Cache
{
    /**
     * 桶前缀（二维分层根节点标识）
     */
    private const BUCKET_PREFIX = 'gd_bucket_';
    /**
     * 锁前缀
     */
    private const LOCK_PREFIX = 'gd_lock_';
    /**
     * 空值缓存TTL（防穿透）
     */
    private const NULL_CACHE_TTL = 5;
    /**
     * 最大热点缓存数量
     */
    private const MAX_HOT_CACHE_COUNT = 200;
    /**
     * 默认自旋次数（轻量级自旋锁）
     */
    private const DEFAULT_SPIN_COUNT = 100;
    /**
     * LRU淘汰触发阈值（最大key数量）
     */
    private const DEFAULT_MAX_KEY_COUNT = 10000;
    /**
     * 每字节的位数
     */
    private const BITS_PER_BYTE = 8;
    /**
     * 分片数量
     */
    private const SHARD_COUNT = 16;
    /**
     * 服务器地址
     */
    private readonly string $address;
    /**
     * 桶唯一标识（二维分层核心，按前缀隔离）
     */
    private readonly string $bucketKey;
    /**
     * 最大key数量（LRU触发阈值）
     */
    private readonly int $maxKeyCount;
    /**
     * 自旋次数（轻量级锁）
     */
    private readonly int $spinCount;
    /**
     * 锁超时时间(毫秒)，-1为永久阻塞
     */
    private readonly int $lockOutTime;
    /**
     * 锁默认过期时间（秒）
     */
    private readonly int $lockTtl;
    /**
     * 连接池清理间隔（秒）
     */
    private readonly int $connectionCleanupInterval;
    /**
     * 是否开启本地二级缓存
     */
    private readonly bool $enableLocalCache;

    /**
     * 最后一次连接池清理时间（秒）
     */
    private int $lastCleanupTime = 0;
    private array $connectionMetadata = [];
    private array $connectionPool = [];
    /**
     * 已获取锁的key列表
     */
    private array $heldLocks = [];
    /**
     * 热点缓存（LRU淘汰）
     */
    private array $hotCache = [];
    private array $lruRecords = [];

    /**
     * 构造函数（严格对齐父类规范）
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        parent:()


### /**
     * 获取分片锁名称（与全驱动对齐，代码风格统一）
     * @param string $key 原始键
     * @return string 分片锁名称
     */
    private function getShardLockName(string $key):()


### /**
     * 更新LRU记录（与全驱动对齐）
     * @param string $key 原始key
     * @return void
     */
    private function touchLru(string $key):()


### /**
     * 裁剪LRU记录（与全驱动对齐，安全阈值）
     * @return void
     */
    private function trimLru():()

* 更新LRU记录（与全驱动对齐）
     * @param string $key 原始key
     * @return void


### /**
     * TTL格式化：符合通用缓存规范
     * @param int $ttl 过期时间（秒），0表示不过期
     * @return int 格式化后的过期时间戳（秒）
     */
    private function formatTtl(int $ttl):()


### /**
     * 本地二级缓存：获取热点数据
     * @param string $key 原始key
     * @return mixed 值
     */
    private function getHotCache(string $key):()


### /**
     * 本地二级缓存：设置热点数据
     * @param string $key 原始key
     * @param mixed $value 值
     * @param int $expireTimestamp 过期时间戳（秒）
     * @return void
     */
    private function setHotCache(string $key, mixed $value, int $expireTimestamp):()


### /**
     * 本地二级缓存：删除热点数据
     * @param string $key 原始key
     * @return void
     */
    private function deleteHotCache(string $key):()


### /**
     * 本地二级缓存：清空热点数据
     * @return void
     */
    private function clearHotCache():()


### /**
     * 设置缓存值（支持任意层数多维键）
     * @param string $key 缓存键（支持多维：user.profile.name）
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间（秒），0表示不过期
     * @return bool 设置是否成功
     */
    public function __rawSet(string $key, mixed $value, int $ttl = 0):()

* 本地二级缓存：清空热点数据
     * @return void

