<?php
namespace NoSQL;

use Candy\Extend\Protocols\Binary as BinaryProtocol;

/**
 * NoSQL 数据库客户端 (多用户/多库版)
 * 
 * ================================
 * 📖 使用说明
 * ================================
 * 
 * 本客户端用于连接 NoSQL 服务器，提供类 Redis 的操作接口。
 * 支持认证、选库、以及所有 Cache 驱动提供的业务命令。
 * 
 * ================================
 * 🚀 快速开始
 * ================================
 * 
 * ```php
 * use NoSQL\Client;
 * 
 * // 1. 创建客户端实例
 * $client = new Client('127.0.0.1:2404');
 * 
 * // 2. 用户认证
 * $client->auth('alice', 'secret');
 * 
 * // 3. 选择数据库（可选，默认为 'default'）
 * $client->selectDb('orders');
 * 
 * // 4. 执行缓存操作
 * $client->set('user:1001', ['name' => 'Alice', 'email' => 'alice@example.com'], 3600);
 * $data = $client->get('user:1001');
 * echo $data['name']; // 输出: Alice
 * 
 * // 5. 使用完毕后关闭连接
 * $client->close();
 * ```
 * 
 * ================================
 * ⚙️ 连接模式
 * ================================
 * 
 * 客户端支持两种连接模式：
 * 
 * 1. **短连接模式**（默认）
 *    - 每次操作建立新连接，操作后关闭
 *    - 适用于 CLI 脚本、一次性操作
 *    - 配置：`new Client('127.0.0.1:2404', ['persistent' => false])`
 * 
 * 2. **长连接模式**
 *    - 连接在进程内复用，减少连接开销
 *    - 自动检测 Workerman 环境并启用
 *    - 配置：`new Client('127.0.0.1:2404', ['persistent' => true])`
 *    - 注意：长连接断开后需要重新 auth
 * 
 * ================================
 * 📋 支持的命令
 * ================================
 * 
 * 客户端通过魔术方法自动代理所有 Cache 驱动公开的方法。
 * 常用命令包括：
 * 
 * **基础操作：**
 * - `set($key, $value, $ttl = 0)` - 设置缓存
 * - `get($key, $default = null)` - 获取缓存
 * - `delete($key)` - 删除缓存
 * - `has($key)` - 判断缓存是否存在
 * - `ttl($key)` - 获取剩余过期时间
 * 
 * **批量操作：**
 * - `mSet($items, $ttl = 0)` - 批量设置
 * - `mGet($keys, $default = null)` - 批量获取
 * - `mDelete($keys)` - 批量删除
 * 
 * **队列操作：**
 * - `push($key, $args, $cover = false, $head = 0)` - 推入队列
 * - `pop($key, $head = 0)` - 弹出队列
 * 
 * **原子操作：**
 * - `incr($key, $step = 1, $type = 0)` - 自增/自减
 * 
 * **向量操作：**
 * - `vectorSet($key, $vector, $ttl = 0)` - 设置向量
 * - `vectorGet($key)` - 获取向量
 * - `vectorSearch($vector, $limit = 10)` - 向量搜索
 * 
 * **锁操作：**
 * - `lock($name, $timeout = null)` - 获取锁
 * - `unlock($name)` - 释放锁
 * 
 * **Stream 消息队列：**
 * - `xAdd($key, $fieldValues, $id = '*', $maxLen = 0)` - 添加消息
 * - `xRange($key, $start = '-', $end = '+', $count = 0)` - 范围查询
 * - `xRead($keys, $count = 0, $blockMs = 0)` - 读取消息
 * 
 * 完整命令列表请参考 MethodMapper 的白名单配置。
 * 
 * ================================
 * ⚠️ 注意事项
 * ================================
 * 
 * 1. **认证是必须的**：所有业务命令前必须先调用 auth()
 * 2. **长连接重连后需重新 auth**：连接断开重连后认证状态会丢失
 * 3. **命令调用方式**：使用 `$client->methodName()` 而非 `$client->method_name()`
 * 4. **错误处理**：命令失败会抛出异常，建议用 try-catch 包裹
 * 
 * ================================
 * 🔍 错误处理示例
 * ================================
 * 
 * ```php
 * try {
 *     $client->auth('alice', 'wrong_password');
 * } catch (\Exception $e) {
 *     echo "认证失败: " . $e->getMessage();
 * }
 * 
 * try {
 *     $result = $client->get('non_existent_key');
 * } catch (\Exception $e) {
 *     echo "命令执行失败: " . $e->getMessage();
 * }
 * ```
 * 
 * @package NoSQL
 * @version 2.0.0
 * @since   1.0.0
 * 
 * @see     Server 服务端类
 * @see     MethodMapper 方法映射工具
 */
class Client
{
    /**
     * 长连接实例 (进程内共享)
     * 
     * 说明：
     * - 在 Workerman 环境下，此连接会在进程生命周期内复用
     * - 多个 Client 实例（同一进程内）会共享此连接
     * - 连接断开后会自动重连，但需要重新 auth
     * 
     * @var resource|null
     */
    protected static $longConn = null;

    /**
     * 长连接已认证的用户名（防跨用户复用：不同用户的 persistent 客户端不共享连接）
     * 
     * @var string|null
     */
    protected static ?string $longConnUser = null;

    /**
     * 长连接 PID
     * 
     * 说明：
     * - 记录创建长连接的进程 ID
     * - 当进程重启时（如 Worker 重启），会检测到 PID 变化并重置连接
     * 
     * @var int|null
     */
    protected static ?int $longConnPid = null;

    /**
     * 短连接实例
     * 
     * 说明：
     * - 每次创建 Client 实例时独立创建
     * - 不跨进程，不共享
     * 
     * @var resource|null
     */
    protected $shortConn = null;

    /**
     * 服务器地址
     * @var string
     */
    protected string $host;

    /**
     * 服务器端口
     * @var int
     */
    protected int $port;

    /**
     * 连接超时时间（秒）
     * @var float
     */
    protected float $timeout = 3.0;

    /**
     * 请求超时时间（秒）
     * @var float
     */
    protected float $requestTimeout = 5.0;

    /**
     * 是否使用长连接
     * @var bool
     */
    protected bool $persistent = false;

    // 当前会话状态

    /**
     * 当前认证用户名
     * @var string
     */
    protected string $username = '';

    /**
     * 当前选择的数据库名
     * @var string
     */
    protected string $currentDb = 'default';

    /**
     * 是否已认证
     * 
     * 说明：
     * - 调用 auth() 成功后设置为 true
     * - 连接断开或重连后重置为 false
     * 
     * @var bool
     */
    protected bool $isAuthenticated = false;

    /**
     * 构造函数
     * 
     * 使用示例：
     * ```php
     * // 基础用法（短连接）
     * $client = new Client('127.0.0.1:2404');
     * 
     * // 自定义超时和长连接
     * $client = new Client('127.0.0.1:2404', [
     *     'timeout'         => 5.0,        // 连接超时 5 秒
     *     'request_timeout' => 10.0,       // 请求超时 10 秒
     *     'persistent'      => true,       // 使用长连接
     * ]);
     * 
     * // Workerman 环境下自动检测并启用长连接
     * $client = new Client('127.0.0.1:2404', [
     *     'persistent' => null,  // null = 自动检测（推荐）
     * ]);
     * ```
     * 
     * @param string $address 服务器地址，格式 "host:port"（如 "127.0.0.1:2404"）
     * @param array $config 配置数组
     *   - timeout: float 连接超时时间（秒），默认 3.0
     *   - request_timeout: float 请求超时时间（秒），默认 5.0
     *   - persistent: bool|null 是否使用长连接，null=自动检测
     * 
     * @throws \Exception 连接失败时抛出
     */
    public function __construct(string $address = '127.0.0.1:2404', array $config = [])
    {
        [$this->host, $this->port] = explode(':', $address);
        $this->timeout = $config['timeout'] ?? 3.0;
        $this->requestTimeout = $config['request_timeout'] ?? 5.0;

        // 自动检测运行环境决定是否使用长连接
        $persistent = $config['persistent'] ?? null;
        if ($persistent === null) {
            $this->persistent = $this->isInWorkermanWorker();
        } else {
            $this->persistent = $persistent;
        }

        // 初始化方法映射 (仅加载基类方法)
        MethodMapper::buildMethodCache();

        if ($this->persistent) {
            $this->initLongConnection();
        }
    }

    protected function isInWorkermanWorker(): bool
    {
        return class_exists('\Workerman\Worker') 
            && defined('\Workerman\Worker::$pid') 
            && \Workerman\Worker::$pid > 0;
    }

    protected function initLongConnection(): void
    {
        $currentPid = getmypid();
        // 如果 PID 变了（例如 Worker 重启），需要重置连接
        if (self::$longConnPid !== $currentPid) {
            self::$longConn = null;
            self::$longConnUser = null;
            self::$longConnPid = $currentPid;
        }

        // 不同用户不共享连接（认证上下文是连接级的，跨用户复用会导致第二个用户 auth 失败）
        if (self::$longConn !== null && self::$longConnUser !== null && $this->username !== '' && self::$longConnUser !== $this->username) {
            if (is_resource(self::$longConn)) {
                @fclose(self::$longConn);
            }
            self::$longConn = null;
            self::$longConnUser = null;
        }

        if (self::$longConn !== null) {
            if ($this->isConnAlive(self::$longConn)) return;
        }
        
        self::$longConn = $this->createConnection();
    }

    protected function createConnection()
    {
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT
        );
        if (!$socket) throw new \Exception("Connect failed: ({$errno}) {$errstr}");
        
        stream_set_timeout($socket, $this->requestTimeout);
        stream_set_blocking($socket, true);
        return $socket;
    }

    protected function isConnAlive($conn): bool
    {
        if (!$conn) return false;
        $meta = stream_get_meta_data($conn);
        return !($meta['eof'] ?? true) && !($meta['timed_out'] ?? false);
    }

    protected function getConnection()
    {
        if ($this->persistent) {
            if (self::$longConn === null || !$this->isConnAlive(self::$longConn)) {
                $this->initLongConnection();
                // 长连接断开重连后，状态失效，需要重新 auth
                $this->isAuthenticated = false; 
            }
            return self::$longConn;
        } else {
            if ($this->shortConn === null || !$this->isConnAlive($this->shortConn)) {
                $this->shortConn = $this->createConnection();
                $this->isAuthenticated = false;
            }
            return $this->shortConn;
        }
    }

    /**
     * 用户认证
     * 
     * 认证流程：
     * 1. 建立连接（如果还没有连接）
     * 2. 读取服务端发送的挑战码
     * 3. 使用密码和挑战码生成 token: hash('sha256', password . challenge)
     * 4. 发送认证请求
     * 5. 验证响应结果
     * 
     * 使用示例：
     * ```php
     * // 基础认证
     * $client->auth('alice', 'secret');
     * 
     * // 认证失败处理
     * try {
     *     $client->auth('alice', 'wrong_password');
     * } catch (\Exception $e) {
     *     echo "认证失败: " . $e->getMessage();
     * }
     * ```
     * 
     * ⚠️ 注意：
     * - 必须先认证才能执行业务命令
     * - 长连接断开重连后需要重新认证
     * - 认证成功后默认选择 'default' 数据库
     * 
     * @param string $username 用户名
     * @param string $password 密码（明文）
     * @return bool 认证成功返回 true
     * @throws \Exception 认证失败时抛出异常
     */
    public function auth(string $username, string $password): bool
    {
        $conn = $this->getConnection();
        
        // 1. 获取 Challenge (连接建立时服务端已发，直接读取)
        $data = $this->readRaw();
        if (!is_array($data) || !isset($data['challenge'])) {
            throw new \Exception('Auth failed: No challenge received');
        }
        $challenge = $data['challenge'];

        // 2. 发送 Token
        $token = hash('sha256', $password . $challenge);
        $this->sendRaw([
            'cmd'       => 'auth',
            'username'  => $username,
            'token'     => $token,
            'challenge' => $challenge
        ]);

        // 3. 检查结果
        $res = $this->readRaw();
        if (is_array($res) && $res['code'] === 200) {
            $this->isAuthenticated = true;
            $this->username = $username;
            $this->currentDb = 'default'; // 重置库名为默认
            // 记录长连接所属用户（持久化模式复用隔离）
            if ($this->persistent) {
                self::$longConnUser = $username;
            }
            return true;
        }
        
        throw new \Exception($res['msg'] ?? 'Auth failed');
    }

    /**
     * 选择数据库
     * 
     * 说明：
     * - 选择要操作的数据库，数据会按用户+数据库前缀隔离
     * - 认证成功后默认选择 'default' 数据库
     * - 可以随时切换数据库
     * 
     * 使用示例：
     * ```php
     * // 认证后选择数据库
     * $client->auth('alice', 'secret');
     * $client->selectDb('orders');
     * 
     * // 切换数据库
     * $client->selectDb('users');
     * 
     * // 失败处理
     * try {
     *     $client->selectDb('invalid_db');
     * } catch (\Exception $e) {
     *     echo "选库失败: " . $e->getMessage();
     * }
     * ```
     * 
     * ⚠️ 注意：
     * - 必须先认证才能选库
     * - 数据库名只能包含字母、数字和下划线
     * - 切换数据库后，后续所有操作都在新数据库中
     * 
     * @param string $dbName 数据库名（如 'orders', 'users'）
     * @return bool 成功返回 true
     * @throws \Exception 未认证或选库失败时抛出异常
     */
    public function selectDb(string $dbName): bool
    {
        if (!$this->isAuthenticated) {
            throw new \Exception('Not authenticated');
        }

        $this->sendRaw(['cmd' => 'selectDb', 'db' => $dbName]);
        $res = $this->readRaw();
        
        if (is_array($res) && $res['code'] === 200) {
            $this->currentDb = $dbName;
            return true;
        }
        
        throw new \Exception($res['msg'] ?? 'SelectDb failed');
    }

    /**
     * 魔术方法：自动代理业务命令
     * 
     * 此方法通过 `$client->methodName()` 调用方式代理所有 Cache 驱动的业务命令。
     * 方法名和参数会自动映射到服务端的对应方法。
     * 
     * 使用示例：
     * ```php
     * // 基础操作
     * $client->set('user:1001', ['name' => 'Alice'], 3600);
     * $data = $client->get('user:1001');
     * $exists = $client->has('user:1001');
     * $client->delete('user:1001');
     * 
     * // 批量操作
     * $client->mSet([
     *     'key1' => 'value1',
     *     'key2' => 'value2',
     * ], 3600);
     * $results = $client->mGet(['key1', 'key2']);
     * 
     * // 队列操作
     * $client->push('myqueue', ['item1', 'item2']);
     * $item = $client->pop('myqueue');
     * 
     * // 原子操作
     * $newVal = $client->incr('counter', 5); // 增加 5
     * $newVal = $client->incr('counter', 1, 1); // 减少 1（type=1）
     * 
     * // 向量操作
     * $client->vectorSet('doc:1', [0.1, 0.2, 0.3]);
     * $vector = $client->vectorGet('doc:1');
     * $results = $client->vectorSearch([0.1, 0.2, 0.3], 10);
     * 
     * // 锁操作
     * if ($client->lock('my_lock', 5000)) {
     *     try {
     *         // 执行临界区代码
     *     } finally {
     *         $client->unlock('my_lock');
     *     }
     * }
     * 
     * // Stream 消息队列
     * $id = $client->xAdd('mystream', ['field1' => 'value1', 'field2' => 'value2']);
     * $messages = $client->xRange('mystream', '-', '+', 10);
     * ```
     * 
     * 参数映射规则：
     * 1. 优先按参数名精确匹配（如 `$client->set('key', 'value')` 中的 'key' 映射到 $key）
     * 2. 兼容蛇形命名（如 field_values 会自动映射到 fieldValues）
     * 3. 支持常见别名（如 ttl 可以传 expire, timeout 等）
     * 
     * ⚠️ 注意：
     * - 必须先调用 auth() 认证
     * - 方法名区分大小写（与 Cache 驱动一致）
     * - 失败会抛出异常
     * 
     * @param string $name 方法名
     * @param array $arguments 参数数组
     * @return mixed 方法返回值
     * @throws \Exception 未认证时调用
     * @throws \BadMethodCallException 方法不存在时抛出
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!$this->isAuthenticated) {
            throw new \Exception('Not authenticated. Call auth() first.');
        }

        // 检查方法是否存在
        if (!MethodMapper::hasMethod($name)) {
            throw new \BadMethodCallException("Method {$name} not supported");
        }

        // 构建请求包
        $paramNames = MethodMapper::getMethodParams($name);
        $data = ['cmd' => $name];
        // 只传实际提供的参数（连续前缀），缺失的尾部可选参数不传 null，
        // 避免覆盖方法默认值（如 incr($key, $step=1) 传 null 导致 TypeError）
        foreach ($paramNames as $i => $p) {
            if (!array_key_exists($i, $arguments)) {
                break;
            }
            $data[$p] = $arguments[$i];
        }

        return $this->sendAndRecv($data);
    }

    // 底层通信辅助方法

    /**
     * 发送请求帧
     *
     * 与框架协议 Candy\Extend\Protocols\Binary 对齐：长度头 + PackBinary 载荷（禁止 PHP serialize）
     *
     * @param array $data 请求数组（含 cmd 等字段）
     * @return void
     * @throws \Exception 连接写入失败时抛出
     */
    protected function sendRaw(array $data): void
    {
        $conn = $this->getConnection();
        $packet = BinaryProtocol::encode($data);

        $len = strlen($packet);
        $sent = 0;
        while ($sent < $len) {
            $n = fwrite($conn, substr($packet, $sent));
            if ($n === false || $n === 0) {
                // 写入失败，标记断开
                if ($this->persistent) self::$longConn = null;
                else $this->shortConn = null;
                throw new \Exception('Connection lost during write');
            }
            $sent += $n;
        }
    }

    /**
     * 读取并解码一帧响应
     *
     * 按 4 字节长度头收齐整包后交给 BinaryProtocol::decode，与服务端一致
     *
     * @return mixed 解码后的 PHP 数据；读失败返回 null
     */
    protected function readRaw(): mixed
    {
        $conn = $this->getConnection();
        $header = $this->readExact($conn, 4);
        if (!$header) {
            return null;
        }

        $totalLength = unpack('N', $header)[1];
        // 与协议层 input() 一致：非法长度直接视为异常包
        if ($totalLength <= 0 || $totalLength > BinaryProtocol::MAX_PACKET_SIZE) {
            return null;
        }

        $bodyLen = $totalLength - 4;
        if ($bodyLen < 0) {
            return null;
        }

        $body = $bodyLen === 0 ? '' : $this->readExact($conn, $bodyLen);
        if ($body === false) {
            return null;
        }

        return BinaryProtocol::decode($header . $body);
    }

    protected function readExact($conn, int $len): string|false
    {
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($conn, $len - strlen($data));
            if ($chunk === false || $chunk === '') return false;
            $data .= $chunk;
        }
        return $data;
    }

    protected function sendAndRecv(array $data): mixed
    {
        $this->sendRaw($data);
        $res = $this->readRaw();
        if (is_array($res)) {
            if ($res['code'] !== 200) throw new \Exception($res['msg'] ?? 'Error');
            return $res['data'] ?? null;
        }
        return $res;
    }

    public function close(): void
    {
        if (!$this->persistent && $this->shortConn) {
            fclose($this->shortConn);
            $this->shortConn = null;
        }
    }

    public function __destruct() { $this->close(); }
}
