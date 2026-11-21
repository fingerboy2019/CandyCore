<?php
/**
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

namespace Candy\Core\Impl\Cache;

use Candy\Core\Cache;
use Candy\Core\Impl\Pack\Binary;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Shmop extends Cache
{
    /**
     * Driver Capabilities
     * @return array
     */
    protected function driverCapabilities(): array
    {
        return [
            'distributed_lock' => ['support' => true, 'mode' => 'local', 'distributed' => false, 'durable' => false, 'atomic' => true],
            'distributed_state' => ['support' => true, 'mode' => 'local', 'distributed' => false, 'durable' => false, 'atomic' => true],
            'atomic_mutation' => ['support' => true, 'mode' => 'local', 'distributed' => false, 'durable' => false, 'atomic' => true],
        ];
    }
    use \Candy\Core\Trait\Cache\LocalHotCacheDualKey;
    use \Candy\Core\Trait\Cache\Segment\SegmentMetaIndex;
    use \Candy\Core\Trait\Cache\Segment\SegmentBlockBitmap;
    use \Candy\Core\Trait\Cache\Segment\SegmentKeyCodec;
    use \Candy\Core\Trait\Cache\Segment\SegmentStreamOps;
    use \Candy\Core\Trait\Cache\Segment\SegmentGcOps;
    use \Candy\Core\Trait\Cache\Segment\SegmentKvDataOps;
    use \Candy\Core\Trait\Cache\Segment\SegmentBitmapHelpers;
    use \Candy\Core\Trait\Cache\Segment\SegmentBitmapKvOps;
    use \Candy\Core\Trait\Cache\Segment\SegmentKvPublicOps;
    private const VERSION = 6; // 版本升级，旧版本自动重建
    private const WRITING_FLAG_OK = 0;
    private const WRITING_FLAG_WRITING = 1;
    private const WRITING_FLAG_TIMEOUT = 30;
    private const SHARD_COUNT = 16;

    // 固定头部（33字节，兼容优化）
    
    private const INDEX_HEADER_LENGTH = 6; // 2字节位图长度 + 2字节空闲头 + 2字节空闲尾
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

    /** 单个数据分区大小（字节），默认 4MB */
    private int $partitionSize = 4194304;

    /** 最大分区数（决定总容量与位图位数） */
    private int $maxPartitions = 16;

    /** 每个分区的块数 */
    private int $blocksPerPartition = 0;

    /** 控制段大小（header + index + bitmap + meta，不含数据区） */
    private int $controlSize = 0;

    /** 已创建的数据分区数（持久化在段头 reserved） */
    private int $partitionCount = 0;

    /** 数据分区句柄：[区号 => shmop] */
    private array $dataShms = [];

    /** 已同步的段头写入序号（跨进程变更检测） */
    private int $syncedSeq = -1;
    private int $totalBlockCount = 0;
    private array $header = [];

    // 核心内存映射
    private array $indexMap = [];
    private string $bitmap = '';
    private array $dirtyBitmapPages = []; // 位图脏页标记
    private array $freeMetaOrders = [];
    private array $metadataCache = [];
    private array $metaToMemKey = []; // 反向映射 [metaOrder => memKey]

    /** @var array{hotCache: array, lruRecords: array} */
    private array $shmopHotBucket = [
        'hotCache' => [],
        'lruRecords' => [],
    ];

    // 读写分离锁属性
    private array $heldLocks = [];
    private int $readLockCount = 0;
    private bool $writeWaiting = false;
    private array $semaphores = [];
    private readonly int $semKeyBase;
    private mixed $rwSemaphore = false;

    /** 写互斥专用信号量（max_acquire=1；rwSemaphore 是 max_acquire=1000，只 acquire 一次并不互斥） */
    private mixed $writeSemaphore = false;

    // LRU与GC属性
    private int $lastGcTime = 0;
    private readonly int $gcInterval;
    private array $gcState = []; // 增量GC状态

    // 异步落盘属性
    private readonly bool $enableDump;
    private readonly string $dumpFile;
    private readonly int $dumpInterval;
    private bool $dirty = false;
    private float $lastDumpTime;
    private array $dirtyKeys = []; // 按前缀隔离的脏数据键列表
    private readonly string $dumpLockName;
    private readonly int $prefixCrc32;
    private static array $dumpCheckedMap = [];
    protected string $cachePrefix = '';

    /**
     * Shmop 驱动热缓存桶
     */
    protected function &dualHotCacheBucket(?string $dbAlias): array
    {
        return $this->shmopHotBucket;
    }

    /**
     *   construct
     * @param mixed $config
     * @return mixed
     */
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
        // 占位：真正的赋值放在 blockSize 之后（readonly 属性只允许写一次）
        $this->blockSize = $config['blockSize'] ?? self::SEG_DEFAULT_BLOCK_SIZE;
        // 槽位数缺省（0/未配置）时按段大小与块大小自动推导，保证「槽位 ≈ 块数÷0.8」自洽
        $this->maxVarNumber = ((int) ($config['maxVarNumber'] ?? 0)) > 0
            ? (int) $config['maxVarNumber']
            : self::segmentResolveSlotCount($this->segmentSize, $this->blockSize, $this->segmentIndexHeaderLength());
        // 惰性分区：数据区按 partitionSize 增长，不再一次性申请 segmentSize
        $this->partitionSize = max(1024 * 1024, (int) ($config['partitionSize'] ?? 4 * 1024 * 1024));
        $this->maxPartitions = max(1, (int) ($config['maxPartitions'] ?? 16));
        $this->gcInterval = $config['gcInterval'] ?? 10;
        $this->prefixCrc32 = crc32($this->cachePrefix);

        // 索引区固定长度计算
        $this->indexAreaFixedSize = self::INDEX_HEADER_LENGTH + $this->maxVarNumber * self::SEG_INDEX_LENGTH;
        $this->metaAreaSize = $this->maxVarNumber * self::SEG_META_LENGTH;

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
        // 写互斥专用信号量：rwSemaphore 的 max_acquire 是 1000，只 acquire 一次不会阻塞，
        // 多进程会同时进入写临界区 → bitmap / 空闲槽位栈互相覆盖。这里用 max_acquire=1 单独一把。
        $this->writeSemaphore = sem_get($this->segmentKey + 5000, 1, self::SEM_PERMISSION, true);
        if ($this->writeSemaphore === false) {
            throw new \RuntimeException('写锁信号量创建失败');
        }

        // 内存空间合法性校验
        $initNeedSize = self::SEG_HEADER_SIZE + $this->indexAreaFixedSize + $this->metaAreaSize;
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
            // loadDump 会写段 → 必须在「段初始化 + 写信号量就绪」之后调用（见构造尾部）
            register_shutdown_function([$this, 'autoDump']);
        }

        // —— 惰性分区：先算出控制段大小（header + index + bitmap + meta），数据区另按分区惰性申请 ——
        $fixedOverhead   = self::SEG_HEADER_SIZE + $this->indexAreaFixedSize + $this->metaAreaSize;
        $availableSpace  = $this->segmentSize - $fixedOverhead;
        $this->bitmapAreaSize      = (int)ceil($availableSpace / ($this->blockSize * self::SEG_BITS_PER_BYTE + 1));
        $this->totalBlockCount     = $this->bitmapAreaSize * self::SEG_BITS_PER_BYTE;
        $this->blocksPerPartition  = (int)max(1, intdiv($this->partitionSize, $this->blockSize));
        $this->controlSize         = $this->alignOffset($fixedOverhead + $this->bitmapAreaSize);
        // 必须在任何读写之前确立边界，否则控制段读写会被误路由到分区 0
        $this->dataAreaStart       = $this->controlSize;

        // 共享内存段初始化（只申请控制段，数据区按需）
        $isNewCreate = false;
        try {
            $this->shmId = @shmop_open($this->segmentKey, 'w', 0, 0);
            if ($this->shmId === false) {
                $isNewCreate = true;
                $this->shmId = @shmop_open($this->segmentKey, 'c', $this->segmentMode, $this->controlSize);
                if ($this->shmId === false) {
                    throw new \RuntimeException('共享内存创建失败');
                }
            } else {
                // 控制段实际大小不一定等于容量预算，保留配置里的 segmentSize（容量预算）
                $this->controlSize = (int) shmop_size($this->shmId);
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
                $this->header['magic_number'] !== self::SEG_MAGIC_NUMBER
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
            $this->totalBlockCount = $this->bitmapAreaSize * self::SEG_BITS_PER_BYTE;
            self::segmentWarnSlotMismatch(
                $this->maxVarNumber,
                $this->totalBlockCount,
                $this->blockSize,
                $this->segmentSize,
                $this->segmentIndexHeaderLength()
            );
            $this->dataAreaStart = $this->alignOffset(self::SEG_HEADER_SIZE + $this->indexAreaFixedSize + $this->bitmapAreaSize + $this->metaAreaSize);
        }

        // 恢复分区数：靠探测「segmentKey + 1000 + i」是否存在推导（reserved 已用于写序号）
        $this->partitionCount = $isNewCreate ? 0 : $this->detectPartitionCount();
        // 记录当前写序号基线
        $this->syncedSeq = $isNewCreate ? 0 : $this->readHeaderSeq();

        // 一次IO读取索引+位图
        $this->loadIndexAndBitmap();
        $this->rebuildLru();
        $this->segmentGcInitState();

        // 落盘恢复：必须放在段初始化与写信号量之后，否则 acquireWriteLock 拿到的是 false 信号量，
        // 在 lockOutTime=-1（阻塞模式）下会无限 usleep 重试 → 进程挂死。
        $this->loadDump();

        register_shutdown_function([$this, '__releaseAllLocksOnShutdown']);
    }
    
    /**
     * 对内存偏移量进行4KB页对齐
     * @param int $offset 偏移量
     * @return int 页对齐后的偏移量
     */
    private function alignOffset(int $offset): int
    {
        return (int)ceil($offset / self::PAGE_SIZE) * self::PAGE_SIZE;
    }
    
    
    /**
     * 从共享内存读取索引+位图
     * @return void
     */
    private function loadIndexAndBitmap(): void
    {
        $this->indexMap = [];
        $this->dirtyBitmapPages = [];
        $this->freeMetaOrders = [];
        $this->metaToMemKey = []; // 初始化反向映射

        // 读取索引区头部
        $indexHeaderBin = $this->readFromCache(self::SEG_HEADER_SIZE, self::INDEX_HEADER_LENGTH);
        $indexHeader = unpack('nbitmap_len/nfree_head/nfree_tail', $indexHeaderBin);
        $this->bitmapAreaSize = $indexHeader['bitmap_len'];

        // 一次读取完整索引+位图
        $totalReadLength = $this->indexAreaFixedSize + $this->bitmapAreaSize;
        $indexAndBitmap = $this->readFromCache(self::SEG_HEADER_SIZE, $totalReadLength);

        // 拆分索引与位图
        $indexBody = substr($indexAndBitmap, self::INDEX_HEADER_LENGTH, $this->maxVarNumber * self::SEG_INDEX_LENGTH);
        $this->bitmap = substr($indexAndBitmap, $this->indexAreaFixedSize);

        // 解析索引映射和空闲槽位
        $indexArray = str_split($indexBody, self::SEG_INDEX_LENGTH);
        foreach ($indexArray as $order => $chunk) {
            if (strlen($chunk) < self::SEG_INDEX_LENGTH) break;
            $item = unpack(self::SEG_INDEX_UNPACK_FORMAT, $chunk);
            if (!$item) continue;
            if ($item['meta_order'] === self::SEG_INDEX_ORDER_FREE) {
                $this->freeMetaOrders[] = $order;
            } else {
                // 构建与 getCrc32Key 一致的 memKey
                $memKey = $item['crc32'] . '_' . $item['v_crc32'];
                $this->indexMap[$memKey] = $item['meta_order'];
                $this->metaToMemKey[$item['meta_order']] = $memKey; // 建立反向映射
            }
        }
    }

    


    




    
    /**
     * 更新索引头部信息
     * @return void
     */
    private function updateIndexHeader(): void
    {
        // 简化版，Shmop主要用freeMetaOrders数组
        $headerBin = pack('nnn', $this->bitmapAreaSize, 0, 0);
        $this->writeToCache($headerBin, self::SEG_HEADER_SIZE);
    }











    /**
     * 初始化段（创建新的共享内存结构）
     * @return void
     */
    private function initSegment(): void
    {
        try {
            // 动态计算区块大小
            $fixedOverhead = self::SEG_HEADER_SIZE + $this->indexAreaFixedSize + $this->metaAreaSize;
            $availableSpace = $this->segmentSize - $fixedOverhead;
            $this->bitmapAreaSize = (int)ceil($availableSpace / ($this->blockSize * self::SEG_BITS_PER_BYTE + 1));
            $this->totalBlockCount = $this->bitmapAreaSize * self::SEG_BITS_PER_BYTE;
            self::segmentWarnSlotMismatch(
                $this->maxVarNumber,
                $this->totalBlockCount,
                $this->blockSize,
                $this->segmentSize,
                $this->segmentIndexHeaderLength()
            );
            $this->dataAreaStart = $this->alignOffset($fixedOverhead + $this->bitmapAreaSize);

            // 写入固定头部
            $this->header = [
                'magic_number'  => self::SEG_MAGIC_NUMBER,
                'version'       => self::VERSION,
                'data_capacity' => $this->segmentSize,
                'writing_flag'  => self::WRITING_FLAG_OK,
                'writing_start_time' => 0,
                'block_size'    => $this->blockSize,
                'max_var_number' => $this->maxVarNumber,
                'bitmap_length' => $this->bitmapAreaSize,
                'reserved'      => 0
            ];
            $packedHeader = pack(self::SEG_HEADER_FORMAT, ...array_values($this->header));
            $this->writeToCache($packedHeader, 0);

            // 初始化索引区（全空闲）
            $indexHeader = pack('nnn', $this->bitmapAreaSize, 0, 0);
            $indexBody = str_repeat(pack(self::SEG_INDEX_FORMAT, 0, 0, self::SEG_INDEX_ORDER_FREE), $this->maxVarNumber);
            $this->writeToCache($indexHeader . $indexBody, self::SEG_HEADER_SIZE);

            // 初始化位图区（全空闲）
            $this->bitmap = str_repeat("\0", $this->bitmapAreaSize);
            $this->dirtyBitmapPages = [];
            $this->writeToCache($this->bitmap, self::SEG_HEADER_SIZE + $this->indexAreaFixedSize);

            // 初始化元数据区
            $this->writeToCache(str_repeat("\0", $this->metaAreaSize), self::SEG_HEADER_SIZE + $this->indexAreaFixedSize + $this->bitmapAreaSize);

            // 清空内存缓存
            $this->clearMetaCache();
            $this->indexMap = [];
            $this->metaToMemKey = [];
            $this->shmopHotBucket['hotCache'] = [];
            $this->shmopHotBucket['lruRecords'] = [];
            $this->lastGcTime = time();
            $this->segmentGcInitState();
        } catch (Throwable $e) {
            throw new \RuntimeException('共享内存初始化失败：' . $e->getMessage());
        }
    }

    /**
     * 初始化头部数据
     * @return void
     */
    private function initHeaderData(): void
    {
        if (empty($this->header)) {
            $data = $this->readFromCache(0, self::SEG_HEADER_SIZE);
            $this->header = $data ? (array)unpack(self::SEG_HEADER_UNPACK_FORMAT, $data) : [];
        }
    }

    /**
     * 检查并重置写入标志
     * @return void
     */
    private function checkAndResetWritingFlag(): void
    {
        if (
            isset($this->header['writing_flag'])
            && $this->header['writing_flag'] === self::WRITING_FLAG_WRITING
        ) {
            $writingStartTime = $this->header['writing_start_time'] ?? 0;
            if (time() - $writingStartTime > self::WRITING_FLAG_TIMEOUT) {
                $this->headerValue('writing_flag', self::WRITING_FLAG_OK);
            }
        }
    }

    /**
     * 获取或设置头部值
     * @param string|array $key 键名或键值对数组
     * @param mixed $value 值（设置时使用）
     * @return mixed 获取时返回值，设置时返回true
     */
    private function headerValue(string|array $key, mixed $value = ''): mixed
    {
        $this->initHeaderData();
        if (!empty($value) || is_array($key)) {
            $updateArray = is_array($key) ? $key : [$key => $value];
            foreach ($updateArray as $k => $v) {
                if (isset($this->header[$k])) $this->header[$k] = $v;
            }
            $packed = pack(self::SEG_HEADER_FORMAT, ...array_values($this->header));
            $this->writeToCache($packed, 0);
            return true;
        }
        return empty($key) ? $this->header : ($this->header[$key] ?? null);
    }

    /**
     * 获取分片锁名称
     * @param string $key 原始键
     * @return string 分片锁名称
     */
    private function getShardLockName(string $key): string
    {
        $hash = crc32($key);
        $shardIndex = $hash % self::SHARD_COUNT;
        return 'shmop_shard_' . $shardIndex;
    }
    
    /**
     * 获取读锁（共享锁）
     * @return bool 是否获取成功
     */
    private function acquireReadLock(): bool
    {
        $startTime = microtime(true);
        $isBlock = $this->lockOutTime === -1;

        do {
            // 有写锁等待，禁止新的读锁，解决写饥饿
            if ($this->writeWaiting) {
                usleep($this->retryInterval);
                continue;
            }

            if (sem_acquire($this->rwSemaphore, $this->lockOutTime === 0)) {
                $this->readLockCount++;
                $this->heldLocks['__read_lock'] = ($this->heldLocks['__read_lock'] ?? 0) + 1;
                return true;
            }

            if ($this->lockOutTime === 0) break;
            $costMs = (int)((microtime(true) - $startTime) * 1000);
            if (!$isBlock && $costMs >= $this->lockOutTime) break;
            usleep($this->retryInterval);
        } while (true);

        return false;
    }

    /**
     * 释放读锁（共享锁）
     * @return bool 是否释放成功
     */
    private function releaseReadLock(): bool
    {
        if (!isset($this->heldLocks['__read_lock']) || $this->heldLocks['__read_lock'] <= 0) {
            return true;
        }

        $this->heldLocks['__read_lock']--;
        if ($this->heldLocks['__read_lock'] === 0) {
            unset($this->heldLocks['__read_lock']);
        }

        $this->readLockCount--;
        return sem_release($this->rwSemaphore);
    }

    /**
     * 获取写锁（排他锁）
     * @return bool 是否获取成功
     */
    private function acquireWriteLock(): bool
    {
        // 可重入：同一进程内嵌套加锁（如 forceDump 持锁期间调 set()）不能再去 sem_acquire，
        // 否则 max_acquire=1 的信号量会被自己锁死（lockOutTime=-1 时无限阻塞）。
        if (isset($this->heldLocks['__write_lock'])) {
            $this->heldLocks['__write_lock']++;
            return true;
        }

        $startTime = microtime(true);
        $isBlock = $this->lockOutTime === -1;
        $this->writeWaiting = true;

        do {
            // 等待所有读锁释放，获取全量排他锁（用 max_acquire=1 的专用信号量才真正互斥）
            if (sem_acquire($this->writeSemaphore, $this->lockOutTime === 0)) {
                $this->heldLocks['__write_lock'] = 1;
                $this->writeWaiting = false;
                return true;
            }

            if ($this->lockOutTime === 0) break;
            $costMs = (int)((microtime(true) - $startTime) * 1000);
            if (!$isBlock && $costMs >= $this->lockOutTime) break;
            usleep($this->retryInterval);
        } while (true);

        $this->writeWaiting = false;
        return false;
    }

    /**
     * 释放写锁（排他锁）
     * @return bool 是否释放成功
     */
    private function releaseWriteLock(): bool
    {
        if (!isset($this->heldLocks['__write_lock'])) {
            return true;
        }

        // 可重入计数：内层释放在这里递减，只有最外层才真正 sem_release
        $depth = (int) $this->heldLocks['__write_lock'];
        if ($depth > 1) {
            $this->heldLocks['__write_lock'] = $depth - 1;
            return true;
        }

        unset($this->heldLocks['__write_lock']);
        $this->writeWaiting = false;
        
        // 安全释放：只归还实际获取的信号量数量
        // 读锁使用1个信号量，写锁需要排他，释放1次即可
        $result = @sem_release($this->writeSemaphore);
        
        return $result;
    }

    /**
     * 兼容原有锁接口（写锁）
     * @param string $lockName 锁名称
     * @param int|null $timeout 超时时间（毫秒）
     * @return bool 是否获取成功
     */
    public function __acquireLock(string $lockName, ?int $timeout = null): bool
    {
        return $this->acquireWriteLock();
    }

    /**
     * 兼容原有锁接口（释放锁）
     * @param string $lockName 锁名称
     * @return bool 是否释放成功
     */
    public function __releaseLock(string $lockName): bool
    {
        return $this->releaseWriteLock();
    }
    

    /**
     * 重建LRU记录
     * @return void
     */
    private function rebuildLru(): void
    {
        $lru = [];
        foreach ($this->indexMap as $memKey => $metaOrder) {
            $meta = $this->getMetaByOrder($metaOrder);
            if ($meta && $meta['status'] === 1) {
                $lru[$memKey] = ['ckey' => '', 'time' => $meta['last_use'] ?? time()];
            }
        }
        uasort($lru, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
        $this->shmopHotBucket['lruRecords'] = $lru;
    }


    /**
     * 写入共享内存
     * @param string $data 数据
     * @param int $offset 偏移量
     * @return bool 写入是否成功
     */
    private function writeToCache(string $data, int $offset): bool
    {
        $dataLength = strlen($data);
        if ($offset < 0 || $dataLength <= 0) {
            throw new \RuntimeException('共享内存写入越界');
        }
        // 控制段（header / index / bitmap / meta）
        if ($offset + $dataLength <= $this->controlSize) {
            if ($offset + $dataLength > $this->controlSize) {
                throw new \RuntimeException('控制段写入越界');
            }
            return shmop_write($this->shmId, $data, $offset) === $dataLength;
        }
        // 数据区：虚拟偏移 → (区号, 区内偏移)
        $parts = $this->splitDataOffset($offset);
        if ($parts === false) {
            throw new \RuntimeException('共享内存写入越界');
        }
        [$part, $inner] = $parts;
        if ($inner + $dataLength > $this->partitionSize) {
            throw new \RuntimeException('单个条目跨越分区边界');
        }
        $shm = $this->dataSegment($part, true);
        if ($shm === false) {
            return false;
        }
        return shmop_write($shm, $data, $inner) === $dataLength;
    }

    /**
     * 把「以 dataAreaStart 为基准的虚拟偏移」拆成 [区号, 区内偏移]
     *
     * @param int $offset 虚拟偏移
     * @return array{0:int,1:int}|false
     */
    private function splitDataOffset(int $offset): array|false
    {
        $virtual = $offset - $this->dataAreaStart;
        if ($virtual < 0) {
            return false;
        }
        $part  = intdiv($virtual, $this->partitionSize);
        $inner = $virtual % $this->partitionSize;
        if ($part < 0 || $part >= $this->maxPartitions) {
            return false;
        }
        return [$part, $inner];
    }

    /**
     * 取数据分区句柄（$create=true 时按需申请）
     *
     * @param int $part 区号
     * @param bool $create 是否允许新建
     * @return \Shmop|false
     */
    private function dataSegment(int $part, bool $create = false)
    {
        if (isset($this->dataShms[$part])) {
            return $this->dataShms[$part];
        }
        $key = $this->segmentKey + 1000 + $part;
        $shm = @shmop_open($key, 'w', 0, 0);
        if ($shm === false) {
            if (!$create) {
                return false;
            }
            $shm = @shmop_open($key, 'c', $this->segmentMode, $this->partitionSize);
            if ($shm === false) {
                return false;
            }
            if ($part >= $this->partitionCount) {
                $this->partitionCount = $part + 1;
            }
        }
        return $this->dataShms[$part] = $shm;
    }

    /**
     * 探测已创建的数据分区数（区段按序号连续创建，取最大存在区号 + 1）
     */
    private function detectPartitionCount(): int
    {
        $count = 0;
        for ($i = 0; $i < $this->maxPartitions; $i++) {
            $shm = @shmop_open($this->segmentKey + 1000 + $i, 'w', 0, 0);
            if ($shm === false) {
                continue;
            }
            @shmop_close($shm);
            $count = $i + 1;
        }

        return $count;
    }

    /**
     * 轻量刷新分区数：从当前计数向前探测（通常 1 次 shmop_open），
     * 避免每次强制重载都扫满 maxPartitions 个段（16 次系统调用 × 每次写 = 吞吐灾难）
     */
    private function refreshPartitionCount(): void
    {
        $probe = $this->partitionCount;
        while ($probe < $this->maxPartitions) {
            $shm = @shmop_open($this->segmentKey + 1000 + $probe, 'w', 0, 0);
            if ($shm === false) {
                break;
            }
            @shmop_close($shm);
            $probe++;
        }
        if ($probe > $this->partitionCount) {
            $this->partitionCount = $probe;
        }
    }

    /**
     * 读取段头写入序号（复用 header 的 reserved 字段）
     */
    private function readHeaderSeq(): int
    {
        if (!$this->shmId) {
            return -1;
        }
        $raw = @shmop_read($this->shmId, 0, self::SEG_HEADER_SIZE);
        if (!is_string($raw) || strlen($raw) < self::SEG_HEADER_SIZE) {
            return -1;
        }
        $unpacked = @unpack(self::SEG_HEADER_UNPACK_FORMAT, $raw);

        return is_array($unpacked) ? (int) $unpacked['reserved'] : -1;
    }

    /**
     * 读前同步：序号变了就重载索引与位图（跨进程一致性）
     */
    protected function segmentSyncBeforeRead(?string $dbAlias = null): void
    {
        $seq = $this->readHeaderSeq();
        if ($seq < 0 || $seq === $this->syncedSeq) {
            return;
        }
        $this->initHeaderData();
        $this->loadIndexAndBitmap();
        $this->rebuildLru();
        $this->syncedSeq = $seq;
    }

    /**
     * 写锁内的强制同步：不看序号，无条件按磁盘现状重建 index / bitmap / 空闲槽位栈
     */
    protected function segmentForceSyncBeforeWrite(?string $dbAlias = null): void
    {
        if (!$this->shmId) {
            return;
        }
        $this->initHeaderData();
        $this->loadIndexAndBitmap();
        $this->rebuildLru();
        // 分区数是进程内状态：别的进程可能刚创建了新分区，
        // 不刷新会让两个进程都认为「下一个新区号」是同一个 → 抢同一分区 → 分配相同块 → 覆盖
        $this->refreshPartitionCount();
        $this->syncedSeq = $this->readHeaderSeq();
    }

    /**
     * 写后同步：递增段头写入序号（供其它进程检测变更）
     */
    protected function segmentTouchAfterWrite(?string $dbAlias = null): void
    {
        if (!$this->shmId) {
            return;
        }
        $seq = $this->readHeaderSeq();
        if ($seq < 0) {
            $seq = 0;
        }
        $seq++;
        if (is_array($this->header) && $this->header !== []) {
            $this->header['reserved'] = $seq;
            $packed = pack(self::SEG_HEADER_FORMAT, ...array_values($this->header));
            @shmop_write($this->shmId, $packed, 0);
        }
        $this->syncedSeq = $seq;
    }

    /**
     * 从共享内存读取数据
     * @param int $offset 偏移量
     * @param int $length 长度
     * @return string|false 读取的数据，失败返回false
     */
    private function readFromCache(int $offset, int $length): string|false
    {
        if ($offset < 0 || $length <= 0) {
            return false;
        }
        if ($offset + $length <= $this->controlSize) {
            if ($offset + $length > $this->controlSize) {
                return false;
            }
            return shmop_read($this->shmId, $offset, $length);
        }
        $parts = $this->splitDataOffset($offset);
        if ($parts === false) {
            return false;
        }
        [$part, $inner] = $parts;
        if ($inner + $length > $this->partitionSize) {
            return false;
        }
        $shm = $this->dataSegment($part, false);
        if ($shm === false) {
            return false;
        }
        return shmop_read($shm, $inner, $length);
    }
    
    /**
     * 异步落盘（定时检查并落盘）
     * @return void
     */
    public function autoDump(): void
    {
        if (!$this->enableDump || !$this->dirty || microtime(true) - $this->lastDumpTime < $this->dumpInterval) {
            return;
        }
        $this->forceDump();
    }

    /**
     * 强制落盘（对齐 Memory 的前缀隔离增量落盘）
     * @return bool 落盘是否成功
     */
    public function forceDump(): bool
    {
        if (!$this->enableDump || !$this->shmId) return false;
        if (!$this->acquireWriteLock()) return false;

        try {
            $data = [];
            $now = time();
            
            // 统计脏 key，决定走增量还是全量
            $dirtyCount = 0;
            foreach ($this->dirtyKeys as $keys) {
                $dirtyCount += count($keys);
            }
            $totalCount = count($this->indexMap);

            // 增量只在「有旧快照可合并」且「脏 key 不超过一半」时用；
            // 脏太多时读旧文件+合并的成本已接近全量，不如直接全量。
            $useIncremental = $dirtyCount > 0
                && $dirtyCount <= (int) ceil($totalCount / 2)
                && is_file($this->dumpFile);

            if ($useIncremental) {
                // ★ 增量落盘必须「读旧 + 合并 + 写回」。
                // 原实现只收集脏 key 就整文件覆盖 —— 没变的 key 会被全部丢掉（静默数据丢失）。
                $dumpBase = Binary::loadDump($this->dumpFile, self::DUMP_MAGIC, $this->prefixCrc32);
                $data = is_array($dumpBase) ? $dumpBase : [];

                // 按前缀隔离增量落盘脏数据
                foreach ($this->dirtyKeys as $prefix => $keys) {
                    foreach ($keys as $memKey => $isDirty) {
                        $meta = $this->getMetaByOrder($this->indexMap[$memKey] ?? 0);
                        // 已删除 / 已过期 → 从旧快照里移除，否则它会永久留在 dump 里
                        if (!$meta || $meta['status'] !== 1
                            || ($meta['timestamp'] > 0 && $meta['timestamp'] < $now)) {
                            unset($data[$memKey]);
                            continue;
                        }
                        if ($memKey === $this->getCrc32Key(self::SYSTEM_LAST_UPDATE_KEY)[0]) continue;

                        $raw = $this->readFromCache($meta['offset'], $meta['string_length']);
                        if ($raw === false) {
                            unset($data[$memKey]);
                            continue;
                        }
                        $unpacked = Binary::tryUnpack($raw);
                        if (!is_array($unpacked)) {
                            unset($data[$memKey]);
                            continue;
                        }
                        $ttl = $meta['timestamp'] > 0 ? max(0, $meta['timestamp'] - $now) : 0;

                        $data[$memKey] = [
                            'value'  => $unpacked['data'] ?? null,
                            'expire' => $ttl > 0 ? $now + $ttl : 0,
                            'mem_key' => $memKey
                        ];
                    }
                }

                // 兜底：清掉旧快照里已过期、但这轮没被标脏的项
                foreach ($data as $memKey => $item) {
                    if (($item['expire'] ?? 0) > 0 && $item['expire'] < $now) {
                        unset($data[$memKey]);
                    }
                }
            } else {
                // 全量落盘：当没有脏数据时，仍然执行全量落盘
                foreach ($this->indexMap as $memKey => $metaOrder) {
                    $meta = $this->getMetaByOrder($metaOrder);
                    if (!$meta || $meta['status'] !== 1) continue;
                    if ($meta['timestamp'] > 0 && $meta['timestamp'] < $now) continue;
                    if ($memKey === $this->getCrc32Key(self::SYSTEM_LAST_UPDATE_KEY)[0]) continue;

                    $raw = $this->readFromCache($meta['offset'], $meta['string_length']);
                    if ($raw === false) continue;
                    $unpacked = Binary::tryUnpack($raw);
                    if (!is_array($unpacked)) continue;
                    $ttl = $meta['timestamp'] > 0 ? max(0, $meta['timestamp'] - $now) : 0;

                    $data[$memKey] = [
                        'value'  => $unpacked['data'] ?? null,
                        'expire' => $ttl > 0 ? $now + $ttl : 0,
                        'mem_key' => $memKey
                    ];
                }
            }

            $binBody    = Binary::pack($data);
            $dataLength = strlen($binBody);
            $dumpTime   = time();
            $dataCrc32  = crc32($binBody);

            $header = pack(
                'NCNNNN',
                self::DUMP_MAGIC,
                self::DUMP_VERSION,
                $dumpTime,
                $this->prefixCrc32,
                $dataLength,
                $dataCrc32
            );
            $header = str_pad($header, self::DUMP_HEADER_SIZE, "\0");

            $tmpFile = $this->dumpFile . '.tmp';
            $writeResult = file_put_contents($tmpFile, $header . $binBody, LOCK_EX);

            if ($writeResult !== false) {
                @rename($tmpFile, $this->dumpFile);
                $this->updateMemoryLastUpdateTime($dumpTime);
                $this->dirty = false;
                $this->dirtyKeys = []; // 清空脏数据列表（按前缀隔离）
                $this->lastDumpTime = microtime(true);
                return true;
            }

            @unlink($tmpFile);
            return false;
        } catch (Throwable) {
            return false;
        } finally {
            $this->releaseWriteLock();
        }
    }

    /**
     * 加载落盘文件（修复版：避免递归锁，直接操作）
     * @return void
     */
    public function loadDump(): void
    {
        // 未启用落盘时 dumpFile 从未赋值（typed property），必须提前返回
        if (!$this->enableDump) {
            return;
        }

        $dumpUniqueKey = md5($this->dumpFile);
        if (isset(self::$dumpCheckedMap[$dumpUniqueKey])) {
            return;
        }

        if (
            !$this->enableDump
            || !$this->shmId
            || !is_file($this->dumpFile)
            || !is_readable($this->dumpFile)
        ) {
            self::$dumpCheckedMap[$dumpUniqueKey] = true;
            return;
        }

        $fileSize = filesize($this->dumpFile);
        if ($fileSize < self::DUMP_HEADER_SIZE) {
            self::$dumpCheckedMap[$dumpUniqueKey] = true;
            return;
        }

        $fp = fopen($this->dumpFile, 'rb');
        if (!$fp) {
            self::$dumpCheckedMap[$dumpUniqueKey] = true;
            return;
        }

        try {
            $headerBin = fread($fp, self::DUMP_HEADER_SIZE);
            $header = unpack('Nmagic/Cversion/NdumpTime/NprefixCrc/NdataLen/NdataCrc', $headerBin);
            if (
                $header['magic'] !== self::DUMP_MAGIC
                || $header['version'] !== self::DUMP_VERSION
                || $header['prefixCrc'] !== $this->prefixCrc32
                || $fileSize !== self::DUMP_HEADER_SIZE + $header['dataLen']
            ) {
                return;
            }

            $memoryLastUpdateTime = $this->getMemoryLastUpdateTime();
            if ($memoryLastUpdateTime >= $header['dumpTime']) {
                self::$dumpCheckedMap[$dumpUniqueKey] = true;
                return;
            }

            $binBody = fread($fp, $header['dataLen']);
            if (crc32($binBody) !== $header['dataCrc']) return;

            $data = Binary::tryUnpack($binBody);
            if (!is_array($data)) return;

            $now = time();
            foreach ($data as $memKey => $entry) {
                // 修复：检查mem_key格式
                if (!isset($entry['value'], $entry['expire']) || !isset($entry['mem_key'])) continue;
                
                $realMemKey = $entry['mem_key'];
                $expireTimestamp = $entry['expire'];
                if ($expireTimestamp > 0 && $expireTimestamp < $now) continue;
                
                // 检查是否已存在
                if (isset($this->indexMap[$realMemKey])) continue;
                
                // 解析memKey获取crc32和vCrc32
                if (!str_contains($realMemKey, '_')) continue;
                [$crc32, $vCrc32] = explode('_', $realMemKey);
                $crc32 = (int)$crc32;
                $vCrc32 = (int)$vCrc32;
                
                // 直接写入，不调用__rawSet避免锁递归
                $ttl = $expireTimestamp > 0 ? max(0, $expireTimestamp - $now) : 0;
                $this->writeRawDataByKeyDirect($realMemKey, $crc32, $vCrc32, $entry['value'], $ttl);
            }

            self::$dumpCheckedMap[$dumpUniqueKey] = true;
            $this->dirty = false;
        } catch (Throwable) {
            return;
        } finally {
            fclose($fp);
        }
    }
    
    /**
     * 直接写入原始数据（用于loadDump，避免锁递归）
     * @param string $memKey 内存Key
     * @param int $crc32 CRC32
     * @param int $vCrc32 vCRC32
     * @param mixed $value 值
     * @param int $ttl TTL
     * @return bool
     */
    private function writeRawDataByKeyDirect(string $memKey, int $crc32, int $vCrc32, mixed $value, int $ttl): bool
    {
        $serializedData = Binary::pack(['data' => $value]);
        $dataLength = strlen($serializedData);
        $dataCrc32 = crc32($serializedData);

        $dataOffset = $this->allocateSpace($dataLength);
        if ($dataOffset === false) return false;

        $this->writeToCache($serializedData, $dataOffset);
        
        $expireTimestamp = $ttl > 0 ? time() + $ttl : 0;
        $newMeta = [
            'status' => 1,
            'timestamp' => $expireTimestamp,
            'block_length' => $dataLength, // 存字节数
            'string_length' => $dataLength,
            'offset' => $dataOffset,
            'last_use' => time(),
            'data_crc32' => $dataCrc32
        ];

        $useMetaOrder = $this->findFreeMetaOrder();
        if ($useMetaOrder === false) return false;
        $this->removeFreeIndex($useMetaOrder);

        $this->writeMetaByOrder($useMetaOrder, $newMeta);
        $this->updateIndex($memKey, $crc32, $vCrc32, $useMetaOrder);
        return true;
    }

    /**
     * 获取内存最后更新时间
     * @return int 时间戳
     */
    private function getMemoryLastUpdateTime(): int
    {
        [$memKey, ,] = $this->getCrc32Key(self::SYSTEM_LAST_UPDATE_KEY);
        if (!isset($this->indexMap[$memKey])) return 0;

        $meta = $this->getMetaByOrder($this->indexMap[$memKey]);
        if (!$meta) return 0;

        $raw = $this->readFromCache($meta['offset'], $meta['string_length']);
        if ($raw === false) return 0;

        $unpacked = Binary::tryUnpack($raw);
        return is_array($unpacked) && isset($unpacked['data']) && is_int($unpacked['data'])
            ? $unpacked['data']
            : 0;
    }

    /**
     * 更新内存最后更新时间
     * @param int $timestamp 时间戳
     * @return void
     */
    private function updateMemoryLastUpdateTime(int $timestamp): void
    {
        $this->__rawSet(self::SYSTEM_LAST_UPDATE_KEY, $timestamp, 0);
    }
    







    




    /**
     * 清空缓存
     * @return bool 清空是否成功
     */
    public function __clear(): bool
    {
        if (!$this->acquireWriteLock()) return false;
        try {
            $this->initSegment();
            $this->loadIndexAndBitmap();
            $this->dirty = true;
            return true;
        } finally {
            $this->releaseWriteLock();
        }
    }
    

    


    // SegmentStreamOps 桥接
    /**
     * Segment Stream Load
     * @param mixed $key
     * @return mixed
     */
    protected function segmentStreamLoad(string $key): mixed
    {
        return $this->readRawDataByKey($key);
    }

    /**
     * Segment Stream Save
     * @param mixed $key
     * @param mixed $stream
     * @return bool
     */
    protected function segmentStreamSave(string $key, array $stream): bool
    {
        $ok = $this->writeRawDataByKey($key, $stream, 0, true);
        if ($ok) {
            $this->dirty = true;
        }
        return $ok;
    }

    /**
     * Segment Stream With Write Lock
     * @param mixed $key
     * @param mixed $fn
     * @return mixed
     */
    protected function segmentStreamWithWriteLock(string $key, callable $fn): mixed
    {
        if (!$this->acquireWriteLock()) {
            return false;
        }

        try {
            return $fn();
        } finally {
            $this->releaseWriteLock();
        }
    }

    /**
     * Segment Stream With Read Lock
     * @param mixed $key
     * @param mixed $fn
     * @return mixed
     */
    protected function segmentStreamWithReadLock(string $key, callable $fn): mixed
    {
        if (!$this->acquireReadLock()) {
            return false;
        }

        try {
            return $fn();
        } finally {
            $this->releaseReadLock();
        }
    }

// Segment P1 桥接 — Format / Bitmap / MetaIndex
    /**
     * Segment Index Header Length
     * @return int
     */
    protected function segmentIndexHeaderLength(): int
    {
        return self::INDEX_HEADER_LENGTH;
    }

    /**
     * Segment Cache Prefix
     * @param mixed $dbAlias
     * @return string
     */
    protected function segmentCachePrefix(?string $dbAlias = null): string
    {
        return $this->cachePrefix;
    }

    protected function &segmentBitmap(?string $dbAlias = null): string
    {
        return $this->bitmap;
    }

    /**
     * Segment Total Block Count
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentTotalBlockCount(?string $dbAlias = null): int
    {
        return $this->totalBlockCount;
    }

    /**
     * Segment Block Size
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentBlockSize(?string $dbAlias = null): int
    {
        return $this->blockSize;
    }

    /**
     * Segment Data Area Start
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentDataAreaStart(?string $dbAlias = null): int
    {
        return $this->dataAreaStart;
    }

    /**
     * Segment On Bitmap Byte Dirty
     * @param mixed $byteOffset
     * @param mixed $dbAlias
     */
    protected function segmentOnBitmapByteDirty(int $byteOffset, ?string $dbAlias = null): void
    {
        $this->dirtyBitmapPages[$byteOffset] = true;
    }

    /**
     * Segment Use Chunk Bitmap Scan
     * @return bool
     */
    protected function segmentUseChunkBitmapScan(): bool
    {
        return true;
    }

    /**
     * Segment Persist Bitmap
     * @param mixed $dbAlias
     */
    protected function segmentPersistBitmap(?string $dbAlias = null): void
    {
        if ($this->dirtyBitmapPages === []) {
            return;
        }

        $bitmapOffset = self::SEG_HEADER_SIZE + $this->indexAreaFixedSize;
        foreach ($this->dirtyBitmapPages as $byteOffset => $_) {
            $this->writeToCache($this->bitmap[$byteOffset], $bitmapOffset + $byteOffset);
        }

        $this->dirtyBitmapPages = [];
        $this->updateIndexHeader();
    }

    protected function &segmentIndexMap(?string $dbAlias = null): array
    {
        return $this->indexMap;
    }

    protected function &segmentMetaToMemKey(?string $dbAlias = null): array
    {
        return $this->metaToMemKey;
    }

    protected function &segmentFreeMetaOrders(?string $dbAlias = null): array
    {
        return $this->freeMetaOrders;
    }

    protected function &segmentMetadataCache(?string $dbAlias = null): array
    {
        return $this->metadataCache;
    }

    protected function &segmentMetaLRU(?string $dbAlias = null): array
    {
        static $empty = [];
        return $empty;
    }

    /**
     * Segment Max Var Number
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentMaxVarNumber(?string $dbAlias = null): int
    {
        return $this->maxVarNumber;
    }

    /**
     * Segment Index Area Fixed Size
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentIndexAreaFixedSize(?string $dbAlias = null): int
    {
        return $this->indexAreaFixedSize;
    }

    /**
     * Segment Bitmap Area Size
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentBitmapAreaSize(?string $dbAlias = null): int
    {
        return $this->bitmapAreaSize;
    }

    /**
     * Segment Read Bytes
     * @param mixed $offset
     * @param mixed $length
     * @param mixed $dbAlias
     * @return string|false
     */
    /**
     * 分配空间（惰性分区版）
     *
     * 与 File 的区别：必须在**单个分区内**找到连续块，不允许跨区；
     * 现有分区都装不下时才申请新分区。
     *
     * @param int $needLength 需要的字节数
     * @param string|null $dbAlias
     * @return int|false 虚拟偏移（dataAreaStart + 全局块号 × blockSize）
     */
    protected function allocateSpace(int $needLength, ?string $dbAlias = null): int|false
    {
        if ($needLength <= 0) {
            return false;
        }
        $this->assertSegmentValueFits($needLength, $dbAlias);

        $blockSize = $this->blockSize;
        $needBlockCount = (int) ceil($needLength / $blockSize);
        $perPartition = $this->blocksPerPartition;

        if ($needBlockCount > $perPartition) {
            throw new \LengthException(sprintf(
                '缓存值过大：需要 %d 个块（%d 字节），但单个分区只有 %d 个块（partitionSize=%d, blockSize=%d）。'
                . '请减小写入的值，或调大 partitionSize。',
                $needBlockCount,
                $needLength,
                $perPartition,
                $this->partitionSize,
                $blockSize
            ));
        }

        // ① 现有分区里找（按区号顺序，区内 first-fit）
        for ($p = 0; $p < $this->partitionCount; $p++) {
            $start = $this->findFreeInPartition($p, $needBlockCount);
            if ($start === false) {
                continue;
            }
            if ($this->dataSegment($p, false) === false) {
                continue;
            }
            for ($i = 0; $i < $needBlockCount; $i++) {
                $this->bitSet($start + $i, true, $dbAlias);
            }
            $this->segmentPersistBitmap($dbAlias);

            return $this->dataAreaStart + $start * $blockSize;
        }

        // ② 没有分区装得下 → 先确认别的进程没抢着建新区
        $this->refreshPartitionCount();
        if ($this->partitionCount >= $this->maxPartitions || $this->partitionCount * $perPartition >= $this->totalBlockCount) {
            return false;
        }
        $p = $this->partitionCount;
        if ($this->dataSegment($p, true) === false) {
            return false;
        }
        $start = $p * $perPartition;
        for ($i = 0; $i < $needBlockCount; $i++) {
            $this->bitSet($start + $i, true, $dbAlias);
        }
        $this->segmentPersistBitmap($dbAlias);

        return $this->dataAreaStart + $start * $blockSize;
    }

    /**
     * 在指定分区内做 first-fit，返回全局块号起点
     *
     * @param int $part 区号
     * @param int $needBlockCount 需要的连续块数
     * @return int|false
     */
    private function findFreeInPartition(int $part, int $needBlockCount): int|false
    {
        $from = $part * $this->blocksPerPartition;
        $to   = min($from + $this->blocksPerPartition, $this->totalBlockCount);
        $run  = 0;
        for ($i = $from; $i < $to; $i++) {
            if (!$this->bitGet($i)) {
                $run++;
                if ($run >= $needBlockCount) {
                    return $i - $run + 1;
                }
            } else {
                $run = 0;
            }
        }

        return false;
    }

    protected function segmentReadBytes(int $offset, int $length, ?string $dbAlias = null): string|false
    {
        return $this->readFromCache($offset, $length);
    }

    /**
     * Segment Write Bytes
     * @param mixed $offset
     * @param mixed $data
     * @param mixed $dbAlias
     * @return bool
     */
    protected function segmentWriteBytes(int $offset, string $data, ?string $dbAlias = null): bool
    {
        return $this->writeToCache($data, $offset);
    }

/**
 * Segment Meta Area Size
 * @param mixed $dbAlias
 * @return int
 */
protected function segmentMetaAreaSize(?string $dbAlias = null): int
    {
        return $this->metaAreaSize;
    }

    /**
     * Segment Gc Try Begin
     * @param mixed $excludeKey
     * @param mixed $needLength
     * @param mixed $dbAlias
     * @return bool
     */
    protected function segmentGcTryBegin(string $excludeKey, int $needLength, ?string $dbAlias = null): bool
    {
        $now = time();
        if ($needLength === 0 && $now - $this->lastGcTime < $this->gcInterval) {
            return false;
        }
        if ($this->headerValue('writing_flag') === self::WRITING_FLAG_WRITING) {
            return false;
        }
        $this->headerValue([
            'writing_flag' => self::WRITING_FLAG_WRITING,
            'writing_start_time' => $now,
        ]);
        return true;
    }

    /**
     * Segment Gc Finish
     * @param mixed $dbAlias
     */
    protected function segmentGcFinish(?string $dbAlias = null): void
    {
        $this->headerValue('writing_flag', self::WRITING_FLAG_OK);
        $this->lastGcTime = time();
    }


    /**
     * Segment Bit Op
     * @param mixed $key
     * @param mixed $opType
     * @param mixed $destKey
     * @param mixed $id
     * @param mixed $sourceKeys
     * @param mixed $dbAlias
     * @param mixed $expire
     * @return int
     */
    protected function segmentBitOp(
        string $key,
        string $opType,
        ?string $destKey,
        int|array $id,
        ?array $sourceKeys,
        ?string $dbAlias,
        int $expire
    ): int {
        if (empty($sourceKeys)) {
            return 0;
        }
        $operation = strtoupper($opType);
        $bitStrings = [];
        $maxLen = 0;
        foreach ($sourceKeys as $srcKey) {
            $bs = $this->segmentLoadBitString($srcKey, $dbAlias);
            if ($bs !== '') {
                $bitStrings[$srcKey] = $bs;
                $maxLen = max($maxLen, strlen($bs));
            }
        }
        if ($maxLen === 0) {
            return 0;
        }
        $result = str_repeat("\0", $maxLen);
        for ($byte = 0; $byte < $maxLen; $byte++) {
            $values = [];
            foreach ($sourceKeys as $srcKey) {
                $values[] = $byte < strlen($bitStrings[$srcKey]) ? ord($bitStrings[$srcKey][$byte]) : 0;
            }
            $resultByte = match ($operation) {
                'AND' => array_reduce($values, fn ($carry, $v) => $carry & $v, 0xFF),
                'OR' => array_reduce($values, fn ($carry, $v) => $carry | $v, 0),
                'XOR' => array_reduce($values, fn ($carry, $v) => $carry ^ $v, 0),
                'NOT' => isset($values[0]) ? (~$values[0]) & 0xFF : 0,
                default => 0,
            };
            $result[$byte] = chr($resultByte);
        }
        $targetKey = $destKey ?? ('bitop_result_' . $key . '_' . time());
        [$targetMemKey, $targetCrc32, $targetVCrc32] = $this->getCrc32Key($targetKey);
        if (!$this->acquireWriteLock()) {
            return 0;
        }
        try {
            $targetMetaOrder = $this->findFreeMetaOrder();
            if ($targetMetaOrder === false) {
                return 0;
            }
            $this->removeFreeIndex($targetMetaOrder);
            $offset = $this->allocateSpace($maxLen);
            if ($offset === false) {
                return 0;
            }
            $this->writeToCache($result, $offset);
            $meta = [
                'status' => 1,
                'timestamp' => $expire > 0 ? time() + $expire : 0,
                'block_length' => $maxLen,
                'string_length' => $maxLen,
                'offset' => $offset,
                'last_use' => time(),
                'data_crc32' => crc32($result),
            ];
            $this->writeMetaByOrder($targetMetaOrder, $meta);
            $this->updateIndex($targetMemKey, $targetCrc32, $targetVCrc32, $targetMetaOrder);
            $this->dirty = true;
        } finally {
            $this->releaseWriteLock();
        }
        return $maxLen * 8;
    }

    /**
     * Segment Gc Rebuild Lru
     * @param mixed $dbAlias
     */
    protected function segmentGcRebuildLru(?string $dbAlias = null): void
    {
        $this->rebuildLru();
    }

/**
 * Segment Public Resolve Db
 * @return ?string
 */
protected function segmentPublicResolveDb(): ?string
    {
        return null;
    }

    /**
     * Segment Public With Shard Write Lock
     * @param mixed $key
     * @param mixed $fn
     * @return mixed
     */
    protected function segmentPublicWithShardWriteLock(string $key, callable $fn): mixed
    {
        if (!$this->acquireWriteLock()) {
            return false;
        }
        try {
            return $fn();
        } finally {
            $this->releaseWriteLock();
        }
    }

    /**
     * Segment Public With Read Lock
     * @param mixed $key
     * @param mixed $fn
     * @param mixed $lockFailValue
     * @return mixed
     */
    protected function segmentPublicWithReadLock(string $key, callable $fn, mixed $lockFailValue = null): mixed
    {
        if (!$this->acquireReadLock()) {
            return $lockFailValue;
        }
        try {
            return $fn();
        } finally {
            $this->releaseReadLock();
        }
    }

    /**
     * Segment Public With Batch Read Lock
     * @param mixed $fn
     * @param mixed $lockFailValue
     * @return mixed
     */
    protected function segmentPublicWithBatchReadLock(callable $fn, mixed $lockFailValue): mixed
    {
        if (!$this->acquireReadLock()) {
            return $lockFailValue;
        }
        try {
            return $fn();
        } finally {
            $this->releaseReadLock();
        }
    }

    /**
     * Segment Bit With Write Lock
     * @param mixed $key
     * @param mixed $fn
     * @param mixed $dbAlias
     * @return mixed
     */
    protected function segmentBitWithWriteLock(string $key, callable $fn, ?string $dbAlias = null): mixed
    {
        if (!$this->acquireWriteLock()) {
            return false;
        }
        try {
            return $fn();
        } finally {
            $this->releaseWriteLock();
        }
    }

    /**
     * Segment Bit With Read Lock
     * @param mixed $key
     * @param mixed $fn
     * @param mixed $dbAlias
     * @return mixed
     */
    protected function segmentBitWithReadLock(string $key, callable $fn, ?string $dbAlias = null): mixed
    {
        if (!$this->acquireReadLock()) {
            return false;
        }
        try {
            return $fn();
        } finally {
            $this->releaseReadLock();
        }
    }
    /**
     * Segment Kv Mark Dirty
     * @param mixed $key
     * @param mixed $dbAlias
     */
    protected function segmentKvMarkDirty(string $key, ?string $dbAlias = null): void
    {
        if ($key !== self::SYSTEM_LAST_UPDATE_KEY) {
            $this->dirty = true;
        }
    }

    /**
     * Read Raw Data By Key
     * @param mixed $key
     * @param mixed $updateCache
     * @return mixed
     */
    private function readRawDataByKey(string $key, bool $updateCache = false): mixed
    {
        return $this->segmentKvReadRaw($key, $updateCache);
    }

    /**
     * Write Raw Data By Key
     * @param mixed $key
     * @param mixed $value
     * @param mixed $ttl
     * @param mixed $updateCache
     * @return bool
     */
    private function writeRawDataByKey(string $key, mixed $value, int $ttl = 0, bool $updateCache = false): bool
    {
        return $this->segmentKvWriteRaw($key, $value, $ttl, $updateCache);
    }
    /**
     * Segment Gc Collect Space
     * @param mixed $key
     * @param mixed $needLength
     * @param mixed $dbAlias
     */
    protected function segmentGcCollectSpace(string $key, int $needLength, ?string $dbAlias = null): void
    {
        $this->collectSpace($key, $needLength);
    }

    protected function &segmentGcStateRef(?string $dbAlias = null): array
    {
        return $this->gcState;
    }

    





    /**
     * 释放所有锁（关闭时调用）
     * @return void
     */
    public function __releaseAllLocksOnShutdown(): void
    {
        // 释放所有读锁
        while (isset($this->heldLocks['__read_lock']) && $this->heldLocks['__read_lock'] > 0) {
            $this->releaseReadLock();
        }
        // 释放写锁
        if (isset($this->heldLocks['__write_lock'])) {
            $this->releaseWriteLock();
        }
        $this->heldLocks = [];
    }

    /**
     * 析构函数（释放共享内存资源）
     * @return void
     */
    public function __destruct()
    {
        foreach ($this->dataShms as $shm) {
            if ($shm) {
                @shmop_close($shm);
            }
        }
        $this->dataShms = [];

        if ($this->shmId) {
            @shmop_close($this->shmId);
            $this->shmId = false;
        }
    }
}
