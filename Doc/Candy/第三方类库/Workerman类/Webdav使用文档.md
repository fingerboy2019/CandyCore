# Webdav 组件使用文档

## 1. 组件概述

Webdav 是一个基于 Workerman 实现的 WebDAV 服务器组件，提供文件共享和远程文件操作功能。它支持标准 WebDAV 协议，可以与各种 WebDAV 客户端兼容使用。

主要特性：
- 支持标准 WebDAV 协议方法（GET, PUT, DELETE, PROPFIND, MKCOL, COPY, MOVE, LOCK, UNLOCK）
- 内置 HTTP 基本认证机制，支持多用户管理
- 防止暴力破解攻击，自动锁定多次登录失败的用户
- 灵活的数据存储路径配置
- 基于 Workerman 实现的高性能异步非阻塞架构

## 2. 安装和配置

### 2.1 依赖要求

- PHP >= 7.2
- Workerman 框架

### 2.2 基本配置

Webdav 组件不需要额外安装，已集成在 Candy 框架中。

## 3. 使用示例

### 3.1 基本使用

```php
<?php
use Webdav\Server;

// 创建 WebDAV 服务器实例
$webdav = new Server();

// 启动服务器
$webdav->run();
```

### 3.2 自定义配置

```php
<?php
use Webdav\Server;

// 配置用户认证信息
$users = [
    'admin' => 'admin123',  // 用户名 => 密码
    'user1' => 'password1',
    'user2' => 'password2'
];

// 创建 WebDAV 服务器实例，指定主机、端口、数据目录和用户认证
$webdav = new Server(
    '0.0.0.0',           // 监听地址
    8080,                // 监听端口
    '/path/to/data/dir', // 数据存储目录
    $users               // 用户认证信息
);

// 启动服务器
$webdav->run();
```

## 4. 高级功能

### 4.1 认证与安全

Webdav 组件内置了 HTTP 基本认证机制，支持多用户管理，并具有防止暴力破解的功能：

- **用户密码加密存储**：所有密码使用 PHP 内置的 `password_hash` 函数加密存储
- **防止暴力破解**：默认最大失败登录次数为 5 次，锁定时间为 300 秒（5分钟）
- **IP 锁定**：根据客户端 IP 地址进行登录失败计数和锁定

### 4.2 数据存储

- **默认存储目录**：如果不指定数据目录，默认使用 `CANDYPATH . 'Webdav'`
- **目录自动创建**：如果指定的存储目录不存在，会自动创建
- **文件权限**：默认使用 0777 权限创建目录和文件

### 4.3 WebDAV 协议支持

组件实现了以下 WebDAV 协议方法：

- `GET`：获取文件或目录列表
- `PUT`：上传文件
- `DELETE`：删除文件或目录
- `PROPFIND`：获取资源属性
- `MKCOL`：创建目录
- `COPY`：复制文件或目录
- `MOVE`：移动或重命名文件或目录
- `LOCK`：锁定资源
- `UNLOCK`：解锁资源

## 5. API 参考

### 5.1 Server 类

#### 构造函数

```php
public function __construct(string $host = '0.0.0.0', int $port = 8080, ?string $data_dir = null, array $users = [])
```

**参数：**
- `$host`：服务器监听地址，默认 `0.0.0.0`（监听所有网络接口）
- `$port`：服务器监听端口，默认 `8080`
- `$data_dir`：数据存储目录，默认 `CANDYPATH . 'Webdav'`
- `$users`：用户认证信息数组，格式为 `['username' => 'password', ...]`

#### run() 方法

```php
public function run()
```

启动 WebDAV 服务器。

#### handleRequest() 方法

```php
public function handleRequest(TcpConnection $connection, Request $request)
```

处理 WebDAV 请求，根据请求方法调用相应的处理函数。

## 6. 客户端使用

Webdav 服务器可以与各种 WebDAV 客户端兼容使用，包括：

- Windows 资源管理器
- macOS Finder
- Linux Nautilus
- 文件管理器应用程序（如 Cyberduck, FileZilla）
- 命令行工具（如 cadaver）

### 6.1 Windows 资源管理器

1. 打开「我的电脑」
2. 在地址栏输入：`http://服务器IP:端口/`
3. 输入用户名和密码进行认证

### 6.2 macOS Finder

1. 点击「前往」菜单，选择「连接服务器」
2. 输入：`http://服务器IP:端口/`
3. 输入用户名和密码进行认证

### 6.3 使用 cadaver 命令行工具

```bash
# 安装 cadaver（Ubuntu/Debian）
sudo apt-get install cadaver

# 连接到 WebDAV 服务器
cadaver http://服务器IP:端口/
```

## 7. 常见问题

### 7.1 无法连接到服务器

- 检查服务器是否正在运行
- 检查防火墙是否允许访问指定端口
- 检查服务器监听地址是否正确

### 7.2 认证失败

- 检查用户名和密码是否正确
- 检查是否因多次登录失败而被锁定

### 7.3 无法上传文件

- 检查服务器数据目录的写入权限
- 检查文件大小限制

## 8. 性能优化

- 根据服务器硬件配置调整 Worker 进程数
- 使用 SSD 存储提高文件访问性能
- 对大文件传输使用分块传输机制

## 9. 注意事项

- WebDAV 协议默认不加密传输数据，建议在生产环境中使用 HTTPS
- 合理设置用户权限和数据目录权限，确保系统安全
- 定期备份 WebDAV 服务器数据
- 监控服务器资源使用情况，及时优化配置

## 10. 更新日志

- v1.0.0：初始版本，实现基本 WebDAV 功能
- v1.1.0：增加用户认证和防止暴力破解功能
- v1.2.0：优化性能和稳定性