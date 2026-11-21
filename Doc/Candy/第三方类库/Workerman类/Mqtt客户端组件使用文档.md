# MQTT客户端组件使用文档

## 1. 组件概述

MQTT客户端组件是基于Workerman框架实现的轻量级MQTT客户端，支持MQTT 3.1/3.1.1/5.0协议，提供了完整的MQTT客户端功能，包括连接、订阅、发布、断开连接等操作。

### 1.1 主要特性

- 支持MQTT 3.1/3.1.1/5.0协议
- 支持QoS 0/1/2消息质量级别
- 支持自动重连和订阅恢复
- 支持WebSocket连接（ws/wss协议）
- 支持SSL/TLS加密连接
- 支持遗嘱消息（Will Message）
- 支持心跳包机制
- 支持MQTT 5.0特性（如属性、主题别名等）

### 1.2 应用场景

- 物联网设备通信
- 实时消息推送系统
- 传感器数据采集
- 智能家居控制系统
- 实时监控系统
- 移动应用消息推送

## 2. 安装与配置

### 2.1 依赖要求

- PHP 7.2+
- Workerman框架

### 2.2 引入组件

```php
use Workerman\Mqtt\Client;
use Workerman\Worker;
```

### 2.3 基本配置

MQTT客户端支持以下配置选项：

| 配置项 | 类型 | 默认值 | 说明 |
|-------|------|--------|------|
| clean_session | int | 1 | 清理会话标志，0表示保留离线消息 |
| username | string | '' | 用户名 |
| password | string | '' | 密码 |
| keepalive | int | 50 | 心跳间隔时间（秒），0表示禁用 |
| protocol_name | string | 'MQTT' | 协议名称，可选MQTT或MQIsdp |
| protocol_level | int | 4 | 协议级别，MQTT 3.1.1为4，MQTT 5.0为5 |
| reconnect_period | int | 1 | 重连间隔时间（秒），0表示禁用 |
| connect_timeout | int | 30 | 连接超时时间（秒） |
| resubscribe | bool | true | 重连后是否自动重新订阅主题 |
| bindto | string | '' | 绑定的本地IP地址和端口 |
| ssl | bool/array | false | SSL配置，参考PHP SSL上下文 |
| debug | bool | false | 是否开启调试模式 |
| properties | array | [] | MQTT 5.0属性配置 |
| uri | string | '/mqtt' | WebSocket连接时的URI路径 |

## 3. 核心功能

### 3.1 客户端状态

客户端支持以下状态：

- `STATE_INITIAL` (1): 初始状态
- `STATE_CONNECTING` (2): 连接中
- `STATE_WAITCONACK` (3): 等待连接确认
- `STATE_ESTABLISHED` (4): 连接已建立
- `STATE_DISCONNECT` (5): 已断开连接

### 3.2 事件回调

客户端提供以下事件回调：

- `onConnect`: 连接成功时触发
- `onReconnect`: 重连成功时触发
- `onMessage`: 接收消息时触发
- `onClose`: 连接关闭时触发
- `onError`: 发生错误时触发

### 3.3 消息质量级别

支持三种消息质量级别：

- QoS 0: 最多一次（At most once）
- QoS 1: 至少一次（At least once）
- QoS 2: 刚好一次（Exactly once）

## 4. API参考

### 4.1 构造函数

```php
public function __construct(string $address, array $options = [])
```

创建MQTT客户端实例。

**参数：**
- `address`: MQTT服务器地址，格式为 `mqtt://host:port` 或 `ws://host:port`
- `options`: 客户端配置选项

**示例：**
```php
$client = new Client('mqtt://broker.emqx.io:1883', [
    'username' => 'test',
    'password' => 'test',
    'client_id' => 'candy-client-123',
]);
```

### 4.2 connect()

```php
public function connect(): void
```

连接到MQTT服务器。

**示例：**
```php
$client->connect();
```

### 4.3 subscribe()

```php
public function subscribe(array|string $topic, array $options = [], ?callable $callback = null, array $properties = []): void
```

订阅一个或多个主题。

**参数：**
- `topic`: 主题名称或主题数组，格式为 `['topic1' => qos1, 'topic2' => qos2]`
- `options`: 订阅选项，如qos等
- `callback`: 订阅确认回调函数
- `properties`: MQTT 5.0属性

**示例：**
```php
// 订阅单个主题
$client->subscribe('sensor/temperature', ['qos' => 1], function ($exception, $granted) {
    if ($exception) {
        echo "订阅失败: {$exception->getMessage()}"
    } else {
        foreach ($granted as $topic => $qos) {
            echo "订阅成功，主题: $topic, QoS: $qos"
        }
    }
});

// 订阅多个主题
$client->subscribe([
    'sensor/temperature' => 1,
    'sensor/humidity' => 2
], [], function ($exception, $granted) {
    // 处理订阅结果
});
```

### 4.4 unsubscribe()

```php
public function unsubscribe(array|string $topic, ?callable $callback = null, array $properties = []): void
```

取消订阅一个或多个主题。

**参数：**
- `topic`: 主题名称或主题数组
- `callback`: 取消订阅确认回调函数
- `properties`: MQTT 5.0属性

**示例：**
```php
// 取消订阅单个主题
$client->unsubscribe('sensor/temperature', function () {
    echo "取消订阅成功"
});

// 取消订阅多个主题
$client->unsubscribe(['sensor/temperature', 'sensor/humidity']);
```

### 4.5 publish()

```php
public function publish($topic, $content, $options = [], $callback = null, $properties = []): void
```

发布消息到指定主题。

**参数：**
- `topic`: 主题名称
- `content`: 消息内容
- `options`: 发布选项，包括qos、retain、dup等
- `callback`: 消息确认回调函数（仅QoS 1/2有效）
- `properties`: MQTT 5.0属性

**示例：**
```php
// 发布QoS 0消息
$client->publish('sensor/temperature', '25.5');

// 发布QoS 1消息，并接收确认
$client->publish('sensor/temperature', '25.5', ['qos' => 1], function () {
    echo "消息发布成功并确认"
});

// 发布带保留标志的消息
$client->publish('sensor/temperature', '25.5', ['retain' => 1]);
```

### 4.6 disconnect()

```php
public function disconnect(int $code = ReasonCodeConst::NORMAL_DISCONNECTION, array $properties = []): void
```

断开与MQTT服务器的连接。

**参数：**
- `code`: 断开连接原因代码
- `properties`: MQTT 5.0属性

**示例：**
```php
$client->disconnect();
```

### 4.7 close()

```php
public function close(): void
```

关闭MQTT连接。

**示例：**
```php
$client->close();
```

### 4.8 reconnect()

```php
public function reconnect(int $after = 0): void
```

重新连接到MQTT服务器。

**参数：**
- `after`: 延迟重连时间（秒）

**示例：**
```php
$client->reconnect(5); // 5秒后重连
```

## 5. 使用示例

### 5.1 基本示例：连接并订阅消息

```php
<?php
use Workerman\Mqtt\Client;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// 创建MQTT客户端
$client = new Client('mqtt://broker.emqx.io:1883', [
    'client_id' => 'candy-mqtt-client-' . rand(),
]);

// 连接成功回调
$client->onConnect = function (Client $client) {
    echo "连接成功\n";
    // 订阅主题
    $client->subscribe('test/topic', ['qos' => 1], function ($exception, $granted) {
        if ($exception) {
            echo "订阅失败: {$exception->getMessage()}\n";
            return;
        }
        foreach ($granted as $topic => $qos) {
            echo "订阅成功，主题: $topic, QoS: $qos\n";
        }
    });
};

// 接收消息回调
$client->onMessage = function (string $topic, string $content, Client $client) {
    echo "收到消息 - 主题: $topic, 内容: $content\n";
};

// 连接关闭回调
$client->onClose = function () {
    echo "连接已关闭\n";
};

// 连接错误回调
$client->onError = function (string $error) {
    echo "连接错误: $error\n";
};

// 连接到MQTT服务器
$client->connect();

// 运行Worker
Worker::runAll();
```

### 5.2 发布消息示例

```php
<?php
use Workerman\Mqtt\Client;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// 创建MQTT客户端
$client = new Client('mqtt://broker.emqx.io:1883', [
    'client_id' => 'candy-mqtt-publisher-' . rand(),
]);

// 连接成功回调
$client->onConnect = function (Client $client) {
    echo "连接成功，开始发布消息\n";
    
    // 发布QoS 1消息
    $client->publish('test/topic', 'Hello MQTT from Candy Framework!', ['qos' => 1], function () {
        echo "消息发布成功并确认\n";
        // 发布完成后断开连接
        $this->disconnect();
    });
};

// 连接到MQTT服务器
$client->connect();

// 运行Worker
Worker::runAll();
```

### 5.3 使用WebSocket连接示例

```php
<?php
use Workerman\Mqtt\Client;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// 创建WebSocket连接的MQTT客户端
$client = new Client('ws://broker.emqx.io:8083/mqtt', [
    'client_id' => 'candy-websocket-client-' . rand(),
    'protocol_level' => 4,
]);

$client->onConnect = function (Client $client) {
    echo "WebSocket连接成功\n";
    $client->subscribe('test/websocket', ['qos' => 0]);
};

$client->onMessage = function (string $topic, string $content, Client $client) {
    echo "收到WebSocket消息 - 主题: $topic, 内容: $content\n";
};

$client->connect();
Worker::runAll();
```

### 5.4 使用SSL/TLS加密连接

```php
<?php
use Workerman\Mqtt\Client;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// 创建SSL连接的MQTT客户端
$client = new Client('mqtt://broker.emqx.io:8883', [
    'client_id' => 'candy-ssl-client-' . rand(),
    'ssl' => true, // 启用SSL
]);

$client->onConnect = function (Client $client) {
    echo "SSL连接成功\n";
    $client->subscribe('test/ssl', ['qos' => 1]);
};

$client->onMessage = function (string $topic, string $content, Client $client) {
    echo "收到SSL消息 - 主题: $topic, 内容: $content\n";
};

$client->connect();
Worker::runAll();
```

### 5.5 使用遗嘱消息

```php
<?php
use Workerman\Mqtt\Client;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// 创建带遗嘱消息的MQTT客户端
$client = new Client('mqtt://broker.emqx.io:1883', [
    'client_id' => 'candy-will-client-' . rand(),
    'will' => [
        'topic' => 'device/status',
        'content' => json_encode(['status' => 'offline', 'device_id' => 'device-123']),
        'qos' => 1,
        'retain' => 1
    ]
]);

$client->onConnect = function (Client $client) {
    echo "连接成功，遗嘱消息已设置\n";
    // 发布在线状态
    $client->publish('device/status', json_encode(['status' => 'online', 'device_id' => 'device-123']), ['qos' => 1, 'retain' => 1]);
};

$client->connect();
Worker::runAll();
```

## 6. MQTT 5.0特性使用

### 6.1 支持MQTT 5.0协议

```php
<?php
use Workerman\Mqtt\Client;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// 创建MQTT 5.0客户端
$client = new Client('mqtt://broker.emqx.io:1883', [
    'client_id' => 'candy-mqtt5-client-' . rand(),
    'protocol_level' => 5, // 使用MQTT 5.0协议
    'properties' => [
        'session_expiry_interval' => 3600, // 会话过期时间1小时
        'receive_maximum' => 1000, // 最大接收消息数
    ]
]);

$client->onConnect = function (Client $client) {
    echo "MQTT 5.0连接成功\n";
    
    // 使用MQTT 5.0属性订阅主题
    $client->subscribe('mqtt5/topic', ['qos' => 1], null, [
        'subscription_identifier' => 123
    ]);
    
    // 发布带属性的消息
    $client->publish('mqtt5/topic', 'Hello MQTT 5.0!', ['qos' => 1], null, [
        'message_expiry_interval' => 60, // 消息过期时间60秒
        'content_type' => 'text/plain', // 内容类型
        'response_topic' => 'mqtt5/response' // 响应主题
    ]);
};

$client->connect();
Worker::runAll();
```

## 7. 高级用法

### 7.1 自动重连与订阅恢复

客户端默认启用自动重连和订阅恢复功能，可以通过配置选项控制：

```php
$client = new Client('mqtt://broker.emqx.io:1883', [
    'reconnect_period' => 5, // 重连间隔5秒
    'resubscribe' => true, // 自动重新订阅主题
]);
```

### 7.2 使用心跳包机制

客户端默认启用心跳包机制，可以通过配置选项控制：

```php
$client = new Client('mqtt://broker.emqx.io:1883', [
    'keepalive' => 30, // 心跳间隔30秒
]);
```

### 7.3 调试模式

启用调试模式可以查看详细的通信过程：

```php
$client = new Client('mqtt://broker.emqx.io:1883', [
    'debug' => true, // 启用调试模式
]);
```

## 8. 注意事项

### 8.1 客户端ID唯一性

每个MQTT客户端必须有唯一的客户端ID，否则会导致连接冲突。建议使用随机数或设备唯一标识生成客户端ID。

### 8.2 主题命名规范

- 主题名称区分大小写
- 主题可以包含字母、数字、斜杠(/)、加号(+)和井号(#)
- 加号(+)表示单级通配符
- 井号(#)表示多级通配符，只能放在主题末尾

### 8.3 QoS级别选择

- QoS 0: 最快但不可靠，适合实时性要求高但丢失少量消息可接受的场景
- QoS 1: 可靠但可能重复，适合需要确保消息到达但少量重复可接受的场景
- QoS 2: 最可靠但最慢，适合需要确保消息仅到达一次的场景

### 8.4 性能优化建议

- 根据实际需求选择合适的QoS级别
- 合理设置心跳间隔，避免过于频繁的心跳包
- 对于大量消息，考虑使用批量处理
- 避免订阅过于宽泛的主题
- 合理设置会话过期时间

### 8.5 安全注意事项

- 使用SSL/TLS加密连接保护数据传输
- 不要在代码中硬编码用户名和密码
- 定期更换认证信息
- 限制客户端的订阅和发布权限
- 启用访问控制列表（ACL）

## 9. 故障排除

### 9.1 连接失败

常见原因：
- MQTT服务器地址或端口错误
- 网络连接问题
- 防火墙限制
- 认证失败（用户名/密码错误）
- 客户端ID已存在

解决方法：
- 检查服务器地址和端口是否正确
- 检查网络连接是否正常
- 检查防火墙设置
- 确认用户名和密码是否正确
- 使用唯一的客户端ID

### 9.2 消息接收不到

常见原因：
- 订阅主题错误
- QoS级别不匹配
- 网络连接不稳定
- 服务器端过滤了消息
- 客户端断开连接

解决方法：
- 检查订阅主题是否正确
- 确认QoS级别设置是否合理
- 检查网络连接状态
- 查看服务器日志
- 启用自动重连功能

### 9.3 消息发送失败

常见原因：
- 主题名称错误
- 权限不足
- 网络连接断开
- QoS确认超时

解决方法：
- 检查主题名称是否正确
- 确认客户端有发布权限
- 检查网络连接状态
- 增加超时时间设置

## 10. 代码参考

### 10.1 核心文件

- `/www/wwwroot/candy/Candy/Vendor/Workerman/Mqtt/Client.php` - 客户端主要实现
- `/www/wwwroot/candy/Candy/Vendor/Workerman/Mqtt/Consts/MQTTConst.php` - MQTT常量定义
- `/www/wwwroot/candy/Candy/Vendor/Workerman/Mqtt/Protocols/Mqtt.php` - MQTT协议实现
- `/www/wwwroot/candy/Candy/Vendor/Workerman/Mqtt/Protocols/Mqtt5.php` - MQTT 5.0协议实现
- `/www/wwwroot/candy/Candy/Vendor/Workerman/Mqtt/Protocols/Ws.php` - WebSocket协议实现

### 10.2 相关常量

```php
// 连接状态
Client::STATE_INITIAL
Client::STATE_CONNECTING
Client::STATE_WAITCONACK
Client::STATE_ESTABLISHED
Client::STATE_DISCONNECT

// MQTT命令
MQTTConst::CMD_CONNECT
MQTTConst::CMD_CONNACK
MQTTConst::CMD_PUBLISH
MQTTConst::CMD_PUBACK
MQTTConst::CMD_PUBREC
MQTTConst::CMD_PUBREL
MQTTConst::CMD_PUBCOMP
MQTTConst::CMD_SUBSCRIBE
MQTTConst::CMD_SUBACK
MQTTConst::CMD_UNSUBSCRIBE
MQTTConst::CMD_UNSUBACK
MQTTConst::CMD_PINGREQ
MQTTConst::CMD_PINGRESP
MQTTConst::CMD_DISCONNECT
MQTTConst::CMD_AUTH
```

## 11. 总结

MQTT客户端组件提供了完整的MQTT协议实现，支持多种MQTT版本和特性，适用于各种物联网和实时通信场景。通过简单的API调用，开发者可以快速实现MQTT客户端功能，包括连接、订阅、发布等操作。组件还提供了丰富的配置选项和回调函数，方便开发者根据实际需求进行定制和扩展。

在使用MQTT客户端时，建议根据实际场景选择合适的QoS级别、合理设置心跳间隔、确保客户端ID唯一性，并注意安全配置，以保证通信的可靠性和安全性。

通过结合Candy框架和MQTT客户端组件，开发者可以快速构建高性能、可靠的物联网应用和实时消息系统。