# Workerman DNS 服务器组件使用文档

## 1. 组件介绍

Workerman DNS 组件是一个基于 Workerman 框架实现的高性能 DNS 服务器组件，提供了完整的 DNS 协议支持和灵活的扩展能力。

### 1.1 主要功能

- 支持多种 DNS 记录类型（A、CNAME、NS、PTR、AAAA、SOA、MX、TXT 等）
- 高度可扩展的查询处理机制
- 支持 IPv4 和 IPv6 地址
- 支持 DNS 响应的精确控制
- 基于 Workerman 框架，具备高性能和高并发能力

### 1.2 组件结构

- **DnsServer.php**：DNS 服务器主类，处理客户端连接和请求
- **Ipv6.php**：IPv6 地址处理工具类
- **Protocols/Dns.php**：DNS 协议编解码实现

## 2. 安装与配置

### 2.1 环境要求

- PHP 7.0+，推荐 PHP 7.4+
- Workerman 框架
- 支持 UDP 协议的网络环境

### 2.2 命令行启动（推荐）

```bash
# 启动权威 DNS 服务器（后台驻留）
php candy dns start --host=0.0.0.0 --port=53 --zones=Source/Inc/Dns.inc.php

# 停止 / 重启 / 热重载 / 状态查看
php candy dns stop
php candy dns restart    # 自动恢复上次启动参数
php candy dns reload
php candy dns status
```

- 服务器使用独立 PID/日志/状态文件（`Runtime/Dns/`），与主 Web 服务器互不干扰
- 监听 UDP 端口（默认 53 需 root 或 CAP_NET_BIND_SERVICE）

### 2.3 权威记录来源（二选一）

1. **内置权威存储（推荐）**：默认读取 `Source/Inc/Dns.inc.php`（或 `--zones` 指定 PHP/JSON 文件），无需写代码。
2. **自定义回调（向后兼容）**：在 `Source/Func/global.func.php` 实现 `getDnsInfoByType(string $type, string $name): array`，存在时优先使用。

## 3. 核心功能与使用

### 3.1 内置权威记录配置（推荐）

默认读取 `Source/Inc/Dns.inc.php`（PHP 返回数组），支持 A/AAAA/CNAME/NS/MX/TXT/SOA/PTR、通配符与 PTR 反查：

```php
return [
    'example.com' => [
        'ttl'  => 60,
        'A'    => ['93.184.216.34'],
        'AAAA' => ['2001:db8::1'],
        'NS'   => ['ns1.example.com'],
        'MX'   => [['name' => 'mail.example.com', 'pre' => 10]],
        'TXT'  => ['v=spf1 include:_spf.example.com ~all'],
        'SOA'  => ['mname' => 'ns1.example.com', 'rname' => 'admin.example.com',
                   'serial' => 2024010101, 'refresh' => 3600, 'retry' => 1200,
                   'expire' => 86400, 'minimum-ttl' => 600],
    ],
    'www.example.com'   => ['CNAME' => 'example.com'],   // CNAME 独占；目标在本区时自动附带 A/AAAA
    '*.dev.example.com' => ['A' => ['10.0.0.1']],        // 通配符
    '10.0.0.1'          => ['PTR' => 'host.example.com'], // PTR 反查（用 IP 作键）
];
```

- 查询名不在任何 zone 内 → SERVFAIL；在 zone 内但无记录 → NXDOMAIN（自动附带 SOA）

### 3.2 自定义回调 getDnsInfoByType（向后兼容）

未使用内置存储时，可在 `Source/Func/global.func.php` 实现该函数，存在则优先使用。

#### 函数签名

```php
/**
 * 根据查询类型和域名获取 DNS 记录
 * 
 * @param string $type 查询类型（如 A、CNAME、NS 等）
 * @param string $name 查询的域名或记录名
 * @return array DNS 响应数据数组
 */
function getDnsInfoByType(string $type, string $name): array
```

#### 返回数据结构

```php
$response = [
    'type' => 'A', // DNS 记录类型
    'detail' => ['192.168.1.1', '192.168.1.2'], // 记录值
    'ttl' => 300 // 生存时间（可选，默认 0）
];
```

### 3.2 支持的 DNS 记录类型

#### A 记录（IPv4 地址）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'A' && $name === 'example.com') {
        return [
            'type' => 'A',
            'detail' => ['192.168.1.1', '192.168.1.2'],
            'ttl' => 300
        ];
    }
    // 其他记录类型处理...
}
```

#### CNAME 记录（别名记录）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'CNAME' && $name === 'www.example.com') {
        return [
            'type' => 'CNAME',
            'detail' => ['example.com'],
            'ttl' => 300
        ];
    }
    // 其他记录类型处理...
}
```

#### CNAME+A 记录（别名+IPv4 地址）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'A' && $name === 'cdn.example.com') {
        return [
            'type' => 'CNAME+A',
            'detail' => 'origin.example.com',
            'ttl' => 300
        ];
    }
    // 其他记录类型处理...
}
```

#### NS 记录（名称服务器）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'NS' && $name === 'example.com') {
        return [
            'type' => 'NS',
            'detail' => ['ns1.example.com', 'ns2.example.com'],
            'ttl' => 3600
        ];
    }
    // 其他记录类型处理...
}
```

#### PTR 记录（反向解析）

```php
function getDnsInfoByType(string $type, string $name): array
{
    // 注意：PTR 查询的 $name 是倒序 IP 格式，如 1.0.168.192.in-addr.arpa
    if ($type === 'PTR' && $name === '1.0.168.192.in-addr.arpa') {
        return [
            'type' => 'PTR',
            'detail' => 'router.example.com',
            'ttl' => 300
        ];
    }
    // 其他记录类型处理...
}
```

#### AAAA 记录（IPv6 地址）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'AAAA' && $name === 'ipv6.example.com') {
        return [
            'type' => 'AAAA',
            'detail' => [
                '20010db8000000000000000000000001', // 十六进制格式的 IPv6 地址
                '20010db8000000000000000000000002'
            ],
            'ttl' => 300
        ];
    }
    // 其他记录类型处理...
}
```

#### SOA 记录（起始授权记录）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'SOA' && $name === 'example.com') {
        return [
            'type' => 'SOA',
            'detail' => [
                'type' => 'auto' // 自动获取上级 SOA
            ],
            'ttl' => 3600
        ];
    }
    // 其他记录类型处理...
}
```

#### MX 记录（邮件交换记录）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'MX' && $name === 'example.com') {
        return [
            'type' => 'MX',
            'detail' => [
                (object)[
                    'name' => 'mx1.example.com',
                    'pre' => '10' // 优先级
                ],
                (object)[
                    'name' => 'mx2.example.com',
                    'pre' => '20' // 优先级
                ]
            ],
            'ttl' => 3600
        ];
    }
    // 其他记录类型处理...
}
```

#### TXT 记录（文本记录）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($type === 'TXT' && $name === 'example.com') {
        return [
            'type' => 'TXT',
            'detail' => ['v=spf1 include:spf.example.com ~all', 'txt2-record'],
            'ttl' => 3600
        ];
    }
    // 其他记录类型处理...
}
```

### 3.3 高级响应控制

#### 返回域名不存在（NXDOMAIN）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($name === 'nonexistent.example.com') {
        return [
            'type' => 'none',
            'detail' => [
                'type' => 'auto',
                'name' => $name
            ]
        ];
    }
    // 其他记录类型处理...
}
```

#### 返回服务器失败（SERVFAIL）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($name === 'error.example.com') {
        return [
            'type' => 'flag',
            'flag' => 'SERVFAIL'
        ];
    }
    // 其他记录类型处理...
}
```

#### 拒绝查询（REFUSE）

```php
function getDnsInfoByType(string $type, string $name): array
{
    if ($name === 'blocked.example.com') {
        return [
            'type' => 'flag',
            'flag' => 'REFUSE'
        ];
    }
    // 其他记录类型处理...
}
```

## 4. API 参考

### 4.1 DnsServer 类

#### 构造函数

```php
/**
 * 创建 DNS 服务器实例
 * 
 * @param string $address 服务器监听地址，默认为 '0.0.0.0:53'
 */
public function __construct($address = '0.0.0.0:53')
```

#### 核心方法

```php
/**
 * 运行 DNS 服务器
 * 
 * @return void
 */
public function run(): void
```

```php
/**
 * 处理 DNS 查询消息
 * 
 * @param TcpConnection $connection 客户端连接对象
 * @param mixed $data DNS 查询数据
 * @return void
 */
public function onMessage(\Workerman\Connection\TcpConnection $connection, mixed $data): void
```

### 4.2 Ipv6 类

#### 地址处理方法

```php
/**
 * 获取或格式化 IPv6 地址
 * 
 * @param string|null $addr 要处理的 IP 地址，默认为客户端 IP
 * @return string 格式化后的 IPv6 地址
 */
function addr($addr = null)
```

```php
/**
 * 压缩 IPv6 地址
 * 
 * @param string $addr 要压缩的 IPv6 地址
 * @return string 压缩后的 IPv6 地址
 */
function cut($addr)
```

```php
/**
 * 将 IPv6 地址转换为标准格式
 * 
 * @param string $addr 要扩展的 IPv6 地址
 * @return string 标准格式的 IPv6 地址
 */
function fill($addr)
```

```php
/**
 * 将 IPv4 地址转换为 IPv6 格式
 * 
 * @param string $addr 要转换的 IPv4 地址
 * @return string 转换后的 IPv6 地址
 */
function ip426($addr)
```

### 4.3 Dns 协议类

#### 核心方法

```php
/**
 * 从 DNS 查询数据中提取域名
 * 
 * @param string $data DNS 查询数据（十六进制格式）
 * @param int $startbyte 开始解析的字节位置
 * @return array 包含域名和下一个字节位置的数组
 */
public static function getDomain($data, $startbyte)
```

```php
/**
 * 解析 DNS 输入数据
 * 
 * @param string $data 接收到的 DNS 数据
 * @return int 完整 DNS 数据包的长度
 */
public static function input($data)
```

```php
/**
 * 编码 DNS 响应
 * 
 * @param string $buffer 要发送的 DNS 响应数据
 * @return string 编码后的 DNS 响应
 */
public static function encode($buffer)
```

```php
/**
 * 解码 DNS 查询
 * 
 * @param string $buffer 接收到的 DNS 查询数据
 * @return string 解码后的 DNS 查询
 */
public static function decode($buffer)
```

## 5. 完整示例

### 5.1 简单 DNS 服务器实现

```php
<?php
use Workerman\Worker;
use Dns\DnsServer;

// 实现 DNS 查询处理函数
function getDnsInfoByType(string $type, string $name): array
{
    // A 记录查询处理
    if ($type === 'A') {
        switch ($name) {
            case 'example.com':
                return [
                    'type' => 'A',
                    'detail' => ['192.168.1.1', '192.168.1.2'],
                    'ttl' => 300
                ];
            case 'www.example.com':
                return [
                    'type' => 'CNAME+A',
                    'detail' => 'example.com',
                    'ttl' => 300
                ];
        }
    }
    
    // MX 记录查询处理
    if ($type === 'MX' && $name === 'example.com') {
        return [
            'type' => 'MX',
            'detail' => [
                (object)[
                    'name' => 'mx1.example.com',
                    'pre' => '10'
                ],
                (object)[
                    'name' => 'mx2.example.com',
                    'pre' => '20'
                ]
            ],
            'ttl' => 3600
        ];
    }
    
    // TXT 记录查询处理
    if ($type === 'TXT' && $name === 'example.com') {
        return [
            'type' => 'TXT',
            'detail' => ['v=spf1 include:spf.example.com ~all'],
            'ttl' => 3600
        ];
    }
    
    // 反向解析处理
    if ($type === 'PTR' && $name === '1.0.168.192.in-addr.arpa') {
        return [
            'type' => 'PTR',
            'detail' => 'router.example.com',
            'ttl' => 300
        ];
    }
    
    // 默认返回域名不存在
    return [
        'type' => 'none',
        'detail' => [
            'type' => 'auto',
            'name' => $name
        ]
    ];
}

// 创建 DNS 服务器实例
$dnsServer = new DnsServer();

// 启动服务器
Worker::runAll();
```

### 5.2 从数据库获取 DNS 记录

```php
<?php
use Workerman\Worker;
use Dns\DnsServer;
use PDO;


// 数据库连接配置
$dbConfig = [
    'host' => 'localhost',
    'dbname' => 'dns_records',
    'username' => 'root',
    'password' => 'password'
];

// 创建数据库连接
$pdo = new PDO(
    "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']}",
    $dbConfig['username'],
    $dbConfig['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 实现 DNS 查询处理函数
function getDnsInfoByType(string $type, string $name): array
{
    global $pdo;
    
    try {
        // 从数据库查询 DNS 记录
        $stmt = $pdo->prepare("SELECT * FROM dns_records WHERE name = ? AND type = ?");
        $stmt->execute([$name, $type]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($records)) {
            // 记录不存在，返回 NXDOMAIN
            return [
                'type' => 'none',
                'detail' => [
                    'type' => 'auto',
                    'name' => $name
                ]
            ];
        }
        
        // 构建响应数据
        $response = [
            'type' => $type,
            'detail' => [],
            'ttl' => $records[0]['ttl']
        ];
        
        // 根据记录类型处理详细信息
        foreach ($records as $record) {
            switch ($type) {
                case 'A':
                case 'NS':
                case 'TXT':
                    $response['detail'][] = $record['content'];
                    break;
                case 'PTR':
                    $response['detail'] = $record['content'];
                    break;
                case 'MX':
                    $response['detail'][] = (object)[
                        'name' => $record['content'],
                        'pre' => $record['priority']
                    ];
                    break;
                case 'AAAA':
                    $response['detail'][] = bin2hex(inet_pton($record['content']));
                    break;
            }
        }
        
        return $response;
        
    } catch (Exception $e) {
        // 数据库查询失败，返回 SERVFAIL
        return [
            'type' => 'flag',
            'flag' => 'SERVFAIL'
        ];
    }
}

// 创建 DNS 服务器实例
$dnsServer = new DnsServer();

// 启动服务器
Worker::runAll();
```

## 6. 性能优化建议

1. **使用缓存机制**：对频繁查询的 DNS 记录进行缓存，减少数据库查询或复杂计算
2. **优化查询处理逻辑**：尽量减少查询处理函数中的复杂计算和外部依赖
3. **使用多进程模式**：根据服务器性能配置适当数量的进程
4. **合理设置 TTL**：根据业务需求设置合适的 TTL 值，减少不必要的重复查询
5. **监控与日志**：实现完善的监控和日志记录，及时发现和解决问题

## 7. 常见问题与解决方案

### 7.1 端口占用问题

**问题**：启动服务器时提示端口 53 被占用

**解决方案**：
- 检查是否已有其他 DNS 服务在运行（如 bind9、dnsmasq 等）
- 可以临时关闭其他 DNS 服务，或使用其他端口

### 7.2 权限问题

**问题**：普通用户无法启动 DNS 服务器（端口号小于 1024）

**解决方案**：
- 使用 root 用户启动
- 或使用 `setcap` 命令为 PHP 添加网络权限：
  ```bash
  sudo setcap cap_net_bind_service=+ep /path/to/php
  ```

### 7.3 记录返回格式错误

**问题**：DNS 响应格式不正确，客户端无法解析

**解决方案**：
- 确保 `getDnsInfoByType` 函数返回的格式符合要求
- 检查记录值的格式是否正确（特别是 AAAA 记录需要十六进制格式）

### 7.4 性能问题

**问题**：DNS 服务器响应缓慢

**解决方案**：
- 实现缓存机制
- 优化数据库查询
- 增加服务器进程数
- 检查网络环境

## 8. 总结

Workerman DNS 组件提供了一个强大、灵活且高性能的 DNS 服务器实现，适用于各种需要自定义 DNS 解析的场景。通过实现 `getDnsInfoByType` 函数，可以灵活控制 DNS 查询的处理逻辑，支持各种 DNS 记录类型和响应控制。

该组件基于 Workerman 框架，具备出色的性能和并发处理能力，适合构建高性能的 DNS 服务。同时，其高度可扩展的设计也使得它可以轻松适应各种复杂的业务需求。