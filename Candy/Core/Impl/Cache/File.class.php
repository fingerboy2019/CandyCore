<?php
/**
 * FileCache文件缓存类
 *
 * 该类是Candy框架的缓存附属类，基于文件系统实现缓存功能
 * 提供键值对存储、过期时间管理、原子操作、多维键支持等功能
 *
 * 特性：
 * - 文件系统存储，数据持久化
 * - 支持TTL过期时间管理
 * - 原子操作保证数据一致性
 * - 支持多维键（如：user.profile.name）
 * - 热缓存机制提升读取性能
 * - LRU淘汰机制防止内存溢出
 * - 支持多种锁机制（文件锁/信号量）
 *
 * 依赖扩展：
 * - 无特殊扩展依赖（可选sysvsem扩展用于信号量锁）
 *
 * 使用场景：
 * - 需要持久化缓存的Web应用
 * - 替代共享内存缓存以提高数据持久化
 * - 简单的缓存需求
 * - 多数据库实例支持
 *
 * @package Candy\Core\Cache
 * @author  Candy Team
 * @final 该类为最终类，不允许继承
 */

declare(strict_types=1);

namespace Candy\Core\Impl\Cache;

use Candy\Core\Cache;
use Candy\Core\Impl\Pack\Binary;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class File extends Cache
{
    /**
     * Driver Capabilities
     * @return array
     */
    protected function driverCapabilities(): array
    {
        return [
            'atomic_mutation' => ['support' => true, 'mode' => 'local', 'distributed' => false, 'durable' => true, 'atomic' => true],
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
    private const VERSION = 5;
    private const WRITING_FLAG_OK = 0;
    private const WRITING_FLAG_WRITING = 1;
    private const WRITING_FLAG_TIMEOUT = 30;
    private const SHARD_COUNT = 16;
    private const INIT_LOCK_NAME = 'file_global_init';

    private const INDEX_HEADER_LENGTH = 2;

    private array $dbInstances = [];
    private ?string $tempDbAlias = null;
    private array $baseConfig = [];
    private string $cacheFile;
    private string $lockDir;
    private mixed $fileHandle = false;
    private int $segmentSize = 0;
    private int $lockOutTime;
    private int $maxVarNumber;
    private int $blockSize;
    private int $indexAreaFixedSize = 0;
    private int $metaAreaSize = 0;
    private int $bitmapAreaSize = 0;
    private int $dataAreaStart = 0;
    private int $totalBlockCount = 0;
    private array $header = [];
    private array $indexMap = [];
    private string $bitmap = '';
    private array $metadataCache = [];
    private array $heldLocks = [];
    private string $lockType;
    private mixed $semaphore = false;
    private array $hotCache = [];
    private array $lruRecords = [];
    private int $lastGcTime = 0;
    private int $gcInterval;
    private int $prefixCrc32;
    private string $gcLockName;
    private const GC_LOCK_TIMEOUT = 10;
    
    // 新增：反向映射，用于快速查找 [metaOrder => memKey]
    private array $metaToMemKey = [];

    /**
     * File 驱动热缓存桶（按 dbAlias 隔离）
     *
     * @return array{hotCache: array, lruRecords: array, ...}
     */
    protected function &dualHotCacheBucket(?string $dbAlias): array
    {
        if ($dbAlias === null || $dbAlias === '') {
            throw new \InvalidArgumentException('File 热缓存需要 dbAlias');
        }

        return $this->dbInstances[$dbAlias];
    }

    /**
     * 读取位图热缓存（统一 DualKey 结构，兼容旧版 data/timestamp 格式）
     */
    private function fetchBitHotCache(string $ckey, string $memKey, string $dbAlias): ?string
    {
        $cached = $this->getHotCache($ckey, $memKey, $dbAlias);
        if ($cached !== null) {
            return is_string($cached) ? $cached : '';
        }

        $db = &$this->dbInstances[$dbAlias];
        $item = $db['hotCache'][$ckey] ?? null;
        if (!is_array($item)) {
            return null;
        }

        $legacy = $item['value'] ?? $item['data'] ?? null;

        return is_string($legacy) ? $legacy : null;
    }

    /**
     * 写入位图热缓存（统一 DualKey value/expire 结构）
     */
    private function storeBitHotCache(string $ckey, string $memKey, string $bitString, string $dbAlias): void
    {
        $this->setHotCache($ckey, $memKey, $bitString, 0, $dbAlias);
    }

    /**
     *   construct
     * @param mixed $config
     * @return mixed
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        parent::__construct($config);
        $this->baseConfig = $config;
        $this->initDbInstance('default', $config);
        $this->switchToDb('default');
        register_shutdown_function([$this, '__releaseAllLocksOnShutdown']);
    }

    /**
     * 切换数据库实例
     * @param string $dbAlias 数据库别名
     * @param array $customConfig 自定义配置（可选）
     * @return self 返回当前实例以支持链式调用
     */
    public function db(string $dbAlias, array $customConfig = []): self
    {
        $this->tempDbAlias = $dbAlias;
        if (!isset($this->dbInstances[$dbAlias])) {
            $this->initDbInstance($dbAlias, array_merge($this->baseConfig, $customConfig));
        }
        return $this;
    }

    /**
     * 初始化数据库实例
     * @param string $dbAlias 数据库别名
     * @param array $config 配置参数
     * @return void
     */
    private function initDbInstance(string $dbAlias, array $config): void
    {
        $lockOutTime    = $config['lockOutTime'] ?? 3000;
        $lockType       = $config['lockType'] ?? 'file';
        $retryInterval  = $config['retryInterval'] ?? 200;
        $segmentSize    = $config['segmentSize'] ?? 4 * 1024 * 1024;
        $blockSize      = $config['blockSize'] ?? self::SEG_DEFAULT_BLOCK_SIZE;
        // 槽位数缺省（0/未配置）时按段大小与块大小自动推导，保证「槽位 ≈ 块数÷0.8」自洽
        $maxVarNumber   = (int) ($config['maxVarNumber'] ?? 0);
        if ($maxVarNumber <= 0) {
            $maxVarNumber = self::segmentResolveSlotCount($segmentSize, $blockSize, self::INDEX_HEADER_LENGTH);
        }
        $gcInterval     = $config['gcInterval'] ?? 10;
        // 数据区按需增长步长；0 表示一次性分配到 segmentSize（老行为）
        $growStep       = (int) ($config['growStep'] ?? 4 * 1024 * 1024);
        $growStep       = max(0, min($growStep, $segmentSize));
        $cachePrefix    = $config['prefix'] ?? 'candy:';
        $prefixCrc32     = crc32($cachePrefix);
        $gcLockName     = 'file_cache_gc_' . md5($cachePrefix . $dbAlias);

        $cacheDir = $lockDir  = $config['cacheDir'] ?? (is_null(C('RUNTIME')) ?  sys_get_temp_dir() : C('RUNTIME') . 'Tmps');
        
        if (!is_writable($cacheDir)) throw new \RuntimeException(sprintf('缓存目录%s不可写', $cacheDir));

        $cacheKey = $dbAlias;
        $cacheFile = $cacheDir . '/' . md5($cachePrefix . $cacheKey) . '.data';
        
        $isNewCreate = !file_exists($cacheFile);
        $fileHandle = fopen($cacheFile, 'c+b');
        if (!$fileHandle) throw new \RuntimeException('缓存文件打开失败');

        $semaphore = false;
        if ($lockType === 'semaphore' && extension_loaded('sysvsem')) {
            $semKey = ftok($cacheFile, 'C');
            if ($semKey !== -1) $semaphore = sem_get($semKey, 1, 0660, true);
        }

        $indexAreaFixedSize = self::INDEX_HEADER_LENGTH + $maxVarNumber * self::SEG_INDEX_LENGTH;
        $metaAreaSize = $maxVarNumber * self::SEG_META_LENGTH;

        $this->dbInstances[$dbAlias] = [
            'config' => $config,
            'fileHandle' => $fileHandle,
            'lockDir' => $lockDir,
            'cacheFile' => $cacheFile,
            'lockType' => $lockType,
            'semaphore' => $semaphore,
            'lockOutTime' => $lockOutTime,
            'retryInterval' => $retryInterval,
            'maxVarNumber' => $maxVarNumber,
            'segmentSize' => $segmentSize,
            'blockSize' => $blockSize,
            'gcInterval' => $gcInterval,
            'growStep' => $growStep,
            'cachePrefix' => $cachePrefix,
            'prefixCrc32' => $prefixCrc32,
            'gcLockName' => $gcLockName,
            'indexAreaFixedSize' => $indexAreaFixedSize,
            'metaAreaSize' => $metaAreaSize,
            'bitmapAreaSize' => 0,
            'dataAreaStart' => 0,
            'totalBlockCount' => 0,
            'header' => [],
            'indexMap' => [],
            'bitmap' => '',
            'metadataCache' => [],
            'hotCache' => [],
            'lruRecords' => [],
            'freeMetaOrders' => [],
        // 锁文件句柄池：锁文件常驻、句柄复用。
        // 原来每条写都 fopen+flock+fclose+unlink，unlink 会让下次必须重建文件，
        // 每条写要触发十几次 stat —— 这是写路径最大的固定开销。
        'lockHandles' => [],
            'lastGcTime' => 0,
            'heldLocks' => [],
            'metaToMemKey' => [], // 初始化反向映射
            'syncedSeq' => -1,    // 已同步的段头写入序号（跨进程变更检测）
        ];

        $db = &$this->dbInstances[$dbAlias];
        if ($isNewCreate) {
            if (!$this->__acquireLock(self::INIT_LOCK_NAME)) {
                throw new \RuntimeException('获取初始化锁失败');
            }
            
            try {
                if ($this->checkMagicNumber($dbAlias)) {
                    $this->__releaseLock(self::INIT_LOCK_NAME);
                    return;
                }
                
                // growStep=0 → 一次性分配到预算（老行为）；否则初始只开一个步长
                ftruncate($fileHandle, $growStep <= 0 ? $segmentSize : max(1, min($segmentSize, $growStep)));
                $this->initSegment($dbAlias);
            } finally {
                $this->__releaseLock(self::INIT_LOCK_NAME);
            }
        } else {
            if (!$this->__acquireLock(self::INIT_LOCK_NAME)) {
                throw new \RuntimeException('获取初始化锁失败');
            }
            
            try {
                if (!$this->checkMagicNumber($dbAlias)) {
                    $this->initSegment($dbAlias);
                }
            } finally {
                $this->__releaseLock(self::INIT_LOCK_NAME);
            }
            
            // 不再无条件把文件撑到 segmentSize：数据区按需增长，只保证「文件不小于配置」
            fseek($fileHandle, 0, SEEK_END);
            $fileSize = (int) ftell($fileHandle);
            if ($fileSize > $segmentSize) {
                $db['segmentSize'] = $fileSize;
            }

            $this->initHeaderData($dbAlias);
            $this->checkAndResetWritingFlag($dbAlias);

            $header = $this->getDbAttr($dbAlias, 'header');
            if (
                $header['magic_number'] !== self::SEG_MAGIC_NUMBER
                || $header['version'] < self::VERSION
                || $header['block_size'] !== $blockSize
                || $header['max_var_number'] !== $maxVarNumber
            ) {
                if (!$this->__acquireLock(self::INIT_LOCK_NAME)) {
                    throw new \RuntimeException('获取初始化锁失败');
                }
                
                try {
                    if (!$this->checkMagicNumber($dbAlias)) {
                        $this->initSegment($dbAlias);
                    }
                } finally {
                    $this->__releaseLock(self::INIT_LOCK_NAME);
                }
            }

            $db['bitmapAreaSize'] = $header['bitmap_length'];
            $db['totalBlockCount'] = $db['bitmapAreaSize'] * self::SEG_BITS_PER_BYTE;
            $db['dataAreaStart'] = self::SEG_HEADER_SIZE + $db['indexAreaFixedSize'] + $db['bitmapAreaSize'] + $db['metaAreaSize'];
            $db['effectiveCapacity'] = self::segmentEffectiveCapacity($db['totalBlockCount'], $db['maxVarNumber']);
            self::segmentWarnSlotMismatch(
                $db['maxVarNumber'],
                $db['totalBlockCount'],
                $db['blockSize'],
                $db['segmentSize'],
                self::INDEX_HEADER_LENGTH,
                $dbAlias
            );
        }

        $this->loadIndexAndBitmap($dbAlias);
        $this->rebuildLru($dbAlias);
        
        $this->segmentGcInitState($dbAlias);

        // 记录当前段头写入序号，供跨进程变更检测使用
        $this->touchSegmentBaseline($dbAlias);
    }

    /**
     * 读前同步：段文件被其它进程改过时，重载索引/位图并丢弃进程内热缓存
     *
     * 背景：Cache::store() 是进程级单例，File 驱动把 index/bitmap/meta 与
     * LocalHotCache 全部常驻实例内存；别的进程写入后，本进程不重载就
     * 一直读到旧值（G() 的 localRev 同步机制在 File 下也因此失效）。
     *
     * 检测手段：段头 header 的 reserved 字段当**写入序号**，每次写 +1。
     * 不用 filemtime —— 秒级精度，同一秒内多次写会漏检（实测会永久 stale）。
     *
     * @param string $dbAlias 数据库别名
     * @return void
     */
    protected function segmentSyncBeforeRead(?string $dbAlias): void
    {
        if (!isset($this->dbInstances[$dbAlias])) {
            return;
        }

        $db  = &$this->dbInstances[$dbAlias];
        $seq = $this->readHeaderSeq($dbAlias);
        if ($seq === (int) ($db['syncedSeq'] ?? -1)) {
            return;
        }

        $this->loadIndexAndBitmap($dbAlias);
        $db['hotCache'] = [];
        $db['lruRecords'] = [];
        $db['metadataCache'] = [];
        $db['syncedSeq'] = $seq;
    }

    /**
     * 写后同步钩子：递增段头写入序号（供其它进程检测变更）
     *
     * @param string $dbAlias 数据库别名
     * @return void
     */
    protected function segmentTouchAfterWrite(?string $dbAlias): void
    {
        $this->bumpWriteSeq($dbAlias);
    }

    /**
     * 写锁内的强制同步：不看序号，无条件按磁盘现状重建 index / bitmap / 空闲槽位栈
     *
     * @param string|null $dbAlias
     * @return void
     */
    protected function segmentForceSyncBeforeWrite(?string $dbAlias): void
    {
        $alias = $dbAlias ?? 'default';
        if (!isset($this->dbInstances[$alias])) {
            return;
        }
        $db = &$this->dbInstances[$alias];
        $this->loadIndexAndBitmap($alias);
        $db['hotCache'] = [];
        $db['lruRecords'] = [];
        $db['metadataCache'] = [];
        $db['syncedSeq'] = $this->readHeaderSeq($alias);
    }

    /**
     * 读取段头写入序号（复用 header 的 reserved 字段，不改格式、不升版本）
     *
     * @param string $dbAlias 数据库别名
     * @return int 序号；读取失败返回 -1
     */
    private function readHeaderSeq(string $dbAlias): int
    {
        $fh = $this->dbInstances[$dbAlias]['fileHandle'];
        if (@fseek($fh, 0, SEEK_SET) !== 0) {
            return -1;
        }

        $raw = fread($fh, self::SEG_HEADER_SIZE);
        if (!is_string($raw) || strlen($raw) < self::SEG_HEADER_SIZE) {
            return -1;
        }

        // PHP 的 unpack 对「无名字段」不会拆成 1..N（实测返回 {"CNNNNNNN": ...}），
        // 必须自带字段名；这里不复用 SEG_HEADER_FORMAT，避免影响 Shmop 的 pack。
        $header = unpack(
            'Nmagic/Cversion/Ncapacity/Nflag/Nstart/Nblock/Nmaxvar/Nbitmap/Nreserved',
            $raw
        );

        return is_array($header) ? (int) ($header['reserved'] ?? -1) : -1;
    }

    /**
     * 递增段头写入序号并写回（4 字节写，成本可忽略）
     *
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function bumpWriteSeq(string $dbAlias): void
    {
        if (!isset($this->dbInstances[$dbAlias])) {
            return;
        }

        $db = &$this->dbInstances[$dbAlias];
        // 必须基于「磁盘上的当前序号」递增，不能基于内存副本：
        // 内存副本停留在本进程上次写的值，多进程交替写时会把序号写回旧值 → 其它进程漏检变更 → 用旧 index 覆盖 → 丢条目
        $seq = $this->readHeaderSeq($dbAlias);
        if ($seq < 0) {
            $seq = (int) ($db['header']['reserved'] ?? 0);
        }
        $db['header']['reserved'] = $seq + 1;

        if (@fseek($db['fileHandle'], 0, SEEK_SET) === 0) {
            fwrite($db['fileHandle'], pack(self::SEG_HEADER_FORMAT, ...array_values($db['header'])));
        }

        // 关键：本进程刚写完，内存里的 index/bitmap 已是写后状态，
        // 必须同步基线，否则下一次读会把自己的写当成「外部变更」而重载一次
        // （实测每次多花 1ms 量级，zPopDue 从 ~300μs 涨到 ~1.2ms）。
        $this->touchSegmentBaseline($dbAlias);
    }

    /**
     * 刷新段头同步基线
     *
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function touchSegmentBaseline(string $dbAlias): void
    {
        if (!isset($this->dbInstances[$dbAlias])) {
            return;
        }

        $this->dbInstances[$dbAlias]['syncedSeq'] = $this->readHeaderSeq($dbAlias);
    }

    /**
     * 切换到目标数据库实例
     * @param string|null $dbAlias 数据库别名
     * @return void
     */
    private function switchToDb(?string $dbAlias): void
    {
        $db = $this->dbInstances[$dbAlias ?? 'default'];

        $this->fileHandle         = $db['fileHandle'];
        $this->lockDir            = $db['lockDir'];
        $this->cacheFile          = $db['cacheFile'];
        $this->lockType           = $db['lockType'];
        $this->semaphore          = $db['semaphore'];
        $this->lockOutTime        = $db['lockOutTime'];
        $this->maxVarNumber       = $db['maxVarNumber'];
        $this->segmentSize        = $db['segmentSize'];
        $this->blockSize          = $db['blockSize'];
        $this->gcInterval         = $db['gcInterval'];
        $this->prefixCrc32        = $db['prefixCrc32'];
        $this->gcLockName         = $db['gcLockName'];
        $this->indexAreaFixedSize = $db['indexAreaFixedSize'];
        $this->metaAreaSize       = $db['metaAreaSize'];
        $this->bitmapAreaSize     = $db['bitmapAreaSize'];
        $this->dataAreaStart      = $db['dataAreaStart'];
        $this->totalBlockCount    = $db['totalBlockCount'];
        $this->header             = $db['header'];
        $this->indexMap           = $db['indexMap'];
        $this->bitmap             = $db['bitmap'];
        $this->metadataCache      = $db['metadataCache'];
        $this->hotCache           = $db['hotCache'];
        $this->lruRecords         = $db['lruRecords'];
        $this->lastGcTime         = $db['lastGcTime'];
        $this->heldLocks          = $db['heldLocks'];
        $this->metaToMemKey       = $db['metaToMemKey'] ?? []; // 加载反向映射

        $this->tempDbAlias = null;
    }

    /**
     * 获取数据库实例属性
     * @param string $dbAlias 数据库别名
     * @param string $key 属性名
     * @return mixed 属性值
     */
    private function getDbAttr(string $dbAlias, string $key): mixed
    {
        return $this->dbInstances[$dbAlias][$key] ?? null;
    }


    /**
     * 设置数据库实例属性
     * @param string $dbAlias 数据库别名
     * @param string $key 属性名
     * @param mixed $val 属性值
     * @return void
     */
    private function setDbAttr(string $dbAlias, string $key, mixed $val): void
    {
        $this->dbInstances[$dbAlias][$key] = $val;
    }

    /**
     * 获取目标数据库别名
     * @return string 数据库别名
     */
    private function takeTargetDb(): string
    {
        $db = $this->tempDbAlias ?? 'default';
        $this->tempDbAlias = null;
        $this->switchToDb($db);
        return $db;
    }

    /**
     * 加载索引和位图
     * @param string $dbAlias 数据库别名
     * @return void
     */
    /**
     * 按 growStep 扩展数据区（稀疏文件 ftruncate，几乎零成本），直到 segmentSize 上限
     *
     * @param string|null $dbAlias
     * @return bool 是否扩出了一段
     */
    protected function segmentGrowCapacity(?string $dbAlias = null): bool
    {
        $alias = $dbAlias ?? 'default';
        if (!isset($this->dbInstances[$alias])) {
            return false;
        }
        $db = &$this->dbInstances[$alias];
        $step = (int) ($db['growStep'] ?? 0);
        if ($step <= 0) {
            return false;
        }
        $fh = $db['fileHandle'] ?? null;
        if (!$fh) {
            return false;
        }
        $stat = fstat($fh);
        $current = (int) ($stat['size'] ?? 0);
        $limit = (int) ($db['segmentSize'] ?? 0);
        if ($current >= $limit) {
            return false;
        }
        $next = min($limit, $current + $step);
        if ($next <= $current) {
            return false;
        }
        if (!ftruncate($fh, $next)) {
            return false;
        }
        $db['fileSize'] = $next;

        return true;
    }

    private function loadIndexAndBitmap(string $dbAlias): void
    {
        $db = &$this->dbInstances[$dbAlias];
        $db['indexMap'] = [];
        $db['freeMetaOrders'] = [];
        $db['metaToMemKey'] = []; // 初始化反向映射
        $fh = $db['fileHandle'];

        fseek($fh, self::SEG_HEADER_SIZE, SEEK_SET);
        $indexHeader = fread($fh, self::INDEX_HEADER_LENGTH);
        $bitmapLength = unpack('nlen', $indexHeader)['len'];
        $db['bitmapAreaSize'] = $bitmapLength;

        $totalReadLength = $db['indexAreaFixedSize'] + $bitmapLength;
        fseek($fh, self::SEG_HEADER_SIZE, SEEK_SET);
        $indexAndBitmap = fread($fh, $totalReadLength);

        $indexBody = substr($indexAndBitmap, self::INDEX_HEADER_LENGTH, $db['maxVarNumber'] * self::SEG_INDEX_LENGTH);
        $db['bitmap'] = substr($indexAndBitmap, self::INDEX_HEADER_LENGTH + $db['maxVarNumber'] * self::SEG_INDEX_LENGTH);

        $indexArray = str_split($indexBody, self::SEG_INDEX_LENGTH);
        foreach ($indexArray as $order => $chunk) {
            if (strlen($chunk) < self::SEG_INDEX_LENGTH) break;
            $item = unpack(self::SEG_INDEX_UNPACK_FORMAT, $chunk);
            if (!$item) continue;
            if ($item['meta_order'] === self::SEG_INDEX_ORDER_FREE) {
                $db['freeMetaOrders'][] = $order;
            } else {
                $memKey = $item['crc32'] . '_' . $item['v_crc32'];
                $db['indexMap'][$memKey] = $item['meta_order'];
                $db['metaToMemKey'][$item['meta_order']] = $memKey; // 建立反向映射
            }
        }
    }


















    /**
     * 初始化段（创建新的缓存文件结构）
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function initSegment(string $dbAlias): void
    {
        $db = &$this->dbInstances[$dbAlias];
        $fh = $db['fileHandle'];

        try {
            $fixedOverhead = self::SEG_HEADER_SIZE + $db['indexAreaFixedSize'] + $db['metaAreaSize'];
            $availableSpace = $db['segmentSize'] - $fixedOverhead;
            $db['bitmapAreaSize'] = (int)ceil($availableSpace / ($db['blockSize'] * self::SEG_BITS_PER_BYTE + 1));
            $db['totalBlockCount'] = $db['bitmapAreaSize'] * self::SEG_BITS_PER_BYTE;
            $db['dataAreaStart'] = $fixedOverhead + $db['bitmapAreaSize'];
            $db['effectiveCapacity'] = self::segmentEffectiveCapacity($db['totalBlockCount'], $db['maxVarNumber']);
            self::segmentWarnSlotMismatch(
                $db['maxVarNumber'],
                $db['totalBlockCount'],
                $db['blockSize'],
                $db['segmentSize'],
                self::INDEX_HEADER_LENGTH,
                $dbAlias
            );

            $db['header'] = [
                'magic_number'  => self::SEG_MAGIC_NUMBER,
                'version'       => self::VERSION,
                'data_capacity' => $db['segmentSize'],
                'writing_flag'  => self::WRITING_FLAG_OK,
                'writing_start_time' => 0,
                'block_size'    => $db['blockSize'],
                'max_var_number' => $db['maxVarNumber'],
                'bitmap_length' => $db['bitmapAreaSize'],
                'reserved'      => 0
            ];
            fseek($fh, 0, SEEK_SET);
            fwrite($fh, pack(self::SEG_HEADER_FORMAT, ...array_values($db['header'])));

            $indexHeader = pack('n', $db['bitmapAreaSize']);
            $indexBody = str_repeat(pack(self::SEG_INDEX_FORMAT, 0, 0, self::SEG_INDEX_ORDER_FREE), $db['maxVarNumber']);
            fseek($fh, self::SEG_HEADER_SIZE, SEEK_SET);
            fwrite($fh, $indexHeader . $indexBody);

            $db['bitmap'] = str_repeat("\0", $db['bitmapAreaSize']);
            fseek($fh, self::SEG_HEADER_SIZE + $db['indexAreaFixedSize'], SEEK_SET);
            fwrite($fh, $db['bitmap']);

            fseek($fh, self::SEG_HEADER_SIZE + $db['indexAreaFixedSize'] + $db['bitmapAreaSize'], SEEK_SET);
            fwrite($fh, str_repeat("\0", $db['metaAreaSize']));

            // growStep=0 → 一次性到预算；否则初始只开到「固定区 + 一个步长」
            $growStep = (int) ($db['growStep'] ?? 0);
            if ($growStep <= 0) {
                ftruncate($fh, $db['segmentSize']);
            } else {
                $initialSize = $db['dataAreaStart'] + max($growStep, $db['blockSize']);
                ftruncate($fh, min($db['segmentSize'], $initialSize));
            }

            $this->clearMetaCache($dbAlias);
            $db['hotCache'] = [];
            $db['lruRecords'] = [];
            $db['lastGcTime'] = time();
        } catch (Throwable $e) {
            throw new \RuntimeException('缓存文件初始化失败：' . $e->getMessage());
        }
    }

    /**
     * Check Magic Number
     * @param mixed $dbAlias
     * @return bool
     */
    private function checkMagicNumber(string $dbAlias): bool
    {
        $db = &$this->dbInstances[$dbAlias];
        fseek($db['fileHandle'], 0, SEEK_SET);
        $headerData = fread($db['fileHandle'], self::SEG_HEADER_SIZE);
        if (!$headerData || strlen($headerData) < self::SEG_HEADER_SIZE) {
            return false;
        }
        $header = unpack(self::SEG_HEADER_UNPACK_FORMAT, $headerData);
        return isset($header['magic_number']) && $header['magic_number'] === self::SEG_MAGIC_NUMBER;
    }

    /**
     * 初始化头部数据
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function initHeaderData(string $dbAlias): void
    {
        $db = &$this->dbInstances[$dbAlias];
        if (empty($db['header'])) {
            fseek($db['fileHandle'], 0, SEEK_SET);
            $data = fread($db['fileHandle'], self::SEG_HEADER_SIZE);
            $db['header'] = $data ? (array)unpack(self::SEG_HEADER_UNPACK_FORMAT, $data) : [];
        }
    }

    /**
     * 检查并重置写入标志
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function checkAndResetWritingFlag(string $dbAlias): void
    {
        $db = &$this->dbInstances[$dbAlias];
        if (
            isset($db['header']['writing_flag'])
            && $db['header']['writing_flag'] === self::WRITING_FLAG_WRITING
        ) {
            $writingStartTime = $db['header']['writing_start_time'] ?? 0;
            if (time() - $writingStartTime > self::WRITING_FLAG_TIMEOUT) {
                $this->headerValue('writing_flag', self::WRITING_FLAG_OK, $dbAlias);
            }
        }
    }

    /**
     * 获取或设置头部值
     * @param string|array $key 键名或键值对数组
     * @param mixed $value 值（设置时使用）
     * @param string|null $dbAlias 数据库别名
     * @return mixed 获取时返回值，设置时返回true
     */
    private function headerValue(string|array $key, mixed $value = '', ?string $dbAlias = null): mixed
    {
        $dbAlias = $dbAlias ?? $this->takeTargetDb();
        $db = &$this->dbInstances[$dbAlias];
        $this->initHeaderData($dbAlias);

        if (!empty($value) || is_array($key)) {
            $updateArray = is_array($key) ? $key : [$key => $value];
            foreach ($updateArray as $k => $v) {
                if (isset($db['header'][$k])) $db['header'][$k] = $v;
            }
            fseek($db['fileHandle'], 0, SEEK_SET);
            fwrite($db['fileHandle'], pack(self::SEG_HEADER_FORMAT, ...array_values($db['header'])));
            return true;
        }
        return empty($key) ? $db['header'] : ($db['header'][$key] ?? null);
    }


    /**
     * Run Incremental GC
     * @param mixed $maxSteps
     * @param mixed $dbAlias
     * @return array
     */
    public function runIncrementalGC(int $maxSteps = 10, ?string $dbAlias = null): array
    {
        $dbAlias = $dbAlias ?? $this->takeTargetDb();
        $db = &$this->dbInstances[$dbAlias];
        
        if (!isset($db['gcState'])) {
            $this->segmentGcInitState($dbAlias);
        }
        
        $gcState = &$db['gcState'];
        $now = time();
        
        if ($gcState['status'] === 'idle') {
            if (!$this->__acquireLock($db['gcLockName'], self::GC_LOCK_TIMEOUT)) {
                return ['status' => 'locked', 'processed' => 0, 'total' => 0];
            }
            
            try {
                if ($now - $db['lastGcTime'] < $db['gcInterval']) {
                    $this->__releaseLock($db['gcLockName']);
                    return ['status' => 'skipped', 'processed' => 0, 'total' => 0];
                }
                
                if ($this->headerValue('writing_flag', '', $dbAlias) === self::WRITING_FLAG_WRITING) {
                    $this->__releaseLock($db['gcLockName']);
                    return ['status' => 'writing', 'processed' => 0, 'total' => 0];
                }
                
                $this->headerValue([
                    'writing_flag' => self::WRITING_FLAG_WRITING,
                    'writing_start_time' => $now
                ], $dbAlias);
                
                $gcState['status'] = 'collecting';
                $gcState['processedCount'] = 0;
                $gcState['totalCount'] = 0;
                $gcState['validItems'] = [];
                $gcState['newIndexMap'] = [];
                $gcState['currentOffset'] = 0;
                $gcState['pendingWrites'] = [];
                
                foreach ($db['indexMap'] as $memKey => $metaOrder) {
                    $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
                    if (!$meta || $meta['status'] !== 1) continue;
                    if ($meta['timestamp'] > 0 && $meta['timestamp'] < $now) continue;
                    
                    fseek($db['fileHandle'], $meta['offset'], SEEK_SET);
                    $chunk = fread($db['fileHandle'], $meta['string_length']);
                    if ($chunk === false) continue;
                    
                    $gcState['validItems'][] = [
                        'mem_key' => $memKey,
                        'meta' => $meta,
                        'data' => $chunk,
                        'meta_order' => $metaOrder
                    ];
                    $gcState['totalCount']++;
                }
                
                if (count($gcState['validItems']) > $db['maxVarNumber'] * 0.8) {
                    uasort($gcState['validItems'], fn($a, $b) => ($a['meta']['last_use'] ?? 0) <=> ($b['meta']['last_use'] ?? 0));
                    $gcState['validItems'] = array_slice($gcState['validItems'], (int)(count($gcState['validItems']) * 0.2), preserve_keys: true);
                    $gcState['totalCount'] = count($gcState['validItems']);
                }
                
                $db['bitmap'] = str_repeat("\0", $db['bitmapAreaSize']);
                $db['indexMap'] = [];
                $db['metaToMemKey'] = [];
                $db['freeMetaOrders'] = [];
                $this->clearMetaCache($dbAlias);
                
                for ($i = 0; $i < $db['maxVarNumber']; $i++) {
                    $db['freeMetaOrders'][] = $i;
                }
                $db['freeMetaOrders'] = array_reverse($db['freeMetaOrders']);
                
            } catch (Throwable $e) {
                $this->headerValue('writing_flag', self::WRITING_FLAG_OK, $dbAlias);
                $this->__releaseLock($db['gcLockName']);
                $gcState['status'] = 'idle';
                return ['status' => 'error', 'message' => $e->getMessage(), 'processed' => 0, 'total' => 0];
            }
        }
        
        $stepsProcessed = 0;
        $itemsPerStep = max(1, (int)(count($gcState['validItems']) / $maxSteps));
        
        while ($stepsProcessed < $maxSteps && $gcState['currentOffset'] < count($gcState['validItems'])) {
            $endOffset = min($gcState['currentOffset'] + $itemsPerStep, count($gcState['validItems']));
            
            for ($i = $gcState['currentOffset']; $i < $endOffset; $i++) {
                $item = $gcState['validItems'][$i];
                $memKey = $item['mem_key'];
                $chunk = $item['data'];
                $oldMeta = $item['meta'];
                
                $needBlockCount = (int)ceil(strlen($chunk) / $db['blockSize']);
                $startBit = $this->findBestFitBlocks($needBlockCount, $dbAlias);
                if ($startBit === false) continue;
                
                for ($j = 0; $j < $needBlockCount; $j++) {
                    $this->bitSet($startBit + $j, true, $dbAlias);
                }
                
                $newOffset = $db['dataAreaStart'] + $startBit * $db['blockSize'];
                
                if (empty($db['freeMetaOrders'])) continue;
                $useMetaOrder = array_pop($db['freeMetaOrders']);
                
                $newMetaItem = [
                    'status' => 1,
                    'timestamp' => $oldMeta['timestamp'],
                    'block_length' => strlen($chunk),
                    'string_length' => strlen($chunk),
                    'offset' => $newOffset,
                    'last_use' => $oldMeta['last_use'] ?? $now,
                    'data_crc32' => crc32($chunk)
                ];
                
                $gcState['newIndexMap'][$memKey] = [
                    'order' => $useMetaOrder,
                    'meta' => $newMetaItem,
                    'data' => $chunk,
                    'offset' => $newOffset
                ];
                
                $gcState['processedCount']++;
            }
            
            $gcState['currentOffset'] = $endOffset;
            $stepsProcessed++;
            
            if ($stepsProcessed < $maxSteps && $gcState['currentOffset'] < count($gcState['validItems'])) {
                $this->headerValue('writing_flag', self::WRITING_FLAG_OK, $dbAlias);
                $this->__releaseLock($db['gcLockName']);
                return [
                    'status' => 'running',
                    'processed' => $gcState['processedCount'],
                    'total' => $gcState['totalCount'],
                    'progress' => $gcState['processedCount'] / $gcState['totalCount']
                ];
            }
        }
        
        if ($gcState['currentOffset'] >= count($gcState['validItems'])) {
            try {
                $this->segmentPersistBitmap($dbAlias);
                
                foreach ($gcState['newIndexMap'] as $memKey => $info) {
                    $useMetaOrder = $info['order'];
                    $newMetaItem = $info['meta'];
                    $chunk = $info['data'];
                    $newOffset = $info['offset'];
                    
                    $metaBin = pack(self::SEG_META_FORMAT, 
                        $newMetaItem['status'],
                        $newMetaItem['timestamp'],
                        $newMetaItem['block_length'],
                        $newMetaItem['string_length'],
                        $newOffset,
                        $newMetaItem['last_use'],
                        $newMetaItem['data_crc32'] ?? 0
                    );
                    
                    $metaOffset = self::SEG_HEADER_SIZE + $db['indexAreaFixedSize'] + $db['bitmapAreaSize'] + $useMetaOrder * self::SEG_META_LENGTH;
                    fseek($db['fileHandle'], $metaOffset, SEEK_SET);
                    fwrite($db['fileHandle'], $metaBin);
                    
                    fseek($db['fileHandle'], $newOffset, SEEK_SET);
                    fwrite($db['fileHandle'], $chunk);
                    
                    [$crc32, $vCrc32] = explode('_', $memKey);
                    $crc32 = (int)$crc32;
                    $vCrc32 = (int)$vCrc32;
                    
                    $indexOffset = self::SEG_HEADER_SIZE + self::INDEX_HEADER_LENGTH + $useMetaOrder * self::SEG_INDEX_LENGTH;
                    $bin = pack(self::SEG_INDEX_FORMAT, $crc32, $vCrc32, $useMetaOrder);
                    fseek($db['fileHandle'], $indexOffset, SEEK_SET);
                    fwrite($db['fileHandle'], $bin);
                    
                    $db['indexMap'][$memKey] = $useMetaOrder;
                    $db['metaToMemKey'][$useMetaOrder] = $memKey;
                    $db['metadataCache'][$useMetaOrder] = $newMetaItem;
                }
                
                if (!empty($gcState['pendingWrites'])) {
                    foreach ($gcState['pendingWrites'] as $memKey => $writeInfo) {
                        $useMetaOrder = $writeInfo['order'];
                        $newMetaItem = $writeInfo['meta'];
                        $chunk = $writeInfo['data'];
                        $newOffset = $writeInfo['offset'];
                        
                        $metaBin = pack(self::SEG_META_FORMAT, 
                            $newMetaItem['status'],
                            $newMetaItem['timestamp'],
                            $newMetaItem['block_length'],
                            $newMetaItem['string_length'],
                            $newOffset,
                            $newMetaItem['last_use'],
                            $newMetaItem['data_crc32'] ?? 0
                        );
                        
                        $metaOffset = self::SEG_HEADER_SIZE + $db['indexAreaFixedSize'] + $db['bitmapAreaSize'] + $useMetaOrder * self::SEG_META_LENGTH;
                        fseek($db['fileHandle'], $metaOffset, SEEK_SET);
                        fwrite($db['fileHandle'], $metaBin);
                        
                        fseek($db['fileHandle'], $newOffset, SEEK_SET);
                        fwrite($db['fileHandle'], $chunk);
                        
                        [$crc32, $vCrc32] = explode('_', $memKey);
                        $crc32 = (int)$crc32;
                        $vCrc32 = (int)$vCrc32;
                        
                        $indexOffset = self::SEG_HEADER_SIZE + self::INDEX_HEADER_LENGTH + $useMetaOrder * self::SEG_INDEX_LENGTH;
                        $bin = pack(self::SEG_INDEX_FORMAT, $crc32, $vCrc32, $useMetaOrder);
                        fseek($db['fileHandle'], $indexOffset, SEEK_SET);
                        fwrite($db['fileHandle'], $bin);
                        
                        $db['indexMap'][$memKey] = $useMetaOrder;
                        $db['metaToMemKey'][$useMetaOrder] = $memKey;
                        $db['metadataCache'][$useMetaOrder] = $newMetaItem;
                    }
                }
                
                $this->rebuildLru($dbAlias);
                $db['lastGcTime'] = time();
                
                $this->headerValue('writing_flag', self::WRITING_FLAG_OK, $dbAlias);
                $this->__releaseLock($db['gcLockName']);
                $gcState['status'] = 'idle';
                
                return [
                    'status' => 'completed',
                    'processed' => $gcState['processedCount'],
                    'total' => $gcState['totalCount'],
                    'progress' => 1.0
                ];
                
            } catch (Throwable $e) {
                $this->headerValue('writing_flag', self::WRITING_FLAG_OK, $dbAlias);
                $this->__releaseLock($db['gcLockName']);
                $gcState['status'] = 'idle';
                return ['status' => 'error', 'message' => $e->getMessage(), 'processed' => $gcState['processedCount'], 'total' => $gcState['totalCount']];
            }
        }
        
        return [
            'status' => 'running',
            'processed' => $gcState['processedCount'],
            'total' => $gcState['totalCount'],
            'progress' => $gcState['processedCount'] / $gcState['totalCount']
        ];
    }

    /**
     * 重建LRU记录
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function rebuildLru(string $dbAlias): void
    {
        $db = &$this->dbInstances[$dbAlias];
        $lru = [];
        // indexMap 的 key 现在就是 memKey
        foreach ($db['indexMap'] as $memKey => $metaOrder) {
            $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
            if ($meta && $meta['status'] === 1) {
                // LRU 结构保持为 [memKey => ['ckey' => ..., 'time' => ...]]
                // 但这里我们没有原始 key 来生成 ckey，所以只存时间戳
                // 实际上 rebuildLru 主要用于初始化，热缓存会在第一次访问时重建
                $lru[$memKey] = $meta['last_use'] ?? time();
            }
        }
        arsort($lru);
        // 转换为标准结构
        $db['lruRecords'] = [];
        foreach ($lru as $memKey => $time) {
            $db['lruRecords'][$memKey] = ['ckey' => '', 'time' => $time];
        }
    }

    /**
     * 写入缓存数据
     * @param string $data 数据
     * @param int $offset 偏移量
     * @param string $dbAlias 数据库别名
     * @return bool 写入是否成功
     */
    private function writeToCache(string $data, int $offset, string $dbAlias): bool
    {
        $db = &$this->dbInstances[$dbAlias];
        $dataLength = strlen($data);
        if ($offset < 0 || $dataLength <= 0 || $offset + $dataLength > $db['segmentSize']) {
            throw new \RuntimeException('缓存文件写入越界');
        }
        fseek($db['fileHandle'], $offset, SEEK_SET);
        return fwrite($db['fileHandle'], $data) === $dataLength;
    }

    /**
     * 从缓存读取数据
     * @param int $offset 偏移量
     * @param int $length 长度
     * @param string $dbAlias 数据库别名
     * @return string|false 读取的数据，失败返回false
     */
    private function readFromCache(int $offset, int $length, string $dbAlias): string|false
    {
        $db = &$this->dbInstances[$dbAlias];
        if ($offset < 0 || $length <= 0 || $offset + $length > $db['segmentSize']) {
            return false;
        }
        fseek($db['fileHandle'], $offset, SEEK_SET);
        return fread($db['fileHandle'], $length);
    }













    
    /**
     * 清空缓存
     * @return bool 清空是否成功
     */
    public function __clear(): bool
    {
        $dbAlias = $this->takeTargetDb();
        $this->__acquireLock('file_meta_write');
        try {
            $this->initSegment($dbAlias);
            $this->loadIndexAndBitmap($dbAlias);
            return true;
        } finally {
            $this->__releaseLock('file_meta_write');
        }
    }









    /**
     * 位图运算操作（内部方法）
     * 对多个位图进行AND/OR/XOR/NOT运算
     * @param string $key 源key数组（逗号分隔）或单个key（NOT操作时）
     * @param string $opType 运算类型：AND/OR/XOR/NOT
     * @param string|null $destKey 目标key
     * @param int|array $id 区间数组 [start, end]（可选，限制运算范围）
     * @param string $dbAlias 数据库别名
     * @param int $expire 过期时间
     * @return int 结果位图的字节数
     */
    protected function segmentBitOp(
        string $key,
        string $opType,
        ?string $destKey,
        int|array $id,
        ?array $sourceKeys,
        ?string $dbAlias,
        int $expire
    ): int
    {
        $db = &$this->dbInstances[$dbAlias];

        $dbAlias ??= $this->takeTargetDb();

        if ($sourceKeys === null || $sourceKeys === []) {
            $sourceKeys = ($opType === 'NOT') ? [$key] : array_filter(array_map('trim', explode(',', $key)));
        }
        if (empty($sourceKeys)) return 0;

        // 获取所有源位图数据
        $bitmaps = [];
        $maxLength = 0;
        foreach ($sourceKeys as $srcKey) {
            $bitString = $this->readBitString($srcKey, $dbAlias);
            if ($bitString !== '') {
                $bitmaps[] = $bitString;
                $maxLength = max($maxLength, strlen($bitString));
            }
        }

        if (empty($bitmaps)) return 0;

        // 执行位图运算
        $result = '';
        if ($opType === 'NOT') {
            // NOT操作只需要一个源
            for ($i = 0; $i < strlen($bitmaps[0]); $i++) {
                $result .= chr(~ord($bitmaps[0][$i]));
            }
        } else {
            // AND/OR/XOR操作需要多个源
            for ($i = 0; $i < $maxLength; $i++) {
                $byte = 0;
                $first = true;
                foreach ($bitmaps as $bitmap) {
                    $b = $i < strlen($bitmap) ? ord($bitmap[$i]) : 0;
                    if ($first) {
                        $byte = $b;
                        $first = false;
                    } else {
                        switch ($opType) {
                            case 'AND': $byte &= $b; break;
                            case 'OR':  $byte |= $b; break;
                            case 'XOR': $byte ^= $b; break;
                        }
                    }
                }
                $result .= chr($byte);
            }
        }

        // 如果指定了目标key，写入结果
        if ($destKey !== null) {
            $this->writeBitString($destKey, $result, $dbAlias, $expire);
        }

        return strlen($result);
    }


    /**
     * 读取位图数据（内部辅助方法）
     * @param string $key 位图key
     * @param string $dbAlias 数据库别名
     * @return string 位图数据
     */
    private function readBitString(string $key, string $dbAlias): string
    {
        return $this->segmentLoadBitString($key, $dbAlias);
    }

    /**
     * 写入位图数据（内部辅助方法）
     * @param string $key 位图key
     * @param string $bitString 位图数据
     * @param string $dbAlias 数据库别名
     * @param int $expire 过期时间
     * @return bool
     */
    private function writeBitString(string $key, string $bitString, string $dbAlias, int $expire = 0): bool
    {
        $db = &$this->dbInstances[$dbAlias];
        if (empty($key) || empty($bitString)) return false;

        [$memKey, $crc32, $vCrc32] = $this->getCrc32Key($key);
        $ckey = $this->formatKey($key, $dbAlias);

        // 加写锁
        if (!$this->__acquireLock($this->getShardLockName($key), self::GC_LOCK_TIMEOUT)) {
            return false;
        }

        try {
            $metaOrder = $db['indexMap'][$memKey] ?? false;
            $meta = [];

            // 检查现有元数据
            if ($metaOrder !== false) {
                $meta = $this->getMetaByOrder($metaOrder, $dbAlias);
                if (!$this->segmentValidateMeta($meta)) {
                    $this->freeIndex($metaOrder, $dbAlias);
                    $this->freeSpace($meta['offset'], $meta['block_length'], $dbAlias);
                    $metaOrder = false;
                }
            }

            // 分配新空间
            if ($metaOrder === false) {
                $metaOrder = $this->findFreeMetaOrder($dbAlias);
                if ($metaOrder === false) {
                    $this->__releaseLock($this->getShardLockName($key));
                    return false;
                }

                $offset = $this->allocateSpace(strlen($bitString), $dbAlias);
                if ($offset === false) {
                    $this->__releaseLock($this->getShardLockName($key));
                    return false;
                }

                $bitDataCrc32 = crc32($bitString);
                $meta = [
                    'status'       => 1,
                    'timestamp'    => $expire > 0 ? time() + $expire : 0,
                    'block_length' => strlen($bitString),
                    'string_length'=> strlen($bitString),
                    'offset'       => $offset,
                    'last_use'     => time(),
                    'data_crc32'   => $bitDataCrc32
                ];
                $this->writeMetaByOrder($metaOrder, $meta, $dbAlias);
                $this->updateIndex($memKey, $crc32, $vCrc32, $metaOrder, $dbAlias);
            } else {
                // 更新现有数据
                if ($expire > 0 || $meta['timestamp'] > 0) {
                    $meta['timestamp'] = $expire > 0 ? time() + $expire : 0;
                }
                $meta['last_use'] = time();
            }

            // 写入数据
            fseek($db['fileHandle'], $meta['offset'], SEEK_SET);
            $writeResult = fwrite($db['fileHandle'], $bitString) === strlen($bitString);

            if ($writeResult) {
                $meta['data_crc32'] = crc32($bitString);
                $this->writeMetaByOrder($metaOrder, $meta, $dbAlias);
                $this->storeBitHotCache($ckey, $memKey, $bitString, $dbAlias);
            }

            $this->__releaseLock($this->getShardLockName($key));
            return $writeResult;
        } catch (Throwable) {
            $this->__releaseLock($this->getShardLockName($key));
            return false;
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
        return $this->readRawDataByKey($key, $this->takeTargetDb());
    }

    /**
     * Segment Stream Save
     * @param mixed $key
     * @param mixed $stream
     * @return bool
     */
    protected function segmentStreamSave(string $key, array $stream): bool
    {
        return $this->writeRawDataByKey($key, $stream, $this->takeTargetDb(), 0, true);
    }

    /**
     * Segment Stream With Write Lock
     * @param mixed $key
     * @param mixed $fn
     * @return mixed
     */
    protected function segmentStreamWithWriteLock(string $key, callable $fn): mixed
    {
        if (!$this->__acquireLock($this->getShardLockName($key))) {
            return false;
        }

        try {
            return $fn();
        } finally {
            $this->__releaseLock($this->getShardLockName($key));
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
        return $this->segmentStreamWithWriteLock($key, $fn);
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
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['cachePrefix'];
    }

    protected function &segmentBitmap(?string $dbAlias = null): string
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['bitmap'];
    }

    /**
     * Segment Total Block Count
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentTotalBlockCount(?string $dbAlias = null): int
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['totalBlockCount'];
    }

    /**
     * Segment Block Size
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentBlockSize(?string $dbAlias = null): int
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['blockSize'];
    }

    /**
     * Segment Data Area Start
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentDataAreaStart(?string $dbAlias = null): int
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['dataAreaStart'];
    }

    /**
     * Segment Persist Bitmap
     * @param mixed $dbAlias
     */
    protected function segmentPersistBitmap(?string $dbAlias = null): void
    {
        $dbAlias ??= $this->takeTargetDb();
        $db = &$this->dbInstances[$dbAlias];
        $fh = $db['fileHandle'];

        fseek($fh, self::SEG_HEADER_SIZE, SEEK_SET);
        fwrite($fh, pack('n', strlen($db['bitmap'])));

        $bitmapOffset = self::SEG_HEADER_SIZE + $db['indexAreaFixedSize'];
        fseek($fh, $bitmapOffset, SEEK_SET);
        fwrite($fh, $db['bitmap']);

        $this->headerValue('bitmap_length', strlen($db['bitmap']), $dbAlias);
    }

    protected function &segmentIndexMap(?string $dbAlias = null): array
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['indexMap'];
    }

    protected function &segmentMetaToMemKey(?string $dbAlias = null): array
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['metaToMemKey'];
    }

    protected function &segmentFreeMetaOrders(?string $dbAlias = null): array
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['freeMetaOrders'];
    }

    protected function &segmentMetadataCache(?string $dbAlias = null): array
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['metadataCache'];
    }

    protected function &segmentMetaLRU(?string $dbAlias = null): array
    {
        $dbAlias ??= $this->takeTargetDb();
        if (!isset($this->dbInstances[$dbAlias]['metaLRU'])) {
            $this->dbInstances[$dbAlias]['metaLRU'] = [];
        }
        return $this->dbInstances[$dbAlias]['metaLRU'];
    }

    /**
     * Segment Max Var Number
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentMaxVarNumber(?string $dbAlias = null): int
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['maxVarNumber'];
    }

    /**
     * Segment Index Area Fixed Size
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentIndexAreaFixedSize(?string $dbAlias = null): int
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['indexAreaFixedSize'];
    }

    /**
     * Segment Bitmap Area Size
     * @param mixed $dbAlias
     * @return int
     */
    protected function segmentBitmapAreaSize(?string $dbAlias = null): int
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['bitmapAreaSize'];
    }

    /**
     * Segment Read Bytes
     * @param mixed $offset
     * @param mixed $length
     * @param mixed $dbAlias
     * @return string|false
     */
    protected function segmentReadBytes(int $offset, int $length, ?string $dbAlias = null): string|false
    {
        $dbAlias ??= $this->takeTargetDb();
        $fh = $this->dbInstances[$dbAlias]['fileHandle'];
        fseek($fh, $offset, SEEK_SET);
        $bin = fread($fh, $length);
        if ($bin === false || strlen($bin) < $length) {
            return false;
        }
        return $bin;
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
        $dbAlias ??= $this->takeTargetDb();
        $fh = $this->dbInstances[$dbAlias]['fileHandle'];
        fseek($fh, $offset, SEEK_SET);
        return fwrite($fh, $data) === strlen($data);
    }

/**
 * Segment Meta Area Size
 * @param mixed $dbAlias
 * @return int
 */
protected function segmentMetaAreaSize(?string $dbAlias = null): int
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->dbInstances[$dbAlias]['metaAreaSize'];
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
        $dbAlias ??= $this->takeTargetDb();
        $db = &$this->dbInstances[$dbAlias];
        $now = time();

        if ($needLength === 0 && $now - $db['lastGcTime'] < $db['gcInterval']) {
            return false;
        }
        if (!$this->__acquireLock($db['gcLockName'], self::GC_LOCK_TIMEOUT)) {
            return false;
        }
        if ($needLength === 0 && $now - $db['lastGcTime'] < $db['gcInterval']) {
            $this->__releaseLock($db['gcLockName']);
            return false;
        }
        $db['lastGcTime'] = $now;

        if ($this->headerValue('writing_flag', '', $dbAlias) === self::WRITING_FLAG_WRITING) {
            $this->__releaseLock($db['gcLockName']);
            return false;
        }

        $this->headerValue([
            'writing_flag' => self::WRITING_FLAG_WRITING,
            'writing_start_time' => $now,
        ], $dbAlias);

        return true;
    }

    /**
     * Segment Gc Finish
     * @param mixed $dbAlias
     */
    protected function segmentGcFinish(?string $dbAlias = null): void
    {
        $dbAlias ??= $this->takeTargetDb();
        $db = &$this->dbInstances[$dbAlias];
        $this->headerValue('writing_flag', self::WRITING_FLAG_OK, $dbAlias);
        $this->__releaseLock($db['gcLockName']);
    }

    /**
     * Segment Gc Rebuild Lru
     * @param mixed $dbAlias
     */
    protected function segmentGcRebuildLru(?string $dbAlias = null): void
    {
        $this->rebuildLru($dbAlias ?? $this->takeTargetDb());
    }

    /**
     * Read Raw Data By Key
     * @param mixed $key
     * @param mixed $dbAlias
     * @param mixed $updateCache
     * @return mixed
     */
    private function readRawDataByKey(string $key, string $dbAlias, bool $updateCache = false): mixed
    {
        return $this->segmentKvReadRaw($key, $updateCache, $dbAlias);
    }

    /**
     * Write Raw Data By Key
     * @param mixed $key
     * @param mixed $value
     * @param mixed $dbAlias
     * @param mixed $ttl
     * @param mixed $updateCache
     * @return bool
     */
    private function writeRawDataByKey(
        string $key,
        mixed $value,
        string $dbAlias,
        int $ttl = 0,
        bool $updateCache = false
    ): bool {
        return $this->segmentKvWriteRaw($key, $value, $ttl, $updateCache, $dbAlias);
    }
/**
 * Segment Public Resolve Db
 * @return ?string
 */
protected function segmentPublicResolveDb(): ?string
    {
        return $this->takeTargetDb();
    }

    /**
     * Segment Public With Shard Write Lock
     * @param mixed $key
     * @param mixed $fn
     * @return mixed
     */
    protected function segmentPublicWithShardWriteLock(string $key, callable $fn): mixed
    {
        if (!$this->__acquireLock($this->getShardLockName($key))) {
            return false;
        }
        try {
            return $fn();
        } finally {
            $this->__releaseLock($this->getShardLockName($key));
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
        return $fn();
    }

    /**
     * Segment Public With Batch Read Lock
     * @param mixed $fn
     * @param mixed $lockFailValue
     * @return mixed
     */
    protected function segmentPublicWithBatchReadLock(callable $fn, mixed $lockFailValue): mixed
    {
        return $fn();
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
        if (!$this->__acquireLock($this->getShardLockName($key), self::GC_LOCK_TIMEOUT)) {
            return false;
        }
        try {
            return $fn();
        } finally {
            $this->__releaseLock($this->getShardLockName($key));
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
        return $fn();
    }

    /**
     * Segment Bit Hot Cache Get
     * @param mixed $ckey
     * @param mixed $memKey
     * @param mixed $dbAlias
     * @return ?string
     */
    protected function segmentBitHotCacheGet(string $ckey, string $memKey, ?string $dbAlias): ?string
    {
        $dbAlias ??= $this->takeTargetDb();
        return $this->fetchBitHotCache($ckey, $memKey, $dbAlias);
    }
    /**
     * Segment Gc Collect Space
     * @param mixed $key
     * @param mixed $needLength
     * @param mixed $dbAlias
     */
    protected function segmentGcCollectSpace(string $key, int $needLength, ?string $dbAlias = null): void
    {
        $this->collectSpace($key, $needLength, $dbAlias ?? $this->takeTargetDb());
    }

    protected function &segmentGcStateRef(?string $dbAlias = null): array
    {
        $dbAlias ??= $this->takeTargetDb();
        if (!isset($this->dbInstances[$dbAlias]['gcState'])) {
            $this->dbInstances[$dbAlias]['gcState'] = [];
        }
        return $this->dbInstances[$dbAlias]['gcState'];
    }
/**
 * Get Shard Lock Name
 * @param mixed $key
 * @return string
 */
private function getShardLockName(string $key): string
    {
        // 方案 A：写路径整段串行。
        // 原因：bitmap 与空闲槽位栈是进程内副本，分片锁会让不同 key 的写互相覆盖分配结果。
        unset($key);

        return 'file_write_global';
    }

    /**
     *   acquire Lock
     * @param mixed $lockName
     * @param mixed $timeout
     * @return bool
     */
    public function __acquireLock(string $lockName, ?int $timeout = null): bool
    {
        $dbAlias = $this->tempDbAlias ?? 'default';
        $db = &$this->dbInstances[$dbAlias];
        $timeout = $timeout ?? $db['lockOutTime'];

        if (isset($db['heldLocks'][$lockName])) {
            $db['heldLocks'][$lockName]['count']++;
            return true;
        }

        if ($db['lockType'] === 'semaphore' && $db['semaphore']) {
            $startTime = microtime(true);
            $isBlock = $timeout === -1;
            do {
                if (sem_acquire($db['semaphore'], $timeout === 0)) {
                    $db['heldLocks'][$lockName] = ['count' => 1, 'handle' => $db['semaphore'], 'type' => 'semaphore'];
                    return true;
                }
                if ($timeout === 0) break;
                if (((microtime(true) - $startTime) * 1000) >= $timeout && !$isBlock) break;
                usleep($db['retryInterval']);
            } while (true);
            return false;
        }

        $lockFile = $db['lockDir'] . '/' . md5($dbAlias . '_' . $lockName) . '.lock';
        // 句柄池复用：锁文件常驻，首次 fopen 后一直用同一个句柄
        $lockHandle = $db['lockHandles'][$lockFile] ?? null;
        if (!is_resource($lockHandle)) {
            $lockHandle = @fopen($lockFile, 'c+b');
            if (!$lockHandle) return false;
            $db['lockHandles'][$lockFile] = $lockHandle;
        }

        $startTime = microtime(true);
        $isBlock = $timeout === -1;
        do {
            if (flock($lockHandle, $isBlock ? LOCK_EX : LOCK_EX | LOCK_NB)) {
                $db['heldLocks'][$lockName] = [
                    'count' => 1,
                    'handle' => $lockHandle,
                    'type' => 'file',
                    'file' => $lockFile
                ];
                return true;
            }
            if (!$isBlock && ((microtime(true) - $startTime) * 1000) >= $timeout) {
                fclose($lockHandle);
                break;
            }
            usleep($db['retryInterval']);
        } while (true);

        return false;
    }

    /**
     * 释放锁（兼容接口）
     * @param string $lockName 锁名称
     * @return bool 是否释放成功
     */
    public function __releaseLock(string $lockName): bool
    {
        $dbAlias = $this->tempDbAlias ?? 'default';
        $db = &$this->dbInstances[$dbAlias];

        if (!isset($db['heldLocks'][$lockName])) return true;
        $lockInfo = $db['heldLocks'][$lockName];
        $lockInfo['count']--;

        if ($lockInfo['count'] > 0) {
            $db['heldLocks'][$lockName]['count'] = $lockInfo['count'];
            return true;
        }

        $res = true;
        if ($lockInfo['type'] === 'semaphore') {
            $res = sem_release($lockInfo['handle']);
        } else {
            // 只释放 flock，不 fclose、不 unlink：
            // 句柄留在 lockHandles 池里供下次复用，锁文件常驻（flock 是内核语义，文件在哪不重要）。
            // 真正的关闭在 __releaseAllLocksOnShutdown 里统一做。
            @flock($lockInfo['handle'], LOCK_UN);
        }

        unset($db['heldLocks'][$lockName]);
        return $res;
    }

    /**
     * 释放所有锁（关闭时调用）
     * @return void
     */
    public function __releaseAllLocksOnShutdown(): void
    {
        foreach ($this->dbInstances as $dbAlias => &$db) {
            foreach (array_keys($db['heldLocks'] ?? []) as $lock) {
                $this->tempDbAlias = $dbAlias;
                $this->__releaseLock($lock);
            }
            $db['heldLocks'] = [];
            // 进程退出时才真正关闭锁句柄（锁文件本身保留，下次启动继续复用）
            foreach ($db['lockHandles'] ?? [] as $h) {
                if (is_resource($h)) {
                    @fclose($h);
                }
            }
            $db['lockHandles'] = [];
        }
        $this->tempDbAlias = null;
    }

    /**
     * 析构函数（释放文件资源）
     * @return void
     */
    public function __destruct()
    {
        foreach ($this->dbInstances as $dbAlias => &$db) {
            if (isset($db['gcState']) && $db['gcState']['status'] === 'running') {
                try {
                    $this->tempDbAlias = $dbAlias;
                    $this->runIncrementalGC(100, $dbAlias);
                } catch (Throwable) {
                }
            }
            
            if (is_resource($db['fileHandle'])) fclose($db['fileHandle']);
        }
        $this->dbInstances = [];
    }
}
