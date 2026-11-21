# GatewayWorker 实时通信框架使用文档

## 1. 组件简介

GatewayWorker 是一个基于 Workerman 开发的高性能分布式实时通信框架，用于快速构建支持百万级并发连接的实时通信应用。它采用三层架构设计（Register、Gateway、BusinessWorker），实现了客户端连接管理与业务逻辑的分离，具有高性能、易扩展、高可用等特点。

### 1.1 核心功能
- 支持百万级并发连接
- 支持 TCP、UDP、WebSocket、HTTP 等多种协议
- 支持分布式部署，轻松扩展
- 提供丰富的 API，方便开发实时通信功能
- 支持负载均衡，提高系统稳定性
- 支持持久化连接和心跳检测
- 支持自定义协议和事件处理

### 1.2 架构设计

GatewayWorker 采用三层架构设计：

1. **Register（注册中心）**：用于注册 Gateway 和 BusinessWorker，维护两者之间的通信连接
2. **Gateway（网关）**：负责处理客户端连接，转发客户端请求到 BusinessWorker，以及转发 BusinessWorker 的响应到客户端
3. **BusinessWorker（业务处理进程）**：负责处理具体的业务逻辑，与 Gateway 通信

这种分层架构使得系统具有良好的扩展性和可维护性，各个组件可以独立部署和扩展。

### 1.3 适用场景
- 即时通讯系统（IM）
- 实时聊天应用
- 在线游戏服务器
- 实时监控系统
- 实时数据推送
- 物联网（IoT）应用
- 在线客服系统

## 2. 安装与配置

### 2.1 依赖要求
- PHP >= 5.4
- Workerman 框架
- 支持 TCP/IP 协议的操作系统（Linux/Windows/MacOS）

### 2.2 安装方式
GatewayWorker 组件已集成到 Candy 框架中，无需额外安装。组件文件位于：
```
/Candy/Vendor/Workerman/GatewayWorker/
```

### 2.3 目录结构
```
GatewayWorker/
├── BusinessWorker.php   # 业务处理进程
├── Gateway.php          # 网关进程
├── Register.php         # 注册中心
├── Lib/
│   └── Context.php      # 上下文管理
└── Protocols/
    └── GatewayProtocol.php  # 内部通信协议
```

## 3. 核心 API 参考

### 3.1 Register 类

Register 是 GatewayWorker 的注册中心，用于注册 Gateway 和 BusinessWorker，维护两者之间的通信连接。

#### 3.1.1 构造函数
```php
public function __construct(string $socketName = '', array $contextOption = [])
```

**参数说明：**
- `$socketName`：注册中心监听地址和端口，例如 `'text://0.0.0.0:1236'`
- `$contextOption`：上下文选项，用于设置 SSL 等参数

**功能说明：**
初始化注册中心，设置监听地址和端口。

#### 3.1.2 secretKey 属性
```php
public $secretKey = '';
```

**功能说明：**
设置注册中心的秘钥，用于验证 Gateway 和 BusinessWorker 的身份。

### 3.2 Gateway 类

Gateway 是 GatewayWorker 的网关进程，负责处理客户端连接，转发客户端请求到 BusinessWorker，以及转发 BusinessWorker 的响应到客户端。

#### 3.2.1 构造函数
```php
public function __construct($socket_name, $context_option = array())
```

**参数说明：**
- `$socket_name`：网关监听地址和端口，例如 `'websocket://0.0.0.0:8282'`
- `$context_option`：上下文选项，用于设置 SSL 等参数

**功能说明：**
初始化网关进程，设置监听地址和端口。

#### 3.2.2 核心属性

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `lanIp` | string | `'127.0.0.1'` | 本机 IP，分布式部署时需要设置成本机实际 IP |
| `lanPort` | int | `0` | 本机端口，用于与 BusinessWorker 通信 |
| `startPort` | int | `2000` | 内部通讯起始端口，每个 Gateway 实例应该不同 |
| `registerAddress` | string | `'127.0.0.1:1236'` | 注册中心地址 |
| `pingInterval` | int | `0` | 心跳时间间隔，单位为秒 |
| `pingNotResponseLimit` | int | `0` | 允许的心跳丢失次数 |
| `pingData` | string | `''` | 心跳数据内容 |
| `secretKey` | string | `''` | 与注册中心通信的秘钥 |
| `selectLoadBalancingMode` | string | `self::ROUTER_LEAST_CONNECTIONS` | 负载均衡模式 |

#### 3.2.3 负载均衡模式

| 模式常量 | 说明 |
|----------|------|
| `self::ROUTER_RANDOM` | 随机负载均衡 |
| `self::ROUTER_LEAST_CONNECTIONS` | 最小连接数负载均衡（默认） |

#### 3.2.4 主要方法

```php
// 发送消息给指定客户端
public static function sendToClient($client_id, $message)

// 发送消息给指定用户
public static function sendToUid($uid, $message)

// 发送消息给指定分组
public static function sendToGroup($group, $message)

// 广播消息给所有客户端
public static function broadcast($message)

// 关闭指定客户端连接
public static function closeClient($client_id)

// 将客户端加入分组
public static function joinGroup($client_id, $group)

// 将客户端从分组中移除
public static function leaveGroup($client_id, $group)

// 获取客户端的 UID
public static function getUidByClientId($client_id)

// 获取指定用户的所有客户端连接
public static function getClientIdByUid($uid)
```

### 3.3 BusinessWorker 类

BusinessWorker 是 GatewayWorker 的业务处理进程，负责处理具体的业务逻辑，与 Gateway 通信。

#### 3.3.1 构造函数
```php
public function __construct($socket_name = '', $context_option = array())
```

**参数说明：**
- `$socket_name`：业务处理进程监听地址和端口，通常不需要设置
- `$context_option`：上下文选项，用于设置 SSL 等参数

**功能说明：**
初始化业务处理进程。

#### 3.3.2 核心属性

| 属性名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `registerAddress` | string | `'127.0.0.1:1236'` | 注册中心地址 |
| `eventHandler` | string | `'Events'` | 事件处理类名 |
| `secretKey` | string | `''` | 与注册中心通信的秘钥 |
| `gatewayConnections` | array | `[]` | 与 Gateway 的连接列表 |

## 4. 快速开始

### 4.1 简单示例：WebSocket 聊天服务器

#### 4.1.1 注册中心（start_register.php）

```php
<?php
use GatewayWorker\Register;



// 创建注册中心实例
$register = new Register('text://0.0.0.0:1236');

// 启动注册中心
Worker::runAll();
```

#### 4.1.2 网关（start_gateway.php）

```php
<?php
use GatewayWorker\Gateway;
use Workerman\Worker;



// 创建网关实例
$gateway = new Gateway('websocket://0.0.0.0:8282');

// 设置本机 IP（分布式部署时需要设置成本机实际 IP）
$gateway->lanIp = '127.0.0.1';

// 设置内部通讯端口
$gateway->lanPort = 1238;

// 设置注册中心地址
$gateway->registerAddress = '127.0.0.1:1236';

// 设置进程数
$gateway->count = 4;

// 设置服务名称
$gateway->name = 'ChatGateway';

// 启动网关
Worker::runAll();
```

#### 4.1.3 业务处理进程（start_businessworker.php）

```php
<?php
use GatewayWorker\BusinessWorker;
use Workerman\Worker;



// 创建业务处理进程实例
$worker = new BusinessWorker();

// 设置进程数
$worker->count = 4;

// 设置服务名称
$worker->name = 'ChatBusinessWorker';

// 设置注册中心地址
$worker->registerAddress = '127.0.0.1:1236';

// 设置事件处理类
$worker->eventHandler = 'Events';

// 启动业务处理进程
Worker::runAll();
```

#### 4.1.4 事件处理类（Events.php）

```php
<?php
namespace Chat;

use GatewayWorker\Lib\Gateway;

class Events
{
    /**
     * 当客户端连接时触发
     * @param int $client_id 客户端 ID
     */
    public static function onConnect($client_id)
    {
        Gateway::sendToClient($client_id, json_encode(array(
            'type' => 'welcome',
            'message' => "欢迎加入聊天室！您的 ID 是 $client_id"
        )));
    }

    /**
     * 当客户端发来消息时触发
     * @param int $client_id 客户端 ID
     * @param string $message 消息内容
     */
    public static function onMessage($client_id, $message)
    {
        // 广播消息给所有客户端
        Gateway::broadcast(json_encode(array(
            'type' => 'chat',
            'client_id' => $client_id,
            'message' => $message
        )));
    }

    /**
     * 当客户端连接关闭时触发
     * @param int $client_id 客户端 ID
     */
    public static function onClose($client_id)
    {
        // 广播客户端离开消息
        Gateway::broadcast(json_encode(array(
            'type' => 'notice',
            'message' => "客户端 $client_id 已离开聊天室"
        )));
    }
}
```

#### 4.1.5 启动服务

```bash
# 启动注册中心
php start_register.php start

# 启动网关
php start_gateway.php start

# 启动业务处理进程
php start_businessworker.php start
```

#### 4.1.6 客户端测试（index.html）

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>WebSocket 聊天</title>
</head>
<body>
    <h1>WebSocket 聊天</h1>
    <div id="message-container"></div>
    <input type="text" id="message-input" placeholder="输入消息...">
    <button id="send-button">发送</button>

    <script>
        const ws = new WebSocket('ws://127.0.0.1:8282');
        const messageContainer = document.getElementById('message-container');
        const messageInput = document.getElementById('message-input');
        const sendButton = document.getElementById('send-button');

        // 连接建立时
        ws.onopen = function() {
            addMessage('系统', '连接成功');
        };

        // 接收消息时
        ws.onmessage = function(event) {
            const data = JSON.parse(event.data);
            switch(data.type) {
                case 'welcome':
                    addMessage('系统', data.message);
                    break;
                case 'chat':
                    addMessage('客户端 ' + data.client_id, data.message);
                    break;
                case 'notice':
                    addMessage('系统', data.message);
                    break;
            }
        };

        // 连接关闭时
        ws.onclose = function() {
            addMessage('系统', '连接关闭');
        };

        // 发送消息
        sendButton.onclick = function() {
            const message = messageInput.value;
            if (message) {
                ws.send(message);
                messageInput.value = '';
            }
        };

        // 添加消息到界面
        function addMessage(sender, message) {
            const div = document.createElement('div');
            div.innerHTML = '<strong>' + sender + ':</strong> ' + message;
            messageContainer.appendChild(div);
            messageContainer.scrollTop = messageContainer.scrollHeight;
        }
    </script>
</body>
</html>
```

## 5. 事件处理

GatewayWorker 的事件处理类（默认是 Events 类）需要实现以下方法：

### 5.1 onConnect 方法

```php
public static function onConnect($client_id)
```

**参数说明：**
- `$client_id`：客户端 ID

**功能说明：**
当客户端连接时触发，可用于初始化客户端信息、发送欢迎消息等。

### 5.2 onMessage 方法

```php
public static function onMessage($client_id, $message)
```

**参数说明：**
- `$client_id`：客户端 ID
- `$message`：客户端发送的消息内容

**功能说明：**
当客户端发来消息时触发，是处理业务逻辑的主要方法。

### 5.3 onClose 方法

```php
public static function onClose($client_id)
```

**参数说明：**
- `$client_id`：客户端 ID

**功能说明：**
当客户端连接关闭时触发，可用于清理客户端资源、通知其他客户端等。

### 5.4 自定义事件

除了上述默认事件外，还可以定义自定义事件：

```php
// 在业务处理进程中触发自定义事件
Gateway::sendToBusinessWorker($client_id, 'myEvent', $data);

// 在事件处理类中实现自定义事件
public static function onMyEvent($client_id, $data)
{
    // 处理自定义事件
}
```

## 6. 高级功能

### 6.1 分布式部署

GatewayWorker 支持分布式部署，可以将 Register、Gateway、BusinessWorker 部署在不同的服务器上：

#### 6.1.1 多网关部署

```php
// 网关 1（服务器 A）
$gateway = new Gateway('websocket://0.0.0.0:8282');
$gateway->lanIp = '192.168.1.100';
$gateway->lanPort = 1238;
$gateway->registerAddress = '192.168.1.200:1236';

// 网关 2（服务器 B）
$gateway = new Gateway('websocket://0.0.0.0:8282');
$gateway->lanIp = '192.168.1.101';
$gateway->lanPort = 1238;
$gateway->registerAddress = '192.168.1.200:1236';
```

#### 6.1.2 多业务处理进程部署

```php
// 业务处理进程 1（服务器 C）
$worker = new BusinessWorker();
$worker->registerAddress = '192.168.1.200:1236';

// 业务处理进程 2（服务器 D）
$worker = new BusinessWorker();
$worker->registerAddress = '192.168.1.200:1236';
```

### 6.2 负载均衡

GatewayWorker 支持两种负载均衡模式：

#### 6.2.1 随机负载均衡

```php
// 设置随机负载均衡模式
Gateway::$selectLoadBalancingMode = Gateway::ROUTER_RANDOM;
```

#### 6.2.2 最小连接数负载均衡

```php
// 设置最小连接数负载均衡模式（默认）
Gateway::$selectLoadBalancingMode = Gateway::ROUTER_LEAST_CONNECTIONS;
```

### 6.3 心跳检测

为了保持长连接的稳定性，可以设置心跳检测：

```php
// 设置心跳时间间隔为 30 秒
$gateway->pingInterval = 30;

// 允许的心跳丢失次数为 2 次
$gateway->pingNotResponseLimit = 2;

// 心跳数据内容
$gateway->pingData = 'ping';
```

### 6.4 UID 绑定

可以将用户 ID 与客户端连接绑定，方便后续操作：

```php
// 绑定 UID 到客户端连接
Gateway::bindUid($client_id, $uid);

// 根据 UID 获取客户端连接
$client_ids = Gateway::getClientIdByUid($uid);

// 根据客户端连接获取 UID
$uid = Gateway::getUidByClientId($client_id);
```

### 6.5 分组管理

可以将客户端加入分组，方便向特定组发送消息：

```php
// 将客户端加入分组
Gateway::joinGroup($client_id, $group);

// 将客户端从分组中移除
Gateway::leaveGroup($client_id, $group);

// 向分组发送消息
Gateway::sendToGroup($group, $message);
```

## 7. 安全配置

### 7.1 秘钥设置

为了防止未授权的 Gateway 和 BusinessWorker 连接到注册中心，可以设置秘钥：

```php
// 注册中心设置秘钥
$register->secretKey = 'your_secret_key';

// 网关设置秘钥
$gateway->secretKey = 'your_secret_key';

// 业务处理进程设置秘钥
$worker->secretKey = 'your_secret_key';
```

### 7.2 SSL/TLS 加密

可以为 Gateway 设置 SSL/TLS 加密，提高通信安全性：

```php
// 创建网关实例，使用 SSL 加密
$gateway = new Gateway('websocket://0.0.0.0:8282', array(
    'ssl' => array(
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
        'verify_peer' => false
    )
));

// 设置传输方式为 SSL
$gateway->transport = 'ssl';
```

### 7.3 IP 白名单

可以设置 IP 白名单，限制只有特定 IP 可以连接到注册中心：

```php
// 注册中心设置 IP 白名单
$register->onConnect = function($connection) {
    $allowed_ips = array('127.0.0.1', '192.168.1.0/24');
    $client_ip = $connection->getRemoteIp();
    
    if (!in_array($client_ip, $allowed_ips)) {
        $connection->close();
    }
};
```

## 8. 性能优化

### 8.1 调整进程数

根据服务器的 CPU 核心数调整进程数，可以提高系统性能：

```php
// 设置网关进程数为 CPU 核心数
$gateway->count = 4;

// 设置业务处理进程数为 CPU 核心数的 2 倍
$worker->count = 8;
```

### 8.2 调整缓冲区大小

可以根据实际需求调整缓冲区大小：

```php
// 调整 gateway 发送给 businessWorker 的缓冲区大小
$gateway->sendToWorkerBufferSize = 10240000;

// 调整 gateway 发送给客户端的缓冲区大小
$gateway->sendToClientBufferSize = 1024000;

// 调整 businessWorker 发送给 gateway 的缓冲区大小
$worker->sendToGatewayBufferSize = 10240000;
```

### 8.3 协议加速

启用协议加速可以提高性能：

```php
// 启用协议加速
$gateway->protocolAccelerate = true;
```

### 8.4 避免阻塞操作

在业务逻辑中避免使用阻塞操作，如文件读写、数据库查询等，可以使用异步操作或多进程处理：

```php
// 不推荐（阻塞操作）
public static function onMessage($client_id, $message)
{
    // 阻塞的数据库查询
    $result = mysql_query('SELECT * FROM users');
    
    // 阻塞的文件读写
    file_put_contents('log.txt', $message, FILE_APPEND);
    
    Gateway::sendToClient($client_id, '处理完成');
}

// 推荐（异步操作）
public static function onMessage($client_id, $message)
{
    // 异步处理
    swoole_async_readfile('data.txt', function($filename, $content) use ($client_id) {
        Gateway::sendToClient($client_id, '文件内容：' . $content);
    });
}
```

## 9. 常见问题解答

### 9.1 如何查看日志？

GatewayWorker 的日志文件默认位于各个启动文件所在目录的 `logs` 子目录中：

```php
// 也可以自定义日志文件路径
Worker::$logFile = '/path/to/worker.log';
```

### 9.2 如何调试业务逻辑？

可以使用 `Worker::log()` 方法记录调试信息：

```php
use Workerman\Worker;

public static function onMessage($client_id, $message)
{
    Worker::log("客户端 $client_id 发来消息：$message");
    // 业务逻辑
}
```

### 9.3 如何处理断线重连？

客户端需要实现断线重连逻辑：

```javascript
function connect() {
    const ws = new WebSocket('ws://127.0.0.1:8282');
    
    ws.onopen = function() {
        console.log('连接成功');
    };
    
    ws.onmessage = function(event) {
        console.log('收到消息：', event.data);
    };
    
    ws.onclose = function() {
        console.log('连接关闭，5秒后重连');
        setTimeout(connect, 5000);
    };
    
    ws.onerror = function(error) {
        console.log('连接错误：', error);
    };
    
    return ws;
}

let ws = connect();
```

### 9.4 如何实现私聊功能？

可以使用 UID 绑定和 `sendToUid` 方法实现私聊：

```php
// 绑定 UID
Gateway::bindUid($client_id, $uid);

// 发送私聊消息
public static function onMessage($client_id, $message)
{
    $data = json_decode($message, true);
    
    if ($data['type'] === 'private') {
        // 发送私聊消息给指定用户
        Gateway::sendToUid($data['to_uid'], json_encode(array(
            'type' => 'private',
            'from_uid' => $data['from_uid'],
            'message' => $data['message']
        )));
    }
}
```

### 9.5 如何实现消息持久化？

可以将消息存储到数据库中，实现消息持久化：

```php
public static function onMessage($client_id, $message)
{
    // 存储消息到数据库
    $db = new mysqli('localhost', 'username', 'password', 'database');
    $sql = "INSERT INTO messages (client_id, content, create_time) VALUES (?, ?, ?)";
    $stmt = $db->prepare($sql);
    $create_time = time();
    $stmt->bind_param('ssi', $client_id, $message, $create_time);
    $stmt->execute();
    $stmt->close();
    $db->close();
    
    // 广播消息
    Gateway::broadcast($message);
}
```

## 10. 总结

GatewayWorker 是一个功能强大、易于使用的实时通信框架，适合构建各种实时通信应用。它采用三层架构设计，实现了客户端连接管理与业务逻辑的分离，具有高性能、易扩展、高可用等特点。通过本文档的介绍，您应该已经掌握了 GatewayWorker 的基本使用方法和高级功能，可以开始构建自己的实时通信应用了。

GatewayWorker 的核心优势在于其高性能和易扩展性，能够轻松处理百万级并发连接，并且可以通过分布式部署进一步提高系统的处理能力和稳定性。无论是构建即时通讯系统、实时监控系统还是物联网应用，GatewayWorker 都是一个理想的选择。