<?php
/***
 * 缓存抽象基类
 *
 * 业务说明：统一 KV/结构 API、单例池、空值仅本地负缓存、热点续期采样；驱动见 Impl/Cache
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-10-24 11:06:07 $
 *
 * @package Candy\Core
 * @abstract
 */

declare(strict_types=1);

namespace Candy\Core;

abstract class Cache
{
    // 加载 Trait
    use Trait\Cache\Lock\WithLock, Trait\Cache\Helper, Trait\Cache\NestedKey, Trait\Cache\Transaction, Trait\Cache\Bitmap, Trait\Cache\Cuckoo, Trait\Cache\Geo, Trait\Cache\Hash, Trait\Cache\HyperLog, Trait\Cache\RateLimit, Trait\Cache\Set, Trait\Cache\Stack, Trait\Cache\Stream, Trait\Cache\Vector, Trait\Cache\ZSet, Trait\Cache\ListOps;

    /**
     * 驱动单例实例池（按配置唯一标识隔离）
     * @var array<string, static>
     */
    private static array $instancePool = [];

    /**
     * 锁重试间隔（微秒，全驱动统一）
     * @var int
     */
    protected int $retryInterval;

    // 缓存统计
    protected int $hits = 0; // 命中次数
    protected int $misses = 0; // 未命中次数

    // 热点 key 自动续期
    protected int $autoRenewThreshold = 360; // 自动续期阈值（秒）
    protected int $defaultTtl = 3600; // 默认过期时间（秒）

    /** Redis Cluster 是否将租户 ID 放入 hash tag；其他驱动忽略。 */
    protected bool $clusterHashTag = false;

    /**
     * 是否启用本地负缓存（仅进程内，禁止写远端 STRING，避免污染 List/Hash）
     */
    protected bool $cacheNullValues = true;

    /** 负缓存 TTL（秒）；驱动 LocalHotCache 默认用 NULL_CACHE_TTL，此字段保留兼容配置 */
    protected int $nullValueTtl = 5;

    /** 热点续期采样分母：1=每次续期，10=约 10% */
    protected int $autoRenewSampleRate = 10;

    /** @var object|null get() 未命中哨兵（避免与合法 null/false 混淆） */
    private static ?object $missToken = null;

    /**
     * 构造函数，初始化缓存前缀和重试间隔
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->cachePrefix = $config['prefix'] ?? $config['cachePrefix'] ?? 'Candy_';
        $this->clusterHashTag = (bool) ($config['clusterHashTag'] ?? false);
        
        // 初始化事务方法
        $this->initTransactionMethods();
        $this->retryInterval = $config['retryInterval'] ?? 1000;
        
        // 缓存统计
        $this->hits = 0;
        $this->misses = 0;
        
        // 热点 key 自动续期
        $this->autoRenewThreshold = $config['autoRenewThreshold'] ?? 360;
        $this->defaultTtl = $config['defaultTtl'] ?? 3600;
        $this->autoRenewSampleRate = max(1, (int)($config['autoRenewSampleRate'] ?? 10));
        
        // 空值仅本地负缓存（默认开启；写远端请显式关闭或改驱动）
        $this->cacheNullValues = (bool)($config['cacheNullValues'] ?? true);
        $this->nullValueTtl = max(1, (int)($config['nullValueTtl'] ?? 5));
    }

    /**
     * 未命中哨兵对象（仅全等比较，不会与业务值冲突）
     */
    final protected static function missToken(): object
    {
        return self::$missToken ??= new \stdClass();
    }

    /**
     * 读操作禁止进入事务队列
     *
     * @param string $operation 操作名
     * @throws \RuntimeException
     */
    final protected function assertReadableOutsideTransaction(string $operation): void
    {
        if ($this->inTransaction) {
            throw new \RuntimeException(
                'Cache read operation "' . $operation . '" cannot be used inside a transaction'
            );
        }
    }

    /**
     * 比较缓存值是否相等（数组/对象走 serialize，避免 CAS 假失败）
     */
    final protected function valuesEqual(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if (is_array($left) || is_array($right) || is_object($left) || is_object($right)) {
            try {
                return serialize($left) === serialize($right);
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }

    /**
     * 检查缓存值是否可以通过统一二进制协议编码。
     *
     * 不可编码值在缓存公共入口直接拒绝，避免进入具体驱动后重复尝试打包、写入坏键或产生误导性日志。
     *
     * @param mixed $value 待写入的缓存值
     * @return bool 可以编码返回 true，否则返回 false
     */
    final protected function canPackCacheValue(mixed $value): bool
    {
        return \Candy\Core\Impl\Pack\Binary::canPack(['data' => $value]);
    }

    /**
     * 本地负缓存钩子（LocalHotCache 驱动覆盖；默认无操作）
     */
    protected function rememberLocalMiss(string $prefixedKey): void
    {
        unset($prefixedKey);
    }

    /**
     * 获取当前实例的驱动配置名
     * @return bool 不是文件缓存驱动
     */
    /**
     * 当前驱动是否为 File 驱动
     *
     * @param string|null $store 配置名
     * @return bool
     */
    final public static function isFileDriver(?string $store = null): bool
    {
        [$driverName] = self::getDriverNameAndConfig($store);
        return $driverName === __NAMESPACE__ . '\\Impl\\Cache\\File';
    }

    /**
     * 当前驱动是否可跨进程 / 跨机共享
     *
     * 只用于「要不要放进共享缓存」这类判断（会话、分布式状态）。
     * 注意：File / Shmop / Memory 都是本地的，返回 false。
     *
     * @param string|null $store 配置名
     * @return bool
     */
    final public static function isSharedCache(?string $store = null): bool
    {
        [$driverName] = self::getDriverNameAndConfig($store);
        foreach ([
            __NAMESPACE__ . '\\Impl\\Cache\\Redis',
            __NAMESPACE__ . '\\Impl\\Cache\\Memcached',
            __NAMESPACE__ . '\\Impl\\Cache\\Gd',
        ] as $shared) {
            if ($driverName === $shared) {
                return true;
            }
        }
        return false;
    }

    /**
     * 改 prefix 是否等于「换一份独立存储」
     *
     * File：prefix 参与 .data 文件名 → true（可用来做零配置的逻辑分区）
     * Redis/Memcached：prefix 只是 key 命名空间，物理上同一池 → false
     * Shmop：段按 ftok 派生，prefix 不影响段 → false
     *
     * @param string|null $store 配置名
     * @return bool
     */
    final public static function prefixScopesStorage(?string $store = null): bool
    {
        return self::isFileDriver($store);
    }

    /**
     * 取某个 store 的最终配置（供派生实例覆盖 prefix 等）
     *
     * @param string|null $store 配置名
     * @return array
     */
    final public static function storeConfig(?string $store = null): array
    {
        [, $config] = self::getDriverNameAndConfig($store);
        return $config;
    }

    /**
     * 获取缓存驱动实例
     * @param string|null $store 配置名
     * @return static
     */
    final public static function store(?string $store = null, array $overrides = []): static
    {
        $requestedStore = $store;
        // 获取驱动名称和配置
        [$driverName, $config, $driverPath] = self::getDriverNameAndConfig($store);

        // 调用方可以覆盖少量配置项（如 prefix），用于「同一驱动、独立命名空间」的派生实例。
        // 实例池 key 由最终 config 生成，因此覆盖后会自动得到独立实例。
        if ($overrides !== []) {
            $config = array_merge($config, $overrides);
        }

        //如果$driverPath不为空则是实现类先加载文件
        if(!empty($driverPath)){
            require_once($driverPath);
        }

        //需要先检查 GlobalData(Gd) 服务器是否存在
        if($driverName == __NAMESPACE__ . '\\Impl\\Cache\\Gd'){
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
        $storeName = self::resolveStoreName($requestedStore);
        if ($storeName === 'queue') {
            $instance->requireCapabilities(['durable_queue', 'consumer_group']);
        } elseif ($storeName === 'state') {
            $instance->requireCapabilities('distributed_state');
        }
        self::$instancePool[$instanceKey] = $instance;

        return $instance;
    }
    
    /**
     * 静态方法代理，原有调用方式完全兼容
     * @param string $method 方法名
     * @param array $arguments 参数
     * @return mixed
     */
    final public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::store()->$method(...$arguments);
    }
    
    /**
     * 清空实例池（用于配置热更新/测试）
     * @param string|null $store 指定清空的配置名，null清空全部
     */
    final public static function flushInstance(?string $store = null): void
    {
        if ($store !== null) {
            // 获取驱动名称和配置，与 store 函数保持一致
            [$driverName, $config] = self::getDriverNameAndConfig($store);
            
            if (!empty($driverName)) {
                $instanceKey = self::generateInstanceKey($driverName, $config);
                unset(self::$instancePool[$instanceKey]);
            }
            return;
        }
        self::$instancePool = [];
    }
    
    /**
     * 生成配置唯一标识（含连接参数，避免热更新后仍复用旧连接）
     */
    private static function generateInstanceKey(string $store, array $config): string
    {
        $normalized = self::normalizeConfigForInstanceKey($config);
        return $store . '_' . md5((string) json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 递归规范化配置键（排序后便于稳定哈希）
     *
     * @param mixed $value 配置片段
     * @return mixed
     */
    private static function normalizeConfigForInstanceKey(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = self::normalizeConfigForInstanceKey($v);
        }
        return $value;
    }

    /**
     * 获取驱动名称和配置
     * @param string|null $store 配置名
     * @return array [驱动名称, 配置, 驱动路径]
     */
    private static function getDriverNameAndConfig(?string $store = null): array
    {
        // 加载配置
	    $cacheInc = loadConfig('Config') ?? [];
        
        //传递优先 然后是配置（default 为主键，driver 为历史兼容别名）
        $cacheConfig = $cacheInc['CACHE'] ?? [];
        $store ??= ($cacheConfig['default'] ?? $cacheConfig['driver'] ?? 'auto');
        $config = $cacheConfig['options'] ?? [];

        if (isset($cacheConfig['stores'][$store]) && is_array($cacheConfig['stores'][$store])) {
            $named = self::resolveStoreConfig($cacheConfig, (string) $store);
            $storeName = (string) $store;
            $store = $named['driver'] ?? $named['default'] ?? 'auto';
            $config = $named['options'] ?? [];
            if ($config === [] && isset($named['servers'])) {
                $config = $named;
                unset($config['driver'], $config['default']);
            }
            if ($storeName === 'state') {
                $config['enableLocalCache'] = false;
            }
            // 命名 Store 默认独立键空间；显式 prefix 时尊重业务配置。
            $config['prefix'] ??= 'Candy_' . $storeName . '_';
        }

        //不是自动选择
        $driverName = $driverPath = '';
        $driverLabel = (string) $store;
        if($store != 'auto'){
            //根据命名空间判断路径
            if(stripos($store, '\\') === false){
                // 映射驱动类（GlobalData 等别名统一到 Gd）
                $normalized = strtolower($store);
                $driver = match ($normalized) {
                    'globaldata', 'gd' => 'Gd',
                    default => ucfirst($normalized),
                };
                $driverLabel = $driver;
                $driverName = __NAMESPACE__ . '\\Impl\\Cache\\' . $driver;
            } else {
                //有命名空间
			    $driverRightPath = trim(strtr($store, '\\', '/'), '/');
                if(stripos($store, 'Candy\\Core\\') !== false && is_file(CANDYPATH . $driverRightPath . '.class.php')){
                    //核心类库
                    $driverName = $store;
                    $driverPath = CANDYPATH . $driverRightPath . '.class.php';
                    $driverLabel = $store;
                } elseif (stripos($store, 'APP\\Extend\\') !== false && is_file(C('SOURCEPATH') . 'Class/' . $driverRightPath . '.class.php')){
                    //项目通用驱动
                    $driverName = $store;
                    $driverPath = C('SOURCEPATH') . 'Class/' . $driverRightPath . '.class.php';
                    $driverLabel = $store;
                }elseif (is_file(C('SOURCEPATH') . 'Vendor/' . $driverRightPath . '.php')){
                    //第三方驱动
                    $driverName = $store;
                    $driverPath = C('SOURCEPATH') . 'Vendor/' . $driverRightPath . '.php';
                    $driverLabel = $store;
                }
            }

            //自定义的必须检查加载
            if(empty($driverName)){
                showExceptionMsg('shutdown', '缓存驱动<b>' . $store . '</b>不存在，请检查。');
            }
        } else {
            //自动选择缓存驱动 这种情况只能加载内置缓存驱动
            //CLI模式
            if(isWebServer()){
                //CLI缓存模式
                $driverLabel = '自动匹配的Memory';
                $driverName = __NAMESPACE__ . '\\Impl\\Cache\\Memory';
            } 
            else if (extension_loaded('shmop')){
                //自动匹配的shmop
                $driverLabel = '自动匹配的Shmop';
                $driverName = __NAMESPACE__ . '\\Impl\\Cache\\Shmop';
            }else {
                //默认使用文件
                $driverLabel = '自动匹配的File';
                $driverName = __NAMESPACE__ . '\\Impl\\Cache\\File';
            }
        }

        // 仅非 File 驱动提示一次（条件修正：去掉恒真的 || is_null）
        if (
            $driverName !== __NAMESPACE__ . '\\Impl\\Cache\\File'
            && C('SHOWTHECACHEUSETYPEMSG') === null
        ) {
            C('SHOWTHECACHEUSETYPEMSG', 1);
            \Candy\Core\Debug::addMsg('<font color=\'green\'>使用' . $driverLabel . '类库开启变量缓存。</font>');
        }
        
        return [$driverName, $config, $driverPath];
    }

    /** 获取命名 Store 名称；直接使用驱动名时返回 null。 */
    private static function resolveStoreName(?string $store): ?string
    {
        $cacheConfig = loadConfig('Config')['CACHE'] ?? [];
        $name = $store ?? ($cacheConfig['default'] ?? $cacheConfig['driver'] ?? null);
        return isset($cacheConfig['stores'][$name]) ? (string) $name : null;
    }

    /** 递归解析命名 Store，支持 extends 且检测循环继承。 */
    private static function resolveStoreConfig(array $cacheConfig, string $name, array $stack = []): array
    {
        if (isset($stack[$name])) {
            throw new \RuntimeException('缓存 Store 存在循环继承：' . implode(' -> ', array_keys($stack)) . ' -> ' . $name);
        }
        $named = $cacheConfig['stores'][$name] ?? [];
        if (!is_array($named)) {
            throw new \RuntimeException('缓存 Store 配置必须为数组：' . $name);
        }
        $stack[$name] = true;
        $parentName = $named['extends'] ?? null;
        unset($named['extends']);
        if ($parentName === null) {
            return $named;
        }
        if (!is_string($parentName) || !isset($cacheConfig['stores'][$parentName])) {
            throw new \RuntimeException('缓存 Store 继承目标不存在：' . (string) $parentName);
        }
        return self::mergeStoreConfig(
            self::resolveStoreConfig($cacheConfig, $parentName, $stack),
            $named
        );
    }

    /** 递归合并 Store 配置，子配置覆盖父配置。 */
    private static function mergeStoreConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::mergeStoreConfig($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /** 返回当前驱动的能力清单。 */
    public function capabilities(): array
    {
        $driver = strtolower((new \ReflectionClass($this))->getShortName());
        $base = [
            'kv' => ['support' => true, 'mode' => 'native', 'distributed' => false, 'durable' => false, 'atomic' => false],
            'structures' => ['support' => true, 'mode' => 'emulated', 'distributed' => false, 'durable' => false, 'atomic' => false],
            'distributed_lock' => ['support' => false, 'mode' => 'unavailable', 'distributed' => false, 'durable' => false, 'atomic' => false],
            'distributed_state' => ['support' => false, 'mode' => 'unavailable', 'distributed' => false, 'durable' => false, 'atomic' => false],
            'consumer_group' => ['support' => false, 'mode' => 'unavailable', 'distributed' => false, 'durable' => false, 'atomic' => false],
            'durable_queue' => ['support' => false, 'mode' => 'unavailable', 'distributed' => false, 'durable' => false, 'atomic' => false],
            'pending_recovery' => ['support' => false, 'mode' => 'unavailable', 'distributed' => false, 'durable' => false, 'atomic' => false],
            'atomic_mutation' => ['support' => false, 'mode' => 'unavailable', 'distributed' => false, 'durable' => false, 'atomic' => false],
        ];
        if ($driver === 'redis') {
            $base['kv'] = ['support' => true, 'mode' => 'native', 'distributed' => true, 'durable' => false, 'atomic' => true];
            foreach (['structures', 'distributed_lock', 'distributed_state', 'consumer_group', 'durable_queue', 'atomic_mutation'] as $name) {
                $base[$name] = ['support' => true, 'mode' => 'native', 'distributed' => true, 'durable' => in_array($name, ['distributed_state', 'durable_queue'], true), 'atomic' => true];
            }
        } elseif ($driver === 'memcached') {
            $base['kv'] = ['support' => true, 'mode' => 'native', 'distributed' => true, 'durable' => false, 'atomic' => true];
            $base['distributed_state'] = ['support' => true, 'mode' => 'native', 'distributed' => true, 'durable' => false, 'atomic' => true];
            $base['atomic_mutation'] = ['support' => true, 'mode' => 'native', 'distributed' => true, 'durable' => false, 'atomic' => true];
        } elseif ($driver === 'gd' || $driver === 'shmop') {
            foreach (['distributed_lock', 'distributed_state', 'atomic_mutation'] as $name) {
                $base[$name] = ['support' => true, 'mode' => 'local', 'distributed' => false, 'durable' => false, 'atomic' => true];
            }
        } elseif ($driver === 'memory') {
            $base['kv']['mode'] = 'local';
            $base['structures']['mode'] = 'local';
        } elseif ($driver === 'file') {
            $base['atomic_mutation'] = ['support' => true, 'mode' => 'local', 'distributed' => false, 'durable' => true, 'atomic' => true];
        }
        foreach ($this->driverCapabilities() as $name => $capability) {
            $base[$name] = array_replace($base[$name] ?? [], $capability);
        }
        return $base;
    }

    /** 驱动覆盖点；返回能力名到字段的增量定义。 */
    protected function driverCapabilities(): array
    {
        return [];
    }

    /**
     * Capability
     * @param mixed $name
     * @return array
     */
    public function capability(string $name): array
    {
        return $this->capabilities()[$name] ?? ['support' => false, 'mode' => 'unavailable', 'distributed' => false, 'durable' => false, 'atomic' => false];
    }

    /**
     * Supports
     * @param mixed $name
     * @param mixed $mode
     * @return bool
     */
    public function supports(string $name, ?string $mode = null): bool
    {
        $capability = $this->capability($name);
        return (bool) $capability['support'] && ($mode === null || $capability['mode'] === $mode);
    }

    /**
     * Require Capabilities
     * @param mixed $names
     * @return static
     */
    public function requireCapabilities(string|array $names): static
    {
        foreach ((array) $names as $name) {
            if (!$this->supports((string) $name)) {
                throw new \RuntimeException('缓存驱动不具备所需能力：' . $name);
            }
        }
        return $this;
    }

    /**
     * 写入缓存
     * @param string $key 原始key（自动加前缀）
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间，0=永久
     * @return bool
     */
    /** WAL 操作类型：写 */
    protected const WAL_OP_SET = 1;

    /** WAL 操作类型：删 */
    protected const WAL_OP_DEL = 2;

    /** WAL 操作类型：改过期时间 */
    protected const WAL_OP_EXPIRE = 3;

    /**
     * WAL 记录钩子（默认空实现 = 零开销）
     *
     * 需要「崩溃后不丢」的驱动（Memory/Shmop，靠 dump 落盘的）覆写此方法，
     * 把每次写操作追加到预写日志；Redis 这类本身持久化的驱动不必实现。
     *
     * @param int    $op        WAL_OP_SET / WAL_OP_DEL / WAL_OP_EXPIRE
     * @param string $prefixedKey 已加过前缀的 key（重放时直接交给 __rawSet/__rawDelete）
     * @param mixed  $value     写入的值（EXPIRE 时为 ttl 秒数）
     * @param int    $ttl       过期秒数
     * @return void
     */
    protected function walNote(int $op, string $prefixedKey, mixed $value = null, int $ttl = 0): void
    {
        // 默认不做任何事
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->canPackCacheValue($value)) {
            return false;
        }

        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        if ($ttl > 0) {
            // 添加±5%的随机偏移量
            $ttl += random_int(-(int)($ttl * 0.05), (int)($ttl * 0.05));
        }
        $prefixedKey = $this->prefix($key);
        $ok = $this->__rawSet($prefixedKey, $value, $ttl);
        if ($ok) {
            $this->walNote(self::WAL_OP_SET, $prefixedKey, $value, $ttl);
        }
        return $ok;
    }

    /**
     * 读取缓存
     *
     * 未命中用哨兵区分合法 null/false；负缓存仅本地，不写远端 STRING
     *
     * @param string $key 原始key（自动加前缀）
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertReadableOutsideTransaction('get');
        $token = self::missToken();
        $prefixed = $this->prefix($key);
        $result = $this->__rawGet($prefixed, $token);
        if ($result === $token) {
            $this->misses++;
            if ($this->cacheNullValues) {
                $this->rememberLocalMiss($prefixed);
            }
            return $default;
        }
        $this->hits++;
        // 热点续期：剩余 TTL 低于阈值时按采样续期，降低写放大
        if ($this->autoRenewSampleRate <= 1 || random_int(1, $this->autoRenewSampleRate) === 1) {
            $ttl = $this->ttl($key);
            if ($ttl > 0 && $ttl < $this->autoRenewThreshold) {
                $this->expire($key, $this->defaultTtl);
            }
        }
        return $result;
    }

    /**
     * 判断缓存是否存在
     * @param string $key 原始key（自动加前缀）
     * @return bool
     */
    public function has(string $key): bool
    {
        $this->assertReadableOutsideTransaction('has');
        return $this->__rawHas($this->prefix($key));
    }

    /**
     * 删除缓存
     * @param string $key 原始key（自动加前缀）
     * @return bool
     */
    public function delete(string $key): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $prefixedKey = $this->prefix($key);
        $ok = $this->__rawDelete($prefixedKey);
        if ($ok) {
            $this->walNote(self::WAL_OP_DEL, $prefixedKey);
        }
        return $ok;
    }

    /**
     * 获取缓存剩余过期时间
     * @param string $key 原始key（自动加前缀）
     * @return int 剩余秒数，0=永久，-1=不存在/已过期
     */
    public function ttl(string $key): int
    {
        $this->assertReadableOutsideTransaction('ttl');
        return $this->__ttl($this->prefix($key));
    }

    /**
     * 清空当前作用域缓存
     *
     * 有 TENANT_ID 时仅清该租户键空间；无租户时清默认/平台空间（不含其它租户的 t/{id}/ 段）
     * Redis / Memcached / Memory 按 scopePrefix 扫描；以 @ 写入的共享键在无租户时可能一并清理
     *
     * @return bool
     */
    public function clear(): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__clear();
    }

    /**
     * 逻辑键解析为当前请求下的物理键（调试或直连驱动时用）
     *
     * @param string $key 逻辑键（业务键或以 @ 开头的共享键）
     * @return string
     */
    final public function resolveKey(string $key): string
    {
        return $this->prefix($key);
    }

    /**
     * 标记为平台共享逻辑键（不按 TENANT_ID 隔离）
     *
     * @param string $key 业务键名（可已带 @，不会重复叠加）
     * @return string 以 @ 开头的逻辑键，供 get/set/delete 等使用
     * @static
     */
    final public static function sharedKey(string $key): string
    {
        $mark = self::SHARED_KEY_MARK;
        return str_starts_with($key, $mark) ? $key : $mark . $key;
    }

    /**
     * 批量写入缓存
     * @param array $items 键值对数组（原始key，自动加前缀）
     * @param int $ttl 过期时间，0=永久
     * @return bool
     */
    public function mSet(array $items, int $ttl = 0): bool
    {
        foreach ($items as $value) {
            if (!$this->canPackCacheValue($value)) {
                return false;
            }
        }

        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $prefixedItems = [];
        foreach ($items as $key => $value) {
            $prefixedItems[$this->prefix($key)] = $value;
        }
        return $this->__rawMSet($prefixedItems, $ttl);
    }

    /**
     * 批量读取缓存
     * @param array $keys 原始key数组（自动加前缀）
     * @param mixed $default 单个key的默认值
     * @return array 键值对结果数组（原始key为键）
     */
    public function mGet(array $keys, mixed $default = null): array
    {
        $this->assertReadableOutsideTransaction('mGet');
        $prefixedMap = [];
        $originalMap = [];
        foreach ($keys as $key) {
            $prefixedKey = $this->prefix($key);
            $prefixedMap[] = $prefixedKey;
            $originalMap[$prefixedKey] = $key;
        }

        $token = self::missToken();
        $rawResult = $this->__rawMGet($prefixedMap, $token);
        $result = [];
        foreach ($rawResult as $prefixedKey => $value) {
            $originalKey = $originalMap[$prefixedKey] ?? $prefixedKey;
            if ($value === $token) {
                $this->misses++;
                if ($this->cacheNullValues) {
                    $this->rememberLocalMiss((string) $prefixedKey);
                }
                $result[$originalKey] = $default;
            } else {
                $this->hits++;
                $result[$originalKey] = $value;
            }
        }
        return $result;
    }

    /**
     * 设置已存在 key 的过期时间
     * @param string $key 原始key（自动加前缀）
     * @param int $ttl 过期时间（秒）
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $prefixedKey = $this->prefix($key);
        $ok = $this->__rawExpire($prefixedKey, $ttl);
        if ($ok) {
            $this->walNote(self::WAL_OP_EXPIRE, $prefixedKey, $ttl, $ttl);
        }
        return $ok;
    }

    /**
     * 移除 key 的过期时间
     * @param string $key 原始key（自动加前缀）
     * @return bool
     */
    public function persist(string $key): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawPersist($this->prefix($key));
    }

    /**
     * 重命名 key
     * @param string $oldKey 原始key（自动加前缀）
     * @param string $newKey 新key（自动加前缀）
     * @return bool
     */
    public function rename(string $oldKey, string $newKey): bool
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        return $this->__rawRename($this->prefix($oldKey), $this->prefix($newKey));
    }

    /**
     * 浮点数自增
     * @param string $key 原始key（自动加前缀）
     * @param float $step 步长
     * @return float|false 自增后的值，失败返回false
     */
    public function incrByFloat(string $key, float $step = 1.0): float|false
    {
        // 事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0.0;
        }
        return $this->__rawIncrByFloat($this->prefix($key), $step);
    }
    /**
     * 批量删除缓存
     * @param array $keys 原始key数组（自动加前缀）
     * @return bool
     */
    public function mDelete(array $keys): bool
    {
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return true;
        }
        $prefixedKeys = [];
        foreach ($keys as $key) {
            $prefixedKeys[] = $this->prefix($key);
        }
        return $this->__rawMDelete($prefixedKeys);
    }

    /**
     * 批量判断缓存是否存在
     * @param array $keys 原始key数组（自动加前缀）
     * @return array 键值对结果数组（原始key为键）
     */
    public function mHas(array $keys): array
    {
        $this->assertReadableOutsideTransaction('mHas');
        $prefixedMap = [];
        foreach ($keys as $key) {
            $prefixedKey = $this->prefix($key);
            $prefixedMap[$prefixedKey] = $key;
        }

        $rawResult = $this->__rawMHas(array_keys($prefixedMap));
        $result = [];
        foreach ($rawResult as $prefixedKey => $exists) {
            $originalKey = $prefixedMap[$prefixedKey] ?? $prefixedKey;
            $result[$originalKey] = $exists;
        }
        return $result;
    }
    /**
     * 原子范围查询
     * @param string $key key
     * @param int $offset 偏移量
     * @param int $length 查询长度
     * @param string $sort 排序字段
     * @param string $order 排序顺序
     * @return array|false 查询结果
     */
    public function range(string $key,int $offset,int $length = 0, $sort = '',string $order = 'asc'): array|false
    {
        return $this->__rawRange($this->prefix($key), $offset, $length, $sort,$order);
    }
    
    /**
     * 原子递增
     * @param string $key key
     * @param int $step 递增步长
     * @return int|bool 递增后的值，事务中返回 true
     */
    public function incr(string $key, int $step = 1): int|bool
	{
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0; // 事务中返回 0，保持类型一致
        }
        return $this->__rawIncr($this->prefix($key), $step, 0);
    }
    
    /**
     * 原子递减
     * @param string $key key
     * @param int $step 递减步长
     * @return int|bool 递减后的值，事务中返回 0
     */
    public function decr(string $key, int $step = 1): int|bool
	{
        //事务支持
        if ($this->inTransaction) {
            $this->queueTransaction(__METHOD__, func_get_args());
            return 0; // 事务中返回 0，保持类型一致
        }
        return $this->__rawIncr($this->prefix($key), $step, 1);
    }
    /**
     * 获取缓存统计信息
     * @return array 统计信息数组
     * @note 缓存统计数据存储在实例属性中，在多进程环境（如 PHP-FPM）下统计数据无法共享，仅能反映当前进程状态。
     *       若需要跨进程共享统计数据，建议由驱动实现共享存储。
     */
    public function getStats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => $this->hits + $this->misses > 0 ? $this->hits / ($this->hits + $this->misses) : 0,
        ];
    }

    /** 返回统一监控快照，驱动可在子类追加连接/队列指标。 */
    public function getMetrics(): array
    {
        return [
            'driver' => (new \ReflectionClass($this))->getShortName(),
            'stats' => $this->getStats(),
            'capabilities' => $this->capabilities(),
        ];
    }

    /**
     * CAS（Compare And Swap）操作
     * @param string $key 原始key（自动加前缀）
     * @param mixed $oldValue 旧值
     * @param mixed $newValue 新值
     * @param int $ttl 过期时间，0=永久
     * @return bool 操作是否成功
     */
    public function cas(string $key, mixed $oldValue, mixed $newValue, int $ttl = 0): bool
    {
        // CAS 操作不能在事务中使用，因为它依赖于实时的 "当前值 vs 旧值" 对比
        if ($this->inTransaction) {
            throw new \RuntimeException('CAS operation cannot be used in transaction');
        }

        if (!$this->canPackCacheValue($newValue)) {
            return false;
        }

        $key = $this->prefix($key);
        try {
            // 加锁后比较并交换
            if (!$this->__acquireLock($key)) return false;
            
            $currentValue = $this->__rawGet($key);
            if ($this->valuesEqual($currentValue, $oldValue)) {
                $result = $this->__rawSet($key, $newValue, $ttl);
            } else {
                $result = false;
            }
            
            $this->__releaseLock($key);
            return $result;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }
    
    /**
     * 基类 key 操作 fallback 读全量告警阈值（字节），0 表示不告警
     */
    protected const KEYOPS_FALLBACK_WARN_BYTES = 262144;

    /**
     * fallback 路径读取 value，DEBUG 下对大 value 输出告警
     */
    protected function keyOpsFallbackLoadValue(string $key, string $operation): mixed
    {
        $value = $this->__rawGet($key);
        if (
            $value !== null
            && self::KEYOPS_FALLBACK_WARN_BYTES > 0
            && defined('DEBUG')
            && DEBUG
        ) {
            $size = is_string($value) ? strlen($value) : strlen(serialize($value));
            if ($size >= self::KEYOPS_FALLBACK_WARN_BYTES) {
                error_log(sprintf(
                    '[Candy Cache] %s fallback loaded large value (%d bytes) for key: %s',
                    $operation,
                    $size,
                    $key
                ));
            }
        }

        return $value;
    }

    /**
     * 设置已存在 key 的过期时间（默认实现）
     * @param string $key 带前缀的完整key
     * @param int $ttl 过期时间（秒）
     * @return bool
     * @note 此实现通过 "读取全值 → 重新写入" 实现，若缓存值很大（如几 MB 的数组），会导致严重的性能损耗。
     *       建议驱动类重写此方法以使用原生命令（如 Redis 的 EXPIRE）。
     */
    protected function __rawExpire(string $key, int $ttl): bool
    {
        try {
            // 加锁后操作
            if (!$this->__acquireLock($key)) return false;
            
            $value = $this->keyOpsFallbackLoadValue($key, 'expire');
            if ($value === null) {
                $this->__releaseLock($key);
                return false;
            }
            
            // 重新设置值和过期时间
            $result = $this->__rawSet($key, $value, $ttl);
            $this->__releaseLock($key);
            return $result;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }

    /**
     * 移除 key 的过期时间（默认实现）
     * @param string $key 带前缀的完整key
     * @return bool
     * @note 此实现通过 "读取全值 → 重新写入" 实现，若缓存值很大（如几 MB 的数组），会导致严重的性能损耗。
     *       建议驱动类重写此方法以使用原生命令（如 Redis 的 PERSIST）。
     */
    protected function __rawPersist(string $key): bool
    {
        try {
            // 加锁后操作
            if (!$this->__acquireLock($key)) return false;
            
            $value = $this->keyOpsFallbackLoadValue($key, 'persist');
            if ($value === null) {
                $this->__releaseLock($key);
                return false;
            }
            
            // 重新设置值，永不过期
            $result = $this->__rawSet($key, $value, 0);
            $this->__releaseLock($key);
            return $result;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }

    /**
     * 重命名 key（默认实现）
     * @param string $oldKey 带前缀的完整key
     * @param string $newKey 带前缀的完整key
     * @return bool
     * @note 此实现通过 "读取全值 → 写入新键 → 删除旧键" 实现，若缓存值很大（如几 MB 的数组），会导致严重的性能损耗。
     *       建议驱动类重写此方法以使用原生命令（如 Redis 的 RENAME）。
     */
    protected function __rawRename(string $oldKey, string $newKey): bool
    {
        try {
            // 先获取旧key的锁
            if (!$this->__acquireLock($oldKey)) return false;
            
            // 再获取新key的锁
            if (!$this->__acquireLock($newKey)) {
                $this->__releaseLock($oldKey);
                return false;
            }
            
            $value = $this->keyOpsFallbackLoadValue($oldKey, 'rename');
            if ($value === null) {
                $this->__releaseLock($oldKey);
                $this->__releaseLock($newKey);
                return false;
            }
            
            // 获取旧key的TTL
            $ttl = $this->__ttl($oldKey);
            $realTtl = $ttl > 0 ? $ttl : 0;
            
            // 设置新key
            $result = $this->__rawSet($newKey, $value, $realTtl);
            if ($result) {
                // 删除旧key
                $this->__rawDelete($oldKey);
            }
            
            $this->__releaseLock($oldKey);
            $this->__releaseLock($newKey);
            return $result;
        } catch (\Throwable) {
            $this->__releaseLock($oldKey);
            $this->__releaseLock($newKey);
            return false;
        }
    }

    /**
     * 浮点数自增（默认实现）
     * @param string $key 带前缀的完整key
     * @param float $step 步长
     * @return float|false 自增后的值，失败返回false
     */
    protected function __rawIncrByFloat(string $key, float $step): float|false
    {
        try {
            // 加锁后操作
            if (!$this->__acquireLock($key)) return false;
            
            $value = $this->__rawGet($key);
            $current = is_numeric($value) ? (float)$value : 0.0;
            $newValue = $current + $step;
            
            $this->__rawSet($key, $newValue);
            $this->__releaseLock($key);
            return $newValue;
        } catch (\Throwable) {
            $this->__releaseLock($key);
            return false;
        }
    }

    /**
     * 原生写入（接收带前缀的完整key，驱动内部无需再加前缀）
     * @param string $key 带前缀的完整key
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间，0=永久
     * @return bool
     */
    abstract public function __rawSet(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * 原生读取（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @param mixed $default 默认值
     * @return mixed
     */
    abstract public function __rawGet(string $key, mixed $default = null): mixed;

    /**
     * 原生删除（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @return bool
     */
    abstract public function __rawDelete(string $key): bool;

    /**
     * 原生判断是否存在（接收带前缀的完整key）
     * @param string $key 带前缀的完整key
     * @return bool
     */
    abstract public function __rawHas(string $key): bool;

    /**
     * 获取key剩余过期时间
     * @param string $key 带前缀的完整key
     * @return int 剩余秒数，0=永久，-1=不存在/已过期
     */
    abstract public function __ttl(string $key): int;

    /**
     * 清空当前前缀的全部缓存
     * @return bool
     */
    abstract public function __clear(): bool;

     /**
     * 批量判断缓存是否存在（接收带前缀的完整key数组）
     * @param array $keys 带前缀的完整key数组
     * @return array 键值对结果数组
     */
    abstract public function __rawMHas(array $keys): array;

    /**
     * 批量写入
     * @param array $items 键值对数组
     * @param int $ttl 过期时间，0=永久
     * @return bool
     */
    abstract public function __rawMSet(array $items, int $ttl = 0): bool;

    /**
     * 批量读取
     * @param array $keys 带前缀的key数组
     * @param mixed $default 单个key的默认值
     * @return array 键值对结果数组
     */
    abstract public function __rawMGet(array $keys, mixed $default = null): array;

    /**
     * 批量删除
     * @param array $keys 带前缀的key数组
     * @return bool
     */
    abstract public function __rawMDelete(array $keys): bool;

    /**
     * 入队
     * @param string $key 带前缀的完整key
     * @param array $args 入队值
     * @param bool $cove 是否覆盖
     * @param int $head 入队位置，0=左入队，1=右入队
     * @return int 入队后的队列长度
     */
    abstract public function __rawPush(string $key, array $args, bool $cover, int $head = 0): int;

    /**
     * 出队
     * @param string $key 带前缀的完整key
     * @param int $head 出队位置，0=左出队，1=右出队
     * @return mixed 出队值
     */
    abstract public function __rawPop(string $key, int $head = 0): mixed;

    /**
     * 原子操作
     * @param string $key 带前缀的完整key
     * @param int $step 递增步长
     * @param int $type 递增类型，0=递增，1=递减
     * @return int 增减后的值
     */
    abstract public function __rawIncr(string $key, int $step, int $type): int;

    /**
     * 原子范围查询
     * @param string $key key
     * @param int $offset 偏移量
     * @param int $length 查询长度
     * @param string $sort 排序字段
     * @param string $order 排序顺序
     * @return array|false 查询结果
     */
    abstract public function __rawRange(string $key,int $offset,int $length = 0, $sort = '',string $order = 'asc'): array|false;

}
