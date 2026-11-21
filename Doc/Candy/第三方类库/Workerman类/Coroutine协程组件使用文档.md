# Coroutine 协程组件使用文档

## 1. 组件概述

Workerman 的 Coroutine 组件提供了轻量级的协程支持，允许开发者编写异步代码但使用同步的编程风格，避免了回调地狱，提高了代码的可读性和可维护性。

### 1.1 主要功能

- 支持多种协程驱动（Fiber、Swoole、Swow）
- 提供协程间通信 Channel
- 提供协程同步工具（WaitGroup、Barrier）
- 支持并行执行任务
- 提供协程池管理

### 1.2 应用场景

- 高并发网络编程
- 数据库查询优化
- API 网关开发
- 微服务架构
- 需要高并发但低资源消耗的场景

## 2. 安装与配置

### 2.1 安装

Coroutine 组件是 Workerman 的核心组件，无需额外安装。

### 2.2 依赖要求

- PHP 8.1+（Fiber 驱动需要 PHP 8.1+）
- Swoole 扩展（如果使用 Swoole 驱动）
- Swow 扩展（如果使用 Swow 驱动）

## 3. 快速入门

### 3.1 创建基本协程

```php
<?php
use Workerman\Coroutine;

// 创建一个协程
$coroutine = new Coroutine(function() {
    echo "协程开始执行\n";
    // 协程暂停 1 秒
    Coroutine::sleep(1);
    echo "协程执行完毕\n";
});

// 或者使用静态方法创建
Coroutine::create(function() {
    echo "静态方法创建协程\n";
});
```

### 3.2 协程间通信

```php
<?php
use Workerman\Coroutine;
use Workerman\Coroutine\Channel;

// 创建一个容量为 1 的通道
$channel = new Channel(1);

// 创建生产者协程
Coroutine::create(function() use ($channel) {
    for ($i = 0; $i < 5; $i++) {
        echo "生产数据: {$i}\n";
        $channel->push($i);
        Coroutine::sleep(0.5);
    }
    // 发送 null 表示生产完毕
    $channel->push(null);
});

// 创建消费者协程
Coroutine::create(function() use ($channel) {
    while (true) {
        $data = $channel->pop();
        if ($data === null) {
            break;
        }
        echo "消费数据: {$data}\n";
    }
    echo "消费完毕\n";
});
```

## 4. API 参考

### 4.1 Coroutine 类

#### 4.1.1 构造函数

```php
public function __construct(callable $callable)
```

创建一个新的协程实例。

**参数：**
- `$callable` - 协程要执行的回调函数

#### 4.1.2 create()

```php
public static function create(callable $callable, ...$args): CoroutineInterface
```

静态方法创建协程并立即启动。

**参数：**
- `$callable` - 协程要执行的回调函数
- `$args` - 传递给回调函数的参数

**返回值：**
- CoroutineInterface 实例

#### 4.1.3 start()

```php
public function start(mixed ...$args): mixed
```

启动协程。

**参数：**
- `$args` - 传递给协程回调的参数

**返回值：**
- 协程执行结果

#### 4.1.4 resume()

```php
public function resume(mixed ...$args): mixed
```

恢复协程执行。

**参数：**
- `$args` - 传递给协程的值

**返回值：**
- 协程执行结果

#### 4.1.5 id()

```php
public function id(): int
```

获取协程 ID。

**返回值：**
- 协程 ID

#### 4.1.6 defer()

```php
public static function defer(callable $callable): void
```

注册一个延迟回调函数，在协程退出时执行。

**参数：**
- `$callable` - 延迟执行的回调函数

#### 4.1.7 suspend()

```php
public static function suspend(mixed $value = null): mixed
```

暂停协程执行。

**参数：**
- `$value` - 传递给 resume() 方法的值

**返回值：**
- resume() 方法传递的值

#### 4.1.8 getCurrent()

```php
public static function getCurrent(): CoroutineInterface
```

获取当前协程实例。

**返回值：**
- 当前协程实例

#### 4.1.9 isCoroutine()

```php
public static function isCoroutine(): bool
```

检查当前是否在协程上下文中。

**返回值：**
- bool - 如果在协程上下文中返回 true，否则返回 false

### 4.2 Channel 类

#### 4.2.1 构造函数

```php
public function __construct(int $capacity = 1)
```

创建一个新的通道实例。

**参数：**
- `$capacity` - 通道容量，默认值为 1

#### 4.2.2 push()

```php
public function push(mixed $data, float $timeout = -1): bool
```

向通道推送数据。

**参数：**
- `$data` - 要推送的数据
- `$timeout` - 超时时间（秒），-1 表示永不超时

**返回值：**
- bool - 推送成功返回 true，超时返回 false

#### 4.2.3 pop()

```php
public function pop(float $timeout = -1): mixed
```

从通道弹出数据。

**参数：**
- `$timeout` - 超时时间（秒），-1 表示永不超时

**返回值：**
- mixed - 弹出的数据，如果超时返回 false

#### 4.2.4 length()

```php
public function length(): int
```

获取通道中的数据数量。

**返回值：**
- int - 通道中的数据数量

#### 4.2.5 getCapacity()

```php
public function getCapacity(): int
```

获取通道容量。

**返回值：**
- int - 通道容量

#### 4.2.6 close()

```php
public function close(): void
```

关闭通道。

## 5. 高级功能

### 5.1 WaitGroup

WaitGroup 用于等待一组协程完成。

```php
<?php
use Workerman\Coroutine;
use Workerman\Coroutine\WaitGroup;

$wg = new WaitGroup();

// 创建 5 个协程
for ($i = 0; $i < 5; $i++) {
    $wg->add();
    Coroutine::create(function() use ($wg, $i) {
        echo "协程 {$i} 开始执行\n";
        Coroutine::sleep(1);
        echo "协程 {$i} 执行完毕\n";
        $wg->done();
    });
}

// 等待所有协程完成
echo "等待所有协程完成...\n";
$wg->wait();
echo "所有协程执行完毕\n";
```

### 5.2 Barrier

Barrier 用于同步多个协程，直到所有协程都到达屏障点。

```php
<?php
use Workerman\Coroutine;
use Workerman\Coroutine\Barrier;

// 创建一个屏障，需要 3 个协程到达
$barrier = new Barrier(3);

for ($i = 0; $i < 3; $i++) {
    Coroutine::create(function() use ($barrier, $i) {
        echo "协程 {$i} 执行任务...\n";
        Coroutine::sleep($i);
        echo "协程 {$i} 到达屏障\n";
        // 等待所有协程到达屏障
        $barrier->wait();
        echo "协程 {$i} 继续执行\n";
    });
}
```

### 5.3 并行执行

```php
<?php
use Workerman\Coroutine;
use Workerman\Coroutine\Parallel;

$parallel = new Parallel(3); // 最多同时执行 3 个任务

// 添加任务
$parallel->add(function() {
    Coroutine::sleep(2);
    return "任务 1 完成";
});

$parallel->add(function() {
    Coroutine::sleep(1);
    return "任务 2 完成";
});

$parallel->add(function() {
    Coroutine::sleep(3);
    return "任务 3 完成";
});

// 获取执行结果
$results = $parallel->wait();

foreach ($results as $result) {
    echo $result . "\n";
}
```

### 5.4 协程池

```php
<?php
use Workerman\Coroutine;
use Workerman\Coroutine\Pool;

// 创建一个容量为 5 的协程池
$pool = new Pool(5);

// 提交 10 个任务
for ($i = 0; $i < 10; $i++) {
    $pool->submit(function() use ($i) {
        echo "执行任务 {$i}\n";
        Coroutine::sleep(1);
        return "任务 {$i} 完成";
    });
}

// 关闭协程池
$pool->close();
```

## 6. 最佳实践

### 6.1 错误处理

```php
<?php
use Workerman\Coroutine;

Coroutine::create(function() {
    try {
        // 可能抛出异常的代码
        throw new Exception("测试异常");
    } catch (Exception $e) {
        echo "捕获到异常: " . $e->getMessage() . "\n";
    }
});
```

### 6.2 数据库操作

```php
<?php
use Workerman\Coroutine;
use Workerman\MySQL\Connection;

// 创建数据库连接
$db = new Connection('localhost', 3306, 'username', 'password', 'dbname');

Coroutine::create(function() use ($db) {
    // 执行查询
    $result = $db->query('SELECT * FROM users WHERE id = ?', [1]);
    echo "查询结果: " . json_encode($result) . "\n";
});
```

### 6.3 HTTP 请求

```php
<?php
use Workerman\Coroutine;
use Workerman\Coroutine\HttpClient;

Coroutine::create(function() {
    $http = new HttpClient();
    // 发送 GET 请求
    $response = $http->get('https://www.example.com');
    echo "HTTP 状态码: " . $response->statusCode . "\n";
    echo "响应内容: " . $response->body . "\n";
});
```

## 7. 常见问题与解决方案

### 7.1 协程嵌套问题

**问题：** 协程嵌套过多导致性能问题。

**解决方案：** 合理设计协程结构，避免过深的嵌套，使用协程池管理协程数量。

### 7.2 资源泄露

**问题：** 协程异常退出导致资源未释放。

**解决方案：** 使用 try-finally 块确保资源释放，或使用 Coroutine::defer() 注册清理函数。

```php
Coroutine::create(function() {
    $resource = fopen('file.txt', 'r');
    
    Coroutine::defer(function() use ($resource) {
        fclose($resource);
    });
    
    // 协程执行代码
});
```

### 7.3 死锁问题

**问题：** 协程间相互等待导致死锁。

**解决方案：** 避免循环依赖，使用超时机制，合理设计同步策略。

## 8. 性能优化

1. **合理设置协程数量**：避免创建过多协程，使用协程池控制数量。
2. **减少上下文切换**：尽量在协程中处理更多逻辑，减少切换次数。
3. **使用非阻塞 IO**：确保所有 IO 操作都是非阻塞的。
4. **避免长时间运行的协程**：将长时间运行的任务拆分为多个短任务。
5. **使用适当的驱动**：根据实际需求选择合适的协程驱动（Fiber、Swoole、Swow）。

## 9. 版本历史

- **v1.0.0**：初始版本，支持 Fiber 驱动。
- **v1.1.0**：增加 Swoole 和 Swow 驱动支持。
- **v1.2.0**：增加 Channel、WaitGroup、Barrier 等同步工具。
- **v1.3.0**：增加并行执行和协程池功能。

## 10. 总结

Coroutine 组件为 Workerman 提供了强大的协程支持，允许开发者以同步的方式编写异步代码，提高了代码的可读性和可维护性，同时保持了高并发性能。通过合理使用协程和相关同步工具，可以构建高效、可扩展的应用程序。