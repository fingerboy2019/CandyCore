# Shmop 类

## 概述

* Shmop共享内存缓存类（全优化最终版 + 多维键支持 + File功能对齐）
 * 合并第一+第二优先级全量优化，双驱动完全对齐
 * 保留：快速寻码、6字节防重、位图管理、异步落盘、LRU淘汰
 * 新增：批量位扫描、空闲索引链表、页对齐、批量写、脏页写回、读写分离锁、批量接口
 * 升级：支持多维键 key.key1.key2 读写/删/查/TTL
 * 对齐：与File类库功能完全对齐，批量锁优化、pendingWrites、metaToMemKey反向映射
 *
 * 特性：
 * - 共享内存存储，极致性能（无IO开销）
 * - 支持TTL过期时间管理
 * - 原子操作保证数据一致性
 * - 支持多维键（如：user.profile.name）
 * - 读写分离锁，支持高并发读取
 * - LRU淘汰机制防止内存溢出
 * - 位图管理，O(1)空间分配
 * - 异步落盘，数据持久化
 * - 4KB页对齐，提升性能
 * - 批量操作分片锁，避免死锁
 *
 * 依赖扩展：
 * - sysvsem：信号量支持
 * - shmop：共享内存支持
 *
 *

## 主要方法


### /**
 * Shmop共享内存缓存类（全优化最终版 + 多维键支持 + File功能对齐）
 * 合并第一+第二优先级全量优化，双驱动完全对齐
 * 保留：快速寻码、6字节防重、位图管理、异步落盘、LRU淘汰
 * 新增：批量位扫描、空闲索引链表、页对齐、批量写、脏页写回、读写分离锁、批量接口
 * 升级：支持多维键 key.key1.key2 读写/删/查/TTL
 * 对齐：与File类库功能完全对齐，批量锁优化、pendingWrites、metaToMemKey反向映射
 *
 * 特性：
 * - 共享内存存储，极致性能（无IO开销）
 * - 支持TTL过期时间管理
 * - 原子操作保证数据一致性
 * - 支持多维键（如：user.profile.name）
 * - 读写分离锁，支持高并发读取
 * - LRU淘汰机制防止内存溢出
 * - 位图管理，O(1)空间分配
 * - 异步落盘，数据持久化
 * - 4KB页对齐，提升性能
 * - 批量操作分片锁，避免死锁
 *
 * 依赖扩展：
 * - sysvsem：信号量支持
 * - shmop：共享内存支持
 *
 * 使用场景：
 * - 高性能缓存需求
 * - 低延迟读写操作
 * - 需要高并发支持的应用
 * - 多进程环境下的数据共享
 *
 * @package Candy\Core\Cache
 * @final 该类为最终类，不允许继承
 */

declare(strict_types=1);

namespace Candy\Core\Cache;

use Candy\Core\Cache;
use Candy\Core\Cache\Pack\Binary;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Shmop extends Cache
{
    private const MAGIC_NUMBER = 0xDEADBEEF;
    private const VERSION = 6; // 版本升级，旧版本自动重建
    private const WRITING_FLAG_OK = 0;
    private const WRITING_FLAG_WRITING = 1;
    private const WRITING_FLAG_TIMEOUT = 30;
    private const SHARD_COUNT = 16;

    // 固定头部（33字节，兼容优化）
    private const HEADER_FORMAT = 'NCNNNNNNN';
    private const HEADER_UNPACK_FORMAT = 'Nmagic_number/Cversion/Ndata_capacity/Nwriting_flag/Nwriting_start_time/Nblock_size/Nmax_var_number/Nbitmap_length/Nreserved';
    private const HEADER_SIZE = 33;
    
    private const INDEX_HEADER_LENGTH = 6; // 2字节位图长度 + 2字节空闲头 + 2字节空闲尾
    private const INDEX_FORMAT = 'NNN';
    private const INDEX_UNPACK_FORMAT = 'Ncrc32/Nv_crc32/Nmeta_order';
    private const INDEX_LENGTH = 12;
    private const INDEX_ORDER_FREE = 0xFFFFFFFF;
    
    private const META_LENGTH = 25;
    private const META_FORMAT = 'cNNNNNN';
    private const META_UNPACK_FORMAT = 'cstatus/Ntimestamp/Nblock_length/Nstring_length/Noffset/Nlast_use/Ndata_crc32';
    
    private const DEFAULT_BLOCK_SIZE = 4096;
    private const BITS_PER_BYTE = 8;
    private const BITS_PER_CHUNK = 64; // 64位批量扫描
    private const PAGE_SIZE = 4096; // 系统页大小，对齐用
    
    private const MAX_SEMAPHORES = 100;
    private const SEM_PERMISSION = 0660;
    private const SYSTEM_LAST_UPDATE_KEY = '__shmop_system_last_update_time';
    private const LOCK_TYPE_READ = 1;
    private const LOCK_TYPE_WRITE = 2;
    
    private const DUMP_MAGIC = 0x53484D4F;
    private const DUMP_VERSION = 1;
    private const DUMP_HEADER_SIZE = 32;
    private const GC_LOCK_TIMEOUT = 10;
    
    // 热缓存常量
    private const MAX_HOT_CACHE_COUNT = 200;
    private const META_CACHE_TTL = 30;
    private const MAX_META_CACHE_COUNT = 500;
    
    private mixed $shmId = false;
    private readonly int $segmentKey;
    private readonly int $segmentMode;
    private int $segmentSize = 0;
    private readonly int $lockOutTime;
    protected int $retryInterval;
    private readonly int $maxVarNumber;
    private readonly int $blockSize;
    private int $indexAreaFixedSize = 0;
    private int $metaAreaSize = 0;
    private int $bitmapAreaSize = 0;
    private int $dataAreaStart = 0;
    private int $totalBlockCount = 0;
    private array $header = [];

    // 核心内存映射
    private array $indexMap = [];
    private string $bitmap = '';
    private array $dirtyBitmapPages = []; // 位图脏页标记
    private array $freeMetaOrders = [];
    private array $metadataCache = [];
    private array $metaToMemKey = []; // 反向映射 [metaOrder => memKey]
    private array $hotCache = []; // 热缓存

    // 读写分离锁属性
    private array $heldLocks = [];
    private int $readLockCount = 0;
    private bool $writeWaiting = false;
    private array $semaphores = [];
    private readonly int $semKeyBase;
    private mixed $rwSemaphore = false;

    // LRU与GC属性
    private array $lruRecords = [];
    private int $lastGcTime = 0;
    private readonly int $gcInterval;
    private array $gcState = []; // 增量GC状态

    // 异步落盘属性
    private readonly bool $enableDump;
    private readonly string $dumpFile;
    private readonly int $dumpInterval;
    private bool $dirty = false;
    private float $lastDumpTime;
    private readonly string $dumpLockName;
    private readonly int $prefixCrc32;
    private static array $dumpCheckedMap = [];
    protected string $cachePrefix = '';

    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        parent::__construct($config);

        if (!extension_loaded('sysvsem') || !extension_loaded('shmop')) {
            throw new \RuntimeException('请安装并启用 sysvsem 和 shmop 扩展');
        }

        // 基础配置初始化
        $this->cachePrefix = $config['prefix'] ?? 'candy:';
        $this->lockOutTime = $config['lockOutTime'] ?? 3000;
        $this->retryInterval = $config['retryInterval'] ?? 200;
        $this->segmentSize = $config['segmentSize'] ?? 4 * 1024 * 1024;
        $this->segmentMode = $config['segmentMode'] ?? 0640;
        $this->maxVarNumber = $config['maxVarNumber'] ?? 2000;
        $this->blockSize = $config['blockSize'] ?? self::DEFAULT_BLOCK_SIZE;
        $this->gcInterval = $config['gcInterval'] ?? 10;
        $this->prefixCrc32 = crc32($this->cachePrefix);

        // 索引区固定长度计算
        $this->indexAreaFixedSize = self::INDEX_HEADER_LENGTH + $this->maxVarNumber * self::INDEX_LENGTH;
        $this->metaAreaSize = $this->maxVarNumber * self::META_LENGTH;

        // 共享内存/信号量KEY生成
        $baseFile = __FILE__;
        $this->segmentKey = $config['segmentKey'] ?? ftok($baseFile, 'C');
        $this->semKeyBase = $config['semKeyBase'] ?? ftok($baseFile, 'S');
        $rwSemKey = ftok($baseFile, 'R');
        if ($this->segmentKey === -1 || $this->semKeyBase === -1 || $rwSemKey === -1) {
            throw new \RuntimeException('ftok 生成共享内存/信号量KEY失败');
        }

        // 读写分离信号量初始化（最大1000并发读）
        $this->rwSemaphore = sem_get($rwSemKey, 1000, self::SEM_PERMISSION, true);
        if ($this->rwSemaphore === false) {
            throw new \RuntimeException('读写锁信号量创建失败');
        }

        // 内存空间合法性校验
        $initNeedSize = self::HEADER_SIZE + $this->indexAreaFixedSize + $this->metaAreaSize;
        if ($this->segmentSize - $initNeedSize < 1024 * 1024) {
            throw new \RuntimeException('共享内存可用空间不足，至少保留1MB数据区');
        }
        if ($this->segmentSize > 0xFFFFFFFF) {
            throw new \RuntimeException('共享内存大小不能超过4GB');
        }

        // 落盘配置初始化
        $this->enableDump = isset($config['dumpFile']) && !empty($config['dumpFile']);
        if ($this->enableDump) {
            $this->dumpFile = $config['dumpFile'];
            $this->dumpInterval = $config['dumpInterval'] ?? 5;
            $this->dumpLockName = 'shmop_dump_lock_' . md5($this->cachePrefix);
            $this->lastDumpTime = microtime(true);
            $this->loadDump();
            register_shutdown_function([$this, 'autoDump']);
        }

        // 共享内存段初始化
        $isNewCreate = false;
        try {
            $this->shmId = @shmop_open($this->segmentKey, 'w', 0, 0);
            if ($this->shmId === false) {
                $isNewCreate = true;
                $this->shmId = @shmop_open($this->segmentKey, 'c', $this->segmentMode, $this->segmentSize);
                if ($this->shmId === false) {
                    throw new \RuntimeException('共享内存创建失败');
                }
            } else {
                $this->segmentSize = shmop_size($this->shmId);
            }
        } catch (Throwable $e) {
            throw new \RuntimeException('共享内存初始化异常：' . $e->getMessage());
        }

        // 头部校验与初始化
        $needInit = $isNewCreate;
        if (!$isNewCreate) {
            $this->initHeaderData();
            $this->checkAndResetWritingFlag();
            if (
                $this->header['magic_number'] !== self::MAGIC_NUMBER
                || $this->header['version'] < self::VERSION
                || $this->header['block_size'] !== $this->blockSize
                || $this->header['max_var_number'] !== $this->maxVarNumber
            ) {
                $needInit = true;
            }
        }

        if ($needInit) $this->initSegment();
        else {
            $this->bitmapAreaSize = $this->header['bitmap_length'];
            $this->totalBlockCount = $this->bitmapAreaSize * self::BITS_PER_BYTE;
            $this->dataAreaStart = $this->alignOffset(self::HEADER_SIZE + $this->indexAreaFixedSize + $this->bitmapAreaSize + $this->metaAreaSize);
        }

        // 一次IO读取索引+位图
        $this->loadIndexAndBitmap();
        $this->rebuildLru();
        $this->initIncrementalGCState();

        register_shutdown_function([$this, '__releaseAllLocksOnShutdown']);
    }
    
    /**
     * 对内存偏移量进行4KB页对齐
     * @param int $offset 偏移量
     * @return int 页对齐后的偏移量
     */
    private function alignOffset(int $offset):()


### /**
     * 初始化增量GC状态
     * @return void
     */
    private function initIncrementalGCState():()


### /**
     * 从共享内存读取索引+位图
     * @return void
     */
    private function loadIndexAndBitmap():()


### /**
     * 查找空闲的MetaOrder槽位
     * @return int|false 空闲的metaOrder，无则返回false
     */
    private function findFreeMetaOrder():()


### /**
     * 从空闲列表中移除指定的槽位（当手动分配时）
     * @param int $metaOrder 槽位序号
     * @return void
     */
    private function removeFreeIndex(int $metaOrder):()


### /**
     * 释放索引槽位
     * @param int $metaOrder 元数据序号
     * @return void
     */
    private function freeIndex(int $metaOrder):()


### /**
     * 更新索引映射
     * @param string $memKey 内存Key
     * @param int $crc32 主CRC
     * @param int $vCrc32 校验CRC
     * @param int $metaOrder 元数据序号
     * @return void
     */
    private function updateIndex(string $memKey, int $crc32, int $vCrc32, int $metaOrder):()


### /**
     * 获取位图中某一位的值
     * @param int $bit 位偏移量
     * @return bool 位值（true=已使用，false=空闲）
     */
    private function bitGet(int $bit):()


### /**
     * 设置位图中某一位的值
     * @param int $bit 位偏移量
     * @param bool $used 位值（true=已使用，false=空闲）
     * @return void
     */
    private function bitSet(int $bit, bool $used):()


### /**
     * 查找最佳空闲块（64位批量扫描优化）
     * @param int $needBlockCount 需要的块数量
     * @return int|false 最佳起始位偏移，找不到返回false
     */
    private function findBestFitBlocks(int $needBlockCount):()

