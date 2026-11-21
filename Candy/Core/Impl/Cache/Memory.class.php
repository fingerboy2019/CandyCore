<?php
/**
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
 * @method bool vectorSet(string $key, array $vector, int $dimension, int $expire = 0) 设置向量
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

namespace Candy\Core\Impl\Cache;

use Candy\Core\Cache;
use Candy\Core\Impl\Pack\Binary;
use Candy\Core\Trait\Cache\Memory\MemoryBitmapOps;
use Candy\Core\Trait\Cache\Memory\MemoryKeyCodec;
use Candy\Core\Trait\Cache\Memory\MemoryKvPublicOps;
use Candy\Core\Trait\Cache\Memory\MemoryLocking;
use Candy\Core\Trait\Cache\Memory\MemoryLru;
use Candy\Core\Trait\Cache\Memory\MemoryStorage;
use Candy\Core\Trait\Cache\Segment\SegmentStreamOps;
use Throwable;

defined('CANDY') OR die('Access Denied');

final class Memory extends Cache
{
    /**
     * Driver Capabilities
     * @return array
     */
    protected function driverCapabilities(): array
    {
        return [
            'kv' => ['mode' => 'local'],
            'structures' => ['mode' => 'local'],
        ];
    }
    use MemoryKeyCodec;
    use MemoryLru;
    use MemoryLocking;
    use MemoryStorage;
    use MemoryKvPublicOps;
    use MemoryBitmapOps;
    use SegmentStreamOps;

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
     * 落盘魔数
     *
     * @var int 0x4D454D4F = "MEMO"
     */
    private const DUMP_MAGIC = 0x4D454D4F;
    private const VECTOR_MAX_DIMENSION = 2048;
    private const MAX_VECTOR_SEARCH_CACHE = 1000;
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
     * 预写日志（WAL）：把两次 dump 之间的写操作记下来，崩溃后重放补齐
     *
     * dump 是低频全量（默认 5 秒，为降 IO），WAL 是高频增量（每条写都记）。
     * 恢复顺序：loadDump（基础）→ replay WAL（dump 之后的操作）。
     * dump 成功后按 dump 前的 seq 截断 WAL，避免无限增长。
     */
    private ?\Candy\Core\Impl\Cache\Wal $wal = null;

    /** WAL 文件路径（配置 walFile / wal_file，为空则不启用） */
    private ?string $walFile = null;

    /** 重放期间屏蔽 WAL 记录，避免把重放本身又写进 WAL */
    private bool $walReplaying = false;
    
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
     *   construct
     * @param mixed $config
     * @return mixed
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        parent::__construct($config);

        // 基础配置初始化
        $this->lockOutTime = $config['lockOutTime'] ?? 3000;
        $this->lockTtl = $config['lockTtl'] ?? 30;
        $this->retryInterval = $config['retryInterval'] ?? 200;
        $this->maxKeyCount = $config['maxKeyCount'] ?? self::DEFAULT_MAX_KEY_COUNT;
        $this->cleanupInterval = $config['cleanupInterval'] ?? self::DEFAULT_CLEANUP_INTERVAL;

        // 落盘配置初始化
        $this->dumpFile = $config['dump_file'] ?? $config['dumpFile'] ?? null;
        $this->enableDump = !empty($this->dumpFile);
        // WAL 只在启用落盘时有意义（没有 dump 就没有"两次 dump 之间"）
        $walCfg = $config['wal_file'] ?? $config['walFile'] ?? null;
        // 'wal' => false 可整体关闭（配了 dump 也不写 WAL）
        if ($walCfg === false || (($config['wal'] ?? null) === false)) {
            $this->walFile = null;
        } else {
            $this->walFile = is_string($walCfg) && $walCfg !== '' ? $walCfg : null;
        }
        if (empty($this->walFile) && $this->enableDump && (($config['wal'] ?? null) !== false)) {
            // 未显式配置时，跟 dump 文件同名 + .wal
            $this->walFile = $this->dumpFile . '.wal';
        }
        $this->dumpInterval = $config['dumpInterval'] ?? self::DEFAULT_DUMP_INTERVAL;
        $this->prefixCrc32 = crc32($this->cachePrefix);
        $this->dumpLockName = 'memory_dump_lock_' . md5($this->cachePrefix);

        // 时间初始化
        $this->lastCleanupTime = (int)microtime(true);
        $this->lastDumpTime = time();

        // 初始化分片锁状态，避免竞态条件
        for ($i = 0; $i < self::SHARD_COUNT; $i++) {
            $this->writeWaitings[$i] = false;
            $this->isWriteLockeds[$i] = false;
            $this->readLockCounts[$i] = 0;
            $this->writeStarvationCounters[$i] = 0;
        }

        // 自动加载落盘数据
        if ($this->enableDump) {
            $this->loadDump();
        }
        $this->createWalAndReplay();

        // 加载向量键索引（线性扫描用）
        $this->vectorIndex = $this->__rawGet($this->getSystemKey(self::VECTOR_INDEX_KEY)) ?? [];
        if (!is_array($this->vectorIndex)) {
            $this->vectorIndex = [];
        }

        // 注册钩子
        register_shutdown_function([$this, '__releaseAllLocksOnShutdown']);
        // 进程退出时无条件落盘：原用 autoDump（带 dumpInterval 间隔检查），
        // 刚写入的数据在间隔内退出会被跳过，导致“正常重启也丢数据”。
        $this->enableDump && register_shutdown_function([$this, 'forceDump']);
    }
    /**
     * Auto Dump
     */
    public function autoDump(): void
    {
        if (!$this->enableDump || !$this->dirty) return;

        $now = time();
        // 使用 microtime(true) 提高精度
        if (microtime(true) - $this->lastDumpTime < $this->dumpInterval) return;

        $this->forceDump();
    }

    /**
     * 强制原子落盘
     * 立即执行持久化操作，保证数据一致性
     * 
     * @return bool 是否成功落盘
     * 
     * @throws Throwable 落盘过程中可能抛出异常
     */
    public function forceDump(): bool
    {
        if (!$this->enableDump || !$this->acquireWriteLock('__system_dump', self::DUMP_LOCK_TIMEOUT)) return false;

        // dump 之前先记下 WAL 已分配到的 seq：dump 成功只截断 <= 它的部分，
        // 之后新写入的记录必须保留（否则正好在 dump 期间的写会丢）
        $walSeq = $this->wal?->lastSeq() ?? 0;

        try {
            $now = time();
            $dumpData = [];

            // 统计脏 key，决定走增量还是全量
            $dirtyCount = 0;
            foreach ($this->dirtyKeys as $keys) {
                $dirtyCount += count($keys);
            }
            $totalCount = count($this->data);

            // 增量只在「有旧快照可合并」且「脏 key 不超过一半」时用；
            // 脏太多时读旧文件+合并的成本已接近全量，不如直接全量。
            $useIncremental = $dirtyCount > 0
                && $dirtyCount <= (int) ceil($totalCount / 2)
                && is_file($this->dumpFile);

            if ($useIncremental) {
                // ★ 增量落盘必须「读旧 + 合并 + 写回」。
                // 原实现只收集脏 key 就整文件覆盖 —— 没变的 key 会被全部丢掉（静默数据丢失）。
                $base = Binary::loadDump($this->dumpFile, self::DUMP_MAGIC, $this->prefixCrc32);
                $dumpData = is_array($base) ? $base : [];

                foreach ($this->dirtyKeys as $prefix => $keys) {
                    foreach ($keys as $rawKey => $isDirty) {
                        $internalKey = $this->compileInternalKey($rawKey);
                        if (isset($this->data[$internalKey])) {
                            $item = $this->data[$internalKey];
                            if ($item['expire'] > 0 && $item['expire'] < $now) {
                                unset($dumpData[$rawKey]);      // 已过期 → 从快照移除
                                continue;
                            }
                            if (str_contains($item['raw_key'], self::LAST_DUMP_KEY)) {
                                continue;
                            }
                            $dumpData[$item['raw_key']] = $item;
                        } else {
                            unset($dumpData[$rawKey]);          // 已删除 → 从快照移除
                        }
                    }
                }

                // 兜底：清掉旧快照里已过期、但这轮没被标脏的项
                foreach ($dumpData as $rawKey => $item) {
                    if (($item['expire'] ?? 0) > 0 && $item['expire'] < $now) {
                        unset($dumpData[$rawKey]);
                    }
                }
            } else {
                // 全量落盘：无旧快照、或脏 key 太多、或完全没有脏 key
                foreach ($this->data as $item) {
                    if ($item['expire'] > 0 && $item['expire'] < $now) continue;
                    if (str_contains($item['raw_key'], self::LAST_DUMP_KEY)) continue;

                    $dumpData[$item['raw_key']] = $item;
                }
            }

            $result = Binary::atomicDump(
                $this->dumpFile,
                self::DUMP_MAGIC,
                $this->prefixCrc32,
                $dumpData
            );

            if ($result) {
                $this->lastDumpTime = $now;
                $this->dirty = false;
                // dump 已包含 $walSeq 之前的全部状态 → 可以安全截断
                if ($walSeq > 0) {
                    $this->wal?->truncate($walSeq);
                }
                $this->dirtyKeys = []; // 清空脏数据列表（按前缀隔离）
                $this->cleanupDirtyKeys(); // 清理空的脏数据条目
                $this->data[$this->compileInternalKey($this->getSystemKey(self::LAST_DUMP_KEY))] = [
                    'value'    => $now,
                    'expire'   => 0,
                    'last_use' => $now,
                    'raw_key'  => $this->getSystemKey(self::LAST_DUMP_KEY)
                ];
            }
            return $result;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock('__system_dump');
        }
    }

    /**
     * 创建 WAL 实例并把其中「dump 之后」的操作重放回内存
     *
     * 必须在 loadDump() 之后调用：dump 是基础快照，WAL 是它之后增量。
     * 重放期间用 $walReplaying 屏蔽 walNote，避免重放动作又被记进 WAL。
     *
     * @return void
     */
    protected function createWalAndReplay(): void
    {
        if (!$this->enableDump || empty($this->walFile) || $this->wal !== null) {
            return;
        }
        try {
            $this->wal = new \Candy\Core\Impl\Cache\Wal($this->walFile, [
                'flush_ms'   => (int) ($this->walFlushMs ?? 100),
                'flush_rows' => (int) ($this->walFlushRows ?? 64),
            ]);
            if (!$this->wal->available()) {
                $this->wal = null;
                return;
            }

            $rows = $this->wal->replay(0);
            if ($rows === []) {
                return;
            }

            $this->walReplaying = true;
            try {
                foreach ($rows as $one) {
                    $key = (string) $one['key'];
                    $val = $one['value'];
                    switch ((int) $one['op']) {
                        case self::WAL_OP_SET:
                            $this->__rawSet($key, $val['v'] ?? null, (int) ($val['ttl'] ?? 0));
                            break;
                        case self::WAL_OP_DEL:
                            $this->__rawDelete($key);
                            break;
                        case self::WAL_OP_EXPIRE:
                            if (is_numeric($val)) {
                                $this->__rawExpire($key, (int) $val);
                            }
                            break;
                    }
                }
            } finally {
                $this->walReplaying = false;
            }
        } catch (\Throwable) {
            // WAL 是增强能力，任何失败都不能影响缓存本身可用
            $this->wal = null;
        }
    }

    /**
     * 把一次写操作追加到 WAL（由 Cache 基类的 set/delete/expire 回调）
     *
     * @param int    $op          WAL_OP_SET / WAL_OP_DEL / WAL_OP_EXPIRE
     * @param string $prefixedKey 已加前缀的 key
     * @param mixed  $value       写入值（EXPIRE 时为 ttl）
     * @param int    $ttl         过期秒数
     * @return void
     */
    protected function walNote(int $op, string $prefixedKey, mixed $value = null, int $ttl = 0): void
    {
        if ($this->wal === null || $this->walReplaying) {
            return;
        }
        try {
            if ($op === self::WAL_OP_EXPIRE) {
                $this->wal->append(\Candy\Core\Impl\Cache\Wal::OP_EXPIRE, $prefixedKey, max(0, (int) $value));
                return;
            }
            $this->wal->append(
                $op === self::WAL_OP_DEL ? \Candy\Core\Impl\Cache\Wal::OP_DEL : \Candy\Core\Impl\Cache\Wal::OP_SET,
                $prefixedKey,
                $op === self::WAL_OP_DEL ? null : ['v' => $value, 'ttl' => $ttl]
            );
        } catch (\Throwable) {
            // 记日志失败不能影响业务写入
        }
    }

    /**
     * WAL 状态（供 getStats / 排障使用）
     *
     * @return array<string, mixed>
     */
    public function walInfo(): array
    {
        // 先刷盘再取尺寸：缓冲区里积压的记录没落盘时，size 会误导排障
        try {
            $this->wal?->flush(true);
        } catch (\Throwable) {
        }
        return [
            'enabled'  => $this->wal !== null,
            'file'     => $this->walFile,
            'last_seq' => $this->wal?->lastSeq() ?? 0,
            'size'     => $this->wal?->size() ?? 0,
            'error'    => $this->wal?->error() ?? '',
        ];
    }

    /**
     * 加载落盘数据
     * 从磁盘加载持久化的数据到内存
     * 
     * @return void
     * 
     * @throws Throwable 加载过程中可能抛出异常
     */
    public function loadDump(): void
    {
        if (!$this->enableDump || !is_file($this->dumpFile) || !$this->acquireWriteLock('__system_load', self::DUMP_LOCK_TIMEOUT)) return;

        try {
            $now = time();
            $dumpData = Binary::loadDump($this->dumpFile, self::DUMP_MAGIC, $this->prefixCrc32);
            if (!is_array($dumpData)) return;

            $this->data = $this->keyMap = $this->lruRecords = [];
            $this->lruHeads = $this->lruTails = [];
            $this->lruNodes = [];
            $this->dirtyKeys = [];

            foreach ($dumpData as $rawKey => $item) {
                if (!isset($item['value'], $item['expire']) || ($item['expire'] > 0 && $item['expire'] < $now)) continue;

                $internalKey = $this->compileInternalKey($rawKey);
                $this->data[$internalKey] = $item;
                
                // 重建 LRU 结构
                $prefix = $this->extractDbPrefix($rawKey);
                if (!isset($this->lruRecords[$prefix])) {
                    $this->lruRecords[$prefix] = [];
                    $this->lruHeads[$prefix] = null;
                    $this->lruTails[$prefix] = null;
                    $this->lruNodes[$prefix] = [];
                }
                $this->lruRecords[$prefix][$internalKey] = $item['last_use'];
                
                // 将节点添加到 LRU 链表头部
                $node = ['key' => $internalKey, 'prev' => $this->lruHeads[$prefix], 'next' => null];
                if ($this->lruHeads[$prefix] !== null) {
                    $this->lruHeads[$prefix]['prev'] = $node;
                }
                $this->lruHeads[$prefix] = $node;
                if ($this->lruTails[$prefix] === null) {
                    $this->lruTails[$prefix] = $node;
                }
                $this->lruNodes[$prefix][$internalKey] = $node;
            }

            $this->vectorIndex = $this->__rawGet($this->getSystemKey(self::VECTOR_INDEX_KEY)) ?? [];
            if (!is_array($this->vectorIndex)) {
                $this->vectorIndex = [];
            }

            $this->dirty = false;
        } catch (Throwable) {
            return;
        } finally {
            $this->releaseWriteLock('__system_load');
        }
    }

    /**
     * 清空缓存
     * 删除所有业务键，保留系统键
     * 
     * @return bool 是否清空成功
     * 
     * @throws Throwable 清空过程中可能抛出异常
     */
    public function __clear(): bool
    {
        if (!$this->acquireWriteLock('__system_clear')) return false;

        try {
            // 仅清当前租户作用域；系统内置键与锁键始终跳过
            $scopePrefix = $this->scopePrefix();
            $configPrefix = $this->cachePrefix;
            foreach ($this->data as $internalKey => $item) {
                $rawKey = $item['raw_key'];
                if (!str_starts_with($rawKey, $scopePrefix)
                    || str_starts_with($rawKey, self::LOCK_PREFIX)) {
                    continue;
                }
                $afterConfig = $configPrefix !== '' && str_starts_with($rawKey, $configPrefix)
                    ? substr($rawKey, strlen($configPrefix))
                    : $rawKey;
                if (in_array($afterConfig, self::SYSTEM_INTERNAL_KEYS, true)) {
                    continue;
                }
                $dbPrefix = $this->extractDbPrefix($rawKey);
                if (isset($this->lruNodes[$dbPrefix][$internalKey])) {
                    $this->removeFromList($this->lruNodes[$dbPrefix][$internalKey], $dbPrefix);
                }
                unset($this->data[$internalKey], $this->lruRecords[$dbPrefix][$internalKey], $this->keyMap[$rawKey]);
            }
            $this->dirty = true; // 直接标记全局脏
            $this->forceDump();
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock('__system_clear');
        }
    }
    /**
     *   acquire Lock
     * @param mixed $lockName
     * @param mixed $timeout
     * @return bool
     */
    public function __acquireLock(string $lockName, ?int $timeout = null): bool
    {
        $timeout ??= $this->lockOutTime;
        $lockKey = $this->getSystemKey(self::LOCK_PREFIX . $lockName);

        // 检查是否已持有锁（支持重入）
        if (isset($this->heldLocks[$lockName])) {
            $currentValue = $this->__rawGet($lockKey);
            // 原子性验证：确保锁值匹配且未过期
            if ($currentValue !== null && $currentValue === $this->heldLocks[$lockName]) {
                return true;
            }
            // 锁已失效，需要重新获取
        }

        if (!$this->acquireWriteLock($lockKey)) return false;
        try {
            // 生成唯一锁标识（包含进程ID和随机数，支持多进程）
            $processId = getmypid() ?: random_int(10000, 99999);
            $uniqueId = $processId . ':' . uniqid('', true) . ':' . random_int(100000, 999999);
            $val = $this->cachePrefix . $lockName . ':' . $uniqueId;
            
            // 原子CAS操作：仅当锁不存在或已过期时才设置
            $currentValue = $this->__rawGet($lockKey);
            if ($currentValue === null || $this->isLockExpired($currentValue)) {
                $this->__rawSet($lockKey, $val, $this->lockTtl);
                $this->heldLocks[$lockName] = $val;
                return true;
            }
            return false;
        } finally {
            $this->releaseWriteLock($lockKey);
        }
    }

    /**
     * 检查锁是否过期
     * 通过检查锁是否存在来判断是否过期
     * 
     * @param string $lockValue 锁值
     * 
     * @return bool 是否过期
     */
    private function isLockExpired(string $lockValue): bool
    {
        // 锁值格式：prefix:lockName:processId:uniqid:random
        // 提取锁键名（去掉前缀和随机部分）
        $parts = explode(':', $lockValue, 3);
        if (count($parts) < 3) return true;
        
        $lockName = $parts[1];
        $lockKey = $this->getSystemKey(self::LOCK_PREFIX . $lockName);
        
        // 通过 __rawGet 检查锁是否存在
        // 如果锁已过期，__rawGet 会返回 null
        return $this->__rawGet($lockKey) === null;
    }

    /**
     * 释放锁
     * 释放指定名称的分布式锁
     * 
     * @param string $lockName 锁名称
     * 
     * @return bool 是否成功释放
     * 
     * @throws Throwable 释放锁过程中可能抛出异常
     */
    public function __releaseLock(string $lockName): bool
    {
        if (!isset($this->heldLocks[$lockName])) return true;
        $lockKey = $this->getSystemKey(self::LOCK_PREFIX . $lockName);
        $currentValue = $this->__rawGet($lockKey);
        // 原子性验证：仅当锁值匹配时才删除，防止误删其他进程的锁
        if ($currentValue === $this->heldLocks[$lockName]) {
            $this->__rawDelete($lockKey);
            unset($this->heldLocks[$lockName]);
        }
        return true;
    }

    /**
     * 关闭时释放所有锁
     * 在请求结束时自动释放所有持有的锁
     * 
     * @return void
     */
    public function __releaseAllLocksOnShutdown(): void
    {
        foreach (array_keys($this->heldLocks) as $lock) {
            $this->__releaseLock($lock);
        }
    }
    
    /**
     * 写入向量并登记到 vectorIndex（供前缀线性扫描，非 HNSW）
     *
     * @param string $key 键名
     * @param array $vector 向量
     * @param int $dimension 维度
     * @param int $expire 过期秒数
     * @return bool
     */
    public function vectorSet(string $key, array $vector, int $dimension, int $expire = 0): bool
    {
        if ($dimension <= 0 || $dimension > self::VECTOR_MAX_DIMENSION) {
            return false;
        }
        if (count($vector) !== $dimension || !$this->isValidVector($vector)) {
            return false;
        }
        if (!$this->acquireWriteLock($key)) {
            return false;
        }
        try {
            $data = [
                'dimension' => $dimension,
                'vector' => array_map('floatval', $vector),
                'norm' => $this->calculateVectorNorm($vector),
                'timestamp' => time(),
            ];
            $this->__rawSet($key, $data, $expire);

            if (!in_array($key, $this->vectorIndex, true)) {
                $this->vectorIndex[] = $key;
                $this->__rawSet($this->getSystemKey(self::VECTOR_INDEX_KEY), $this->vectorIndex, 0);
            }

            $this->setDirty();
            $this->vectorSearchCache = [];
            return true;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 读取向量结构
     *
     * @param string $key
     * @return array|null
     */
    public function vectorGet(string $key): ?array
    {
        $data = $this->__rawGet($key);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['vector']) && is_array($data['vector'])) {
            return $data;
        }
        $extracted = $this->extractStoredVector($data);
        if ($extracted === null) {
            return null;
        }
        return [
            'dimension' => count($extracted),
            'vector' => $extracted,
            'norm' => $this->calculateVectorNorm($extracted),
            'timestamp' => 0,
        ];
    }

    /**
     * 删除向量并更新索引
     *
     * @param string $key
     * @return bool
     */
    public function vectorDelete(string $key): bool
    {
        if (!$this->acquireWriteLock($key)) {
            return false;
        }
        try {
            $ok = $this->__rawDelete($key);
            $idx = array_search($key, $this->vectorIndex, true);
            if ($idx !== false) {
                array_splice($this->vectorIndex, (int)$idx, 1);
                $this->__rawSet($this->getSystemKey(self::VECTOR_INDEX_KEY), $this->vectorIndex, 0);
            }
            $this->vectorSearchCache = [];
            $this->setDirty();
            return (bool)$ok;
        } finally {
            $this->releaseWriteLock($key);
        }
    }

    /**
     * 前缀线性扫描近邻（非 ANN）
     *
     * @param string $prefix 键前缀
     * @param array $queryVector 查询向量
     * @param int $topK
     * @param float $threshold
     * @return array<int, array{key:string,score:float}>
     */
    public function vectorScanSearch(string $prefix, array $queryVector, int $topK = 10, float $threshold = 0.0): array
    {
        if (!$this->isValidVector($queryVector)) {
            return [];
        }
        if (!$this->acquireReadLock('__system_vector_search')) {
            return [];
        }
        try {
            $cacheKey = md5($prefix . '|' . json_encode($queryVector) . '|' . $topK . '|' . $threshold);
            if (isset($this->vectorSearchCache[$cacheKey])) {
                return $this->vectorSearchCache[$cacheKey];
            }

            $scores = [];
            foreach ($this->vectorIndex as $k) {
                if (!is_string($k) || !str_starts_with($k, $prefix)) {
                    continue;
                }
                $raw = $this->__rawGet($k);
                $vector = $this->extractStoredVector($raw);
                if ($vector === null || count($vector) !== count($queryVector)) {
                    continue;
                }
                $scores[$k] = $this->vectorCosineSimilarity($vector, $queryVector);
            }

            $result = $this->normalizeVectorSearchResults($scores, $topK, $threshold);

            if (count($this->vectorSearchCache) >= self::MAX_VECTOR_SEARCH_CACHE) {
                $removeCount = (int)(self::MAX_VECTOR_SEARCH_CACHE * 0.2);
                $this->vectorSearchCache = array_slice($this->vectorSearchCache, $removeCount, null, true);
            }
            $this->vectorSearchCache[$cacheKey] = $result;
            return $result;
        } finally {
            $this->releaseReadLock('__system_vector_search');
        }
    }

    /**
     * @deprecated 转调 vectorScanSearch
     */
    public function vectorAnnSearch(string $key, array $queryVector, int $topK = 10, float $threshold = 0): array
    {
        return $this->vectorScanSearch($key, $queryVector, $topK, $threshold);
    }

    /**
     *   destruct
     * @return mixed
     */
    public function __destruct()
    {
        // 仅保留清理逻辑，移除无效的索引写入
        // 索引持久化依赖 autoDump 处理
        $this->data = $this->keyMap = $this->lruRecords = $this->heldLocks = [];
        $this->lruHeads = $this->lruTails = [];
        $this->lruNodes = [];
        $this->dirtyKeys = [];
        $this->vectorSearchCache = [];
        $this->vectorIndex = [];
    }
    
    /**
     * 获取缓存统计信息
     * 提供缓存的各项统计指标，便于监控
     * 
     * @return array 统计信息数组
     */
    public function getStats(): array
    {
        return [
            'key_count' => count($this->data),
            'memory_usage' => memory_get_usage(true),
            'lru_size' => array_sum(array_map('count', $this->lruRecords)),
            'dirty_keys_count' => array_sum(array_map('count', $this->dirtyKeys)),
            'vector_search_cache_size' => count($this->vectorSearchCache),
            'vector_index_count' => count($this->vectorIndex),
            'max_key_count' => $this->maxKeyCount,
            'memory_limit' => ini_get('memory_limit'),
            'enable_dump' => $this->enableDump,
            'last_dump_time' => $this->lastDumpTime,
            'prefix' => $this->cachePrefix
        ];
    }
}
