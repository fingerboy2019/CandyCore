# Workerman 框架使用文档

## 1. 框架概述

Workerman是一个高性能的异步非阻塞PHP网络编程框架，支持TCP/UDP/HTTP/WebSocket等多种协议，适用于构建实时通信系统、网关、游戏服务器等高性能网络应用。

### 1.1 核心特点

- **异步非阻塞**：基于事件驱动的异步IO模型，单进程可处理数千并发连接
- **多协议支持**：内置支持TCP、UDP、HTTP、WebSocket等多种协议
- **多进程架构**：支持多进程运行模式，充分利用多核CPU
- **定时器**：内置定时器功能，支持延迟和重复任务
- **灵活扩展**：支持自定义协议和事件驱动实现
- **高性能**：基于C扩展的事件驱动（如libevent、swoole），性能优异

## 2. 目录结构

Workerman核心目录包含以下主要组件：

```
Workerman/
├── Autoloader.php     # 类自动加载器
├── Timer.php          # 定时器功能
├── Worker.php         # 核心工作进程类
├── Connection/        # 连接处理相关类
├── Events/            # 事件驱动相关类
└── Protocols/         # 协议实现相关类
```

## 3. 核心组件

### 3.1 Worker 类

Worker是Workerman的核心类，负责管理进程和事件循环。

#### 3.1.1 主要功能

- 创建和管理网络服务
- 处理客户端连接
- 管理子进程
- 处理事件循环

#### 3.1.2 核心方法

```php
// 构造函数：创建一个Worker实例
public function __construct(?string $socketName = null, array $socketContext = []);

// 设置连接回调函数
public function onConnect(callable $callback);

// 设置消息回调函数
public function onMessage(callable $callback);

// 设置关闭回调函数
public function onClose(callable $callback);

// 设置错误回调函数
public function onError(callable $callback);

// 运行所有Worker实例
public static function runAll(): void;
```

### 3.2 Connection 组件

Connection组件负责处理网络连接，支持TCP和UDP两种协议。

#### 3.2.1 主要类

- **TcpConnection**：TCP连接实现
- **UdpConnection**：UDP连接实现
- **AsyncTcpConnection**：异步TCP连接
- **AsyncUdpConnection**：异步UDP连接
- **ConnectionInterface**：连接接口

#### 3.2.2 核心方法

```php
// 发送数据
public function send($data);

// 关闭连接
public function close($data = null);

// 设置连接超时时间
public function setSocketOption(int $level, int $optname, $optval);
```

### 3.3 Events 组件

Events组件是Workerman的事件驱动核心，支持多种事件驱动实现。

#### 3.3.1 主要类

- **Select**：基于select的事件驱动（PHP内置，兼容性好）
- **Event**：基于libevent的事件驱动（高性能）
- **Ev**：基于libev的事件驱动（高性能）
- **Swoole**：基于Swoole的事件驱动（高性能，支持协程）
- **Swow**：基于Swow的事件驱动（高性能，支持协程）
- **Fiber**：基于Fiber的事件驱动（PHP 8.1+）

#### 3.3.2 核心接口

```php
// 添加读事件监听
public function add($fd, $flag, callable $func, $args = []);

// 移除事件监听
public function del($fd, $flag);

// 设置定时器
public function delay(float $time_interval, callable $func, array $args = []);

// 设置重复定时器
public function repeat(float $time_interval, callable $func, array $args = []);
```

### 3.4 Protocols 组件

Protocols组件负责处理不同网络协议的编解码。

#### 3.4.1 主要类

- **Http**：HTTP协议实现（上游 `Workerman\Protocols\Http`）
- **Candy Http**：入站适配层，见 [Http使用文档](Http使用文档.md)（`Workerman\Http\Http`）
- **Websocket**：WebSocket协议实现
- **Ws**：简化的WebSocket协议实现
- **Text**：文本协议实现
- **Frame**：帧协议实现
- **ProtocolInterface**：协议接口

#### 3.4.2 核心接口

```php
// 协议解码
public static function input(string $buffer, TcpConnection $connection);

// 协议编码
public static function encode($data);

// 协议解析
public static function decode(string $buffer);
```

### 3.5 Timer 组件

Timer组件用于实现延迟和重复任务。

#### 3.5.1 核心方法

```php
// 添加定时器
public static function add(float $time_interval, callable $func, ?array $args = [], bool $persistent = true);

// 添加延迟任务
public static function delay(float $time_interval, callable $func, array $args = []);

// 添加重复任务
public static function repeat(float $time_interval, callable $func, array $args = []);

// 删除定时器
public static function del(int $timer_id);

// 删除所有定时器
public static function delAll();
```

## 4. 使用示例

### 4.1 TCP 服务器示例

```php
<?php
use Workerman\Worker;

// 创建一个TCP Worker监听端口1234
$tcp_worker = new Worker('tcp://0.0.0.0:1234');

// 设置进程数
$tcp_worker->count = 4;

// 当客户端连接时触发
$tcp_worker->onConnect = function($connection) {
    echo "新客户端连接\n";
};

// 当客户端发送数据时触发
$tcp_worker->onMessage = function($connection, $data) {
    // 向客户端发送数据
    $connection->send("收到数据：$data");
};

// 当客户端关闭连接时触发
$tcp_worker->onClose = function($connection) {
    echo "客户端断开连接\n";
};

// 运行所有Worker
Worker::runAll();
```

### 4.2 HTTP 服务器示例

```php
<?php
use Workerman\Worker;

// 创建一个HTTP Worker监听端口8080
$http_worker = new Worker('http://0.0.0.0:8080');

// 设置进程数
$http_worker->count = 4;

// 当收到HTTP请求时触发
$http_worker->onMessage = function($connection, $request) {
    // 解析请求参数
    $get = $request->get();
    $post = $request->post();
    $headers = $request->header();
    
    // 构造响应
    $response = array(
        'get' => $get,
        'post' => $post,
        'headers' => $headers,
        'time' => time()
    );
    
    // 发送JSON响应
    $connection->send(json_encode($response));
};

// 运行所有Worker
Worker::runAll();
```

### 4.3 WebSocket 服务器示例

```php
<?php
use Workerman\Worker;

// 创建一个WebSocket Worker监听端口8080
$ws_worker = new Worker('websocket://0.0.0.0:8080');

// 设置进程数
$ws_worker->count = 4;

// 当客户端连接时触发
$ws_worker->onConnect = function($connection) {
    echo "新WebSocket客户端连接\n";
};

// 当收到WebSocket消息时触发
$ws_worker->onMessage = function($connection, $data) {
    // 向客户端发送消息
    $connection->send("收到消息：$data");
};

// 当客户端关闭连接时触发
$ws_worker->onClose = function($connection) {
    echo "WebSocket客户端断开连接\n";
};

// 运行所有Worker
Worker::runAll();
```

### 4.4 定时器示例

```php
<?php
use Workerman\Worker;
use Workerman\Timer;

// 创建一个Worker实例
$worker = new Worker();

// 设置进程数
$worker->count = 2;

// 当Worker启动时触发
$worker->onWorkerStart = function($worker) {
    echo "Worker启动\n";
    
    // 设置一个延迟1秒执行的任务
    Timer::delay(1, function() {
        echo "延迟任务执行\n";
    });
    
    // 设置一个每2秒执行一次的重复任务
    Timer::repeat(2, function() {
        echo "重复任务执行\n";
    });
    
    // 设置一个定时器并保存ID，以便后续删除
    $timer_id = Timer::repeat(1, function() {
        static $count = 0;
        echo "计数：$count\n";
        $count++;
        
        // 执行10次后删除定时器
        if ($count >= 10) {
            Timer::del($timer_id);
            echo "定时器已删除\n";
        }
    });
};

// 运行所有Worker
Worker::runAll();
```

## 5. API参考

### 5.1 Worker类

#### 5.1.1 构造函数

```php
public function __construct(?string $socketName = null, array $socketContext = []);
```

- `$socketName`：监听的套接字名称，格式如`tcp://0.0.0.0:8080`
- `$socketContext`：套接字上下文选项

#### 5.1.2 核心方法

```php
// 设置进程数
public function count(int $count);

// 设置用户
public function user(string $user);

// 设置组
public function group(string $group);

// 设置运行模式
public function name(string $name);

// 设置启动文件
public function startFile(string $startFile);

// 设置Pid文件路径
public function pidFile(string $pidFile);

// 设置日志文件路径
public function logFile(string $logFile);

// 设置连接超时时间
public function setTimeout(int $timeout);

// 设置缓冲区大小
public function setBufferSize(int $bufferSize);

// 设置协议
public function protocol(string $protocol);

// 设置SSL证书
public function transport(string $transport);

// 运行所有Worker
public static function runAll(): void;
```

### 5.2 TcpConnection类

#### 5.2.1 核心方法

```php
// 发送数据
public function send($data);

// 关闭连接
public function close($data = null);

// 获取连接ID
public function id();

// 获取远程地址
public function getRemoteIp();

// 获取远程端口
public function getRemotePort();

// 获取本地地址
public function getLocalIp();

// 获取本地端口
public function getLocalPort();

// 设置套接字选项
public function setSocketOption(int $level, int $optname, $optval);

// 获取套接字选项
public function getSocketOption(int $level, int $optname);
```

### 5.3 Timer类

#### 5.3.1 核心方法

```php
// 添加定时器
public static function add(float $time_interval, callable $func, ?array $args = [], bool $persistent = true);

// 添加延迟任务
public static function delay(float $time_interval, callable $func, array $args = []);

// 添加重复任务
public static function repeat(float $time_interval, callable $func, array $args = []);

// 删除定时器
public static function del(int $timer_id);

// 删除所有定时器
public static function delAll();
```

### 5.4 Autoloader类

#### 5.4.1 核心方法

```php
// 设置自动加载根路径
public static function setRootPath($root_path);

// 按命名空间加载文件
public static function loadByNamespace($name);
```

## 6. 高级功能

### 6.1 SSL/TLS支持

```php
<?php
use Workerman\Worker;

// 创建一个支持SSL的Worker
$ssl_worker = new Worker('websocket://0.0.0.0:8080', [
    'ssl' => [
        'local_cert' => '/path/to/ssl/cert.pem',
        'local_pk' => '/path/to/ssl/key.pem',
        'verify_peer' => false
    ]
]);

// 设置transport为ssl
$ssl_worker->transport = 'ssl';

// 其他配置...

Worker::runAll();
```

### 6.2 自定义协议

```php
<?php
use Workerman\Worker;
use Workerman\Protocols\ProtocolInterface;
use Workerman\Connection\TcpConnection;

// 自定义协议类
class MyProtocol implements ProtocolInterface {
    // 协议解码：返回需要接收的数据长度
    public static function input(string $buffer, TcpConnection $connection) {
        // 简单的换行符分隔协议
        $pos = strpos($buffer, "\n");
        if ($pos === false) {
            // 没有找到换行符，返回0表示继续接收数据
            return 0;
        }
        // 返回需要接收的数据长度
        return $pos + 1;
    }
    
    // 协议编码
    public static function encode($data) {
        // 在数据末尾添加换行符
        return $data . "\n";
    }
    
    // 协议解析
    public static function decode(string $buffer) {
        // 移除末尾的换行符
        return rtrim($buffer, "\n");
    }
}

// 创建Worker并设置自定义协议
$worker = new Worker('tcp://0.0.0.0:8080');
$worker->protocol = 'MyProtocol';

// 当收到数据时触发
$worker->onMessage = function($connection, $data) {
    // $data已经是经过MyProtocol::decode处理后的结果
    echo "收到数据：$data\n";
    // 发送数据时会自动经过MyProtocol::encode处理
    $connection->send("响应：$data");
};

Worker::runAll();
```

### 6.3 进程间通信

```php
<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

// 创建一个用于进程间通信的Worker
$ipc_worker = new Worker('Text://0.0.0.0:5678');

// 存储所有连接
$connections = [];

// 当客户端连接时触发
$ipc_worker->onConnect = function($connection) use (&$connections) {
    $connections[$connection->id] = $connection;
};

// 当客户端关闭连接时触发
$ipc_worker->onClose = function($connection) use (&$connections) {
    unset($connections[$connection->id]);
};

// 当收到数据时触发
$ipc_worker->onMessage = function($connection, $data) {
    echo "收到IPC消息：$data\n";
    // 广播消息给所有连接
    global $connections;
    foreach ($connections as $conn) {
        if ($conn !== $connection) {
            $conn->send($data);
        }
    }
};

// 创建另一个Worker用于业务处理
$business_worker = new Worker();

// 当Worker启动时触发
$business_worker->onWorkerStart = function($worker) {
    // 连接到IPC Worker
    $connection = new AsyncTcpConnection('Text://127.0.0.1:5678');
    
    // 当连接成功时触发
    $connection->onConnect = function() use ($connection) {
        echo "IPC连接成功\n";
        // 发送消息
        $connection->send("Hello from business worker");
    };
    
    // 当收到IPC消息时触发
    $connection->onMessage = function($connection, $data) {
        echo "业务Worker收到IPC消息：$data\n";
    };
    
    // 连接错误时触发
    $connection->onError = function($connection, $code, $msg) {
        echo "IPC连接错误：$msg\n";
    };
    
    // 连接关闭时触发
    $connection->onClose = function() {
        echo "IPC连接关闭\n";
    };
    
    // 建立连接
    $connection->connect();
};

Worker::runAll();
```

## 7. 部署与运行

### 7.1 启动服务

```bash
# 调试模式启动
php start.php start

# 后台模式启动
php start.php start -d

# 重启服务
php start.php restart

# 平滑重启服务
php start.php reload

# 停止服务
php start.php stop

# 查看状态
php start.php status

# 查看连接状态
php start.php connections
```

### 7.2 配置建议

- **进程数设置**：根据CPU核心数设置，一般为CPU核心数的1-4倍
- **内存限制**：根据应用需求调整PHP的memory_limit配置
- **文件描述符限制**：调整系统的ulimit设置，提高最大文件描述符数
- **日志管理**：定期清理日志文件，避免占用过多磁盘空间

## 8. 性能优化

1. **使用高性能事件驱动**：优先选择libevent、swoole或swow等高性能事件驱动
2. **合理设置进程数**：根据CPU核心数和业务特点设置合适的进程数
3. **减少内存分配**：避免频繁的内存分配和释放，使用对象池等技术
4. **优化数据传输**：使用更高效的协议和数据格式，如protobuf
5. **合理设置缓冲区大小**：根据业务特点调整缓冲区大小
6. **避免阻塞操作**：在事件回调中避免使用阻塞函数，如sleep、file_get_contents等

## 9. 常见问题

### 9.1 端口被占用

```bash
# 查看端口占用情况
lsof -i:8080

# 或者使用netstat
netstat -tuln | grep 8080

# 结束占用端口的进程
kill -9 <进程ID>
```

### 9.2 权限问题

确保PHP进程有足够的权限读取配置文件和写入日志文件，必要时使用sudo命令或调整文件权限。

### 9.3 防火墙问题

确保防火墙已开放相关端口，如：

```bash
# 开放8080端口
firewall-cmd --zone=public --add-port=8080/tcp --permanent
firewall-cmd --reload
```

## 10. 总结

Workerman是一个功能强大的PHP网络编程框架，具有高性能、高并发、易扩展等特点，适用于构建各种网络应用。通过本文的介绍，您应该已经掌握了Workerman的基本使用方法和核心功能，可以开始构建自己的高性能网络应用了。