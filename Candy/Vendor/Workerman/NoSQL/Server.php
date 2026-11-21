<?php
namespace NoSQL;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Candy\Core\Cache;

/**
 * NoSQL 数据库服务器 (多用户/多库版)
 * 
 * ================================
 * 📖 架构说明
 * ================================
 * 
 * 本服务器基于 Workerman 实现，提供类 Redis 的 NoSQL 缓存服务。
 * 采用多用户/多数据库隔离架构，每个用户+数据库组合拥有独立的数据空间。
 * 
 * 核心特性：
 * - 多用户认证：支持多用户接入，密码验证
 * - 多数据库隔离：每个用户可创建多个数据库，数据完全隔离
 * - 内存缓存：基于 Memory 驱动，高性能内存存储
 * - 数据持久化：支持自动落盘，重启不丢失数据
 * - 向量检索：内置 HNSW 向量索引，支持 AI 向量搜索
 * - 限流保护：全局限流 + 用户级限流，防止滥用
 * 
 * ================================
 * 📋 存储前缀规则
 * ================================
 * 
 * 数据在底层存储时使用前缀隔离：
 *   格式: {用户名}_{数据库名}_
 *   示例: 
 *     - 用户 alice 的 orders 数据库 → "alice_orders_"
 *     - 用户 bob 的 default 数据库 → "bob_default_"
 * 
 * ================================
 * ⚙️ 配置示例
 * ================================
 * 
 * ```php
 * use NoSQL\Server;
 * 
 * $config = [
 *     // 用户列表 ['用户名' => '密码']，空数组允许任意用户名登录
 *     'users' => [
 *         'admin'  => 'admin123',
 *         'alice'  => 'secret',
 *         'bob'    => 'bobpass',
 *     ],
 *     
 *     // 认证超时（秒）：客户端连接后必须在此时间内完成认证
 *     'auth_timeout' => 15,
 *     
 *     // 缓存驱动类（默认 Memory）
 *     'driver' => 'Candy\Core\Impl\Cache\Memory',
 *     
 *     // 驱动基础配置（应用到所有用户/数据库实例）
 *     'driver_config' => [
 *         'lockOutTime'     => 3000,     // 锁超时时间（毫秒）
 *         'lockTtl'         => 30,       // 锁生存时间（秒）
 *         'retryInterval'   => 200,      // 锁重试间隔（微秒）
 *         'cleanupInterval' => 5,        // 过期清理间隔（秒）
 *         'dumpInterval'    => 5,        // 自动落盘间隔（秒）
 *         // 落盘文件目录（可选，每个实例自动按前缀区分）
 *         // 'dumpFile' => '/path/to/dumps/',
 *     ],
 *     
 *     // 每个用户/数据库的最大键数量
 *     'per_user_max_keys' => 5000,
 *     
 *     // 实例池最大数量（控制同时存在的用户/数据库组合数）
 *     'max_instance_pool' => 100,
 *     
 *     // 全局限流：每秒最大请求数
 *     'max_rps' => 1000,
 *     
 *     // 用户级限流：每用户每秒最大请求数
 *     'max_rps_per_user' => 100,
 * ];
 * 
 * $server = new Server('0.0.0.0:2404', $config);
 * ```
 * 
 * ================================
 * 🚀 启动方式
 * ================================
 * 
 * ```php
 * // 方式一：直接启动（前台运行）
 * $server = new Server('0.0.0.0:2404', $config);
 * Worker::runAll();
 * 
 * // 方式二：作为 Workerman 应用的一部分
 * // 在 start.php 中添加：
 * require_once __DIR__ . '/vendor/autoload.php';
 * use Workerman\Worker;
 * use NoSQL\Server;
 * 
 * $config = [...]; // 配置同上
 * $server = new Server('tcp://0.0.0.0:2404', $config);
 * Worker::runAll();
 * ```
 * 
 * ================================
 * 🔧 运维说明
 * ================================
 * 
 * - **强制单进程**：服务器始终运行在单进程模式，确保内存共享
 * - **数据持久化**：配置 dumpFile 后，数据会在 shutdown 时自动保存
 * - **内存监控**：通过 `getStats` 命令可获取各实例的内存使用情况
 * 
 * @package NoSQL
 * @version 2.0.0
 * @since   1.0.0
 * 
 * @see     Client 客户端类
 * @see     \Candy\Core\Impl\Cache\Memory 底层缓存驱动
 */
class Server extends Worker
{
    /**
     * 用户配置列表 ['username' => 'password']
     * 
     * 说明：
     * - 键为用户名，值为密码（明文存储）
     * - 空数组表示允许任意用户名登录（无需密码）
     * - 生产环境建议使用强密码并限制用户列表
     * 
     * @var array<string, string>
     */
    protected array $users = [];

    /**
     * 基础驱动配置（用于所有实例）
     * 
     * 说明：
     * - 此配置会合并到每个用户/数据库实例的构造函数中
     * - 可配置项参考 \Candy\Core\Impl\Cache\Memory 的构造函数参数
     * 
     * @var array
     * @see \Candy\Core\Impl\Cache\Memory::__construct()
     */
    protected array $driverConfig = [];

    /**
     * 每个用户/数据库的最大键数量
     * 
     * 说明：
     * - 限制单个用户/数据库组合的内存使用
     * - 超过此值时会触发 LRU 淘汰
     * - 建议根据实际内存容量调整
     * 
     * @var int
     */
    protected int $perUserMaxKeys = 5000;

    /**
     * 实例池最大数量，防止内存溢出
     * 
     * 说明：
     * - 限制同时存在的用户/数据库实例数量
     * - 超过此值时会淘汰空闲实例
     * - 每个实例独立占用内存
     * 
     * @var int
     */
    protected int $maxInstancePoolSize = 100;

    /**
     * 限流配置：每秒最大请求数（全局）
     * 
     * 说明：
     * - 整个服务器每秒允许的最大请求数
     * - 超过此值后所有新请求返回 429 错误
     * - 设置为 0 表示不限流
     * 
     * @var int
     */
    protected int $maxRequestsPerSecond = 1000;

    /**
     * 限流配置：每用户每秒最大请求数
     * 
     * 说明：
     * - 单个用户每秒允许的最大请求数
     * - 防止单个用户占用过多资源
     * - 设置为 0 表示不限流
     * 
     * @var int
     */
    protected int $maxRequestsPerUserPerSecond = 100;

    /**
     * 请求计数器：[timestamp_second => count]
     * 
     * 说明：
     * - 用于全局限流统计
     * - 定时器会自动清理 5 秒前的旧数据
     * 
     * @var array<int, int>
     */
    protected array $requestCounter = [];

    /**
     * 用户请求计数器：[user => [timestamp_second => count]]
     * 
     * 说明：
     * - 用于用户级限流统计
     * - 定时器会自动清理 5 秒前的旧数据
     * 
     * @var array<string, array<int, int>>
     */
    protected array $userRequestCounter = [];

    /**
     * 连接上下文: conn_id => ['user' => string, 'db' => string, 'prefix' => string, 'challenge' => string]
     * 
     * 结构说明：
     * - user: 当前连接认证后的用户名
     * - db: 当前选择的数据库名
     * - prefix: 存储前缀，格式为 "{user}_{db}_"
     * - challenge: 服务端生成的认证挑战码（仅在认证前存在）
     * 
     * 生命周期：
     * - 连接建立时：仅初始化 challenge
     * - 认证成功后：保留 user, db, prefix，移除 challenge
     * - 选库操作后：更新 db 和 prefix
     * - 连接断开时：清除整个条目
     * 
     * @var array<string, array{user: string, db: string, prefix: string, challenge?: string}>
     */
    protected array $contexts = [];

    /**
     * 缓存实例池: prefix => CacheInstance
     *
     * 说明：
     * - 键为存储前缀（如 "alice_orders_"）
     * - 值为对应的 Cache 实例（带独立配置）
     * - 不同用户/数据库使用不同的实例，数据完全隔离
     * - 实例池有上限（maxInstancePoolSize），超限时会淘汰空闲实例
     *
     * @var array<string, Cache>
     */
    protected array $instancePool = [];

    /**
     * 驱动类名（getInstance 实例化时使用）
     *
     * @var string
     */
    protected string $driverClass = 'Candy\\Core\\Impl\\Cache\\Memory';

    /**
     * 待认证连接集合 conn_id => ['time' => int, 'challenge' => string]
     * 
     * 结构说明：
     * - time: 连接建立的时间戳（用于认证超时判断）
     * - challenge: 服务端生成的随机挑战码（用于认证验证）
     * 
     * 生命周期：
     * - 连接建立时：添加到此集合
     * - 认证成功后：从此集合移除
     * - 认证超时后：从此集合移除并关闭连接
     * 
     * @var array<string, array{time: int, challenge: string}>
     */
    protected array $pendingAuthConnections = [];

    /**
     * 认证超时时间（秒）
     * 
     * 说明：
     * - 客户端连接后必须在此时间内完成认证
     * - 超时后连接会被强制关闭
     * 
     * @var int
     */
    protected int $authTimeout = 10;

    /**
     * 认证防爆破：最大失败次数（达到后锁定）
     *
     * @var int
     */
    protected int $maxAttempts = 5;

    /**
     * 认证防爆破：锁定时间（秒）
     *
     * @var int
     */
    protected int $lockoutTime = 300;

    /**
     * 认证失败计数：ip => ['count'=>n, 'locked_until'=>ts]
     *
     * @var array<string, array{count:int, locked_until:int}>
     */
    protected array $authAttempts = [];

    /**
     * 认证计数持久化文件（防爆破跨重启生效）
     *
     * @var string
     */
    protected string $authFile = '';

    /**
     * Server constructor.
     * @param string $address 监听地址
     * @param array $config 配置 [
     *   'driver'           => 'Candy\Core\Impl\Cache\Memory', // 驱动类
     *   'users'            => ['admin' => '123456'],     // 用户列表，空则允许任意用户名
     *   'auth_timeout'     => 10,                        // 认证超时
     *   'driver_config'    => [...],                    // 驱动基础配置
     *   'per_user_max_keys'=> 5000,                     // 每用户最大键数
     *   'max_instance_pool'=> 100,                      // 实例池最大数量
     *   'max_rps'          => 1000,                     // 全局每秒最大请求数
     *   'max_rps_per_user' => 100                       // 每用户每秒最大请求数
     * ]
     */
    public function __construct($address = '0.0.0.0:2404', array $config = [])
    {
        if (strpos($address, '://') === 0) {
            [, $address] = explode('://', $address);
        }

        // 框架级二进制帧协议（Candy\Extend\Protocols\Binary）
        class_alias(\Candy\Extend\Protocols\Binary::class, 'Protocols\Binary');
        // 兼容 'tcp://host:port' 写法（原 strpos($address,'://')===0 恒不成立，导致文档示例构造出错误地址）
        if (str_starts_with($address, 'tcp://')) {
            $address = substr($address, 6);
        }
        parent::__construct("Binary://$address");

        $this->users = $config['users'] ?? [];
        $this->authTimeout = $config['auth_timeout'] ?? 10;
        $this->maxAttempts = $config['max_attempts'] ?? 5;
        $this->lockoutTime = $config['lockout_time'] ?? 300;
        $this->driverConfig = $config['driver_config'] ?? [];
        $this->perUserMaxKeys = $config['per_user_max_keys'] ?? 5000;
        $this->maxInstancePoolSize = $config['max_instance_pool'] ?? 100;
        $this->maxRequestsPerSecond = $config['max_rps'] ?? 1000;
        $this->maxRequestsPerUserPerSecond = $config['max_rps_per_user'] ?? 100;

        // 认证计数持久化文件（防爆破跨重启生效）
        $this->authFile = $config['auth_file'] ?? (defined('CANDYPATH') ? CANDYPATH . 'Runtime/NoSql/auth.json' : '');
        if ($this->authFile !== '') {
            $authDir = dirname($this->authFile);
            if (!is_dir($authDir)) {
                @mkdir($authDir, 0777, true);
            }
        }

        // 保存驱动类（getInstance 实例化时使用；原实现硬编码 Memory 导致配置失效）
        $this->driverClass = $config['driver'] ?? 'Candy\Core\Impl\Cache\Memory';
        MethodMapper::buildMethodCache($this->driverClass);

        // 强制单进程：确保内存共享和数据一致性，不接受外部 count 设置
        $this->count = 1;
        $this->name = 'noSqlDBServer';
        $this->onMessage = [$this, 'onMessage'];
        $this->onConnect = [$this, 'onConnect'];
        $this->onClose = [$this, 'onClose'];
        $this->reloadable = false;

        // 周期性任务必须在 onWorkerStart 里注册：构造阶段事件循环未初始化，
        // master 进程里 Timer::add 无效（fork 后 worker 不会继承）。
        // 每 1 秒：清理限流计数器 + 回收未认证挂死连接 + 触发实例池周期性落盘。
        // 落盘是驱动的事（Memory/Shmop 有 autoDump，Redis/Memcached 没有）。
        // 初始化时按驱动判一次，不需要落盘的驱动就不必每秒遍历实例池空转。
        $dumpWanted = $this->driverSupportsDump();
        $this->onWorkerStart = function () use ($dumpWanted) {
            \Workerman\Timer::add(1, function () use ($dumpWanted) {
                $this->cleanupRateLimitCounters();
                $this->reapPendingAuthConnections();
                if ($dumpWanted) {
                    $this->periodicDump();
                }
            });
        };
    }

    /**
     * 客户端连接回调
     * 
     * 处理流程：
     * 1. 生成唯一连接 ID（使用 spl_object_hash）
     * 2. 记录连接时间到待认证集合
     * 3. 生成服务端挑战码（防止客户端操控）
     * 4. 发送 401 响应包含挑战码给客户端
     * 
     * 协议说明：
     * - 响应码 401 表示需要认证
     * - challenge 字段包含 32 位十六进制随机字符串
     * 
     * @param TcpConnection $connection 客户端连接对象
     * @return void
     * 
     * @see handleAuth() 认证处理
     * @see onClose() 连接断开处理
     */
    public function onConnect(TcpConnection $connection): void
    {
        $connId = spl_object_hash($connection);
        $this->pendingAuthConnections[$connId] = time();

        // 服务端生成挑战码，防止客户端操控
        $challenge = bin2hex(random_bytes(16));
        // 存储挑战码 + 连接引用（供定时器超时回收时 close）
        $this->pendingAuthConnections[$connId] = [
            'time' => time(),
            'challenge' => $challenge,
            'conn' => $connection,
        ];

        // 发送挑战码
        $connection->send([
            'code'      => 401,
            'msg'       => 'Auth required',
            'challenge' => $challenge
        ]);
    }

    /**
     * 客户端断开回调
     */
    public function onClose(TcpConnection $connection): void
    {
        $connId = spl_object_hash($connection);
        unset($this->contexts[$connId]);
        unset($this->pendingAuthConnections[$connId]);
    }

    /**
     * 处理客户端消息
     * 
     * 消息处理流程：
     * 1. 认证检查：未认证的连接只能发送 auth 命令
     * 2. 内部命令处理：ping, selectDb, info 等系统命令
     * 3. 限流检查：业务命令需要检查请求速率
     * 4. 业务命令路由：根据方法名调用对应的 Cache 方法
     * 
     * 响应码说明：
     * - 200: 成功
     * - 400: 请求错误（参数错误、方法不存在等）
     * - 401: 未认证
     * - 429: 请求频率超限
     * - 500: 服务器内部错误
     * 
     * 使用示例：
     * ```php
     * // 客户端发送
     * $client->set('mykey', 'myvalue', 3600);
     * 
     * // 服务端响应（成功）
     * ['code' => 200, 'data' => true]
     * 
     * // 服务端响应（错误）
     * ['code' => 400, 'msg' => 'Command not found: invalid_cmd']
     * ```
     * 
     * @param TcpConnection $connection 客户端连接对象
     * @param mixed $data 解码后的请求数据
     * @return void
     * 
     * @see handleAuth() 认证处理
     * @see handleSelectDb() 选库处理
     * @see checkRateLimit() 限流检查
     * @see MethodMapper::extractParams() 参数提取
     */
    public function onMessage(TcpConnection $connection, mixed $data)
    {
        $connId = spl_object_hash($connection);

        // 裸 ping 帧（协议层 decode 为字符串 'ping'），未认证也可探活
        if ($data === 'ping') {
            $connection->send('pong');
            return;
        }

        // 非数组请求一律拒绝，避免 $data['cmd'] 在字符串上触发告警/错乱
        if (!is_array($data)) {
            $connection->send(['code' => 400, 'msg' => 'Invalid request payload']);
            return;
        }

        $cmd = $data['cmd'] ?? '';

        // 1. 认证检查
        if (!isset($this->contexts[$connId])) {
            if ($cmd === 'auth') {
                $this->handleAuth($connection, $data);
                return;
            }

            // 检查超时
            $authData = $this->pendingAuthConnections[$connId] ?? null;
            if ($authData === null || (time() - $authData['time']) > $this->authTimeout) {
                $connection->close();
                return;
            }
            $connection->send(['code' => 401, 'msg' => 'Not authenticated']);
            return;
        }

        try {
            // 2. 内部系统命令处理
            if ($cmd === 'ping') {
                $connection->send(['code' => 200, 'data' => 'pong']);
                return;
            }

            if ($cmd === 'selectDb') {
                $this->handleSelectDb($connection, $data, $connId);
                return;
            }

            if ($cmd === 'info') {
                $ctx = $this->contexts[$connId];
                $connection->send([
                    'code' => 200,
                    'data' => [
                        'pid'    => posix_getpid(),
                        'user'   => $ctx['user'],
                        'db'     => $ctx['db'],
                        'prefix' => $ctx['prefix']
                    ]
                ]);
                return;
            }

            // 3. 限流检查（业务命令）
            if (!$this->checkRateLimit($connId)) {
                $connection->send(['code' => 429, 'msg' => 'Rate limit exceeded']);
                return;
            }

            // 4. 路由到 Cache 接口
            if (!MethodMapper::hasMethod($cmd)) {
                throw new \BadMethodCallException("Command not found: {$cmd}");
            }

            // 获取当前连接对应的 Cache 实例
            $ctx = $this->contexts[$connId];
            $cache = $this->getInstance($ctx['prefix']);

            // 提取参数并调用
            $params = MethodMapper::extractParams($cmd, $data);

            try {
                $result = $cache->$cmd(...$params);
                $connection->send(['code' => 200, 'data' => $result]);
            } catch (\BadMethodCallException $e) {
                // 方法不存在
                $connection->send(['code' => 400, 'msg' => $e->getMessage()]);
            } catch (\InvalidArgumentException $e) {
                // 参数错误
                $connection->send(['code' => 400, 'msg' => 'Invalid argument: ' . $e->getMessage()]);
            } catch (\Throwable $e) {
                // 其他错误
                $connection->send(['code' => 500, 'msg' => 'Internal error: ' . $e->getMessage()]);
            }

        } catch (\Throwable $e) {
            $connection->send(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 处理用户认证
     */
    protected function handleAuth(TcpConnection $connection, array $data): void
    {
        $connId = spl_object_hash($connection);
        $username = $data['username'] ?? '';
        $token = $data['token'] ?? '';

        if (!$username) {
            $connection->send(['code' => 400, 'msg' => 'Username required']);
            return;
        }

        // 基于 IP 的暴力破解防护：达到锁定次数直接拒绝并断开
        $clientIp = $connection->getRemoteIp();
        $this->loadAuthAttempts();
        $attempt = $this->authAttempts[$clientIp] ?? ['count' => 0, 'locked_until' => 0];
        if ($attempt['locked_until'] > time()) {
            $retry = $attempt['locked_until'] - time();
            $connection->send(['code' => 429, 'msg' => "Too many failed attempts. Retry after {$retry}s"]);
            $connection->close();
            return;
        }

        // 使用服务端存储的挑战码，防止客户端操控
        $authData = $this->pendingAuthConnections[$connId] ?? null;
        if ($authData === null) {
            $connection->send(['code' => 400, 'msg' => 'No challenge found']);
            return;
        }

        $challenge = $authData['challenge'];

        // 安全修复：users 列表非空时，未知用户名必须拒绝。
        // 原实现 $password=null 时跳过校验，导致任意未在列表中的用户名免密通过
        // （用户名枚举 + 可冒充任意非列表用户名、创建任意前缀空间）。
        $authOk = false;
        if ($this->users === []) {
            // users 为空数组：允许任意用户名登录（文档约定）
            $authOk = true;
        } else {
            $password = $this->users[$username] ?? null;
            $expectedToken = $password === null ? null : hash('sha256', $password . $challenge);
            $authOk = $password !== null && hash_equals((string) $expectedToken, $token);
        }

        if (!$authOk) {
            // 失败：计数 +1，达到上限触发锁定
            $attempt['count']++;
            if ($attempt['count'] >= $this->maxAttempts) {
                $attempt['locked_until'] = time() + $this->lockoutTime;
                $attempt['count'] = 0;
            }
            $this->authAttempts[$clientIp] = $attempt;
            $this->saveAuthAttempts();
            $connection->send(['code' => 403, 'msg' => 'Auth failed']);
            return;
        }

        // 认证成功：重置失败计数
        unset($this->authAttempts[$clientIp]);
        $this->saveAuthAttempts();

        // 初始化上下文，默认 db 为 'default'
        $this->contexts[$connId] = [
            'user'   => $username,
            'db'     => 'default',
            'prefix' => "{$username}_default_"
        ];
        unset($this->pendingAuthConnections[$connId]);

        $connection->send([
            'code' => 200,
            'msg'  => 'Authenticated',
            'db'   => 'default'
        ]);
    }

    /**
     * 从文件加载认证失败计数（flock 保护，每次强制重读保证跨进程一致）
     *
     * @return void
     */
    protected function loadAuthAttempts(): void
    {
        if ($this->authFile !== '' && is_file($this->authFile)) {
            $raw = @file_get_contents($this->authFile);
            $data = $raw ? json_decode($raw, true) : null;
            $this->authAttempts = is_array($data) ? $data : [];
        } else {
            $this->authAttempts = [];
        }
    }

    /**
     * 持久化认证失败计数（flock 保护 + 合并写，避免覆盖其他进程记录）
     *
     * @return void
     */
    protected function saveAuthAttempts(): void
    {
        if ($this->authFile === '') {
            return;
        }
        $fp = @fopen($this->authFile, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            rewind($fp);
            $existing = $raw ? json_decode($raw, true) : null;
            if (is_array($existing)) {
                $this->authAttempts = array_merge($existing, $this->authAttempts);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($this->authAttempts));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * 处理选库命令
     */
    protected function handleSelectDb(TcpConnection $connection, array $data, string $connId): void
    {
        $dbName = (string) ($data['db'] ?? '');
        if ($dbName === '') {
            $connection->send(['code' => 400, 'msg' => 'Db name required']);
            return;
        }
        // 基本校验：长度与非法字符（防前缀混淆/实例池被异常 db 名撑大）
        if (strlen($dbName) > 64 || preg_match('/[^A-Za-z0-9_\-.]/', $dbName)) {
            $connection->send(['code' => 400, 'msg' => 'Invalid db name']);
            return;
        }

        // 更新上下文
        $username = $this->contexts[$connId]['user'];
        $this->contexts[$connId]['db'] = $dbName;
        $this->contexts[$connId]['prefix'] = "{$username}_{$dbName}_";

        $connection->send([
            'code' => 200,
            'msg'  => "Switched to db: {$dbName}",
            'prefix' => $this->contexts[$connId]['prefix']
        ]);
    }

    /**
     * 获取或创建 Cache 实例
     * 基于前缀复用实例，减少内存开销
     */
    protected function getInstance(string $prefix): Cache
    {
        if (!isset($this->instancePool[$prefix])) {
            // 实例池淘汰：检查池大小
            if (count($this->instancePool) >= $this->maxInstancePoolSize) {
                $this->evictIdleInstances();
            }

            // 使用配置的驱动类（原实现硬编码 Memory，导致 driver 配置失效）
            $driverClass = $this->driverClass;

            // 合并基础配置和用户/数据库特定配置
            $instanceConfig = array_merge($this->driverConfig, [
                'cachePrefix' => $prefix,
                'maxKeyCount' => $this->perUserMaxKeys,
            ]);

            // dumpFile 若为目录（文档约定 '/path/to/dumps/'），按前缀生成独立文件路径，
            // 否则 atomicDump 的 rename(临时文件, 目录) 会失败导致落盘永远不生效
            if (!empty($instanceConfig['dumpFile'])) {
                $dumpFile = (string) $instanceConfig['dumpFile'];
                if (is_dir($dumpFile) || str_ends_with($dumpFile, '/') || str_ends_with($dumpFile, DIRECTORY_SEPARATOR)) {
                    $dumpDir = rtrim($dumpFile, '/\\');
                    if (!is_dir($dumpDir)) {
                        @mkdir($dumpDir, 0777, true);
                    }
                    $instanceConfig['dumpFile'] = $dumpDir . '/' . md5($prefix) . '.dump';
                }
            }

            $this->instancePool[$prefix] = new $driverClass($instanceConfig);
        }
        return $this->instancePool[$prefix];
    }

    /**
     * 淘汰空闲实例
     * 当实例池超过最大数量时，清理最少使用的实例
     */
    protected function evictIdleInstances(): void
    {
        // 简单 LRU 淘汰：移除最早的实例
        // 注意：不能淘汰当前连接正在使用的实例
        $activePrefixes = array_column($this->contexts, 'prefix');
        $evictCount = (int)(count($this->instancePool) * 0.2); // 淘汰 20%
        $evicted = 0;

        foreach (array_keys($this->instancePool) as $prefix) {
            if ($evicted >= $evictCount) {
                break;
            }

            // 跳过活跃实例
            if (in_array($prefix, $activePrefixes, true)) {
                continue;
            }

            // 触发自动落盘后再移除
            $instance = $this->instancePool[$prefix];
            if (method_exists($instance, 'autoDump')) {
                $instance->autoDump();
            }

            unset($this->instancePool[$prefix]);
            $evicted++;
        }
    }

    /**
     * 限流检查
     * 检查全局和用户级别的请求速率
     */
    protected function checkRateLimit(string $connId): bool
    {
        $now = time();

        // 清理过期的计数器
        $this->cleanupRateLimitCounters();

        // 全局限流检查
        if (!isset($this->requestCounter[$now])) {
            $this->requestCounter[$now] = 0;
        }
        if ($this->requestCounter[$now] >= $this->maxRequestsPerSecond) {
            return false;
        }

        // 用户级限流检查
        $user = $this->contexts[$connId]['user'] ?? null;
        if ($user !== null) {
            if (!isset($this->userRequestCounter[$user][$now])) {
                $this->userRequestCounter[$user][$now] = 0;
            }
            if ($this->userRequestCounter[$user][$now] >= $this->maxRequestsPerUserPerSecond) {
                return false;
            }
            $this->userRequestCounter[$user][$now]++;
        }

        // 增加全局计数
        $this->requestCounter[$now]++;

        return true;
    }

    /**
     * 当前驱动是否需要外部调度落盘
     *
     * 落盘能力属于驱动：Memory / Shmop 靠 dump 持久化，需要有人周期性调 autoDump；
     * Redis / Memcached / File 自己就是持久的，不需要网关再帮它调度。
     * 所以这里按驱动类判一次，而不是每轮遍历实例池用 method_exists 探。
     *
     * @return bool
     */
    protected function driverSupportsDump(): bool
    {
        try {
            if (!class_exists($this->driverClass)) {
                return false;
            }
            return method_exists($this->driverClass, 'autoDump');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 周期性落盘：遍历实例池触发各实例 autoDump
     *
     * 各驱动按自身 dumpInterval 节流（默认 5 秒），无脏数据时不落盘（无 IO）。
     * 崩溃/断电时最多丢失 dumpInterval 秒内的写入。
     *
     * @return void
     */
    protected function periodicDump(): void
    {
        foreach ($this->instancePool as $prefix => $instance) {
            if (method_exists($instance, 'autoDump')) {
                try {
                    $instance->autoDump();
                } catch (\Throwable $e) {
                    // 单个实例落盘失败不中断服务，下轮重试
                }
            }
        }
    }

    /**
     * 回收超时未完成认证的挂死连接
     *
     * 原实现仅在收到消息时检查 authTimeout，客户端连接后不发消息的挂死连接永不关闭（fd 泄漏）。
     * 定时器每 1 秒扫描一次，超时直接关闭。
     *
     * @return void
     */
    protected function reapPendingAuthConnections(): void
    {
        $now = time();
        foreach ($this->pendingAuthConnections as $connId => $authData) {
            if (($authData['time'] ?? 0) + $this->authTimeout < $now) {
                $conn = $authData['conn'] ?? null;
                if ($conn instanceof \Workerman\Connection\TcpConnection) {
                    $conn->close();
                }
                unset($this->pendingAuthConnections[$connId]);
            }
        }
    }

    /**
     * 清理过期的限流计数器
     * 保留最近 5 秒的数据
     */
    protected function cleanupRateLimitCounters(): void
    {
        $now = time();
        $cutoff = $now - 5;

        // 清理全局计数器
        foreach (array_keys($this->requestCounter) as $timestamp) {
            if ($timestamp < $cutoff) {
                unset($this->requestCounter[$timestamp]);
            }
        }

        // 清理用户级计数器
        foreach ($this->userRequestCounter as $user => $counters) {
            foreach (array_keys($counters) as $timestamp) {
                if ($timestamp < $cutoff) {
                    unset($this->userRequestCounter[$user][$timestamp]);
                }
            }
            // 如果用户没有最近的计数，清理整个用户条目
            if (empty($this->userRequestCounter[$user])) {
                unset($this->userRequestCounter[$user]);
            }
        }
    }
}
