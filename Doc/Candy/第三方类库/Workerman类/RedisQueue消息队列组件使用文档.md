# RedisQueue消息队列组件使用文档

## 1. 组件概述

RedisQueue组件是基于Workerman和Redis实现的高性能消息队列系统，提供了简单易用的API接口，用于实现分布式消息的发送、接收和处理。该组件支持延迟消息、失败重试、队列订阅等功能，适用于各种异步任务处理场景。

## 2. 核心功能

- **三种队列类型**：
  - 等待消费队列（QUEUE_WAITING）：用于存储立即需要处理的消息
  - 延迟消费队列（QUEUE_DELAYED）：用于存储需要延迟处理的消息
  - 消费失败队列（QUEUE_FAILED）：用于存储处理失败的消息

- **消息延迟**：支持设置消息延迟时间，实现定时任务
- **失败重试**：自动重试失败的消息，可配置重试次数和重试间隔
- **队列订阅**：支持订阅一个或多个队列，接收并处理消息
- **消费失败回调**：自定义处理消费失败的消息
- **日志支持**：支持自定义日志记录器

## 3. 安装与配置

### 3.1 环境要求
- PHP 7.2+
- Workerman框架
- Redis服务器
- Workerman/Redis扩展

### 3.2 基本配置

```php
use Workerman\RedisQueue\Client;

// 连接Redis服务器
$redis_config = [
    'host'          => '127.0.0.1',
    'port'          => 6379,
    'auth'          => '',
    'db'            => 0,
    'prefix'        => '',
    'retry_seconds' => 5,
    'max_attempts'  => 5
];

$client = new Client('redis://127.0.0.1:6379', $redis_config);
```

## 4. API参考

### 4.1 构造函数

```php
public function __construct($address, array $options = [])
```

**参数**：
- `$address`：Redis服务器地址，格式：`redis://host:port`
- `$options`：配置选项
  - `auth`：Redis认证密码
  - `db`：Redis数据库索引，默认：0
  - `prefix`：队列前缀，默认：空
  - `retry_seconds`：重试间隔（秒），默认：5
  - `max_attempts`：最大重试次数，默认：5

### 4.2 发送消息

```php
public function send($queue, $data, $delay = 0, $cb = null)
```

**参数**：
- `$queue`：队列名称
- `$data`：消息内容（可以是任意PHP类型，会被JSON编码）
- `$delay`：延迟时间（秒），默认：0（立即发送）
- `$cb`：回调函数，接收一个布尔值参数，表示发送是否成功

**示例**：
```php
// 立即发送消息
$client->send('test-queue', ['message' => 'Hello World']);

// 延迟5秒发送消息
$client->send('test-queue', ['message' => 'Delayed Hello'], 5);

// 带回调的发送
$client->send('test-queue', ['message' => 'Hello with callback'], function($success) {
    if ($success) {
        echo 'Message sent successfully';
    } else {
        echo 'Message sent failed';
    }
});
```

### 4.3 订阅队列

```php
public function subscribe($queue, callable $callback)
```

**参数**：
- `$queue`：队列名称（可以是字符串或数组）
- `$callback`：消息处理回调函数，接收消息内容作为参数

**示例**：
```php
// 订阅单个队列
$client->subscribe('test-queue', function($data) {
    echo 'Received data: ' . json_encode($data) . PHP_EOL;
});

// 订阅多个队列
$client->subscribe(['queue1', 'queue2', 'queue3'], function($data) {
    echo 'Received data: ' . json_encode($data) . PHP_EOL;
});
```

### 4.4 取消订阅

```php
public function unsubscribe($queue)
```

**参数**：
- `$queue`：队列名称（可以是字符串或数组）

**示例**：
```php
// 取消订阅单个队列
$client->unsubscribe('test-queue');

// 取消订阅多个队列
$client->unsubscribe(['queue1', 'queue2']);
```

### 4.5 设置消费失败回调

```php
public function onConsumeFailure(callable $callback)
```

**参数**：
- `$callback`：消费失败回调函数，接收两个参数：
  - `$exception`：抛出的异常对象
  - `$package`：消息包，包含消息ID、队列、数据等信息

**示例**：
```php
$client->onConsumeFailure(function($exception, $package) {
    echo 'Consume failed: ' . $exception->getMessage() . PHP_EOL;
    echo 'Package: ' . json_encode($package) . PHP_EOL;
});
```

### 4.6 设置日志记录器

```php
public function logger(LoggerInterface $logger = null)
```

**参数**：
- `$logger`：实现了Psr\Log\LoggerInterface接口的日志记录器

**示例**：
```php
// 使用Monolog作为日志记录器
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('redis-queue');
$logger->pushHandler(new StreamHandler('redis-queue.log', Logger::INFO));

$client->logger($logger);
```

## 5. 使用示例

### 5.1 基本使用示例

```php
<?php

use Workerman\Worker;
use Workerman\RedisQueue\Client;

// 创建Worker实例
$worker = new Worker();

// 初始化RedisQueue客户端
$redis_client = null;

$worker->onWorkerStart = function() use (&$redis_client) {
    // 连接Redis服务器
    $redis_client = new Client('redis://127.0.0.1:6379', [
        'db'            => 0,
        'retry_seconds' => 5,
        'max_attempts'  => 3
    ]);
    
    // 设置消费失败回调
    $redis_client->onConsumeFailure(function($exception, $package) {
        echo "Consume failed: {$exception->getMessage()}\n";
        echo "Package: " . json_encode($package) . "\n";
    });
    
    // 订阅队列
    $redis_client->subscribe('test-queue', function($data) {
        echo "Received message: " . json_encode($data) . "\n";
        // 处理消息逻辑
        echo "Processing message...\n";
        // 模拟处理时间
        sleep(1);
        echo "Message processed successfully\n";
    });
    
    // 每隔5秒发送一条测试消息
    Worker::timer(5, function() use ($redis_client) {
        $redis_client->send('test-queue', [
            'id' => uniqid(),
            'time' => date('Y-m-d H:i:s'),
            'content' => 'This is a test message'
        ]);
        echo "Test message sent\n";
    });
};

// 运行Worker
Worker::runAll();
```

### 5.2 延迟消息示例

```php
<?php

use Workerman\Worker;
use Workerman\RedisQueue\Client;

$worker = new Worker();

$redis_client = null;

$worker->onWorkerStart = function() use (&$redis_client) {
    $redis_client = new Client('redis://127.0.0.1:6379');
    
    // 订阅延迟队列
    $redis_client->subscribe('delay-queue', function($data) {
        echo "Received delayed message at " . date('Y-m-d H:i:s') . "\n";
        echo "Message: " . json_encode($data) . "\n";
    });
    
    // 发送延迟消息
    echo "Sending delayed message at " . date('Y-m-d H:i:s') . "\n";
    $redis_client->send('delay-queue', [
        'id' => uniqid(),
        'message' => 'This message will be delivered after 10 seconds'
    ], 10); // 延迟10秒
};

Worker::runAll();
```

### 5.3 消费失败重试示例

```php
<?php

use Workerman\Worker;
use Workerman\RedisQueue\Client;

$worker = new Worker();

$redis_client = null;

$worker->onWorkerStart = function() use (&$redis_client) {
    $redis_client = new Client('redis://127.0.0.1:6379', [
        'retry_seconds' => 2,  // 重试间隔2秒
        'max_attempts'  => 3   // 最大重试3次
    ]);
    
    // 设置消费失败回调
    $redis_client->onConsumeFailure(function($exception, $package) {
        echo "Consume failed: {$exception->getMessage()}\n";
        echo "Attempt: {$package['attempts']}/{$package['max_attempts']}\n";
        echo "Package: " . json_encode($package) . "\n";
    });
    
    // 订阅队列，故意抛出异常来测试重试机制
    $redis_client->subscribe('retry-queue', function($data) {
        echo "Processing message at " . date('Y-m-d H:i:s') . "\n";
        echo "Message: " . json_encode($data) . "\n";
        
        // 故意抛出异常，触发重试
        throw new Exception("Simulated processing failure");
    });
    
    // 发送测试消息
    $redis_client->send('retry-queue', ['message' => 'Test retry mechanism']);
};

Worker::runAll();
```

## 6. 高级功能

### 6.1 自定义重试和失败处理

通过`onConsumeFailure`回调，您可以自定义重试和失败处理逻辑：

```php
$client->onConsumeFailure(function($exception, $package) {
    // 自定义处理逻辑
    if ($package['attempts'] < 3) {
        // 重新尝试
        return [
            'data' => $package['data'],
            'attempts' => $package['attempts'],
            'max_attempts' => 5,  // 修改最大重试次数
            'error' => $exception->getMessage()
        ];
    } else {
        // 记录到数据库或其他处理
        echo "Message failed permanently: " . json_encode($package) . "\n";
        return false;  // 不重试
    }
});
```

### 6.2 多队列订阅

您可以同时订阅多个队列：

```php
$client->subscribe(['queue1', 'queue2', 'queue3'], function($data) {
    // 统一处理所有队列的消息
    echo "Received data: " . json_encode($data) . "\n";
});
```

### 6.3 队列前缀

通过设置前缀，可以在同一个Redis实例中隔离不同的应用队列：

```php
$client = new Client('redis://127.0.0.1:6379', [
    'prefix' => 'my-app-'  // 所有队列名称前都会加上这个前缀
]);

// 实际Redis键名为：{redis-queue}-waitingmy-app-test-queue
$client->send('test-queue', ['message' => 'Hello']);
```

## 7. 最佳实践

### 7.1 性能优化
- 根据消息处理复杂度调整Worker进程数
- 对于高并发场景，考虑使用Redis集群
- 避免在消息处理回调中执行耗时操作，如网络请求、数据库查询等（可以考虑使用协程）

### 7.2 错误处理
- 总是设置`onConsumeFailure`回调来处理消费失败的情况
- 对于重要消息，可以在失败后发送告警通知
- 定期清理失败队列中的消息，避免Redis内存占用过高

### 7.3 安全性
- 使用强密码保护Redis服务器
- 考虑限制Redis访问IP
- 避免在消息中存储敏感信息，如密码、令牌等

### 7.4 监控与维护
- 监控Redis的内存使用和连接数
- 监控队列长度，及时发现消息堆积问题
- 定期备份Redis数据

## 8. 队列管理

### 8.1 查看队列长度

```php
// 查看等待队列长度
$waiting_length = $redis->lLen('{redis-queue}-waitingtest-queue');

// 查看延迟队列长度
$delayed_length = $redis->zCard('{redis-queue}-delayed');

// 查看失败队列长度
$failed_length = $redis->lLen('{redis-queue}-failed');
```

### 8.2 清理失败队列

```php
// 清空失败队列
$redis->del('{redis-queue}-failed');

// 只保留最新的1000条失败消息
$redis->lTrim('{redis-queue}-failed', 0, 999);
```

## 9. 总结

RedisQueue组件提供了一个简单易用但功能强大的消息队列系统，适用于各种异步任务处理场景。它基于Redis实现，具有高性能、可靠性和灵活性的特点。通过合理配置和使用，可以满足各种复杂的业务需求。

无论是简单的任务队列、延迟通知，还是复杂的分布式系统通信，RedisQueue都能提供稳定可靠的支持。其丰富的API和灵活的配置选项，使得开发者可以根据实际需求进行定制和扩展。