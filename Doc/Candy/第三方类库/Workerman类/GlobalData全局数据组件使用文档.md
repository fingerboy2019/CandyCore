# GlobalData 全局数据组件使用文档

## 1. 组件概述

GlobalData 是 Workerman 框架的一个全局数据共享组件，提供了跨进程、跨服务器的数据共享能力。它可以让多个 PHP 进程（甚至不同服务器上的进程）通过简单的 API 访问和修改同一套全局数据，类似于内存数据库的功能。

### 主要功能特点：
- 支持跨进程、跨服务器的数据共享
- 提供简单易用的 API（类似操作普通 PHP 对象）
- 支持分布式部署和负载均衡
- 支持数据的原子操作（如 CAS、increment）
- 自动连接管理和心跳检测
- 支持 Unix Socket 和 TCP 协议

### 典型应用场景：
- 分布式系统中的共享配置管理
- 多进程应用中的全局计数器
- 跨服务器的会话共享
- 实时在线用户数统计
- 分布式锁实现

## 2. 系统架构

GlobalData 采用典型的客户端-服务器（Client-Server）架构：

### Server 端
- 负责存储和管理全局数据
- 接收客户端请求并处理数据操作
- 支持 TCP 和 Unix Socket 两种传输方式
- 采用单进程模型，保证数据一致性

### Client 端
- 提供简洁的 API 供应用程序调用
- 负责与 Server 端建立和维护连接
- 支持连接多个 Server 节点实现负载均衡
- 自动进行错误处理和连接重试

## 3. 核心类与 API

### 3.1 Server 类

#### 构造函数
```php
/**
 * 创建 GlobalData 服务器
 * @param string $address 服务器监听地址，如 '0.0.0.0:2207' 或 'unix:///tmp/globaldata.sock'
 */
public function __construct($address = '0.0.0.0:2207');
```

#### 示例
```php
use GlobalData\Server;


// 创建服务器实例，监听 2207 端口
$server = new Server('0.0.0.0:2207');

// 启动服务器
Worker::runAll();
```

### 3.2 Client 类

#### 构造函数
```php
/**
 * 创建 GlobalData 客户端
 * @param array | string $servers 服务器地址，可以是单个地址或地址数组
 */
public function __construct($servers);
```

#### 属性
```php
/**
 * 连接超时时间（秒）
 * @var int
 */
public $timeout = 5;

/**
 * 心跳间隔时间（秒）
 * @var int
 */
public $pingInterval = 25;
```

#### 方法

##### 状态检查
```php
/**
 * 检查服务器连接状态
 * @return bool 连接成功返回 true，失败返回 false
 */
public function getStatus();
```

##### 魔术方法
```php
/**
 * 设置全局变量
 * @param string $key 变量名
 * @param mixed $value 变量值
 */
public function __set($key, $value);

/**
 * 获取全局变量
 * @param string $key 变量名
 * @return mixed 变量值
 */
public function __get($key);

/**
 * 检查全局变量是否存在
 * @param string $key 变量名
 * @return bool 存在返回 true，不存在返回 false
 */
public function __isset($key);

/**
 * 删除全局变量
 * @param string $key 变量名
 */
public function __unset($key);
```

##### 原子操作
```php
/**
 * 比较并交换（Compare And Swap）
 * @param string $key 变量名
 * @param mixed $old_value 旧值
 * @param mixed $new_value 新值
 * @return bool 交换成功返回 true，失败返回 false
 */
public function cas($key, $old_value, $new_value);

/**
 * 只有当变量不存在时才设置
 * @param string $key 变量名
 * @param mixed $value 变量值
 * @return bool 设置成功返回 true，变量已存在返回 false
 */
public function add($key, $value);

/**
 * 变量自增
 * @param string $key 变量名
 * @param int $step 步长，默认为 1
 * @return mixed 自增后的值
 */
public function increment($key, $step = 1);
```

## 4. 安装与配置

### 4.1 依赖要求
- PHP 5.3 及以上版本
- Workerman 框架（已包含在 Candy 框架中）

### 4.2 配置 Server 端

创建 `globaldata_server.php` 文件：

```php
use GlobalData\Server;
use Workerman\Worker;



// 创建服务器实例
$server = new Server('0.0.0.0:2207');

// 启动服务器
Worker::runAll();
```

启动服务器：
```bash
php globaldata_server.php start -d
```

### 4.3 配置 Client 端

在应用程序中创建客户端实例：

```php
use GlobalData\Client;



// 连接单个服务器
$global = new Client('127.0.0.1:2207');

// 或连接多个服务器（分布式部署）
// $global = new Client(array(
//     '192.168.1.100:2207',
//     '192.168.1.101:2207'
// ));
```

## 5. 使用示例

### 5.1 基本使用

```php
use GlobalData\Client;



// 创建客户端实例
$global = new Client('127.0.0.1:2207');

// 设置全局变量
$global->user_count = 100;

// 获取全局变量
echo $global->user_count; // 输出：100

// 修改全局变量
$global->user_count++;
echo $global->user_count; // 输出：101

// 检查变量是否存在
var_dump(isset($global->user_count)); // 输出：bool(true)

// 删除全局变量
unset($global->user_count);
var_dump(isset($global->user_count)); // 输出：bool(false)
```

### 5.2 原子操作示例

```php
// 只有当变量不存在时才设置
$global->add('unique_key', 'value');

// 原子自增操作
$global->increment('counter'); // 计数器加 1
$global->increment('counter', 10); // 计数器加 10

// 比较并交换（CAS）
$old_value = $global->counter;
$new_value = $old_value + 1;
if ($global->cas('counter', $old_value, $new_value)) {
    echo 'CAS 操作成功';
} else {
    echo 'CAS 操作失败，值已被其他进程修改';
}
```

### 5.3 分布式部署示例

```php
// 连接多个 GlobalData 服务器
$servers = array(
    '192.168.1.100:2207',
    '192.168.1.101:2207',
    '192.168.1.102:2207'
);

$global = new Client($servers);

// 客户端会根据 key 的哈希值自动选择对应的服务器节点
$global->user_1 = '张三';
$global->user_2 = '李四';
$global->user_3 = '王五';
```

### 5.4 在线用户统计示例

```php
use GlobalData\Client;



$global = new Client('127.0.0.1:2207');

// 用户登录时
function onUserLogin($user_id) {
    global $global;
    // 增加在线用户数
    $global->increment('online_users');
    // 记录用户登录状态
    $global->online_users[$user_id] = time();
}

// 用户登出时
function onUserLogout($user_id) {
    global $global;
    // 减少在线用户数
    $global->increment('online_users', -1);
    // 清除用户登录状态
    unset($global->online_users[$user_id]);
}

// 获取当前在线用户数
echo $global->online_users;
```

## 6. 高级特性

### 6.1 负载均衡

当连接多个 GlobalData 服务器时，Client 端会根据 key 的 CRC32 哈希值自动选择对应的服务器节点，实现负载均衡。这种方式确保了相同 key 的数据始终存储在同一个服务器上，避免了数据不一致的问题。

### 6.2 连接管理

Client 端会自动管理与服务器的连接：
- 自动建立连接
- 连接超时处理
- 心跳检测（默认每 25 秒发送一次心跳）
- 连接断开自动重连

### 6.3 错误处理

```php
try {
    $global = new Client('127.0.0.1:2207');
    $global->key = 'value';
} catch (Exception $e) {
    echo 'GlobalData 操作失败: ' . $e->getMessage();
}
```

## 7. 注意事项与最佳实践

### 7.1 数据一致性

- GlobalData 是单进程模型，同一服务器内的数据操作是原子的
- 跨服务器的数据操作不保证原子性
- 建议将相关数据存储在同一服务器节点上

### 7.2 性能优化

- 减少网络通信次数，尽量批量操作数据
- 对于频繁访问的数据，可以在本地建立缓存
- 合理设置超时时间，避免长时间阻塞
- 使用 Unix Socket 可以获得更好的性能（仅适用于同一台服务器）

### 7.3 数据持久化

- GlobalData 数据存储在内存中，服务器重启后数据会丢失
- 建议定期将重要数据持久化到数据库或文件中
- 可以使用 Workerman 的定时器实现定时备份

### 7.4 安全配置

- 限制 GlobalData 服务器的访问 IP
- 使用防火墙保护 GlobalData 服务端口
- 敏感数据传输时建议使用加密通道

## 8. 常见问题与解决方案

### 8.1 连接失败

**问题**：Client 无法连接到 Server

**解决方案**：
- 检查服务器是否正在运行
- 检查防火墙设置，确保端口开放
- 检查服务器监听地址是否正确
- 检查网络连接是否正常

### 8.2 数据不一致

**问题**：不同客户端读取到的数据不一致

**解决方案**：
- 确保相关数据存储在同一服务器节点
- 避免在不同节点间进行关联数据操作
- 使用 CAS 操作保证数据一致性

### 8.3 性能问题

**问题**：GlobalData 操作响应缓慢

**解决方案**：
- 减少网络延迟，将客户端和服务器部署在同一网络
- 使用 Unix Socket 替代 TCP 连接
- 增加服务器节点数量，分散负载
- 优化数据结构，减少数据传输量

### 8.4 服务器重启数据丢失

**问题**：GlobalData 服务器重启后数据丢失

**解决方案**：
- 实现数据定时备份机制
- 使用数据库作为持久化存储，GlobalData 作为缓存层
- 设计应用程序能够处理数据丢失的情况

## 9. 完整示例代码

### 9.1 Server 端代码

```php
<?php
/**
 * GlobalData 服务器示例
 */
use GlobalData\Server;
use Workerman\Worker;



// 创建服务器实例，监听 2207 端口
$server = new Server('0.0.0.0:2207');

// 设置服务器名称
$server->_worker->name = 'GlobalDataServer';

// 启动服务器
Worker::runAll();
```

### 9.2 Client 端代码

```php
<?php
/**
 * GlobalData 客户端示例
 */
use GlobalData\Client;



// 创建客户端实例
$global = new Client('127.0.0.1:2207');

// 基本操作示例
echo "=== 基本操作示例 ===\n";

// 设置全局变量
$global->site_name = 'Candy Framework';
$global->site_url = 'http://www.example.com';

// 获取全局变量
echo "网站名称: " . $global->site_name . "\n";
echo "网站地址: " . $global->site_url . "\n";

// 修改变量
$global->site_name = 'Candy Framework v2.0';
echo "更新后网站名称: " . $global->site_name . "\n";

// 检查变量是否存在
var_dump("site_name 存在: " . (isset($global->site_name) ? "是" : "否"));
var_dump("non_existent_key 存在: " . (isset($global->non_existent_key) ? "是" : "否"));

// 原子操作示例
echo "\n=== 原子操作示例 ===\n";

// 初始化计数器
$global->counter = 0;

// 原子自增
$global->increment('counter');
$global->increment('counter', 5);
echo "计数器值: " . $global->counter . "\n";

// CAS 操作示例
$old_value = $global->counter;
$new_value = $old_value + 1;
if ($global->cas('counter', $old_value, $new_value)) {
    echo "CAS 操作成功，新值: " . $global->counter . "\n";
} else {
    echo "CAS 操作失败\n";
}

// 数组操作示例
echo "\n=== 数组操作示例 ===\n";

// 设置数组
$global->users = array(
    array('id' => 1, 'name' => '张三'),
    array('id' => 2, 'name' => '李四')
);

// 修改数组元素
$global->users[0]['name'] = '张三(修改后)';

// 添加数组元素
$global->users[] = array('id' => 3, 'name' => '王五');

// 输出数组内容
print_r($global->users);

// 删除变量示例
echo "\n=== 删除变量示例 ===\n";

// 删除单个变量
unset($global->site_url);
var_dump("site_url 存在: " . (isset($global->site_url) ? "是" : "否"));

// 清空所有变量
unset($global->site_name, $global->counter, $global->users);
echo "所有变量已删除\n";
```

## 10. 总结

GlobalData 是一个功能强大且易于使用的全局数据共享组件，它为 PHP 应用程序提供了跨进程、跨服务器的数据共享能力。通过简单的 API，开发者可以轻松实现分布式系统中的数据共享和同步，而无需关心底层的网络通信和数据一致性问题。

无论是构建简单的多进程应用，还是复杂的分布式系统，GlobalData 都能提供可靠的数据共享服务，帮助开发者提高开发效率和系统性能。