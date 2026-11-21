# Revolt 事件循环库使用文档

## 1. 简介

Revolt 是一个现代的 PHP 事件循环库，提供了异步编程的基础架构。它允许开发者编写非阻塞的代码，提高应用程序的并发处理能力和响应性能。

### 核心特性

- **事件驱动**：基于事件循环的异步编程模型
- **纤维支持**：原生支持 PHP 8.1+ 的纤维(Fibers)功能
- **多种驱动**：支持多种底层实现（如 StreamSelectDriver）
- **丰富的事件类型**：支持微任务、延迟执行、重复执行、流监控、信号处理等
- **灵活的回调管理**：支持启用/禁用/取消回调，以及引用计数机制

## 2. 快速开始

### 基本用法

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Revolt\EventLoop;

// 延迟执行回调
EventLoop::defer(function () {
    echo "延迟执行的回调\n";
});

// 延迟 1 秒执行
$timerId = EventLoop::delay(1.0, function () {
    echo "1 秒后执行的回调\n";
});

// 每 2 秒重复执行
$repeatId = EventLoop::repeat(2.0, function () {
    echo "每 2 秒重复执行的回调\n";
});

// 运行事件循环
EventLoop::run();
```

## 3. 核心功能

### 3.1 微任务和延迟执行

#### 微任务队列

微任务会立即执行，在事件循环获得控制权后立即运行：

```php
// 队列一个微任务
EventLoop::queue(function () {
    echo "微任务将立即执行\n";
});
```

#### 延迟执行

延迟执行会在当前事件循环周期结束后执行：

```php
// 延迟执行
$callbackId = EventLoop::defer(function () {
    echo "当前事件循环周期结束后执行\n";
});
```

### 3.2 定时器

#### 一次性定时器

在指定时间后执行一次：

```php
// 1 秒后执行一次
$timerId = EventLoop::delay(1.0, function () {
    echo "1 秒后执行\n";
});
```

#### 重复定时器

每隔指定时间重复执行：

```php
// 每 0.5 秒执行一次
$timerId = EventLoop::repeat(0.5, function ($id) {
    echo "每 0.5 秒执行\n";
    
    // 执行 10 次后取消
    static $count = 0;
    if (++$count >= 10) {
        EventLoop::cancel($id);
    }
});
```

### 3.3 流资源监控

#### 可读流监控

当流资源可读时执行回调：

```php
$socket = stream_socket_client('tcp://example.com:80');

// 当 socket 可读时执行
$callbackId = EventLoop::onReadable($socket, function ($id, $stream) {
    $data = fread($stream, 8192);
    if ($data === false || $data === '') {
        EventLoop::cancel($id);
        fclose($stream);
        return;
    }
    echo "收到数据: $data\n";
});
```

#### 可写流监控

当流资源可写时执行回调：

```php
$socket = stream_socket_client('tcp://example.com:80');

// 当 socket 可写时执行
$callbackId = EventLoop::onWritable($socket, function ($id, $stream) {
    fwrite($stream, "GET / HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n");
    EventLoop::cancel($id);
    
    // 开始监控可读
    EventLoop::onReadable($stream, function ($readId, $readStream) {
        // 处理响应
    });
});
```

### 3.4 信号处理

当收到指定信号时执行回调：

```php
// 当收到 SIGINT 信号（Ctrl+C）时执行
$callbackId = EventLoop::onSignal(SIGINT, function () {
    echo "收到 SIGINT 信号，正在优雅退出...\n";
    EventLoop::stop();
});
```

### 3.5 回调管理

#### 启用/禁用回调

```php
$callbackId = EventLoop::repeat(1.0, function () {
    echo "执行回调\n";
});

// 禁用回调
EventLoop::disable($callbackId);

// 启用回调
EventLoop::enable($callbackId);
```

#### 取消回调

```php
$callbackId = EventLoop::delay(5.0, function () {
    echo "这个回调不会执行\n";
});

// 取消回调
EventLoop::cancel($callbackId);
```

#### 引用/取消引用

引用的回调会保持事件循环运行，取消引用的回调不会：

```php
$callbackId = EventLoop::repeat(1.0, function () {
    echo "执行回调\n";
});

// 取消引用，事件循环可能会在没有其他引用回调时退出
EventLoop::unreference($callbackId);

// 重新引用
EventLoop::reference($callbackId);
```

### 3.6 错误处理

设置全局错误处理器：

```php
// 设置错误处理器
EventLoop::setErrorHandler(function (Throwable $error) {
    echo "发生错误: " . $error->getMessage() . "\n";
});

// 触发一个错误
EventLoop::defer(function () {
    throw new Exception("测试错误");
});
```

## 4. 纤维(Fibers)支持

Revolt 原生支持 PHP 8.1+ 的纤维功能，可以实现协程式的异步编程：

### 暂停和恢复

```php
$suspension = EventLoop::getSuspension();

// 在另一个回调中恢复执行
EventLoop::delay(1.0, function () use ($suspension) {
    $suspension->resume("Hello from delay!");
});

// 暂停当前纤维的执行
$result = $suspension->suspend();

echo "恢复执行，结果: $result\n";
```

### 错误抛出

```php
$suspension = EventLoop::getSuspension();

// 在另一个回调中抛出错误
EventLoop::delay(1.0, function () use ($suspension) {
    $suspension->throw(new Exception("发生错误"));
});

try {
    // 暂停当前纤维的执行
    $result = $suspension->suspend();
} catch (Exception $e) {
    echo "捕获到错误: " . $e->getMessage() . "\n";
}
```

## 5. 事件循环控制

### 运行事件循环

```php
// 运行事件循环
EventLoop::run();
```

### 停止事件循环

```php
// 停止事件循环
EventLoop::stop();
```

### 检查事件循环状态

```php
// 检查事件循环是否正在运行
if (EventLoop::isRunning()) {
    echo "事件循环正在运行\n";
}
```

## 6. 高级用法

### 自定义驱动程序

Revolt 支持自定义驱动程序，需要实现 `Revolt\EventLoop\Driver` 接口：

```php
use Revolt\EventLoop\Driver;

class CustomDriver implements Driver {
    // 实现所有必要的方法
    // ...
}

// 设置自定义驱动
EventLoop::setDriver(new CustomDriver());
```

### 底层句柄访问

获取底层事件循环句柄：

```php
$driver = EventLoop::getDriver();
$handle = $driver->getHandle();

// 根据驱动类型处理
if ($handle !== null) {
    // 访问底层资源
}
```

## 7. 最佳实践和注意事项

### 7.1 资源管理

- 始终在不需要时关闭流资源
- 使用 `EventLoop::cancel()` 取消不再需要的回调
- 避免内存泄漏，特别是在长时间运行的应用中

### 7.2 性能考虑

- 避免在回调中执行长时间运行的任务
- 合理使用引用计数机制
- 选择合适的驱动程序以获得最佳性能

### 7.3 错误处理

- 始终设置全局错误处理器
- 在回调中捕获异常，避免事件循环终止
- 对异步操作的错误进行适当的处理和记录

### 7.4 纤维使用

- 在 PHP 8.1+ 环境中优先使用纤维功能
- 避免过度使用纤维，保持代码的可读性
- 正确处理纤维的暂停和恢复

## 8. API 参考

### 核心方法

| 方法 | 描述 |
|------|------|
| `EventLoop::run(): void` | 运行事件循环 |
| `EventLoop::stop(): void` | 停止事件循环 |
| `EventLoop::isRunning(): bool` | 检查事件循环是否正在运行 |
| `EventLoop::queue(Closure $closure, mixed ...$args): void` | 队列一个微任务 |
| `EventLoop::defer(Closure $closure): string` | 延迟执行回调 |
| `EventLoop::delay(float $delay, Closure $closure): string` | 在指定时间后执行回调 |
| `EventLoop::repeat(float $interval, Closure $closure): string` | 每隔指定时间重复执行回调 |
| `EventLoop::onReadable(mixed $stream, Closure $closure): string` | 当流可读时执行回调 |
| `EventLoop::onWritable(mixed $stream, Closure $closure): string` | 当流可写时执行回调 |
| `EventLoop::onSignal(int $signal, Closure $closure): string` | 当收到信号时执行回调 |
| `EventLoop::enable(string $callbackId): string` | 启用回调 |
| `EventLoop::disable(string $callbackId): string` | 禁用回调 |
| `EventLoop::cancel(string $callbackId): void` | 取消回调 |
| `EventLoop::reference(string $callbackId): string` | 引用回调 |
| `EventLoop::unreference(string $callbackId): string` | 取消引用回调 |
| `EventLoop::setErrorHandler(?Closure $errorHandler): void` | 设置错误处理器 |
| `EventLoop::getErrorHandler(): ?Closure` | 获取错误处理器 |
| `EventLoop::getSuspension(): Suspension` | 获取暂停/恢复对象 |

## 9. 版本要求

- PHP 8.1+（推荐 PHP 8.1.17+ 或 PHP 8.2.4+ 以避免纤维相关的 GC 问题）
- 可选扩展：
  - `pcntl`：用于信号处理
  - `uv`：用于 uv 驱动程序（更高性能）
  - `ev`：用于 ev 驱动程序（更高性能）

## 10. 总结

Revolt 提供了强大而灵活的事件循环功能，为 PHP 异步编程提供了坚实的基础。通过使用 Revolt，开发者可以编写高性能、非阻塞的应用程序，充分利用现代服务器的多核处理能力。

无论是简单的定时任务还是复杂的异步网络应用，Revolt 都提供了必要的工具和 API 来简化开发过程，提高应用程序的性能和可靠性。