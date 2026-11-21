# File 类

## 概述

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

## 主要方法


### /**
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

namespace Candy\Core\Cache;

use Candy\Core\Cache;
use Candy\Core\Cache\Pack\Binary;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class File extends Cache
{
    private const MAGIC_NUMBER = 0xDEADBEEF;
    private const VERSION = 5;
    private const WRITING_FLAG_OK = 0;
    private const WRITING_FLAG_WRITING = 1;
    private const WRITING_FLAG_TIMEOUT = 30;
    private const SHARD_COUNT = 16;
    private const INIT_LOCK_NAME = 'file_global_init';

    private const HEADER_FORMAT = 'NCNNNNNNN';
    private const HEADER_UNPACK_FORMAT = 'Nmagic_number/Cversion/Ndata_capacity/Nwriting_flag/Nwriting_start_time/Nblock_size/Nmax_var_number/Nbitmap_length/Nreserved';
    private const HEADER_SIZE = 33;

    private const INDEX_HEADER_LENGTH = 2;
    private const INDEX_FORMAT = 'NNN';
    private const INDEX_UNPACK_FORMAT = 'Ncrc32/Nv_crc32/Nmeta_order';
    private const INDEX_LENGTH = 12;
    private const INDEX_ORDER_FREE = 0xFFFFFFFF;

    private const META_LENGTH = 25;
    private const META_FORMAT = 'cNNNNNN';
    private const META_UNPACK_FORMAT = 'cstatus/Ntimestamp/Nblock_length/Nstring_length/Noffset/Nlast_use/Ndata_crc32';

    private const DEFAULT_BLOCK_SIZE = 4096;
    private const BITS_PER_BYTE = 8;

    private const MAX_HOT_CACHE_COUNT = 200;
    private const META_CACHE_TTL = 30;
    private const MAX_META_CACHE_COUNT = 500;

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
    public function db(string $dbAlias, array $customConfig = []):()


### /**
     * 初始化数据库实例
     * @param string $dbAlias 数据库别名
     * @param array $config 配置参数
     * @return void
     */
    private function initDbInstance(string $dbAlias, array $config):()


### /**
     * 切换到目标数据库实例
     * @param string|null $dbAlias 数据库别名
     * @return void
     */
    private function switchToDb(?string $dbAlias):()


### /**
     * 获取数据库实例属性
     * @param string $dbAlias 数据库别名
     * @param string $key 属性名
     * @return mixed 属性值
     */
    private function getDbAttr(string $dbAlias, string $key):()


### /**
     * 设置数据库实例属性
     * @param string $dbAlias 数据库别名
     * @param string $key 属性名
     * @param mixed $val 属性值
     * @return void
     */
    private function setDbAttr(string $dbAlias, string $key, mixed $val):()


### /**
     * 获取目标数据库别名
     * @return string 数据库别名
     */
    private function takeTargetDb():()


### /**
     * 加载索引和位图
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function loadIndexAndBitmap(string $dbAlias):()


### /**
     * 保存位图到文件
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function saveBitmap(string $dbAlias):()


### /**
     * 更新索引映射
     * @param string $memKey 内存Key
     * @param int $crc32 主CRC
     * @param int $vCrc32 校验CRC
     * @param int $metaOrder 元数据序号
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function updateIndex(string $memKey, int $crc32, int $vCrc32, int $metaOrder, string $dbAlias):()


### /**
     * 释放索引槽位
     * @param int $metaOrder 元数据序号
     * @param string $dbAlias 数据库别名
     * @return void
     */
    private function freeIndex(int $metaOrder, string $dbAlias):()

