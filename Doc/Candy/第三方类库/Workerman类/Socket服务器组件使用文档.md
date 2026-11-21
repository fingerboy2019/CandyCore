# Socket服务器组件使用文档

## 1. 组件概述

Socket服务器组件是基于Workerman和GatewayWorker框架封装的一个完整的实时通信服务器解决方案，提供WebSocket通信、HTTP服务和内部通信功能，支持高并发连接和灵活的业务扩展。

### 1.1 主要功能

- **WebSocket通信**：支持WebSocket协议的实时双向通信
- **HTTP服务**：提供HTTP接口用于外部系统与Socket服务器的交互
- **内部通信**：基于GatewayWorker的内部通信机制，支持进程间消息传递
- **业务逻辑分离**：将业务逻辑与网络通信分离，便于维护和扩展
- **SSL支持**：可配置SSL证书，实现HTTPS和WSS加密通信
- **跨域控制**：支持配置允许的源域名，实现安全的跨域通信

### 1.2 组件架构

Socket服务器采用多进程架构，主要包含以下进程类型：

- **BusinessWorker**：业务逻辑进程，处理核心业务逻辑
- **Register**：注册中心进程，管理Gateway和BusinessWorker的连接
- **Gateway**：网关进程，处理WebSocket连接和消息转发
- **Http**：HTTP服务进程，提供HTTP接口

## 2. 核心API

### 2.1 Server类

**命名空间**：`Socket\Server`

**构造函数**：

```php
public function __construct(array $config = [])
```

**参数**：
- `$config`：服务器配置数组，包含各个进程的配置

**功能**：初始化Socket服务器，创建并配置各个进程

### 2.2 Business类

**命名空间**：`Socket\Business`

**构造函数**：

```php
public function __construct(array $config = [])
```

**参数**：
- `$config`：业务进程配置数组

**功能**：初始化业务逻辑进程，设置事件处理类

### 2.3 Gateway类

**命名空间**：`Socket\Gateway`

**构造函数**：

```php
public function __construct(array $config = [])
```

**参数**：
- `$config`：网关进程配置数组

**功能**：初始化网关进程，处理WebSocket连接和消息转发

### 2.4 Http类

**命名空间**：`Socket\Http`

**构造函数**：

```php
public function __construct(array $config = [])
```

**参数**：
- `$config`：HTTP进程配置数组

**功能**：初始化HTTP服务进程，提供HTTP接口

## 3. 配置选项

### 3.1 基础配置

```php
$config = [
    'bussiness' => [
        'name' => 'BussinessWorker',  // 业务进程名称
        'address' => '127.0.0.1:1238'  // 注册中心地址
    ],
    'register' => [
        'address' => '0.0.0.0:1238'  // 注册中心监听地址
    ],
    'gateway' => [
        'address' => 'websocket://0.0.0.0:8282',  // WebSocket监听地址
        'name' => 'Gateway',  // 网关进程名称
        'lanIp' => '127.0.0.1',  // 内网IP
        'startPort' => 2900,  // 起始端口
        'register' => '127.0.0.1:1238',  // 注册中心地址
        'pingInterval' => 55,  // 心跳间隔（秒）
        'pingNotResponseLimit' => 1,  // 心跳超时次数
        'pingData' => '"type":"ping"',  // 心跳数据
        'origin' => 'http://example.com',  // 允许的源域名
        'ssl' => [  // SSL配置
            'local_cert' => '/path/to/cert.pem',  // 证书文件路径
            'local_pk' => '/path/to/key.pem',  // 私钥文件路径
            'allow_self_signed' => true  // 是否允许自签名证书
        ]
    ],
    'http' => [
        'address' => '0.0.0.0:8088',  // HTTP监听地址
        'name' => 'WsHttpServer',  // HTTP进程名称
        'internal' => '127.0.0.1:7273'  // 内部通信地址
    ],
    'internal' => [
        'name' => 'internalGateway',  // 内部网关名称
        'startPort' => 3300,  // 起始端口
        'register' => '127.0.0.1:1238'  // 注册中心地址
    ]
];
```

### 3.2 配置说明

| 配置项 | 类型 | 说明 | 默认值 |
|--------|------|------|--------|
| bussiness.name | string | 业务进程名称 | BussinessWorker |
| bussiness.address | string | 注册中心地址 | 127.0.0.1:1238 |
| register.address | string | 注册中心监听地址 | 0.0.0.0:1238 |
| gateway.address | string | WebSocket监听地址 | websocket://0.0.0.0:8282 |
| gateway.name | string | 网关进程名称 | Gateway |
| gateway.lanIp | string | 内网IP | 127.0.0.1 |
| gateway.startPort | int | 起始端口 | 2900 |
| gateway.register | string | 注册中心地址 | 127.0.0.1:1238 |
| gateway.pingInterval | int | 心跳间隔（秒） | 55 |
| gateway.pingNotResponseLimit | int | 心跳超时次数 | 1 |
| gateway.pingData | string | 心跳数据 | {"type":"ping"} |
| gateway.origin | string | 允许的源域名 | 空 |
| gateway.ssl | array | SSL配置 | [] |
| http.address | string | HTTP监听地址 | 0.0.0.0:8088 |
| http.name | string | HTTP进程名称 | WsHttpServer |
| http.internal | string | 内部通信地址 | 127.0.0.1:7273 |
| internal.name | string | 内部网关名称 | internalGateway |
| internal.startPort | int | 起始端口 | 3300 |
| internal.register | string | 注册中心地址 | 127.0.0.1:1238 |

## 4. 使用示例

### 4.1 基本使用

**创建Socket服务器**：

```php
<?php

use Socket\Server;

// 创建服务器配置
$config = [
    'gateway' => [
        'address' => 'websocket://0.0.0.0:8282',
        'name' => 'MyGateway',
        'lanIp' => '127.0.0.1',
        'startPort' => 2900,
        'register' => '127.0.0.1:1238'
    ],
    'http' => [
        'address' => '0.0.0.0:8088',
        'name' => 'MyHttpServer'
    ]
];

// 初始化并启动服务器
$server = new Server($config);
```

**启动服务器**：

```bash
php server.php start
```

**查看服务器状态**：

```bash
php server.php status
```

### 4.2 配置SSL

```php
<?php

use Socket\Server;

// 创建服务器配置
$config = [
    'gateway' => [
        'address' => 'websocket://0.0.0.0:8283',
        'name' => 'MyGateway',
        'lanIp' => '127.0.0.1',
        'startPort' => 2900,
        'register' => '127.0.0.1:1238',
        'ssl' => [
            'local_cert' => '/path/to/cert.pem',
            'local_pk' => '/path/to/key.pem',
            'allow_self_signed' => true
        ]
    ],
    'http' => [
        'address' => '0.0.0.0:8089',
        'name' => 'MyHttpServer',
        'ssl' => [
            'local_cert' => '/path/to/cert.pem',
            'local_pk' => '/path/to/key.pem',
            'allow_self_signed' => true
        ]
    ]
];

// 初始化并启动服务器
$server = new Server($config);
```

### 4.3 配置跨域

```php
<?php

use Socket\Server;

// 创建服务器配置
$config = [
    'gateway' => [
        'address' => 'websocket://0.0.0.0:8282',
        'name' => 'MyGateway',
        'lanIp' => '127.0.0.1',
        'startPort' => 2900,
        'register' => '127.0.0.1:1238',
        'origin' => 'http://example.com'  // 只允许example.com域名的请求
    ]
];

// 初始化并启动服务器
$server = new Server($config);
```

### 4.4 自定义业务逻辑

**创建业务逻辑处理类**：

```php
<?php
namespace APP\Event;

class wsEvent
{
    /**
     * 当客户端连接时触发
     * @param int $client_id 客户端ID
     */
    public static function onConnect($client_id)
    {
        // 发送欢迎消息
        Gateway::sendToClient($client_id, '欢迎连接到Socket服务器！');
        
        // 广播新用户加入
        Gateway::sendToAll("用户{$client_id}加入了聊天室");
    }
    
    /**
     * 当客户端发送消息时触发
     * @param int $client_id 客户端ID
     * @param mixed $message 消息内容
     */
    public static function onMessage($client_id, $message)
    {
        // 广播消息
        Gateway::sendToAll("用户{$client_id}: {$message}");
    }
    
    /**
     * 当客户端断开连接时触发
     * @param int $client_id 客户端ID
     */
    public static function onClose($client_id)
    {
        // 广播用户离开
        Gateway::sendToAll("用户{$client_id}离开了聊天室");
    }
}
```

**注**：业务逻辑处理类默认创建在 `SOURCEPATH/Event/wsEvent.class.php` 路径下。

### 4.5 使用HTTP接口发送消息

```php
<?php
// 发送HTTP请求到Socket服务器的HTTP接口
$url = 'http://127.0.0.1:8088/send';
$data = [
    'client_id' => '123456',
    'message' => 'Hello from HTTP'
];

$options = [
    'http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($data)
    ]
];

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === FALSE) {
    // 处理错误
} else {
    echo $result;
}
```

## 5. 客户端使用

### 5.1 WebSocket客户端

**JavaScript客户端**：

```javascript
// 创建WebSocket连接
const socket = new WebSocket('ws://127.0.0.1:8282');

// 连接建立时触发
socket.onopen = function() {
    console.log('WebSocket连接已建立');
    socket.send('Hello Server');
};

// 接收消息时触发
socket.onmessage = function(event) {
    console.log('收到服务器消息:', event.data);
};

// 连接关闭时触发
socket.onclose = function() {
    console.log('WebSocket连接已关闭');
};

// 发生错误时触发
socket.onerror = function(error) {
    console.error('WebSocket错误:', error);
};
```

**WSS客户端**：

```javascript
// 创建加密WebSocket连接
const socket = new WebSocket('wss://127.0.0.1:8283');
```

### 5.2 使用HTTP接口

**发送消息**：

```bash
curl -X POST http://127.0.0.1:8088/send \
     -d 'client_id=123456' \
     -d 'message=Hello'
```

**广播消息**：

```bash
curl -X POST http://127.0.0.1:8088/broadcast \
     -d 'message=Hello All'
```

## 6. 最佳实践

### 6.1 业务逻辑分离

将业务逻辑与网络通信分离，放在独立的事件处理类中，便于维护和扩展。

### 6.2 合理配置进程数

根据服务器硬件配置和业务需求，合理配置各个进程的数量：

```php
$config = [
    'bussiness' => [
        'count' => 8  // 根据CPU核心数配置
    ],
    'gateway' => [
        'count' => 4  // 根据预期并发连接数配置
    ]
];
```

### 6.3 配置日志

配置日志文件路径，便于问题排查：

```php
// 在创建Server之前配置日志
Worker::$logFile = '/path/to/logs/workerman.log';
Worker::$pidFile = '/path/to/logs/workerman.pid';
Worker::$stdoutFile = '/path/to/logs/workerman.log';
```

### 6.4 安全配置

- 配置允许的源域名，防止跨站请求伪造
- 使用SSL加密通信，保护数据安全
- 对客户端请求进行验证，防止恶意连接
- 定期更新证书和依赖库，修复安全漏洞

### 6.5 性能优化

- 使用高性能的业务逻辑处理方式，避免阻塞操作
- 合理设置心跳间隔，及时清理无效连接
- 使用缓存减少数据库查询
- 对消息进行压缩，减少网络传输量

## 7. 常见问题

### 7.1 客户端无法连接

**可能原因**：
- 服务器未启动
- 端口被占用
- 防火墙阻止了连接
- 跨域配置不正确

**解决方案**：
- 检查服务器状态：`php server.php status`
- 检查端口占用情况：`netstat -tlnp | grep 8282`
- 配置防火墙规则，允许端口访问
- 正确配置gateway.origin参数

### 7.2 消息发送失败

**可能原因**：
- 客户端ID不存在或已断开连接
- 内部通信地址配置错误
- 业务逻辑处理错误

**解决方案**：
- 检查客户端连接状态
- 检查http.internal配置是否正确
- 查看日志文件，定位错误原因

### 7.3 性能问题

**可能原因**：
- 进程数量配置不合理
- 业务逻辑处理缓慢
- 内存泄漏

**解决方案**：
- 根据硬件配置调整进程数量
- 优化业务逻辑，减少阻塞操作
- 定期重启服务器，释放内存

## 8. 代码示例

### 8.1 完整的服务器启动脚本

```php
<?php
/**
 * Socket服务器启动脚本
 */

// 引入Socket服务器类
use Socket\Server;

// 配置日志路径
Worker::$logFile = __DIR__ . '/logs/workerman.log';
Worker::$pidFile = __DIR__ . '/logs/workerman.pid';
Worker::$stdoutFile = __DIR__ . '/logs/workerman.log';

// 创建服务器配置
$config = [
    'bussiness' => [
        'name' => 'BusinessWorker',
        'count' => 4,
        'address' => '127.0.0.1:1238'
    ],
    'register' => [
        'address' => '0.0.0.0:1238'
    ],
    'gateway' => [
        'address' => 'websocket://0.0.0.0:8282',
        'name' => 'Gateway',
        'count' => 2,
        'lanIp' => '127.0.0.1',
        'startPort' => 2900,
        'register' => '127.0.0.1:1238',
        'pingInterval' => 55,
        'pingNotResponseLimit' => 1,
        'pingData' => '"type":"ping"',
        'origin' => 'http://localhost:8080'
    ],
    'http' => [
        'address' => '0.0.0.0:8088',
        'name' => 'HttpServer',
        'internal' => '127.0.0.1:7273'
    ],
    'internal' => [
        'name' => 'InternalGateway',
        'startPort' => 3300,
        'register' => '127.0.0.1:1238'
    ]
];

// 初始化并启动服务器
$server = new Server($config);
```

### 8.2 完整的业务逻辑处理类

```php
<?php
/**
 * Socket服务器业务逻辑处理类
 */

namespace APP\Event;

class wsEvent
{
    /**
     * 当客户端连接时触发
     *
     * @param int $client_id 客户端ID
     */
    public static function onConnect($client_id)
    {
        // 记录连接日志
        echo "客户端{$client_id}连接成功\n";
        
        // 发送欢迎消息
        Gateway::sendToClient($client_id, json_encode([
            'type' => 'welcome',
            'message' => '欢迎连接到Socket服务器！',
            'client_id' => $client_id
        ]));
        
        // 广播新用户加入
        Gateway::sendToAll(json_encode([
            'type' => 'user_join',
            'client_id' => $client_id,
            'message' => "用户{$client_id}加入了聊天室"
        ]));
    }
    
    /**
     * 当客户端发送消息时触发
     *
     * @param int $client_id 客户端ID
     * @param mixed $message 消息内容
     */
    public static function onMessage($client_id, $message)
    {
        // 解析消息
        $data = json_decode($message, true);
        
        if (!$data) {
            // 消息格式错误
            Gateway::sendToClient($client_id, json_encode([
                'type' => 'error',
                'message' => '消息格式错误'
            ]));
            return;
        }
        
        // 根据消息类型处理
        switch ($data['type']) {
            case 'chat':
                // 广播聊天消息
                Gateway::sendToAll(json_encode([
                    'type' => 'chat',
                    'client_id' => $client_id,
                    'message' => $data['message'],
                    'time' => date('Y-m-d H:i:s')
                ]));
                break;
                
            case 'private':
                // 发送私聊消息
                Gateway::sendToClient($data['to_client_id'], json_encode([
                    'type' => 'private',
                    'from_client_id' => $client_id,
                    'message' => $data['message'],
                    'time' => date('Y-m-d H:i:s')
                ]));
                break;
                
            default:
                // 未知消息类型
                Gateway::sendToClient($client_id, json_encode([
                    'type' => 'error',
                    'message' => '未知消息类型'
                ]));
        }
    }
    
    /**
     * 当客户端断开连接时触发
     *
     * @param int $client_id 客户端ID
     */
    public static function onClose($client_id)
    {
        // 记录断开日志
        echo "客户端{$client_id}断开连接\n";
        
        // 广播用户离开
        Gateway::sendToAll(json_encode([
            'type' => 'user_leave',
            'client_id' => $client_id,
            'message' => "用户{$client_id}离开了聊天室"
        ]));
    }
}
```

## 9. 总结

Socket服务器组件是一个功能强大、易于使用的实时通信解决方案，基于Workerman和GatewayWorker框架封装，提供了WebSocket通信、HTTP服务和内部通信功能，支持高并发连接和灵活的业务扩展。通过合理的配置和使用，可以快速构建各种实时通信应用，如聊天室、实时通知系统、在线游戏等。

使用Socket服务器组件时，建议遵循以下最佳实践：

- 合理配置各个进程的数量和参数
- 将业务逻辑与网络通信分离
- 配置SSL证书，实现安全通信
- 定期检查服务器状态和日志
- 优化业务逻辑，提高性能

通过以上建议，可以确保Socket服务器的稳定运行和良好的性能表现。