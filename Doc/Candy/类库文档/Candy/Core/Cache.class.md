# Cache 类

## 概述

*
 * Candy框架 缓存抽象类接口
 * 这是一个抽象类，定义了缓存操作的标准接口，需要被具体的缓存驱动类实现
 * 支持多种缓存驱动：Memory、Apcu、Redis、Memcached、Shmop和GlobalData
 * 提供统一的缓存操作接口，包括设置、获取、删除缓存等功能
 * 支持分布式锁机制和连接池管理，确保在高并发环境下的数据一致性
 *
 * @package Candy\Core
 * @author: 刘森 (fingerboy@qq.com) $
 * @date: 2023-10-23 10:43:14 $
 * @abstract 抽象类，不能直接实例化

## 主要方法


### /***
 * Candy框架 缓存抽象类接口
 * 这是一个抽象类，定义了缓存操作的标准接口，需要被具体的缓存驱动类实现
 * 支持多种缓存驱动：Memory、Apcu、Redis、Memcached、Shmop和GlobalData
 * 提供统一的缓存操作接口，包括设置、获取、删除缓存等功能
 * 支持分布式锁机制和连接池管理，确保在高并发环境下的数据一致性
 *
 * @package Candy\Core
 * @author: 刘森 (fingerboy@qq.com) $
 * @date: 2023-10-23 10:43:14 $
 * @abstract 抽象类，不能直接实例化
 */

declare(strict_types=1);

namespace Candy\Core;

use Candy\Core\Cache\Pack\Vector\VectorService;
use Candy\Core\Cache\Pack\Geo\GeoService;
use Candy\Core\Cache\Pack\HyperLogLog\HyperLogLogService;
use Candy\Core\Cache\Pack\CuckooFilter\CuckooFilterService;

abstract class Cache
{
    /**
     * 驱动单例实例池（按配置唯一标识隔离）
     * @var array<string, static>
     */
    private static array $instancePool = [];
    
    /**
     * 缓存前缀（全驱动统一处理）
     * @var string
     */
    protected string $cachePrefix = '';

    /**
     * 锁重试间隔（微秒，全驱动统一）
     * @var int
     */
    protected int $retryInterval;

    //事务
    private bool $inTransaction = false; // 是否开启事务
    private array $transactionQueue = []; // 事务命令队列

    // 缓存统计
    protected int $hits = 0; // 命中次数
    protected int $misses = 0; // 未命中次数

    // 热点 key 自动续期
    protected int $autoRenewThreshold = 360; // 自动续期阈值（秒）
    protected int $defaultTtl = 3600; // 默认过期时间（秒）

    // 空值缓存
    protected bool $cacheNullValues = true; // 是否缓存空值
    protected int $nullValueTtl = 300; // 空值缓存过期时间（秒）

    // HyperLogLog 寄存器数（默认2048，可配置调整精度）
    protected const HLL_REGISTERS = 2048;

    // Geo 哈希位数（默认50，可配置调整精度）
    protected const GEO_HASH_BITS = 50;

    // 布隆过滤器默认参数
    protected const BLOOM_DEFAULT_CAPACITY = 100000;
    protected const BLOOM_DEFAULT_ERROR_RATE = 0.001;

    /**
     * 构造函数，初始化缓存前缀和重试间隔
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->cachePrefix = $config['prefix'] ?? $config['cachePrefix'] ?? 'Candy_';
        $this->retryInterval = $config['retryInterval'] ?? 1000;
        
        // 缓存统计
        $this->hits = 0;
        $this->misses = 0;
        
        // 热点 key 自动续期
        $this->autoRenewThreshold = $config['autoRenewThreshold'] ?? 360;
        $this->defaultTtl = $config['defaultTtl'] ?? 3600;
        
        // 空值缓存
        $this->cacheNullValues = $config['cacheNullValues'] ?? true;
        $this->nullValueTtl = $config['nullValueTtl'] ?? 300;
    }

    /**
     * 添加缓存前缀（统一前缀处理，避免重复拼接）
     * @param string $key 原始键名
     * @return string 带前缀的完整键名
     */
    protected function prefix(string $key):()


### /**
     * 获取当前实例的驱动配置名
     * @return bool 不是文件缓存驱动
     */
    final public static function notUsedFile(): bool
    {
        [$driverName] = self::getDriverNameAndConfig();
        return $driverName != __NAMESPACE__ . '\\Cache\\File';
    }

    /**
     * 获取缓存驱动实例
     * @param string|null $store 配置名
     * @return static
     */
    final public static function store(?string $store = null): static
    {
        // 获取驱动名称和配置
        [$driverName, $config, $driverPath] = self::getDriverNameAndConfig($store);

        //如果$driverPath不为空则是实现类先加载文件
        if(!empty($driverPath)){
            require_once($driverPath);
        }

        //需要先检查GlobalData服务器是否存在
        if($driverName == __NAMESPACE__ . '\\Cache\\GlobalData'){
            //先尝试GlobalData服务器
			if (class_exists('\GlobalData\Client') === false) {
				require_once(CANDYPATH . 'Candy/Vendor/Workerman/GlobalData/Client.php');
			}
			$address = ($config['host'] ??= '127.0.0.1') . ':' . ($config['port'] ??= 2207);
			$globaldata = new \GlobalData\Client($address);
			$isConnected = $globaldata->getStatus();
			if ($isConnected === false) {
				showExceptionMsg('shutdown', '缓存驱动<b>GlobalData</b>链接服务器失败，请检查。');
			}
        }
        
        // 生成配置唯一标识（同配置同实例，不同配置隔离）
        $instanceKey = self::generateInstanceKey($driverName, $config);

        // 已实例化直接返回，彻底避免重复初始化
        if (isset(self::$instancePool[$instanceKey])) {
            return self::$instancePool[$instanceKey];
        }
        
        if (!class_exists($driverName) || !is_subclass_of($driverName, self::class)) {
            showExceptionMsg('shutdown', '不支持的缓存驱动：<b>' . $driverName . '</b>。');
        }
        
        // 实例化并写入单例池
        $instance = new $driverName($config);
        self::$instancePool[$instanceKey] = $instance;

        return $instance;
    }
    
    /**
     * 执行事务性操作（闭包方式）
     * 使用全局锁确保所有操作在锁保护下执行，确保原子性和隔离性
     * @param callable $callback 回调函数，接收缓存实例作为参数
     * @return mixed 回调函数的返回值
     * @throws \RuntimeException 无法获取事务锁时抛出
     * @throws \Throwable 回调函数中抛出的异常
     */
    public function transactional(callable $callback)
    {
        $lockName = 'transaction';
        $lockTTL = 30; // 锁的TTL为30秒
        
        // 尝试获取锁
        if (!$this->__acquireLock($lockName, $lockTTL)) {
            throw new \RuntimeException('Failed to acquire transaction lock');
        }
        
        try {
            // 执行回调，传入当前缓存实例
            $result = $callback($this);
            return $result;
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            // 释放锁
            $this->__releaseLock($lockName);
        }
    }

    /**
     * 开启事务（MULTI）
     * 后续写操作入队缓存，不立即执行
     */
    public function multi():()


### /**
     * 执行事务（EXEC）
     * 批量执行所有队列命令，使用回滚机制保证原子性
     */
    public function exec():()


### /**
     * 丢弃事务（DISCARD）
     * 清空队列，关闭事务
     */
    public function discard():()


### /**
     * 处理 hGet 操作的结果
     */
    private function handleHGetResult($result, array $params)
    {
        if (isset($params[1])) {
            $field = $params[1];
            $default = $params[2] ?? null;
            return is_array($result) ? ($result[$field] ?? $default) :()


### /**
     * 处理 zRange 操作的结果
     */
    private function handleZRangeResult($result, array $params)
    {
        if (is_array($result) && !empty($result)) {
            $start = $params[1] ?? 0;
            $end = $params[2] ?? -1;
            $withScore = $params[3] ?? false;
            
            // 按分数正序排序
            asort($result, SORT_NUMERIC);
            $list = $end === -1 ? array_slice($result, $start) :()


### /**
     * 处理 zRevRange 操作的结果
     */
    private function handleZRevRangeResult($result, array $params)
    {
        if (is_array($result) && !empty($result)) {
            $start = $params[1] ?? 0;
            $end = $params[2] ?? -1;
            $withScore = $params[3] ?? false;
            
            // 按分数倒序排序
            arsort($result, SORT_NUMERIC);
            $list = $end === -1 ? array_slice($result, $start) :()


### /**
     * 处理 zScore 操作的结果
     */
    private function handleZScoreResult($result, array $params)
    {
        $member = $params[1] ?? null;
        return is_array($result) && $member !== null ? ($result[(string)$member] ?? null) :()


### /**
     * 处理 zRank 操作的结果
     */
    private function handleZRankResult($result, array $params)
    {
        $member = $params[1] ?? null;
        if (is_array($result) && !empty($result) && $member !== null) {
            asort($result, SORT_NUMERIC);
            $rank = array_search((string)$member, array_keys($result));
            return $rank === false ? null :()


### /**
     * 处理 zRevRank 操作的结果
     */
    private function handleZRevRankResult($result, array $params)
    {
        $member = $params[1] ?? null;
        if (is_array($result) && !empty($result) && $member !== null) {
            arsort($result, SORT_NUMERIC);
            $rank = array_search((string)$member, array_keys($result));
            return $rank === false ? null :()

