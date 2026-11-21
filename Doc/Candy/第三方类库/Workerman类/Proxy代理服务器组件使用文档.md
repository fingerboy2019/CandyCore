# Proxy代理服务器组件使用文档

## 1. 组件概述

Proxy组件是基于Workerman实现的高性能代理服务器框架，提供了两种代理服务实现：

- **HTTP代理**：支持HTTP请求转发和HTTPS CONNECT方法
- **Socks5代理**：支持TCP连接和UDP关联，支持多种认证方式

该组件具有高性能、低延迟、支持高并发等特点，可用于网络爬虫、安全访问、内容过滤等场景。

## 2. 核心功能

### 2.1 HTTP代理功能
- 支持HTTP GET、POST、PUT、DELETE等请求方法
- 支持HTTPS CONNECT隧道
- 简单易用，配置灵活
- 高性能异步处理

### 2.2 Socks5代理功能
- 支持TCP连接转发
- 支持UDP关联
- 支持无认证(METHOD_NO_AUTH)和用户名密码认证(METHOD_USER_PASS)
- 支持IPv4地址和域名解析
- 完整的Socks5协议实现

## 3. 安装与配置

### 3.1 环境要求
- PHP 7.2+
- Workerman框架
- 启用sockets扩展

### 3.2 基本配置

#### HTTP代理配置
```php
use Proxy\Server;

$config = [
    'address' => '0.0.0.0:8080',  // 监听地址和端口
    'name'    => 'ProxyServer',    // 服务器名称
    'count'   => 6                 // 进程数
];

$proxy = new Server($config);
$proxy->runAll();
```

#### Socks5代理配置
```php
use Proxy\Socks;

$config = [
    'address' => '0.0.0.0:1080',   // 监听地址和端口
    'name'    => 'Socks5Server',   // 服务器名称
    'count'   => 6,                // 进程数
    'auth'    => [                 // 认证配置
        METHOD_NO_AUTH => true     // 允许无认证
    ]
];

$socks = new Socks($config);
$socks->runAll();
```

## 4. API参考

### 4.1 Server类（HTTP代理）

#### 构造函数
```php
public function __construct(array $config = [])
```

**参数**：
- `$config`：配置数组
  - `address`：监听地址和端口，默认: '0.0.0.0:8080'
  - `name`：服务器名称，默认: 'ProxyServer'
  - `count`：进程数，默认: 6

#### 核心方法

##### run()
```php
public function run(): void
```
运行代理服务器实例，设置消息处理回调函数并启动Worker实例。

##### onMessage()
```php
public function onMessage($connection, $buffer): void
```
消息处理函数，处理客户端请求，解析HTTP头，建立与目标服务器的连接并进行数据转发。

**参数**：
- `$connection`：客户端连接对象
- `$buffer`：客户端发送的数据

### 4.2 Socks类（Socks5代理）

#### 构造函数
```php
public function __construct(array $config = [])
```

**参数**：
- `$config`：配置数组
  - `address`：监听地址和端口，默认: '0.0.0.0:1234'
  - `name`：服务器名称，默认: 'Socks5Server'
  - `count`：进程数，默认: 6
  - `auth`：认证配置，默认: [METHOD_NO_AUTH => true]

#### 核心方法

##### run()
```php
public function run(): void
```
运行Socks5服务器实例，设置连接、消息和关闭事件的回调函数，并启动UDP Worker实例。

##### onConnect()
```php
public function onConnect($connection): void
```
客户端连接事件处理，初始化客户端连接的阶段和认证类型。

**参数**：
- `$connection`：客户端连接对象

##### onMessage()
```php
public function onMessage($connection, $buffer): void
```
消息处理函数，根据客户端连接的当前阶段处理不同类型的请求。

**参数**：
- `$connection`：客户端连接对象
- `$buffer`：客户端发送的数据

##### onClose()
```php
public function onClose($connection): void
```
客户端关闭事件处理，记录客户端关闭日志。

**参数**：
- `$connection`：客户端连接对象

##### onWorkerStart()
```php
public function onWorkerStart($worker): void
```
Worker启动事件处理，初始化UDP连接数组，并设置定时清理过期连接的任务。

**参数**：
- `$worker`：Worker实例

## 5. 使用示例

### 5.1 HTTP代理示例

```php
<?php

use Proxy\Server;

// 创建HTTP代理服务器
$proxy = new Server([
    'address' => '0.0.0.0:8080',
    'name'    => 'MyHTTPProxy',
    'count'   => 4
]);

// 运行代理服务器
$proxy->runAll();
```

### 5.2 Socks5代理示例（无认证）

```php
<?php

use Proxy\Socks;

// 创建Socks5代理服务器
$socks5 = new Socks([
    'address' => '0.0.0.0:1080',
    'name'    => 'MySocks5Proxy',
    'count'   => 8,
    'auth'    => [
        METHOD_NO_AUTH => true
    ]
]);

// 运行代理服务器
$socks5->runAll();
```

### 5.3 Socks5代理示例（用户名密码认证）

```php
<?php

use Proxy\Socks;

// 创建Socks5代理服务器，使用用户名密码认证
$socks5 = new Socks([
    'address' => '0.0.0.0:1080',
    'name'    => 'MySecureSocks5Proxy',
    'count'   => 8,
    'auth'    => [
        METHOD_USER_PASS => function ($request) {
            // 自定义认证逻辑
            $username = $request['user'];
            $password = $request['pass'];
            
            // 示例：简单的用户名密码验证
            return $username === 'admin' && $password === 'password123';
        }
    ]
]);

// 运行代理服务器
$socks5->runAll();
```

## 6. 高级功能

### 6.1 Socks5代理的UDP支持

Socks5代理默认支持UDP关联，可以通过UDP Worker处理UDP数据包。在创建Socks5服务器时，会自动启动一个UDP Worker来处理UDP关联请求。

### 6.2 自定义认证

对于Socks5代理，可以通过回调函数实现自定义的认证逻辑：

```php
$config['auth'][METHOD_USER_PASS] = function ($request) {
    // $request 包含 'user' 和 'pass' 字段
    
    // 可以连接数据库验证
    // 可以调用外部认证服务
    // 可以实现复杂的认证逻辑
    
    return true; // 认证成功返回true，失败返回false
};
```

### 6.3 配置WanIP（用于UDP关联）

对于Socks5代理的UDP关联功能，需要配置正确的WanIP，以便客户端能够正确连接到UDP服务：

```php
$socks5 = new Socks([
    // 其他配置...
    'wanIP' => '192.168.1.100'  // 服务器的公网IP
]);
```

## 7. 最佳实践

### 7.1 性能优化
- 根据服务器性能调整进程数（count参数）
- 监控服务器负载，及时调整配置
- 对于高并发场景，考虑使用多核CPU和足够的内存

### 7.2 安全建议
- 避免在公网直接暴露未认证的代理服务
- 使用复杂的用户名和密码进行认证
- 考虑限制代理的访问IP范围
- 定期审查代理日志，发现异常行为

### 7.3 错误处理

虽然Proxy组件内部已经处理了大部分错误，但仍建议在生产环境中添加适当的日志记录：

```php
use Candy\Core\Log;

// 设置日志级别
Log::setLevel(Log::INFO);

// 运行代理服务器
$proxy->runAll();
```

## 8. 协议参考

### 8.1 HTTP代理协议

HTTP代理遵循HTTP协议规范，支持标准的HTTP方法和头字段。

### 8.2 Socks5代理协议

Socks5代理遵循RFC 1928规范，主要包含以下阶段：
1. 认证方法协商
2. 认证过程
3. 地址和端口请求
4. 连接建立
5. 数据传输

## 9. 常见问题

### 9.1 代理服务器无法启动
- 检查端口是否被占用
- 检查是否有足够的权限
- 确保sockets扩展已启用

### 9.2 客户端无法连接
- 检查服务器防火墙设置
- 检查监听地址是否正确
- 确保客户端使用正确的代理协议

### 9.3 HTTPS连接失败
- 确保HTTPS CONNECT方法被正确处理
- 检查目标服务器是否支持HTTPS
- 检查证书问题

## 10. 总结

Proxy组件提供了高性能、易用的代理服务器实现，支持HTTP和Socks5两种代理协议。通过简单的配置和使用，开发者可以快速构建自己的代理服务，满足不同场景的需求。

该组件基于Workerman框架的异步特性，具有出色的性能表现，适合处理高并发的代理请求。同时，丰富的配置选项和扩展接口，使得代理服务可以根据实际需求进行灵活定制。