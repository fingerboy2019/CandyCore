# Channel进程间通信组件使用文档

## 1. 组件概述

Channel是Workerman框架提供的一个高性能进程间通信组件，用于在不同的Worker进程之间传递消息和事件。它提供了两种核心通信模式：**事件订阅发布模式**和**消息队列模式**，支持同一服务器内或跨服务器的进程间通信。

### 1.1 主要特点

- **高性能**：基于TCP协议，使用异步非阻塞I/O，支持高并发通信
- **双模式支持**：同时支持事件订阅发布和消息队列两种通信模式
- **自动重连**：客户端与服务器断开连接后自动尝试重连
- **心跳机制**：保持客户端与服务器的连接活跃
- **跨平台**：支持TCP和Unix Socket两种通信方式
- **灵活部署**：可以作为独立服务运行，也可以嵌入到现有应用中
- **多环境支持**：同时支持Workerman环境和非Workerman环境

## 2. 核心功能

### 2.1 事件订阅发布模式

事件订阅发布模式允许一个进程向特定的频道(channel)发布事件，所有订阅了该频道的进程都会收到这个事件和相关数据。

**适用场景**：
- 系统级事件通知（如用户登录、系统重启）
- 实时数据广播（如聊天室消息）
- 服务间状态同步

### 2.2 消息队列模式

消息队列模式允许一个进程向队列中添加消息，另一个或多个进程从队列中取出消息进行处理。Channel组件实现了一个简单的FIFO（先进先出）队列，支持负载均衡分发。

**适用场景**：
- 任务分发与异步处理
- 流量削峰填谷
- 解耦服务间依赖

## 3. 安装与配置

### 3.1 安装

Channel组件是Workerman的一部分，通常随Workerman一起安装。如果需要单独安装，可以通过Composer安装：

```bash
composer require workerman/channel
```

### 3.2 配置

#### 3.2.1 服务器配置

**TCP连接方式**：
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Channel\Server;

// 创建Channel服务器实例
$channel_server = new Server('0.0.0.0', 2206);

// 启动服务器
Worker::runAll();
```

**Unix Socket连接方式**：
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Channel\Server;

// 创建Channel服务器实例
$channel_server = new Server('unix:///tmp/channel.sock');

// 启动服务器
Worker::runAll();
```

**配置参数说明**：
- `$ip`：服务器绑定的IP地址或Unix Socket路径，默认'0.0.0.0'，表示监听所有网卡
- `$port`：服务器绑定的端口，仅在使用TCP连接时有效，默认2206

#### 3.2.2 客户端配置

**TCP连接方式**：
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Channel\Client;

// 连接到Channel服务器
Client::connect('127.0.0.1', 2206);
```

**Unix Socket连接方式**：
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Channel\Client;

// 连接到Channel服务器
Client::connect('unix:///tmp/channel.sock');
```

**配置参数说明**：
- `$ip`：Channel服务器的IP地址或Unix Socket路径
- `$port`：Channel服务器的端口，仅在使用TCP连接时需要

## 4. API参考

### 4.1 服务器端API

#### 4.1.1 构造函数
```php
Server::__construct($ip = '0.0.0.0', $port = 2206)
```

创建Channel服务器实例。

**参数**：
- `$ip`：服务器绑定的IP地址或Unix Socket路径
- `$port`：服务器绑定的端口（仅在使用TCP时有效）

**返回值**：
- `Server`实例

### 4.2 客户端API

#### 4.2.1 连接到服务器
```php
Client::connect($ip = '127.0.0.1', $port = 2206)
```

连接到Channel服务器。

**参数**：
- `$ip`：Channel服务器的IP地址或Unix Socket路径
- `$port`：Channel服务器的端口（仅在使用TCP连接时需要）

**返回值**：
- 无

#### 4.2.2 注册事件回调
```php
Client::on($event, $callback)
```

注册事件回调函数。

**参数**：
- `$event`：事件名称
- `$callback`：事件回调函数，格式：`function($event_data)`

**返回值**：
- 无

#### 4.2.3 订阅事件
```php
Client::subscribe($events)
```

订阅一个或多个事件。

**参数**：
- `$events`：事件名称或事件数组

**返回值**：
- 无

#### 4.2.4 取消订阅事件
```php
Client::unsubscribe($events)
```

取消订阅一个或多个事件。

**参数**：
- `$events`：事件名称或事件数组

**返回值**：
- 无

#### 4.2.5 发布事件
```php
Client::publish($events, $data, $is_loop = false)
```

发布事件到指定频道。

**参数**：
- `$events`：事件名称或事件数组
- `$data`：要发布的数据
- `$is_loop`：是否循环发布（仅发送给一个订阅者），默认false

**返回值**：
- 无

#### 4.2.6 监听队列
```php
Client::watch($channels, $callback, $autoReserve = true)
```

监听队列频道并设置回调函数。

**参数**：
- `$channels`：队列名称或队列数组
- `$callback`：队列回调函数，格式：`function($queue_data)`
- `$autoReserve`：是否自动继续消费队列，默认true

**返回值**：
- 无

#### 4.2.7 取消监听队列
```php
Client::unwatch($channels)
```

取消监听队列。

**参数**：
- `$channels`：队列名称或队列数组

**返回值**：
- 无

#### 4.2.8 向队列添加数据
```php
Client::enqueue($channels, $data)
```

向队列中添加数据。

**参数**：
- `$channels`：队列名称或队列数组
- `$data`：要添加的数据

**返回值**：
- 无

#### 4.2.9 手动消费队列
```php
Client::reserve()
```

手动开始消费队列。

**参数**：
- 无

**返回值**：
- 无

#### 4.2.10 心跳间隔配置
```php
Client::$pingInterval
```

心跳间隔时间（秒），默认55秒。

#### 4.2.11 全局事件回调
```php
Client::$onMessage
```

全局事件回调函数，当没有找到特定事件的回调函数时调用。

#### 4.2.12 连接成功回调
```php
Client::$onConnect
```

连接成功时的回调函数。

#### 4.2.13 连接关闭回调
```php
Client::$onClose
```

连接关闭时的回调函数。

## 5. 使用示例

### 5.1 事件订阅发布模式示例

#### 5.1.1 创建Channel服务器

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Channel\Server;

// 创建Channel服务器实例
$channel_server = new Server('0.0.0.0', 2206);

// 启动服务器
Worker::runAll();
```

#### 5.1.2 事件发布者

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Channel\Client;

// 创建一个Worker监听8000端口
$worker = new Worker('tcp://0.0.0.0:8000');

$worker->onWorkerStart = function() {
    // 连接到Channel服务器
    Client::connect('127.0.0.1', 2206);
};

$worker->onMessage = function($connection, $data) {
    // 发布事件到'user_login'频道
    Client::publish('user_login', [
        'user_id' => $data,
        'time' => time()
    ]);
    $connection->send('Event published');
};

// 启动Worker
Worker::runAll();
```

#### 5.1.3 事件订阅者

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Channel\Client;

// 创建一个Worker
$worker = new Worker();

$worker->onWorkerStart = function() {
    // 连接到Channel服务器
    Client::connect('127.0.0.1', 2206);
    
    // 订阅'user_login'事件
    Client::on('user_login', function($event_data) {
        echo "User {$event_data['user_id']} logged in at {$event_data['time']}\n";
    });
};

// 启动Worker
Worker::runAll();
```

### 5.2 消息队列模式示例

#### 5.2.1 消息生产者

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Channel\Client;

// 创建一个Worker监听8001端口
$worker = new Worker('tcp://0.0.0.0:8001');

$worker->onWorkerStart = function() {
    // 连接到Channel服务器
    Client::connect('127.0.0.1', 2206);
};

$worker->onMessage = function($connection, $data) {
    // 向任务队列添加任务
    Client::enqueue('task_queue', [
        'task_id' => uniqid(),
        'task_data' => $data,
        'time' => time()
    ]);
    $connection->send('Task added to queue');
};

// 启动Worker
Worker::runAll();
```

#### 5.2.2 消息消费者

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Channel\Client;

// 创建3个Worker进程作为消费者
$worker = new Worker();
$worker->count = 3;

$worker->onWorkerStart = function() {
    // 连接到Channel服务器
    Client::connect('127.0.0.1', 2206);
    
    // 监听任务队列
    Client::watch('task_queue', function($task_data) {
        echo "Process {$task_data['task_id']} started by worker " . posix_getpid() . "\n";
        // 处理任务
        sleep(1); // 模拟任务处理时间
        echo "Process {$task_data['task_id']} completed\n";
    });
};

// 启动Worker
Worker::runAll();
```

### 5.3 非Workerman环境使用示例

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Channel\Client;

// 连接到Channel服务器
Client::connect('127.0.0.1', 2206);

// 发布事件
Client::publish('test_event', 'Hello from non-Workerman environment');

// 向队列添加消息
Client::enqueue('test_queue', 'Queue message from non-Workerman environment');
```

## 6. 高级功能

### 6.1 循环发布模式

循环发布模式允许将事件依次发送给不同的订阅者，实现负载均衡：

```php
// 循环发布事件，每次只发送给一个订阅者
Client::publish('balance_event', $data, true);
```

### 6.2 手动控制队列消费

通过设置`$autoReserve = false`可以手动控制队列消费：

```php
Client::watch('task_queue', function($task_data) {
    echo "Task {$task_data['task_id']} received\n";
    // 处理任务
    // 处理完成后手动调用reserve继续消费
    Client::reserve();
}, false);
```

### 6.3 心跳配置

可以自定义心跳间隔时间：

```php
use Workerman\Channel\Client;

// 设置心跳间隔为30秒
Client::$pingInterval = 30;
```

## 7. 注意事项

1. **服务器部署**：Channel服务器通常只需要启动一个进程即可
2. **连接管理**：客户端会自动处理连接的建立和重连，无需手动管理
3. **数据格式**：发布的数据会被序列化后传输，确保数据可序列化
4. **性能考虑**：避免在高频事件中传输大量数据，影响性能
5. **错误处理**：建议在回调函数中添加错误处理逻辑，避免异常导致进程退出
6. **非Workerman环境限制**：在非Workerman环境中，部分功能可能不可用，如自动重连和心跳机制

## 8. 故障排查

### 8.1 连接失败

- 检查Channel服务器是否正常运行
- 检查IP地址和端口是否正确
- 检查防火墙设置是否允许连接

### 8.2 事件发布后没有收到

- 检查订阅者是否正确订阅了事件
- 检查发布者和订阅者是否连接到同一个Channel服务器
- 检查事件名称是否一致

### 8.3 队列消息处理异常

- 检查回调函数是否正确实现
- 检查消息数据格式是否正确
- 检查是否设置了正确的错误处理逻辑

## 9. 版本历史

- **v1.0.0**：初始版本，支持基本的事件订阅发布和消息队列功能
- **v1.1.0**：添加了自动重连和心跳机制
- **v1.2.0**：支持Unix Socket通信方式
- **v1.3.0**：添加了非Workerman环境支持
- **v1.4.0**：优化了性能，增加了循环发布模式

## 10. 参考链接

- [Workerman官方文档](http://www.workerman.net)
- [PHP进程间通信介绍](http://php.net/manual/zh/features.pcntl.php)
- [TCP/IP协议详解](https://tools.ietf.org/html/rfc793)