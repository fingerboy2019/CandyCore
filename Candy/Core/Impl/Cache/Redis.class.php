<?php
/**
 * Candy框架 Redis缓存驱动（全优化最终版 + 多维键支持 + 全驱动对齐）
 * 落地长连接、Pipeline批量、二级缓存、熔断、统一序列化全优化
 * 兼容单机/集群模式、分布式原子锁、连接池复用、异常容错，与全驱动规范完全对齐
 * 升级：支持任意层数多维键 + 批量Pipeline优化 + LRU逻辑与File/Shmop完全对齐
 *
 * @package Candy\Core\Cache
 * @final 最终类，禁止继承
 */

declare(strict_types=1);

namespace Candy\Core\Impl\Cache;

use Candy\Core\Cache;
use Candy\Core\Impl\Pack\Binary;
use Throwable;

defined('CANDY') OR die('Access Denied');

final class Redis extends Cache
{
    use \Candy\Core\Trait\Cache\LocalHotCache;
    use \Candy\Core\Trait\Cache\Lock\RedisLockBridge;
    use \Candy\Core\Trait\Cache\Resilience\CircuitBreaker;
    use \Candy\Core\Trait\Cache\Redis\RedisKvOps;
    use \Candy\Core\Trait\Cache\Redis\RedisBitmapOps;
    use \Candy\Core\Trait\Cache\Redis\RedisListOps;
    use \Candy\Core\Trait\Cache\Redis\RedisStreamOps;
    use \Candy\Core\Trait\Cache\Redis\RedisNativePublicApi;
    use \Candy\Core\Trait\Cache\Redis\RedisSetRawOps;
    use \Candy\Core\Trait\Cache\Redis\RedisHashRawOps;
    use \Candy\Core\Trait\Cache\Redis\RedisZSetRawOps;
    use \Candy\Core\Trait\Cache\Redis\RedisHyperLogRawOps;
    use \Candy\Core\Trait\Cache\Redis\RedisGeoRawOps;
    use \Candy\Core\Trait\Cache\Redis\RedisVectorHelpers;

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

    /** Redis 原生能力按当前 phpredis 扩展动态声明。 */
    protected function driverCapabilities(): array
    {
        $redisClass = \Redis::class;
        $stream = class_exists($redisClass)
            && method_exists($redisClass, 'xAdd')
            && method_exists($redisClass, 'xReadGroup')
            && method_exists($redisClass, 'xAck');
        $pending = $stream
            && method_exists($redisClass, 'xPending')
            && method_exists($redisClass, 'xClaim');
        return [
            'durable_queue' => ['support' => $stream, 'mode' => $stream ? 'native' : 'unavailable', 'distributed' => $stream, 'durable' => $stream, 'atomic' => $stream],
            'consumer_group' => ['support' => $stream, 'mode' => $stream ? 'native' : 'unavailable', 'distributed' => $stream, 'durable' => false, 'atomic' => $stream],
            'pending_recovery' => ['support' => $pending, 'mode' => $pending ? 'native' : 'unavailable', 'distributed' => $pending, 'durable' => $pending, 'atomic' => $pending],
        ];
    }

    /**
     * Redis缓存驱动构造函数
     * @param array $config Redis配置参数
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        $config['clusterHashTag'] = $config['clusterHashTag'] ?? (bool) ($config['cluster'] ?? false);
        // 优先初始化父类
        parent::__construct($config);

        // 配置初始化（与全驱动命名规范统一）
        $this->config = $config;
        $this->isCluster = isset($config['cluster']) && $config['cluster'] === true;
        $this->lockOutTime = $config['lockOutTime'] ?? 3000;
        $this->lockTtl = $config['lockTtl'] ?? 30;
        $this->retryInterval = $config['retryInterval'] ?? 200;
        $this->connectionCleanupInterval = $config['connectionCleanupInterval'] ?? 300;

        // 二级缓存配置
        $this->enableLocalCache = $config['enableLocalCache'] ?? true;
        $this->localCacheTtl = $config['localCacheTtl'] ?? 60;

        $this->configureCircuitBreaker($config);

        // 注册进程退出钩子
        register_shutdown_function([$this, '__releaseAllLocksOnShutdown']);
    }

    /**
     * Redis 本地二级缓存 TTL 上限
     */
    protected function getLocalCacheTtlCap(): int
    {
        return $this->localCacheTtl;
    }
    
    /**
     * 获取分片锁名称（Redis中主要用于代码风格对齐，实际使用Pipeline）
     * @param string $key 原始键
     * @return string 分片锁名称
     */
    private function getShardLockName(string $key): string
    {
        $hash = crc32($key);
        $shardIndex = $hash % self::SHARD_COUNT;
        return 'redis_shard_' . $shardIndex;
    }

    /**
     * Get Client
     * @return \Redis|\RedisCluster
     */
    private function getClient(): \Redis|\RedisCluster
    {
        $this->cleanupConnectionPool();
        $connectionId = md5(serialize($this->config));

        if (isset($this->connectionPool[$connectionId])) {
            $client = $this->connectionPool[$connectionId];
            if ($this->checkConnection($client)) {
                $this->connectionMetadata[$connectionId]['lastUsed'] = time();
                return $client;
            }
            unset($this->connectionPool[$connectionId], $this->connectionMetadata[$connectionId]);
        }

        if (!class_exists(\Redis::class)) {
            // 每进程只提示一次：一次 CLI 命令会实例化多次驱动，重复刷屏会盖住真正的输出
            static $notified = false;
            $msg = 'Redis扩展未安装或未启用（当前 PHP 未加载 redis 扩展，已回退其它缓存驱动）';
            if (!$notified) {
                $notified = true;
                function_exists('showExceptionMsg') ? showExceptionMsg('shutdown', $msg) : fwrite(STDERR, $msg . PHP_EOL);
            }
        }

		$config = $this->config + [
            'servers' => [['host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'db' => 0]],
            'timeout' => 1,
            'readTimeout' => 3,
            // Workerman 长驻禁用 pconnect，避免 fork 后共享坏套接字
            'persistent' => !(
                class_exists(\Candy\Extend\DB\WorkerRuntime::class)
                    ? \Candy\Extend\DB\WorkerRuntime::shouldAvoidPersistentIo()
                    : (function_exists('isWebServer') && isWebServer())
            ),
        ];

        $client = null;
        $servers = $config['servers'];

        // Sentinel 模式：先解析当前 master，再沿用普通连接与连接池逻辑。
        if (!$this->isCluster && is_array($config['sentinel'] ?? null)) {
            $sentinel = $this->resolveSentinelMaster($config['sentinel']);
            if ($sentinel !== null) {
                $servers = [$sentinel];
            }
        }

        if ($this->isCluster) {
            $seeds = [];
            foreach ($servers as $server) {
                $host = $server['host'] ?? '127.0.0.1';
                $port = $server['port'] ?? 6379;
                if (($server['tls'] ?? $config['tls'] ?? false) && !str_starts_with($host, 'tls://')) {
                    $host = 'tls://' . $host;
                }
                $seeds[] = "{$host}:{$port}";
            }
            $password = $config['password'] ?? $servers[0]['password'] ?? '';
            $username = (string) ($config['username'] ?? $servers[0]['username'] ?? '');

            $client = new \RedisCluster(
                null,
                $seeds,
                $config['timeout'],
                $config['readTimeout'],
                $config['persistent'],
                $password ?: null
            );
            if ($username !== '' && $password !== '' && method_exists($client, 'auth')) {
                $client->auth($username, $password);
            }
        } else {
            $server = $servers[0];
            $host = $server['host'] ?? '127.0.0.1';
            $port = $server['port'] ?? 6379;
            if (($server['tls'] ?? $config['tls'] ?? false) && !str_starts_with($host, 'tls://')) {
                $host = 'tls://' . $host;
            }
            $password = $server['password'] ?? '';
            $db = $server['db'] ?? 0;
            $timeout = $config['timeout'];
            $persistent = $config['persistent'];
            $persistentId = $persistent ? "candy_redis_{$host}_{$port}_{$db}" : null;

            $client = new \Redis();
            $connected = $persistent
                ? $client->pconnect($host, $port, $timeout, $persistentId)
                : $client->connect($host, $port, $timeout);

            if (!$connected) {
                $msg = "Redis连接失败：{$host}:{$port}";
                function_exists('showExceptionMsg') ? showExceptionMsg('shutdown', $msg) : die($msg);
            }

            $username = (string) ($server['username'] ?? $config['username'] ?? '');
            if ($password !== '') {
                $username !== '' ? $client->auth($username, $password) : $client->auth($password);
            }
            if ($db !== 0) {
                $client->select($db);
            }
            if (defined(\Redis::class . '::OPT_TCP_NODELAY')) {
                $client->setOption(\Redis::OPT_TCP_NODELAY, true);
            }
        }

        if (!$this->checkConnection($client)) {
            $msg = 'Redis服务器ping无响应';
            function_exists('showExceptionMsg') ? showExceptionMsg('shutdown', $msg) : die($msg);
        }

        $this->connectionPool[$connectionId] = $client;
        $this->connectionMetadata[$connectionId] = [
            'createdAt' => time(),
            'lastUsed'  => time(),
        ];

        return $client;
    }

    /**
     * Get Redis Client
     * @return \Redis|\RedisCluster
     */
    protected function getRedisClient(): \Redis|\RedisCluster
    {
        return $this->getClient();
    }

    /** @return array{host:string,port:int,password:string}|null */
    private function resolveSentinelMaster(array $sentinel): ?array
    {
        $name = (string) ($sentinel['master'] ?? $sentinel['masterName'] ?? 'mymaster');
        foreach (($sentinel['servers'] ?? []) as $node) {
            try {
                $probe = new \Redis();
                $host = (string) ($node['host'] ?? '127.0.0.1');
                $port = (int) ($node['port'] ?? 26379);
                if (!$probe->connect($host, $port, (float) ($sentinel['timeout'] ?? 1))) {
                    continue;
                }
                $password = (string) ($node['password'] ?? $sentinel['password'] ?? '');
                if ($password !== '') {
                    $probe->auth($password);
                }
                $master = $probe->rawCommand('SENTINEL', 'get-master-addr-by-name', $name);
                $probe->close();
                if (is_array($master) && isset($master[0], $master[1])) {
                    return [
                        'host' => (string) $master[0],
                        'port' => (int) $master[1],
                        'password' => (string) ($sentinel['masterPassword'] ?? $sentinel['password'] ?? $password),
                    ];
                }
            } catch (Throwable) {
                if (isset($probe) && $probe instanceof \Redis) {
                    $probe->close();
                }
            }
        }
        return null;
    }

    /**
     * Cleanup Connection Pool
     */
    private function cleanupConnectionPool(): void
    {
        $now = time();
        if ($now - $this->lastCleanupTime < $this->connectionCleanupInterval) {
            return;
        }

        $this->lastCleanupTime = $now;
        foreach ($this->connectionMetadata as $connId => $meta) {
            if ($now - $meta['lastUsed'] > 1800) {
                $client = $this->connectionPool[$connId] ?? null;
                if ($client && !$client instanceof \RedisCluster) {
                    $client->close();
                }
                unset($this->connectionPool[$connId], $this->connectionMetadata[$connId]);
            }
        }
    }

    /**
     * Check Connection
     * @param mixed $client
     * @return bool
     */
    private function checkConnection(\Redis|\RedisCluster $client): bool
    {
        try {
            $pong = $client->ping();
            return $pong === true || $pong === '+PONG';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     *   destruct
     * @return mixed
     */
    public function __destruct()
    {
        $this->connectionPool = [];
        $this->connectionMetadata = [];
    }
}
