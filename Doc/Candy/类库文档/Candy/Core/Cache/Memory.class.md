# Memory 类

## 概述

* Candy框架 Memory内存缓存驱动（修复最终版）
 * 修复：所有致命BUG、多维键支持、向量索引持久化、锁机制、内存溢出防护
 * 特性：LRU淘汰、读写分离锁、原子落盘、向量前缀线性扫描、Stream消息队列、多维键
 *
 * @package Candy\Core\Cache
 * @final 最终类，禁止继承
 * 
 * @author Candy Framework Team
 * @version 2.0.0
 * 
 * @method bool set(string $key, mixed $value, int $ttl = 0) 设置缓存
 * @method mixed get(string $key, mixed $default = null) 获取缓存
 * @method bool delete(string $key) 删除缓存
 * @method bool has(string $key) 判断缓存是否存在
 * @method int ttl(string $key) 获取缓存剩余过期时间
 * @method bool mSet(

## 主要方法


### /**
 * Candy框架 Memory内存缓存驱动（修复最终版）
 * 修复：所有致命BUG、多维键支持、向量索引持久化、锁机制、内存溢出防护
 * 特性：LRU淘汰、读写分离锁、原子落盘、向量前缀线性扫描、Stream消息队列、多维键
 *
 * @package Candy\Core\Cache
 * @final 最终类，禁止继承
 * 
 * @author Candy Framework Team
 * @version 2.0.0
 * 
 * @method bool set(string $key, mixed $value, int $ttl = 0) 设置缓存
 * @method mixed get(string $key, mixed $default = null) 获取缓存
 * @method bool delete(string $key) 删除缓存
 * @method bool has(string $key) 判断缓存是否存在
 * @method int ttl(string $key) 获取缓存剩余过期时间
 * @method bool mSet(array $items, int $ttl = 0) 批量设置缓存
 * @method array mGet(array $keys, mixed $default = null) 批量获取缓存
 * @method bool mDelete(array $keys) 批量删除缓存
 * @method int incr(string $key, int $step = 1, int $type = 0) 自增/自减
 * @method int push(string $key, array $args, bool $recover = false, int $head = 0) 队列推入
 * @method mixed pop(string $key, int $head = 0) 队列弹出
 * @method array|false range(string $key, int $offset, int $length = 0, string $sort = '', string $order = 'asc') 范围查询
 * @method bool|int|array bit(string $key, int|array $id, ?bool $status = null) 位图操作
 * @method bool clear() 清空缓存
 * @method bool vectorSet(string $key, array $vector, int $ttl = 0) 设置向量
 * @method array|null vectorGet(string $key) 获取向量
 * @method bool vectorAdd(string $key, array $vector) 向量相加
 * @method bool vectorSub(string $key, array $vector) 向量相减
 * @method float|null vectorDot(string $key, array $vector) 向量点积
 * @method bool vectorScale(string $key, float $scale) 向量缩放
 * @method bool vectorDelete(string $key) 删除向量
 * @method array vectorSearch(array $vector, int $limit = 10) 向量相似性搜索
 * @method bool lock(string $name, ?int $timeout = null) 获取锁
 * @method bool unlock(string $name) 释放锁
 */
declare(strict_types=1);

namespace Candy\Core\Cache;

use Candy\Core\Cache;
use Candy\Core\Cache\Pack\Binary;
use Throwable;

defined('CANDY') OR die('Access Denied');

final class Memory extends Cache
{
    /**
     * 基础配置常量
     * 
     * @var int LRU触发阈值，当缓存键数量超过此值时触发LRU淘汰
     */
    private const DEFAULT_MAX_KEY_COUNT = 10000;
    
    /**
     * 过期清理间隔
     * 
     * @var int 清理过期键的时间间隔（秒）
     */
    private const DEFAULT_CLEANUP_INTERVAL = 5;
    
    /**
     * 落盘间隔
     * 
     * @var int 自动持久化到磁盘的时间间隔（秒）
     */
    private const DEFAULT_DUMP_INTERVAL = 5;
    
    /**
     * 落盘锁超时时间
     * 
     * @var int 落盘操作获取写锁的超时时间（秒）
     */
    private const DUMP_LOCK_TIMEOUT = 10;
    
    /**
     * 每字节位数
     * 
     * @var int 字节与位的转换常量
     */
    private const BITS_PER_BYTE = 8;
    
    /**
     * 分片数量
     * 
     * @var int 用于分片锁的分片数量，提高并发性能
     */
    private const SHARD_COUNT = 16;
    
    /**
     * 防冲突指纹长度
     *
     * @var int 内部键指纹部分的字节长度
     */
    private const FINGERPRINT_LENGTH = 6;

    /**
     * 向量搜索缓存最大数量
     *
     * @var int 向量搜索结果缓存的上限，防止内存溢出
     */
    private const MAX_VECTOR_SEARCH_CACHE = 1000;
    
    /**
     * 落盘魔数
     * 
     * @var int 用于验证落盘文件完整性的魔法数字（0x4D454D4F = "MEMO"）
     */
    private const DUMP_MAGIC = 0x4D454D4F;

    /**
     * 锁前缀
     * 
     * @var string 内部锁键的前缀标识
     */
    private const LOCK_PREFIX = 'mem_lock_';

    /**
     * 系统内置KEY常量
     * 这些键用于系统内部管理，禁止删除和LRU淘汰
     * 
     * @var string 最后落盘时间戳键名
     */
    private const LAST_DUMP_KEY = '__memory_last_dump_time';
    
    /**
     * 向量索引键名
     * 
     * @var string 向量索引集合的系统键名
     */
    private const VECTOR_INDEX_KEY = '__memory_vector_index';
    
    /**
     * HNSW索引键名
     * 
     * @var string HNSW向量索引的系统键名
     */
    private const HNSW_INDEX_KEY = '__memory_hnsw_index';

    /**
     * 向量/HNSW常量
     * 
     * @var int 向量的最大维度限制
     */
    private const VECTOR_MAX_DIMENSION = 2048;
    
    /**
     * HNSW最大层高
     * 
     * @var int HNSW索引结构的最大层数
     */
    private const HNSW_MAX_LEVEL = 8;
    
    /**
     * HNSW每个节点的最大连接数
     * 
     * @var int HNSW算法中每个节点的最大出边数量
     */
    private const HNSW_M = 16;
    
    /**
     * HNSW构建时的搜索宽度
     * 
     * @var int HNSW插入时的搜索节点数量
     */
    private const HNSW_EF_CONSTRUCTION = 100;

    /**
     * 系统内置KEY白名单
     * 禁止删除和LRU淘汰的系统键列表
     * 
     * @var array 系统键名数组
     */
    private const SYSTEM_INTERNAL_KEYS = [
        self::LAST_DUMP_KEY,
        self::VECTOR_INDEX_KEY,
        self::HNSW_INDEX_KEY
    ];
    
    /**
     * 主数据存储区
     * 
     * @var array 内部存储结构：[internalKey => ['value' => mixed, 'expire' => int, 'last_use' => int, 'raw_key' => string]]
     */
    private array $data = [];
    
    /**
     * 原始key=>内部key 映射（防冲突）
     * 
     * @var array 键映射表：[rawKey => internalKey]
     */
    private array $keyMap = [];
    
    /**
     * 按前缀隔离的LRU记录 [prefix => [internalKey => timestamp]]
     * 前缀规则：**_**_ (如 alice_orders_)
     */
    private array $lruRecords = [];

    /**
     * 按前缀隔离的LRU双向链表头 [prefix => node]
     */
    private array $lruHeads = [];

    /**
     * 按前缀隔离的LRU双向链表尾 [prefix => node]
     */
    private array $lruTails = [];

    /**
     * 按前缀隔离的LRU节点哈希 [prefix => [internalKey => node]]
     */
    private array $lruNodes = [];
    
    /**
     * 按前缀隔离的脏数据键列表 [prefix => [key => true]]
     * 记录被修改过的键，用于增量落盘
     */
    private array $dirtyKeys = [];
    
    /**
     * 向量搜索结果缓存
     * 缓存向量搜索结果，提高频繁搜索相同向量时的性能
     * 
     * @var array 向量搜索结果缓存
     */
    private array $vectorSearchCache = [];
    
    /**
     * 持有的锁列表
     * 
     * @var array 持有锁的列表：[lockName => lockValue]
     */
    private array $heldLocks = [];
    
    /**
     * 向量索引集合
     * 
     * @var array 向量键名列表
     */
    private array $vectorIndex = [];
    
    /**
     * 读锁计数器
     * 
     * @var int 当前持有的读锁数量
     */
    /**
     * 读锁计数（按分片管理）
     * 
     * @var array 每个分片的读锁计数
     */
    private array $readLockCounts = [];
    
    /**
     * 写锁等待标志（按分片管理）
     * 
     * @var array 每个分片是否有写锁等待者
     */
    private array $writeWaitings = [];
    
    /**
     * 写锁状态（按分片管理）
     * 
     * @var array 每个分片是否持有写锁
     */
    private array $isWriteLockeds = [];
    
    /**
     * 写锁饥饿保护计数器（按分片管理）
     * 
     * @var array 每个分片的写锁饥饿计数
     */
    private array $writeStarvationCounters = [];
    
    /**
     * 全局写锁状态
     * 用于系统操作（如落盘和载入）的全局锁
     * 
     * @var bool 是否持有全局写锁
     */
    private bool $globalWriteLocked = false;
    
    /**
     * 全局写锁等待标志
     * 用于系统操作（如落盘和载入）的全局锁等待
     * 
     * @var bool 是否有全局写锁等待者
     */
    private bool $globalWriteWaiting = false;
    
    /**
     * 全局读锁计数
     * 用于系统操作（如落盘和载入）的全局锁
     * 
     * @var int 当前持有的全局读锁数量
     */
    private int $globalReadLockCount = 0;
    
    /**
     * 最大写锁饥饿阈值
     * 
     * @var int 当读锁等待次数超过此值时，强制优先写锁
     */
    private const MAX_STARVATION_THRESHOLD = 100;
    
    /**
     * 最大键数量限制
     * 
     * @var int LRU触发阈值，超过此值将触发淘汰
     */
    private readonly int $maxKeyCount;
    
    /**
     * 过期清理间隔
     * 
     * @var int 清理过期键的时间间隔（秒）
     */
    private readonly int $cleanupInterval;
    
    /**
     * 锁超时时间
     * 
     * @var int 锁的超时时间（毫秒）
     */
    private readonly int $lockOutTime;
    
    /**
     * 锁TTL
     * 
     * @var int 锁的生存时间（秒）
     */
    private readonly int $lockTtl;
    
    /**
     * 重试间隔
     * 
     * @var int 获取锁失败后的重试间隔（微秒）
     */
    protected int $retryInterval;
    
    /**
     * 是否启用落盘
     * 
     * @var bool 是否启用磁盘持久化
     */
    private readonly bool $enableDump;
    
    /**
     * 落盘文件路径
     * 
     * @var ?string 持久化文件的绝对路径
     */
    private readonly ?string $dumpFile;
    
    /**
     * 落盘间隔
     * 
     * @var int 自动落盘的时间间隔（秒）
     */
    private readonly int $dumpInterval;
    
    /**
     * 前缀CRC32校验值
     * 
     * @var int 用于验证落盘文件完整性的CRC32值
     */
    private readonly int $prefixCrc32;
    
    /**
     * 落盘锁名称
     * 
     * @var string 落盘操作使用的锁名称
     */
    private readonly string $dumpLockName;
    
    /**
     * 脏数据标志
     * 
     * @var bool 是否存在未持久化的数据变更
     */
    private bool $dirty = false;
    
    /**
     * 上次清理时间
     * 
     * @var int 上次执行过期清理的时间戳
     */
    private int $lastCleanupTime = 0;
    
    /**
     * 上次落盘时间
     * 
     * @var int 上次执行持久化的时间戳
     */
    private int $lastDumpTime = 0;
    
    /**
     * HNSW索引结构
     * 
     * @var array HNSW索引数据：['nodes' => array, 'max_level' => int, 'entry_point' => ?string]
     */
    private array $hnswIndex = [
        'nodes' => [],
        'max_level' => 0,
        'entry_point' => null
    ];
    
    /**
     * 构造函数
     * 初始化内存缓存驱动，加载配置并准备数据结构
     * 
     * @param array $config 配置参数
     * @param array $config['lockOutTime'] 锁超时时间（毫秒）
     * @param array $config['lockTtl'] 锁TTL（秒）
     * @param array $config['retryInterval'] 重试间隔（微秒）
     * @param array $config['maxKeyCount'] 最大键数量
     * @param array $config['cleanupInterval'] 清理间隔（秒）
     * @param array $config['dump_file'] 落盘文件路径
     * @param array $config['dumpFile'] 落盘文件路径（别名）
     * @param array $config['dumpInterval'] 落盘间隔（秒）
     * 
     * @throws Throwable 构造过程中可能抛出异常
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        parent:()


### /**
     * 获取带前缀的系统KEY
     * 用于多实例隔离的系统键名生成
     * 
     * @param string $key 基础键名
     * 
     * @return string 带前缀的完整键名
     */
    private function getSystemKey(string $key):()


### /**
     * 生成内部防冲突KEY
     * 使用CRC32和MD5指纹生成唯一的内部键名
     * 
     * @param string $rawKey 原始键名
     * 
     * @return string 内部防冲突键名
     */
    private function compileInternalKey(string $rawKey):()


### /**
     * 获取分片锁名称
     * 根据键名计算分片索引，用于并发控制
     * 
     * @param string $key 键名
     * 
     * @return string 分片锁名称
     */
    private function getShardLockName(string $key):()


### /**
     * 从键名提取数据库前缀（用于LRU隔离）
     * 匹配规则：**_**_key (如 alice_orders_key -> alice_orders_)
     */
    private function extractDbPrefix(string $rawKey):()


### /**
     * 更新LRU访问时间（支持按前缀隔离）
     */
    private function touchLru(string $internalKey):()


### /**
     * 从指定前缀的链表中移除节点
     */
    private function removeFromList(array &$node, string $prefix):()


### /**
     * 判断是否为系统键
     * 精确匹配系统键名，防止误删核心系统键
     * 
     * @param string $rawKey 原始键名
     * 
     * @return bool 是否为系统键
     */
    private function isSystemKey(string $rawKey):()


### /**
     * 清理过期KEY（按前缀隔离扫描）
     */
    private function cleanupExpiredKeys():()


### /**
     * LRU淘汰算法（按前缀独立隔离淘汰）
     */
    private function lruElimination():()

