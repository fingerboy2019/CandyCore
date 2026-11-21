# PHPSocketIO 实时通信组件使用文档

## 1. 组件概述

PHPSocketIO 是一个基于 Socket.IO 协议的实时通信组件，允许客户端和服务器之间进行双向通信。它构建在 Workerman 之上，提供了简洁易用的 API，支持多种实时通信场景。

### 1.1 主要功能

- 支持 Socket.IO 协议的实时双向通信
- 支持命名空间（Namespaces）
- 支持房间（Rooms）功能，用于分组通信
- 支持事件发射和接收
- 支持广播消息
- 支持回调确认（Acknowledgement）
- 支持 SSL/TLS 加密连接
- 支持跨域请求
- 支持二进制数据传输

### 1.2 应用场景

- 实时聊天应用
- 在线游戏
- 实时协作工具
- 实时数据可视化
- 推送通知系统
- 在线客服系统
- 实时监控系统

## 2. 安装与配置

### 2.1 安装

PHPSocketIO 是 Workerman 的扩展组件，需要通过 Composer 安装：

```bash
composer require workerman/phpsocket.io
```

### 2.2 依赖要求

- PHP 7.2+
- Workerman 3.5.0+
- ext-sockets 扩展
- ext-mbstring 扩展

## 3. 快速入门

### 3.1 创建基本服务器

```php
<?php
use PHPSocketIO\SocketIO;

// 创建 Socket.IO 服务器，监听 2021 端口
$io = new SocketIO(2021);

// 监听连接事件
$io->on('connection', function($socket) use ($io) {
    echo "客户端连接: {$socket->id}\n";
    
    // 发送欢迎消息
    $socket->emit('welcome', '欢迎连接到 Socket.IO 服务器');
    
    // 监听客户端消息
    $socket->on('message', function($data) use ($socket) {
        echo "收到消息: {$data}\n";
        // 回复消息
        $socket->emit('reply', "服务器已收到: {$data}");
    });
    
    // 监听断开连接事件
    $socket->on('disconnect', function() use ($socket) {
        echo "客户端断开连接: {$socket->id}\n";
    });
});

// 启动服务器
SocketIO::run();
```

### 3.2 客户端连接（JavaScript）

```html
<!DOCTYPE html>
<html>
<head>
    <title>Socket.IO 客户端</title>
    <script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>
</head>
<body>
    <h1>Socket.IO 客户端测试</h1>
    <div id="messages"></div>
    <input type="text" id="messageInput" placeholder="输入消息">
    <button id="sendBtn">发送</button>
    
    <script>
        // 连接到服务器
        const socket = io('http://localhost:2021');
        
        // 监听欢迎消息
        socket.on('welcome', function(data) {
            addMessage('系统: ' + data);
        });
        
        // 监听回复消息
        socket.on('reply', function(data) {
            addMessage('服务器: ' + data);
        });
        
        // 发送消息
        document.getElementById('sendBtn').addEventListener('click', function() {
            const message = document.getElementById('messageInput').value;
            if (message) {
                socket.emit('message', message);
                addMessage('我: ' + message);
                document.getElementById('messageInput').value = '';
            }
        });
        
        // 添加消息到页面
        function addMessage(message) {
            const div = document.createElement('div');
            div.textContent = message;
            document.getElementById('messages').appendChild(div);
        }
    </script>
</body>
</html>
```

## 4. API 参考

### 4.1 SocketIO 类

#### 4.1.1 构造函数

```php
public function __construct($port = null, $opts = [])
```

创建 Socket.IO 服务器实例。

**参数：**
- `$port` - 监听端口号
- `$opts` - 配置选项数组
  - `nsp` - 命名空间类，默认 `\PHPSocketIO\Nsp`
  - `socket` - Socket 类，默认 `\PHPSocketIO\Socket`
  - `adapter` - 适配器类，默认 `\PHPSocketIO\DefaultAdapter`
  - `origins` - 跨域来源，默认 `*:*`
  - `host` - 监听地址，默认 `0.0.0.0`
  - `ssl` - SSL 配置，用于加密连接

**示例：**

```php
// 创建基本服务器
$io = new SocketIO(2021);

// 创建带 SSL 的服务器
$io = new SocketIO(2021, [
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',
        'local_pk' => '/path/to/key.pem',
    ]
]);
```

#### 4.1.2 on()

```php
public function on($ev = null)
```

监听事件。

**参数：**
- `$ev` - 事件名称
- `$callback` - 事件回调函数

**支持的事件：**
- `connection` - 客户端连接事件
- `disconnect` - 客户端断开连接事件
- `workerStart` - 工作进程启动事件
- `workerStop` - 工作进程停止事件

**示例：**

```php
$io->on('connection', function($socket) {
    echo "客户端连接: {$socket->id}\n";
});
```

#### 4.1.3 emit()

```php
public function emit($ev = null)
```

向所有连接的客户端发送事件。

**参数：**
- `$ev` - 事件名称
- `$args` - 事件数据（可变参数）

**示例：**

```php
// 向所有客户端发送消息
$io->emit('news', '这是一条广播消息');
```

#### 4.1.4 in() / to()

```php
public function in()
public function to()
```

指定消息发送到特定房间。

**参数：**
- `$room` - 房间名称

**示例：**

```php
// 向 room1 房间的所有客户端发送消息
$io->in('room1')->emit('news', '房间消息');
$io->to('room1')->emit('news', '房间消息'); // 等同于 in()
```

#### 4.1.5 of()

```php
public function of($name, $fn = null)
```

创建或获取命名空间。

**参数：**
- `$name` - 命名空间名称
- `$fn` - 连接事件回调函数（可选）

**返回值：**
- 命名空间对象

**示例：**

```php
// 创建聊天命名空间
$chatNsp = $io->of('/chat');

// 监听命名空间连接事件
$chatNsp->on('connection', function($socket) {
    echo "客户端连接到 /chat 命名空间\n";
});
```

#### 4.1.6 origins()

```php
public function origins($v = null)
```

设置跨域来源。

**参数：**
- `$v` - 跨域来源，默认 `*:*`

**示例：**

```php
// 允许所有来源
$io->origins('*:*');

// 允许特定来源
$io->origins('example.com:* http://localhost:8080');
```

### 4.2 Socket 类

#### 4.2.1 emit()

```php
public function emit($ev = null)
```

向客户端发送事件。

**参数：**
- `$ev` - 事件名称
- `$args` - 事件数据（可变参数）

**示例：**

```php
// 向客户端发送消息
$socket->emit('message', '你好，客户端');

// 带回调的消息
$socket->emit('message', '需要确认的消息', function($response) {
    echo "客户端确认: {$response}\n";
});
```

#### 4.2.2 on()

```php
public function on($ev = null)
```

监听客户端事件。

**参数：**
- `$ev` - 事件名称
- `$callback` - 事件回调函数

**示例：**

```php
// 监听客户端消息
$socket->on('message', function($data) use ($socket) {
    echo "收到消息: {$data}\n";
    $socket->emit('reply', '已收到消息');
});
```

#### 4.2.3 join()

```php
public function join($room): Socket
```

加入房间。

**参数：**
- `$room` - 房间名称

**返回值：**
- Socket 对象（链式调用）

**示例：**

```php
// 加入房间
$socket->join('room1');
```

#### 4.2.4 leave()

```php
public function leave($room): Socket
```

离开房间。

**参数：**
- `$room` - 房间名称

**返回值：**
- Socket 对象（链式调用）

**示例：**

```php
// 离开房间
$socket->leave('room1');
```

#### 4.2.5 disconnect()

```php
public function disconnect(bool $close = false): Socket
```

断开客户端连接。

**参数：**
- `$close` - 是否关闭底层连接，默认 `false`

**返回值：**
- Socket 对象（链式调用）

**示例：**

```php
// 断开客户端连接
$socket->disconnect();
```

#### 4.2.6 broadcast

**属性**

广播消息到除自己外的所有客户端。

**示例：**

```php
// 广播消息到当前命名空间的所有其他客户端
$socket->broadcast->emit('message', '广播消息');

// 广播消息到特定房间的所有其他客户端
$socket->broadcast->to('room1')->emit('message', '房间广播消息');
```

## 5. 高级功能

### 5.1 命名空间

命名空间允许将客户端分组到不同的通信通道，避免事件冲突。

```php
<?php
use PHPSocketIO\SocketIO;

$io = new SocketIO(2021);

// 默认命名空间
$io->on('connection', function($socket) {
    echo "客户端连接到默认命名空间: {$socket->id}\n";
});

// 聊天命名空间
$chat = $io->of('/chat');
$chat->on('connection', function($socket) {
    echo "客户端连接到聊天命名空间: {$socket->id}\n";
    
    // 监听聊天消息
    $socket->on('message', function($data) use ($socket) {
        // 广播给聊天命名空间的所有客户端
        $socket->broadcast->emit('message', $data);
    });
});

// 游戏命名空间
$game = $io->of('/game');
$game->on('connection', function($socket) {
    echo "客户端连接到游戏命名空间: {$socket->id}\n";
    
    // 监听游戏事件
    $socket->on('move', function($data) use ($socket) {
        // 广播游戏动作
        $socket->broadcast->emit('move', $data);
    });
});

SocketIO::run();
```

### 5.2 房间

房间允许在命名空间内进一步细分客户端，实现更精确的消息发送。

```php
<?php
use PHPSocketIO\SocketIO;

$io = new SocketIO(2021);

$io->on('connection', function($socket) use ($io) {
    // 加入默认房间
    $socket->join('default');
    
    // 监听加入房间事件
    $socket->on('joinRoom', function($room) use ($socket) {
        $socket->join($room);
        $socket->emit('joinedRoom', $room);
        // 通知房间内其他用户
        $socket->broadcast->to($room)->emit('userJoined', $socket->id);
    });
    
    // 监听离开房间事件
    $socket->on('leaveRoom', function($room) use ($socket) {
        $socket->leave($room);
        $socket->emit('leftRoom', $room);
        // 通知房间内其他用户
        $socket->broadcast->to($room)->emit('userLeft', $socket->id);
    });
    
    // 监听房间消息
    $socket->on('roomMessage', function($data) use ($socket) {
        $room = $data['room'];
        $message = $data['message'];
        // 发送消息到指定房间
        $io->to($room)->emit('message', [
            'from' => $socket->id,
            'message' => $message
        ]);
    });
});

SocketIO::run();
```

### 5.3 SSL/TLS 加密连接

```php
<?php
use PHPSocketIO\SocketIO;

// 创建带 SSL 的 Socket.IO 服务器
$io = new SocketIO(2021, [
    'ssl' => [
        'local_cert' => '/path/to/cert.pem',  // SSL 证书
        'local_pk' => '/path/to/key.pem',     // SSL 私钥
        'verify_peer' => false,               // 不验证客户端证书
    ]
]);

$io->on('connection', function($socket) {
    echo "客户端通过 SSL 连接: {$socket->id}\n";
    $socket->emit('welcome', '欢迎使用 SSL 加密连接');
});

SocketIO::run();
```

### 5.4 回调确认

回调确认允许客户端确认收到服务器消息，并返回响应。

```php
<?php
use PHPSocketIO\SocketIO;

$io = new SocketIO(2021);

$io->on('connection', function($socket) {
    // 发送需要确认的消息
    $socket->emit('ping', time(), function($response) use ($socket) {
        echo "客户端确认 ping: {$response}\n";
        $latency = time() - $response;
        $socket->emit('pong', $latency);
    });
    
    // 监听客户端需要确认的消息
    $socket->on('message', function($data, $callback) use ($socket) {
        echo "收到消息: {$data}\n";
        // 处理消息
        $result = "已处理: {$data}";
        // 返回确认
        $callback($result);
    });
});

SocketIO::run();
```

客户端代码：

```javascript
const socket = io('http://localhost:2021');

// 接收 ping 并确认
socket.on('ping', function(timestamp, callback) {
    callback(timestamp);
});

// 接收 pong
socket.on('pong', function(latency) {
    console.log('延迟:', latency, 'ms');
});

// 发送需要确认的消息
socket.emit('message', 'Hello Server', function(response) {
    console.log('服务器确认:', response);
});
```

### 5.5 二进制数据传输

PHPSocketIO 支持传输二进制数据，如图片、文件等。

```php
<?php
use PHPSocketIO\SocketIO;

$io = new SocketIO(2021);

$io->on('connection', function($socket) {
    // 监听二进制数据
    $socket->on('binaryData', function($data) use ($socket) {
        echo "收到二进制数据，长度: " . strlen($data) . "\n";
        // 处理二进制数据（如保存文件）
        file_put_contents(__DIR__ . '/upload.jpg', $data);
        // 返回确认
        $socket->emit('uploadComplete', '文件上传成功');
    });
});

SocketIO::run();
```

客户端代码：

```javascript
const socket = io('http://localhost:2021');

// 上传二进制文件
const fileInput = document.getElementById('fileInput');
fileInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    const reader = new FileReader();
    
    reader.onload = function(e) {
        const arrayBuffer = e.target.result;
        const binaryData = new Uint8Array(arrayBuffer);
        
        // 发送二进制数据
        socket.emit('binaryData', binaryData, function(response) {
            console.log(response);
        });
    };
    
    reader.readAsArrayBuffer(file);
});
```

## 6. 最佳实践

### 6.1 错误处理

```php
<?php
use PHPSocketIO\SocketIO;

$io = new SocketIO(2021);

$io->on('connection', function($socket) {
    try {
        // 可能抛出异常的代码
        $socket->emit('welcome', '欢迎连接');
    } catch (Exception $e) {
        echo "错误: " . $e->getMessage() . "\n";
        // 向客户端发送错误信息
        $socket->emit('error', '服务器错误');
    }
});

// 监听错误事件
$io->on('error', function($socket, $error) {
    echo "客户端错误: {$error}\n";
});

SocketIO::run();
```

### 6.2 客户端认证

```php
<?php
use PHPSocketIO\SocketIO;

$io = new SocketIO(2021);

$io->on('connection', function($socket) {
    // 监听认证事件
    $socket->on('authenticate', function($token) use ($socket) {
        // 验证 token
        if (validateToken($token)) {
            // 认证成功
            $socket->emit('authenticated', true);
            $socket->joined = true;
        } else {
            // 认证失败
            $socket->emit('authenticated', false);
            $socket->disconnect();
        }
    });
    
    // 监听其他事件前检查认证状态
    $socket->on('message', function($data) use ($socket) {
        if (!isset($socket->joined) || !$socket->joined) {
            $socket->emit('error', '请先认证');
            return;
        }
        // 处理消息
    });
});

// 验证 token 函数
function validateToken($token) {
    // 实际应用中应该从数据库或缓存中验证 token
    return $token === 'valid_token';
}

SocketIO::run();
```

### 6.3 性能优化

1. **使用命名空间和房间**：避免向不必要的客户端发送消息
2. **限制广播范围**：只向需要的客户端发送消息
3. **优化数据大小**：减少传输的数据量，使用压缩
4. **合理设置工作进程数**：根据服务器配置调整
5. **使用连接池**：数据库连接池、Redis 连接池等
6. **避免阻塞操作**：使用异步操作或协程

```php
<?php
use PHPSocketIO\SocketIO;
use Workerman\Worker;

// 创建 Socket.IO 服务器，设置 4 个工作进程
$io = new SocketIO(2021, [
    'worker_num' => 4, // 根据 CPU 核心数调整
]);

// 其他代码...

SocketIO::run();
```

### 6.4 多进程共享数据

在多进程环境下，需要使用 Redis 等外部存储来共享数据。

```php
<?php
use PHPSocketIO\SocketIO;
use PHPSocketIO\ChannelAdapter;

$io = new SocketIO(2021, [
    'worker_num' => 4,
    'adapter' => 'PHPSocketIO\ChannelAdapter',
]);

// 设置 Redis 配置
ChannelAdapter::setConfig([
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => '',
    'db' => 0,
]);

// 其他代码...

SocketIO::run();
```

## 7. 常见问题与解决方案

### 7.1 跨域问题

**问题：** 客户端无法连接，控制台显示跨域错误。

**解决方案：** 设置正确的跨域来源。

```php
$io->origins('*:*'); // 允许所有来源
// 或
$io->origins('example.com:* http://localhost:8080'); // 允许特定来源
```

### 7.2 客户端无法连接

**问题：** 客户端无法连接到服务器。

**解决方案：**
1. 检查服务器是否启动
2. 检查端口是否被占用
3. 检查防火墙设置
4. 检查 SSL 证书是否有效
5. 检查客户端连接地址是否正确

### 7.3 消息发送失败

**问题：** 消息无法发送到客户端。

**解决方案：**
1. 检查客户端是否在线
2. 检查命名空间和房间是否正确
3. 检查事件名称是否匹配
4. 检查数据格式是否正确

### 7.4 性能问题

**问题：** 服务器性能下降，响应缓慢。

**解决方案：**
1. 增加工作进程数
2. 优化代码，避免阻塞操作
3. 使用缓存减少数据库查询
4. 限制广播范围
5. 升级服务器硬件

## 8. 版本历史

- **v3.0.0**：支持 Socket.IO 4.x 协议
- **v2.0.0**：支持 PHP 7.2+，改进性能
- **v1.1.0**：增加 SSL 支持
- **v1.0.0**：初始版本

## 9. 总结

PHPSocketIO 是一个功能强大的实时通信组件，基于 Socket.IO 协议，提供了简洁易用的 API。它支持多种实时通信场景，如聊天应用、在线游戏、实时协作等。通过合理使用命名空间、房间和广播功能，可以构建高效、可扩展的实时应用程序。

要了解更多信息，请访问官方文档：
- Workerman 文档：http://doc.workerman.net/
- Socket.IO 官方文档：https://socket.io/docs/

## 10. 参考资源

- [Workerman GitHub](https://github.com/walkor/Workerman)
- [PHPSocketIO GitHub](https://github.com/walkor/PHPSocketIO)
- [Socket.IO 官方网站](https://socket.io/)
- [MDN WebSocket 文档](https://developer.mozilla.org/zh-CN/docs/Web/API/WebSocket)

---

**注意：** 本文档基于 PHPSocketIO 3.x 版本编写，不同版本的 API 可能有所差异，请参考对应版本的官方文档。