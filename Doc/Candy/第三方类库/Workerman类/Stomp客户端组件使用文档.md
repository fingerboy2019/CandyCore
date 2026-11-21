# Stomp客户端组件使用文档

## 1. 组件概述

Stomp客户端组件是基于Workerman框架实现的异步Stomp协议客户端，用于与支持Stomp协议的消息中间件（如ActiveMQ、RabbitMQ、Apache Kafka等）进行通信。该组件提供了完整的Stomp协议支持，包括连接管理、消息发送、消息订阅、确认机制等功能，适用于构建高性能的异步消息处理系统。

### 1.1 主要功能

- **异步连接**：基于Workerman的异步TCP连接，支持高并发
- **自动重连**：连接断开后自动尝试重连
- **消息订阅**：支持订阅指定目的地的消息
- **消息确认**：支持自动确认和手动确认两种模式
- **心跳机制**：支持Stomp协议的心跳机制，保持连接活跃
- **错误处理**：完善的错误处理机制，便于问题排查
- **调试模式**：支持调试模式，输出详细的协议交互信息
- **SSL支持**：可配置SSL连接，实现安全通信

### 1.2 支持的Stomp命令

- CONNECT/CONNECTED：连接和连接确认
- SUBSCRIBE：订阅消息
- UNSUBSCRIBE：取消订阅
- SEND：发送消息
- ACK：确认消息
- NACK：拒绝消息
- DISCONNECT：断开连接
- HEARTBEAT：心跳

## 2. 核心API

### 2.1 Client类

**命名空间**：`Workerman\Stomp\Client`

**构造函数**：

```php
public function __construct($address, $options = [])
```

**参数**：
- `$address`：Stomp服务器地址，格式为 `stomp://host:port` 或 `stomp+ssl://host:port`
- `$options`：客户端配置选项数组

**功能**：创建Stomp客户端实例，配置连接参数

### 2.2 连接管理

**connect()**：

```php
public function connect()
```

**功能**：连接到Stomp服务器

**disconnect()**：

```php
public function disconnect()
```

**功能**：断开与Stomp服务器的连接

**close()**：

```php
public function close()
```

**功能**：关闭连接，不发送DISCONNECT命令

**reconnect()**：

```php
public function reconnect($after = 0)
```

**参数**：
- `$after`：延迟重连时间（秒）

**功能**：重新连接到Stomp服务器

### 2.3 消息订阅

**subscribe()**：

```php
public function subscribe($destination, $callback, array $headers = [])
```

**参数**：
- `$destination`：消息目的地（队列或主题）
- `$callback`：消息处理回调函数
- `$headers`：订阅头信息

**返回值**：订阅ID

**功能**：订阅指定目的地的消息

**subscribeWithAck()**：

```php
public function subscribeWithAck($destination, $callback, array $headers = [])
```

**参数**：
- `$destination`：消息目的地（队列或主题）
- `$callback`：消息处理回调函数
- `$headers`：订阅头信息

**返回值**：订阅ID

**功能**：订阅指定目的地的消息，使用手动确认模式

**unsubscribe()**：

```php
public function unsubscribe($subscription, array $headers = [])
```

**参数**：
- `$subscription`：订阅ID
- `$headers`：取消订阅头信息

**功能**：取消订阅

### 2.4 消息发送

**send()**：

```php
public function send($destination, $body, array $headers = [])
```

**参数**：
- `$destination`：消息目的地（队列或主题）
- `$body`：消息内容
- `$headers`：消息头信息

**功能**：发送消息到指定目的地

### 2.5 消息确认

**ack()**：

```php
public function ack($subscription, $message_id, array $headers = [])
```

**参数**：
- `$subscription`：订阅ID
- `$message_id`：消息ID
- `$headers`：确认头信息

**功能**：确认消息已处理

**nack()**：

```php
public function nack($subscription, $message_id, array $headers = [])
```

**参数**：
- `$subscription`：订阅ID
- `$message_id`：消息ID
- `$headers`：拒绝头信息

**功能**：拒绝处理消息

### 2.6 事件回调

**onConnect**：连接建立成功时触发
```php
public $onConnect = null;
```

**onReconnect**：重新连接成功时触发
```php
public $onReconnect = null;
```

**onClose**：连接关闭时触发
```php
public $onClose = null;
```

**onError**：发生错误时触发
```php
public $onError = null;
```

## 3. 配置选项

### 3.1 默认配置

```php
protected $_options = [
    'vhost'            => '/',
    'login'            => 'guest',
    'passcode'         => 'guest',
    'bindto'           => '',
    'ssl'              => [],
    'connect_timeout'  => 30,
    'reconnect_period' => 2,
    'debug'            => false,
    'heart_beat'       => [0, 0],
];
```

### 3.2 配置说明

| 配置项 | 类型 | 说明 | 默认值 |
|--------|------|------|--------|
| vhost | string | Stomp服务器虚拟主机 | / |
| login | string | 用户名 | guest |
| passcode | string | 密码 | guest |
| bindto | string | 本地绑定地址 | 空 |
| ssl | array | SSL配置选项 | [] |
| connect_timeout | int | 连接超时时间（秒） | 30 |
| reconnect_period | int | 重连间隔时间（秒） | 2 |
| debug | bool | 是否启用调试模式 | false |
| heart_beat | array | 心跳配置，[发送间隔, 接收间隔]，单位毫秒 | [0, 0] |

## 4. 使用示例

### 4.1 基本使用

**连接到Stomp服务器并订阅消息**：

```php
<?php

use Workerman\Worker;
use Workerman\Stomp\Client;

// 创建Worker实例
$worker = new Worker();

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端
    $stomp = new Client('stomp://127.0.0.1:61613', [
        'login' => 'admin',
        'passcode' => 'password',
        'debug' => true
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        echo "连接成功！\n";
        
        // 订阅消息队列
        $stomp->subscribe('/queue/test', function(Client $stomp, $data, $resolver) {
            echo "收到消息：{$data['body']}\n";
            // 自动确认模式下，不需要手动确认
        });
        
        // 发送测试消息
        $stomp->send('/queue/test', 'Hello Stomp!');
    };
    
    // 错误回调
    $stomp->onError = function($exception) {
        echo "错误：{$exception->getMessage()}\n";
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

// 运行Worker
Worker::runAll();
```

### 4.2 手动确认模式

**使用手动确认模式处理消息**：

```php
<?php

use Workerman\Worker;
use Workerman\Stomp\Client;

// 创建Worker实例
$worker = new Worker();

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端
    $stomp = new Client('stomp://127.0.0.1:61613', [
        'login' => 'admin',
        'passcode' => 'password'
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        echo "连接成功！\n";
        
        // 使用手动确认模式订阅消息队列
        $stomp->subscribeWithAck('/queue/test', function(Client $stomp, $data, $resolver) {
            echo "收到消息：{$data['body']}\n";
            
            // 处理消息...
            $success = true;
            
            if ($success) {
                // 确认消息已处理
                $resolver->ack();
                echo "消息已确认\n";
            } else {
                // 拒绝消息
                $resolver->nack();
                echo "消息已拒绝\n";
            }
        });
        
        // 发送测试消息
        $stomp->send('/queue/test', 'Hello Manual Ack!');
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

// 运行Worker
Worker::runAll();
```

### 4.3 使用SSL连接

**配置SSL连接**：

```php
<?php

use Workerman\Worker;
use Workerman\Stomp\Client;

// 创建Worker实例
$worker = new Worker();

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端（使用SSL）
    $stomp = new Client('stomp://127.0.0.1:61614', [
        'login' => 'admin',
        'passcode' => 'password',
        'ssl' => [
            'local_cert' => '/path/to/client.pem',  // 客户端证书
            'local_pk' => '/path/to/client.key',  // 客户端私钥
            'verify_peer' => true,  // 验证服务器证书
            'cafile' => '/path/to/ca.pem'  // CA证书
        ]
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        echo "SSL连接成功！\n";
        
        // 订阅消息队列
        $stomp->subscribe('/queue/test', function(Client $stomp, $data, $resolver) {
            echo "收到消息：{$data['body']}\n";
        });
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

// 运行Worker
Worker::runAll();
```

### 4.4 配置心跳机制

**启用心跳机制**：

```php
<?php

use Workerman\Worker;
use Workerman\Stomp\Client;

// 创建Worker实例
$worker = new Worker();

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端
    $stomp = new Client('stomp://127.0.0.1:61613', [
        'login' => 'admin',
        'passcode' => 'password',
        'heart_beat' => [10000, 10000]  // 发送心跳间隔10秒，接收心跳间隔10秒
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        echo "连接成功！已启用心跳机制\n";
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

// 运行Worker
Worker::runAll();
```

### 4.5 发送带自定义头的消息

**发送带自定义头的消息**：

```php
<?php

use Workerman\Worker;
use Workerman\Stomp\Client;

// 创建Worker实例
$worker = new Worker();

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端
    $stomp = new Client('stomp://127.0.0.1:61613', [
        'login' => 'admin',
        'passcode' => 'password'
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        echo "连接成功！\n";
        
        // 发送带自定义头的消息
        $headers = [
            'content-type' => 'application/json',
            'priority' => 9,
            'custom-header' => 'custom-value'
        ];
        
        $message = json_encode([
            'id' => 1,
            'content' => 'Hello with headers!',
            'timestamp' => time()
        ]);
        
        $stomp->send('/queue/test', $message, $headers);
        echo "带自定义头的消息已发送\n";
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

// 运行Worker
Worker::runAll();
```

### 4.6 取消订阅

**取消订阅消息队列**：

```php
<?php

use Workerman\Worker;
use Workerman\Stomp\Client;
use Workerman\Timer;

// 创建Worker实例
$worker = new Worker();

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端
    $stomp = new Client('stomp://127.0.0.1:61613', [
        'login' => 'admin',
        'passcode' => 'password'
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        echo "连接成功！\n";
        
        // 订阅消息队列，获取订阅ID
        $subscription_id = $stomp->subscribe('/queue/test', function(Client $stomp, $data, $resolver) {
            echo "收到消息：{$data['body']}\n";
        });
        
        echo "已订阅队列，订阅ID：{$subscription_id}\n";
        
        // 5秒后取消订阅
        Timer::add(5, function() use ($stomp, $subscription_id) {
            $stomp->unsubscribe($subscription_id);
            echo "已取消订阅，订阅ID：{$subscription_id}\n";
        });
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

// 运行Worker
Worker::runAll();
```

## 5. AckResolver类

### 5.1 概述

AckResolver类用于在手动确认模式下处理消息的确认和拒绝操作。当收到消息时，会创建一个AckResolver实例并传递给订阅回调函数，通过该实例可以方便地调用ack()或nack()方法。

### 5.2 主要方法

**ack()**：

```php
public function ack(array $headers = array())
```

**功能**：确认消息已处理

**参数**：
- `$headers`：确认头信息

**nack()**：

```php
public function nack(array $headers = array())
```

**功能**：拒绝处理消息

**参数**：
- `$headers`：拒绝头信息

**done()**：

```php
public function done()
```

**功能**：标记消息处理完成，但不发送ack或nack命令（仅用于自动确认模式）

## 6. 最佳实践

### 6.1 错误处理

```php
// 错误回调
$stomp->onError = function($exception) {
    echo "Stomp错误：{$exception->getMessage()}\n";
    // 记录错误日志
    file_put_contents('/path/to/stomp_error.log', date('Y-m-d H:i:s') . ' ' . $exception->getMessage() . PHP_EOL, FILE_APPEND);
    // 可以根据错误类型进行不同的处理
    if (strpos($exception->getMessage(), 'Connection') !== false) {
        echo "连接错误，将自动重连...\n";
    }
};
```

### 6.2 消息处理幂等性

由于网络问题或其他原因，消息可能会被重复发送，因此在处理消息时应确保操作的幂等性：

```php
$stomp->subscribe('/queue/test', function(Client $stomp, $data, $resolver) {
    $message = json_decode($data['body'], true);
    $message_id = $data['headers']['message-id'];
    
    // 检查消息是否已经处理过
    if (is_message_processed($message_id)) {
        echo "消息已处理，跳过：{$message_id}\n";
        $resolver->ack();
        return;
    }
    
    try {
        // 处理消息
        process_message($message);
        
        // 标记消息已处理
        mark_message_processed($message_id);
        
        // 确认消息
        $resolver->ack();
        echo "消息处理成功：{$message_id}\n";
    } catch (Exception $e) {
        echo "消息处理失败：{$message_id}，错误：{$e->getMessage()}\n";
        // 拒绝消息，让服务器重新发送
        $resolver->nack();
    }
});
```

### 6.3 性能优化

- **批量处理**：对于大量消息，可以考虑批量处理以提高性能
- **合理设置并发数**：根据服务器资源和业务需求，合理设置Worker进程数
- **关闭调试模式**：生产环境中关闭调试模式，减少日志输出
- **优化心跳配置**：根据实际情况设置合适的心跳间隔，避免频繁的心跳通信

### 6.4 安全建议

- 使用SSL加密通信，保护数据安全
- 使用强密码，避免使用默认密码
- 限制Stomp服务器的访问IP，仅允许必要的IP访问
- 定期更新客户端和服务器软件，修复安全漏洞
- 对消息内容进行验证，避免恶意消息攻击

## 7. 常见问题

### 7.1 连接失败

**可能原因**：
- Stomp服务器未启动
- 服务器地址或端口错误
- 用户名或密码错误
- 网络连接问题
- 防火墙阻止了连接

**解决方案**：
- 检查Stomp服务器状态：`netstat -tlnp | grep 61613`
- 验证服务器地址、端口、用户名和密码是否正确
- 检查网络连接是否正常：`ping host`
- 配置防火墙规则，允许端口访问：`iptables -A INPUT -p tcp --dport 61613 -j ACCEPT`

### 7.2 消息发送失败

**可能原因**：
- 连接未建立或已断开
- 目的地不存在
- 权限不足
- 消息格式错误

**解决方案**：
- 检查连接状态：`$stomp->getState() == Client::STATE_ESTABLISHED`
- 验证目的地是否存在且有权限访问
- 检查消息格式是否符合要求
- 查看错误日志，定位具体问题

### 7.3 消息丢失

**可能原因**：
- 自动确认模式下，消息在发送到客户端前已被确认
- 客户端处理消息时崩溃，未确认消息
- 网络问题导致消息未到达客户端

**解决方案**：
- 使用手动确认模式，确保消息处理完成后再确认
- 实现消息处理的幂等性，允许重复处理消息
- 配置合适的消息持久化策略
- 定期检查消息队列的状态

### 7.4 性能问题

**可能原因**：
- 客户端处理消息速度慢，导致消息积压
- 并发连接数过多
- 心跳间隔设置不合理
- 调试模式未关闭

**解决方案**：
- 优化消息处理逻辑，提高处理速度
- 增加Worker进程数，提高并发处理能力
- 调整心跳间隔，减少网络通信量
- 生产环境中关闭调试模式

## 8. 代码示例

### 8.1 完整的消息生产者

```php
<?php
/**
 * Stomp消息生产者
 */

use Workerman\Worker;
use Workerman\Stomp\Client;

// 创建Worker实例
$worker = new Worker();

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端
    $stomp = new Client('stomp://127.0.0.1:61613', [
        'login' => 'admin',
        'passcode' => 'password',
        'heart_beat' => [5000, 5000]
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        echo "生产者连接成功！\n";
        
        // 定时发送消息
        Workerman\Timer::add(1, function() use ($stomp) {
            $message = json_encode([
                'id' => uniqid(),
                'content' => 'Hello Stomp!',
                'timestamp' => time(),
                'producer' => getmypid()
            ]);
            
            $stomp->send('/queue/test', $message, [
                'content-type' => 'application/json',
                'priority' => 5
            ]);
            
            echo "发送消息：{$message}\n";
        });
    };
    
    // 错误回调
    $stomp->onError = function($exception) {
        echo "生产者错误：{$exception->getMessage()}\n";
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

// 运行Worker
Worker::runAll();
```

### 8.2 完整的消息消费者

```php
<?php
/**
 * Stomp消息消费者
 */

use Workerman\Worker;
use Workerman\Stomp\Client;

// 创建Worker实例，设置4个进程
$worker = new Worker();
$worker->count = 4;

// 设置Worker启动回调
$worker->onWorkerStart = function() {
    // 创建Stomp客户端
    $stomp = new Client('stomp://127.0.0.1:61613', [
        'login' => 'admin',
        'passcode' => 'password',
        'heart_beat' => [5000, 5000]
    ]);
    
    // 连接成功回调
    $stomp->onConnect = function(Client $stomp) {
        $pid = getmypid();
        echo "消费者连接成功！进程ID：{$pid}\n";
        
        // 订阅消息队列，使用手动确认模式
        $stomp->subscribeWithAck('/queue/test', function(Client $stomp, $data, $resolver) use ($pid) {
            $message = json_decode($data['body'], true);
            $message_id = $data['headers']['message-id'];
            
            echo "消费者 {$pid} 收到消息：{$message_id}，内容：{$message['content']}\n";
            
            try {
                // 模拟处理消息
                usleep(500000); // 延迟500毫秒
                
                // 检查消息是否已经处理过
                if (check_message_processed($message_id)) {
                    echo "消费者 {$pid} 消息已处理，跳过：{$message_id}\n";
                    $resolver->ack();
                    return;
                }
                
                // 处理消息
                process_message($message);
                
                // 标记消息已处理
                mark_message_processed($message_id);
                
                // 确认消息
                $resolver->ack();
                echo "消费者 {$pid} 消息处理成功：{$message_id}\n";
            } catch (Exception $e) {
                echo "消费者 {$pid} 消息处理失败：{$message_id}，错误：{$e->getMessage()}\n";
                // 拒绝消息，让服务器重新发送
                $resolver->nack();
            }
        });
    };
    
    // 错误回调
    $stomp->onError = function($exception) {
        $pid = getmypid();
        echo "消费者 {$pid} 错误：{$exception->getMessage()}\n";
    };
    
    // 连接到Stomp服务器
    $stomp->connect();
};

/**
 * 检查消息是否已经处理过
 */
function check_message_processed($message_id) {
    // 实际应用中可以使用缓存或数据库
    static $processed_messages = [];
    return isset($processed_messages[$message_id]);
}

/**
 * 处理消息
 */
function process_message($message) {
    // 实际业务逻辑
    echo "处理消息：ID={$message['id']}, 内容={$message['content']}\n";
}

/**
 * 标记消息已处理
 */
function mark_message_processed($message_id) {
    // 实际应用中可以使用缓存或数据库
    static $processed_messages = [];
    $processed_messages[$message_id] = true;
    // 只保留最近1000条消息记录
    if (count($processed_messages) > 1000) {
        array_shift($processed_messages);
    }
}

// 运行Worker
Worker::runAll();
```

## 9. 总结

Stomp客户端组件是一个功能强大、易于使用的异步Stomp协议客户端，基于Workerman框架实现，支持高并发、自动重连、消息确认等功能。通过该组件，可以方便地与各种支持Stomp协议的消息中间件进行通信，构建高性能的异步消息处理系统。

在使用Stomp客户端组件时，建议遵循以下最佳实践：

- 合理配置连接参数和心跳机制
- 实现完善的错误处理和日志记录
- 使用手动确认模式确保消息可靠处理
- 确保消息处理的幂等性
- 生产环境中关闭调试模式
- 定期检查系统性能和消息队列状态

通过正确使用Stomp客户端组件，可以构建出稳定、可靠、高性能的消息处理系统，满足各种业务需求。