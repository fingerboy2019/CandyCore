# WebServer 组件使用文档

## 1. 组件概述

WebServer 是一个基于 Workerman 和 Swoole 实现的高性能 Web 服务器组件，提供了完整的 Web 服务功能，支持虚拟主机、PHP 文件执行、静态文件服务、SSL/HTTPS 等特性。

主要特性：
- 基于 Workerman 和 Swoole 实现的高性能异步非阻塞架构
- 支持虚拟主机配置
- 完整的 PHP 文件执行环境
- 高效的静态文件服务
- 支持 SSL/HTTPS 加密传输
- URL 强制跳转功能（如 HTTP 转 HTTPS）
- 访问日志记录
- 自定义 404 页面
- 支持 ETag 和 304 缓存机制
- 支持 Server-Sent Events (SSE) 实时通信

## 2. 安装和配置

### 2.1 依赖要求

- PHP >= 7.2
- Workerman 框架（用于 Server 类）
- Swoole 扩展（可选，用于 Swoole 类）

### 2.2 基本配置

WebServer 组件不需要额外安装，已集成在 Candy 框架中。

## 3. 使用示例

### 3.1 使用 Workerman 实现的 WebServer

```php
<?php
use WebServer\Server;

// 创建 WebServer 实例
$webServer = new Server('0.0.0.0:8080');

// 添加虚拟主机配置
$webServer->addRoot('www.example.com', '/path/to/website');
$webServer->addRoot('blog.example.com', '/path/to/blog');

// 启动服务器
$webServer->run();
```

### 3.2 使用 Swoole 实现的 WebServer

```php
<?php
use WebServer\Swoole;

// 创建 Swoole WebServer 实例
$webServer = new Swoole('0.0.0.0:8080');

// 添加虚拟主机配置
$webServer->addRoot('www.example.com', '/path/to/website');
$webServer->addRoot('blog.example.com', '/path/to/blog');

// 设置 Swoole 相关配置
$webServer->setConfig([
    'worker_num' => 4,
    'max_request' => 1000,
    'dispatch_mode' => 2
]);

// 启动服务器
$webServer->start();
```

### 3.3 配置 HTTPS/SSL

#### Workerman 版本

```php
<?php
use WebServer\Server;

// SSL 配置
$contextOption = [
    'ssl' => [
        'local_cert' => '/path/to/ssl.crt',
        'local_pk' => '/path/to/ssl.key',
        'verify_peer' => false
    ]
];

// 创建支持 HTTPS 的 WebServer 实例
$webServer = new Server('0.0.0.0:443', $contextOption);

// 添加虚拟主机配置
$webServer->addRoot('www.example.com', '/path/to/website');

// 启动服务器
$webServer->run();
```

#### Swoole 版本

```php
<?php
use WebServer\Swoole;

// SSL 配置
$contextOption = [
    'ssl' => [
        'local_cert' => '/path/to/ssl.crt',
        'local_pk' => '/path/to/ssl.key'
    ]
];

// 创建支持 HTTPS 的 Swoole WebServer 实例
$webServer = new Swoole('0.0.0.0:443', $contextOption);

// 添加虚拟主机配置
$webServer->addRoot('www.example.com', '/path/to/website');

// 启动服务器
$webServer->start();
```

### 3.4 配置 URL 强制跳转（HTTP 转 HTTPS）

```php
<?php
use WebServer\Server;

// 配置强制跳转列表
Server::$urlCompelList = [
    'www.example.com',
    'example.com'
];

// 创建 HTTP 服务器（用于跳转）
$httpServer = new Server('0.0.0.0:80');
$httpServer->addRoot('www.example.com', '/path/to/website');
$httpServer->addRoot('example.com', '/path/to/website');

// 创建 HTTPS 服务器
$httpsServer = new Server('0.0.0.0:443', [
    'ssl' => [
        'local_cert' => '/path/to/ssl.crt',
        'local_pk' => '/path/to/ssl.key'
    ]
]);
$httpsServer->addRoot('www.example.com', '/path/to/website');
$httpsServer->addRoot('example.com', '/path/to/website');

// 启动服务器
$httpServer->run();
$httpsServer->run();
```

### 3.5 配置自定义 404 页面

```php
<?php
use WebServer\Server;

// 创建 WebServer 实例
$webServer = new Server('0.0.0.0:8080');

// 添加虚拟主机配置（包含自定义 404 页面）
$webServer->addRoot('www.example.com', [
    'root' => '/path/to/website',
    'custom404' => '/path/to/website/404.html'
]);

// 启动服务器
$webServer->run();
```

### 3.6 配置额外响应头

```php
<?php
use WebServer\Server;

// 创建 WebServer 实例
$webServer = new Server('0.0.0.0:8080');

// 添加虚拟主机配置（包含额外响应头）
$webServer->addRoot('www.example.com', [
    'root' => '/path/to/website',
    'additionHeader' => [
        ['X-Powered-By', 'Candy WebServer'],
        ['X-Frame-Options', 'DENY'],
        ['X-XSS-Protection', '1; mode=block']
    ]
]);

// 启动服务器
$webServer->run();
```

## 4. 高级功能

### 4.1 虚拟主机配置

WebServer 支持多个虚拟主机配置，可以为不同域名设置不同的网站根目录和配置：

```php
<?php
use WebServer\Server;

// 创建 WebServer 实例
$webServer = new Server('0.0.0.0:80');

// 配置多个虚拟主机
$webServer->addRoot('example.com', '/path/to/main/website');
$webServer->addRoot('www.example.com', '/path/to/main/website');
$webServer->addRoot('blog.example.com', '/path/to/blog');
$webServer->addRoot('api.example.com', '/path/to/api');

// 支持泛域名配置
$webServer->addRoot('*.example.com', '/path/to/subdomains');

// 启动服务器
$webServer->run();
```

### 4.2 缓存机制

WebServer 实现了 ETag 和 Last-Modified 缓存机制，可以减少网络传输：

- **ETag**：通过内容哈希值生成，用于验证资源是否发生变化
- **Last-Modified**：基于文件修改时间，用于验证资源是否过期

当客户端发送 `If-None-Match` 或 `If-Modified-Since` 头时，WebServer 会返回 304 Not Modified 状态码，不传输实际内容。

### 4.3 Server-Sent Events (SSE) 支持

WebServer 支持 Server-Sent Events 协议，可以实现服务器到客户端的实时通信：

```php
<?php
// SSE 服务端代码
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// 发送消息
echo "event: message\n";
echo "data: Hello, SSE!\n";
echo "id: 1\n";
echo "\n\n";

// 刷新输出缓冲区
ob_flush();
flush();
```

### 4.4 访问日志

WebServer 会自动记录访问日志，格式如下：

```
192.168.1.1 - - [17/Dec/2025:10:30:00 +0800] "GET /index.php HTTP/1.1" 200 1024 "-" "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
```

日志包含以下信息：
- 客户端 IP 地址
- 访问时间
- 请求方法和 URI
- HTTP 协议版本
- 响应状态码
- 响应大小
- 引用来源
- 用户代理信息

### 4.5 安全特性

- **防路径遍历**：通过验证文件路径确保不会访问到网站根目录外的文件
- **SSL/HTTPS 支持**：提供加密传输功能
- **URL 强制跳转**：支持 HTTP 自动跳转至 HTTPS
- **自定义响应头**：可以添加安全相关的响应头，如 X-Frame-Options、X-XSS-Protection 等

## 5. API 参考

### 5.1 Server 类（Workerman 实现）

#### 构造函数

```php
public function __construct(string $socket_name, array $context_option = [])
```

**参数：**
- `$socket_name`：监听地址（如 `0.0.0.0:8080`）
- `$context_option`：SSL 等上下文配置（可选）

#### addRoot() 方法

```php
public function addRoot(string $domain, string|array $config)
```

添加虚拟主机配置。

**参数：**
- `$domain`：域名（支持泛域名如 `*.example.com`）
- `$config`：配置，可以是字符串（网站根目录）或数组

**配置数组选项：**
- `root`：网站根目录
- `custom404`：自定义 404 页面路径
- `additionHeader`：额外的响应头

#### run() 方法

```php
public function run(): void
```

启动 Web 服务器。

### 5.2 Swoole 类（Swoole 实现）

#### 构造函数

```php
public function __construct(string $address, array $contextOption = [])
```

**参数：**
- `$address`：监听地址（如 `0.0.0.0:8080`）
- `$contextOption`：SSL 等上下文配置（可选）

#### addRoot() 方法

```php
public function addRoot(string $domain, string|array $config)
```

添加虚拟主机配置，与 Server 类相同。

#### setConfig() 方法

```php
public function setConfig(array $config)
```

设置 Swoole 相关配置。

**参数：**
- `$config`：Swoole 配置数组

#### start() 方法

```php
public function start()
```

启动 Swoole Web 服务器。

## 6. 性能优化

### 6.1 Workerman 版本优化

```php
<?php
use WebServer\Server;
use Workerman\Worker;

// 创建 WebServer 实例
$webServer = new Server('0.0.0.0:8080');

// 设置进程数
$webServer->count = 4;

// 启用 gzip 压缩
$webServer->gzip = true;

// 设置缓存时间
$webServer->max_age = 3600;

// 添加虚拟主机配置
$webServer->addRoot('www.example.com', '/path/to/website');

// 启动服务器
$webServer->run();
```

### 6.2 Swoole 版本优化

```php
<?php
use WebServer\Swoole;

// 创建 Swoole WebServer 实例
$webServer = new Swoole('0.0.0.0:8080');

// 设置 Swoole 优化配置
$webServer->setConfig([
    'worker_num' => 4,
    'max_request' => 1000,
    'dispatch_mode' => 2,
    'enable_static_handler' => true,
    'document_root' => '/path/to/static/files',
    'buffer_output_size' => 32 * 1024 * 1024
]);

// 添加虚拟主机配置
$webServer->addRoot('www.example.com', '/path/to/website');

// 启动服务器
$webServer->start();
```

## 7. 常见问题

### 7.1 无法访问服务器

- 检查服务器是否正在运行
- 检查防火墙是否允许访问指定端口
- 检查监听地址是否正确（使用 `0.0.0.0` 监听所有网络接口）

### 7.2 PHP 文件无法执行

- 检查 PHP 文件路径是否正确
- 检查文件权限是否可执行
- 检查 PHP 版本是否符合要求

### 7.3 HTTPS 无法正常工作

- 检查 SSL 证书是否有效
- 检查 SSL 证书和密钥文件路径是否正确
- 检查证书和密钥是否匹配

### 7.4 虚拟主机无法正常工作

- 检查域名是否正确配置
- 检查域名解析是否正确
- 检查网站根目录路径是否正确

## 8. 注意事项

- 在生产环境中建议使用 Swoole 版本以获得更好的性能
- 启用 SSL/HTTPS 可以提高数据传输安全性
- 合理配置缓存机制可以减少网络传输和服务器负载
- 定期检查访问日志可以及时发现异常访问
- 为重要网站配置自定义 404 页面可以提升用户体验

## 9. 更新日志

- v1.0.0：初始版本，实现基本 Web 服务功能
- v1.1.0：增加 Swoole 实现版本
- v1.2.0：增加 SSL/HTTPS 支持
- v1.3.0：增加 URL 强制跳转功能
- v1.4.0：增加自定义 404 页面支持
- v1.5.0：增加 Server-Sent Events 支持