# Workerman FTP 服务器组件使用文档

## 1. 组件简介

Workerman FTP 是一个基于 Workerman 框架开发的轻量级 FTP 服务器组件，提供了基本的 FTP 服务功能。该组件支持用户认证、目录浏览、文件传输等核心 FTP 功能，适用于需要快速搭建简单 FTP 服务的场景。

### 1.1 核心功能
- 支持用户认证系统（USER/PASS命令）
- 支持目录浏览和管理（LIST/CWD/PWD命令）
- 支持 ASCII 和 Binary 传输模式切换（TYPE命令）
- 支持被动模式数据传输（PASV命令）
- 支持系统类型查询（SYST命令）
- 支持特性列表查询（FEAT命令）
- 支持安全的用户权限管理

### 1.2 适用场景
- 需要快速搭建简单 FTP 服务的应用
- 内部系统文件传输需求
- 开发测试环境中的文件共享
- 需要集成 FTP 服务的 Web 应用

## 2. 安装与配置

### 2.1 依赖要求
- PHP >= 5.4
- Workerman 框架
- 支持 TCP 连接的操作系统（Linux/Windows/MacOS）

### 2.2 安装方式
FTP 组件已集成到 Candy 框架中，无需额外安装。组件文件位于：
```
/Candy/Vendor/Workerman/Ftp/
```

## 3. 核心 API 参考

### 3.1 Server 类

#### 3.1.1 构造函数
```php
public function __construct(string $host = '0.0.0.0', int $port = 21, ?string $root_dir = null, array $users = [])
```

**参数说明：**
- `$host`：FTP 服务器监听地址，默认为 '0.0.0.0'（监听所有网络接口）
- `$port`：FTP 服务器监听端口，默认为 21（标准 FTP 端口）
- `$root_dir`：FTP 服务器根目录，默认为 Candy 框架根目录
- `$users`：用户列表，格式为 `['username' => 'password', ...]`

**功能说明：**
初始化 FTP 服务器，设置监听地址、端口、根目录和用户列表。如果根目录不存在，会自动创建。

#### 3.1.2 run 方法
```php
public function run()
```

**功能说明：**
启动 FTP 服务器，开始监听客户端连接。

#### 3.1.3 onConnect 方法
```php
public function onConnect(TcpConnection $connection)
```

**参数说明：**
- `$connection`：客户端连接对象

**功能说明：**
处理客户端连接事件，初始化 FTP 会话信息。

#### 3.1.4 onMessage 方法
```php
public function onMessage(TcpConnection $connection, $data)
```

**参数说明：**
- `$connection`：客户端连接对象
- `$data`：客户端发送的 FTP 命令

**功能说明：**
处理客户端发送的 FTP 命令，根据命令类型调用相应的处理函数。

#### 3.1.5 onClose 方法
```php
public function onClose(TcpConnection $connection)
```

**参数说明：**
- `$connection`：客户端连接对象

**功能说明：**
处理客户端断开连接事件，清理资源。

## 4. 命令行使用（推荐）

```bash
# 启动 FTP 服务器（后台驻留）
php candy ftp start --host=0.0.0.0 --port=21 --data-dir=/data/ftp \
    --users=admin:secret123,user1:pass456 --passive-ports=50000-60000

# 停止 / 重启 / 热重载 / 状态查看
php candy ftp stop
php candy ftp restart      # 自动恢复上次启动参数
php candy ftp reload
php candy ftp status
```

- `--users` 支持明文密码或已有 `password_hash`（`$2y$...`，避免明文出现在 ps 中）
- 密码在服务器内部统一 `password_hash` 存储，并带基于 IP 的失败锁定（5 次/300 秒，计数持久化跨重启生效）
- 服务器使用独立 PID/日志/状态文件（`Runtime/Ftp/`），与主 Web 服务器互不干扰

## 5. 支持的 FTP 命令

### 5.1 认证命令
- **USER**：指定用户名（未存在用户也统一回 331，防枚举）
- **PASS**：指定密码，完成认证

### 5.2 目录操作命令
- **CWD / CDUP**：切换当前工作目录（虚拟路径约束在根目录内，`../` 越界自动钳制到根）
- **PWD**：显示当前工作目录
- **MKD / RMD**：创建 / 删除空目录
- **LIST / NLST**：列出目录内容（支持 `-la` 选项与路径参数）

### 5.3 文件操作命令
- **RETR**：下载文件（支持 REST 断点续传、ASCII/Binary 转换）
- **STOR**：上传文件
- **DELE**：删除文件
- **RNFR / RNTO**：重命名文件/目录
- **SIZE / MDTM**：查询文件大小 / 修改时间

### 5.4 传输参数命令
- **TYPE**：设置传输模式（A=ASCII，I=Binary）
- **PASV / EPSV**：被动模式（数据端口在配置范围内随机分配）
- **PORT**：主动模式（服务器主动连接客户端数据端口）
- **REST**：设置续传偏移

### 5.5 系统命令
- **SYST**：查询服务器系统类型
- **FEAT**：查询服务器支持的特性（PASV/EPSV/SIZE/MDTM/REST/UTF8）
- **NOOP / STAT / HELP / ABOR / OPTS**：基础会话命令
- **QUIT**：退出 FTP 会话

## 6. 高级功能与配置

### 6.1 自定义被动模式端口范围

通过命令行 `--passive-ports` 或构造参数配置：

```bash
php candy ftp start --passive-ports=50000-60000
```

```php
$ftp_server = new Server('0.0.0.0', 21, '/data/ftp', ['admin' => 'secret'], [50000, 60000]);
```

### 6.2 安全特性

- 密码 `password_hash` 存储，不再明文比较
- 基于 IP 的暴力破解锁定（默认 5 次失败锁定 300 秒，计数持久化到 `Runtime/Ftp/auth.json`，多进程/重启后仍生效）
- 虚拟路径约束：`CWD /etc`、`../../etc/passwd` 等越界访问全部拒绝
- USER 命令统一响应，避免用户枚举

## 7. 代码结构与实现原理

### 7.1 核心属性

| 属性名 | 类型 | 说明 |
|--------|------|------|
| `worker` | Worker | Workerman TCP Worker 实例 |
| `users` | array | 用户列表，格式为 `['username' => 'password_hash']` |
| `root_dir` | string | FTP 服务器根目录 |
| `passive_port_range` | array | 被动模式端口范围，默认 [50000, 60000] |
| `passive_workers` | array | 已监听的被动数据端口 => Worker（保活引用） |

### 7.2 会话结构

每个客户端连接都会创建一个 FTP 会话，会话信息存储在连接对象的 `ftp` 属性中：

```php
$connection->ftp = [
    'authenticated' => false,  // 是否已认证
    'username' => null,        // 用户名
    'cwd' => '/',              // 当前工作目录（虚拟路径）
    'type' => 'A',             // 传输模式（ASCII 或 Binary）
    'data_connection' => null, // 数据连接对象
    'data_worker_port' => null // 被动数据端口
];
```

### 7.3 数据传输流程

1. 客户端发送 PASV/EPSV 命令，请求进入被动模式
2. 服务器动态监听随机端口（在被动模式端口范围内，冲突自动重试），Worker 持有引用保活
3. 服务器返回被动模式地址和端口给客户端
4. 客户端使用返回的地址和端口建立数据连接（兼容“先连后命令”与“先命令后连”两种时序）
5. 客户端发送 LIST/RETR/STOR 等需要数据传输的命令
6. 服务器通过数据连接发送/接收数据（RETR 分块发送，发送缓冲满时由 onBufferDrain 推进）
7. 数据传输完成后，先向控制连接发送 226，再清理数据通道

## 8. 性能优化建议

### 8.1 调整 Worker 进程数

目前组件默认使用 1 个 Worker 进程，可以根据需要调整：

```php
// 在 Server.php 文件中找到以下行
$this->worker->count = 1;

// 修改为合适的进程数
$this->worker->count = 4;
```

### 8.2 限制并发连接数

可以添加并发连接数限制，防止服务器资源耗尽：

```php
// 扩展 Server 类
class LimitedFTPServer extends Server
{
    private $max_connections = 100;
    private $current_connections = 0;
    
    public function onConnect(TcpConnection $connection)
    {
        if ($this->current_connections >= $this->max_connections) {
            $connection->send("421 Service not available, closing control connection.
");
            $connection->close();
            return;
        }
        
        $this->current_connections++;
        parent::onConnect($connection);
    }
    
    public function onClose(TcpConnection $connection)
    {
        if ($this->current_connections > 0) {
            $this->current_connections--;
        }
        parent::onClose($connection);
    }
}
```

### 8.3 优化文件系统操作

可以缓存目录列表，减少文件系统操作：

```php
// 扩展 Server 类
class CachedFTPServer extends Server
{
    private $dir_cache = [];
    private $cache_ttl = 30; // 缓存有效期，单位秒
    
    private function getCachedDirList($dir)
    {
        $now = time();
        if (isset($this->dir_cache[$dir]) && $now - $this->dir_cache[$dir]['time'] < $this->cache_ttl) {
            return $this->dir_cache[$dir]['list'];
        }
        
        // 生成目录列表
        $list = $this->generateDirList($dir);
        
        // 更新缓存
        $this->dir_cache[$dir] = [
            'time' => $now,
            'list' => $list
        ];
        
        return $list;
    }
    
    private function generateDirList($dir)
    {
        // 实现目录列表生成逻辑
        // ...
    }
}
```

## 9. 常见问题解答

### 9.1 为什么客户端连接不上 FTP 服务器？

**可能原因：**
1. FTP 服务器未启动
2. 端口被防火墙阻塞
3. 服务器监听地址设置不正确
4. 客户端网络问题

**解决方案：**
```php
// 检查服务器是否在监听
netstat -tlnp | grep 21

// 确保防火墙开放了 FTP 端口
// 在 Linux 上
iptables -A INPUT -p tcp --dport 21 -j ACCEPT

// 确保被动模式端口范围也开放了
iptables -A INPUT -p tcp --dport 50000:60000 -j ACCEPT

// 检查服务器监听地址
$ftp_server = new Server('0.0.0.0', 21);
```

### 9.2 为什么 LIST 命令执行失败？

**可能原因：**
1. 用户未认证
2. 数据连接建立失败
3. 目录权限问题
4. 被动模式端口被防火墙阻塞

**解决方案：**
```php
// 确保用户已认证
if (!$connection->ftp['authenticated']) {
    $connection->send("530 Please login with USER and PASS.
");
    return;
}

// 确保被动模式端口范围开放
// 在组件代码中检查被动模式端口范围设置
private $passive_port_range = [50000, 60000];
```

### 9.3 如何增加更多 FTP 命令支持？

可以通过扩展 Server 类，添加新的命令处理函数：

```php
// 扩展 Server 类
class ExtendedFTPServer extends Server
{
    public function onMessage(TcpConnection $connection, $data)
    {
        $command = strtoupper(strtok(trim($data), ' '));
        $param = substr(trim($data), strlen($command) + 1);

        // 处理自定义命令
        switch ($command) {
            case 'MKD':
                $this->handleMkd($connection, $param);
                return;
            case 'RMD':
                $this->handleRmd($connection, $param);
                return;
        }
        
        // 调用父类方法处理其他命令
        parent::onMessage($connection, $data);
    }
    
    // 处理创建目录命令
    private function handleMkd(TcpConnection $connection, $param)
    {
        // 实现创建目录逻辑
        // ...
    }
    
    // 处理删除目录命令
    private function handleRmd(TcpConnection $connection, $param)
    {
        // 实现删除目录逻辑
        // ...
    }
}
```

### 9.4 如何实现 SSL/TLS 加密？

可以使用 Workerman 的 SSL 功能，为 FTP 服务器添加 SSL/TLS 加密：

```php
// 在 Server.php 文件中找到以下行
$this->worker = new Worker("tcp://{$host}:{$port}");

// 修改为 SSL 连接
$this->worker = new Worker("ssl://{$host}:{$port}");
$this->worker->transport = 'ssl';
$this->worker->ssl = [
    'local_cert' => '/path/to/cert.pem',  // 证书文件
    'local_pk' => '/path/to/key.pem',     // 私钥文件
    'verify_peer' => false                // 不验证客户端证书
];
```

## 10. 总结

Workerman FTP 服务器组件提供了一个轻量级的 FTP 服务实现，支持基本的 FTP 命令和功能。该组件易于使用和扩展，适用于需要快速搭建简单 FTP 服务的场景。通过合理的配置和扩展，可以满足不同应用场景的需求。

虽然目前组件只实现了基本的 FTP 功能，但通过扩展可以轻松添加更多命令和功能。在生产环境中使用时，建议根据实际需求进行适当的安全加固和性能优化。