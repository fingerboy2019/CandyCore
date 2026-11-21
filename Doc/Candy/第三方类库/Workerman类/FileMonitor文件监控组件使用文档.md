# Workerman FileMonitor 文件监控组件使用文档

## 1. 组件简介

Workerman FileMonitor 是一个专为 Windows 平台设计的文件监控组件，用于实时监控项目文件的变更并自动重载 Workerman 进程，实现热更新功能。该组件能够有效提高开发效率，避免频繁手动重启服务的繁琐操作。

### 1.1 核心功能
- 实时监控指定目录下的 PHP 文件变更
- 支持多目录同时监控
- 可配置监控间隔时间
- 仅在守护进程模式下工作，不影响开发调试
- 自动检测文件修改时间，精准触发重载

### 1.2 适用场景
- Windows 平台上的 Workerman 应用开发
- 需要频繁修改代码并快速验证效果的开发环境
- 希望实现代码热更新的生产环境（需谨慎配置）

## 2. 安装与配置
/Candy/Vendor/Workerman/FileMonitor/
### 2.1 依赖要求
- PHP >= 5.4
- Workerman 框架
- Windows 操作系统

### 2.2 安装方式
FileMonitor 组件已集成到 Candy 框架中，无需额外安装。组件文件位于：
```
/Candy/Vendor/Workerman/FileMonitor/
```

## 3. 核心 API 参考

### 3.1 Monitor 类

#### 3.1.1 构造函数
```php
public function __construct($worker, $dir, $timer = 3)
```

**参数说明：**
- `$worker`：Workerman 的 Worker 对象
- `$dir`：要监控的目录，可以是字符串或数组
- `$timer`：监控间隔时间，单位为秒，默认为 3 秒

**功能说明：**
初始化监控组件，设置监控目录和时间间隔。在守护进程模式下且关闭调试模式时，会自动将监控目录设置为 `CANDYPATH . '/Source'`。

#### 3.1.2 monitor 方法
```php
public function monitor(): bool
```

**返回值：**
- `true`：检测到文件变更并触发重载
- `false`：未检测到文件变更

**功能说明：**
遍历监控目录下的所有 PHP 文件，检查文件修改时间，如果发现有文件在最后一次检查后被修改，则触发 Workerman 进程重载。

## 4. 详细使用示例

### 4.1 基本使用示例

```php
<?php
use Workerman\Worker;
use FileMonitor\Monitor;

// 创建一个 Worker 实例
$worker = new Worker('http://0.0.0.0:8080');

// 设置进程数
$worker->count = 4;

// 初始化文件监控组件，监控当前目录，间隔时间为 2 秒
new Monitor($worker, [__DIR__], 2);

// 处理请求
$worker->onMessage = function($connection, $request) {
    $connection->send('Hello, Workerman!');
};

// 启动 Worker
Worker::runAll();
```

### 4.2 监控多个目录

```php
<?php
use Workerman\Worker;
use FileMonitor\Monitor;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;

// 监控多个目录
$monitor_dirs = [
    __DIR__ . '/app',
    __DIR__ . '/config',
    __DIR__ . '/lib'
];

// 初始化文件监控组件
new Monitor($worker, $monitor_dirs, 5);

$worker->onMessage = function($connection, $request) {
    $connection->send('Hello, Workerman!');
};

Worker::runAll();
```

### 4.3 集成到 Candy 框架

```php
<?php
use Workerman\Worker;
use FileMonitor\Monitor;

// 定义 Candy 框架路径
define('CANDYPATH', __DIR__ . '/Candy');

// 创建 Worker 实例
$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;

// 初始化文件监控组件
require_once CANDYPATH . '/Vendor/Workerman/FileMonitor/Monitor.php';
new Monitor($worker, [CANDYPATH . '/Source'], 3);

$worker->onMessage = function($connection, $request) {
    // 处理 Candy 框架请求
    $app = new Candy\Core\App();
    $response = $app->run($request);
    $connection->send($response);
};

Worker::runAll();
```

## 5. 高级功能与配置

### 5.1 监控间隔调整

监控间隔时间会影响文件变更的响应速度和系统资源消耗。间隔时间越短，响应速度越快，但系统资源消耗也越大。建议根据实际需求进行调整：

```php
// 快速响应，适合开发环境
new Monitor($worker, [__DIR__], 1);

// 平衡响应速度和资源消耗
new Monitor($worker, [__DIR__], 3);

// 低资源消耗，适合生产环境
new Monitor($worker, [__DIR__], 10);
```

### 5.2 自定义监控目录

在非守护进程模式下，可以自由指定要监控的目录：

```php
// 监控应用目录和配置目录
$monitor_dirs = [
    __DIR__ . '/application',
    __DIR__ . '/config'
];
new Monitor($worker, $monitor_dirs);
```

在守护进程模式下且关闭调试模式时，监控目录会被强制设置为 `CANDYPATH . '/Source'`：

```php
// 启动守护进程
Worker::$daemonize = true;
// 关闭调试模式
define('DEBUG', 0);

// 监控目录会被自动设置为 CANDYPATH . '/Source'
new Monitor($worker, [__DIR__]);
```

## 6. 注意事项与最佳实践

### 6.1 使用限制
- **仅支持 Windows 平台**：该组件专为 Windows 平台设计，Linux/Unix 系统建议使用 `inotify` 扩展
- **仅监控 PHP 文件**：组件仅检查扩展名为 `.php` 的文件
- **排除特定文件**：默认排除名为 `aa512e6773d6745affa5b2ed2592936a.php` 的文件

### 6.2 性能优化建议
- **合理设置监控目录**：仅监控必要的目录，避免监控过多文件
- **适当调整监控间隔**：根据实际需求和系统性能选择合适的间隔时间
- **生产环境谨慎使用**：在生产环境中使用时，建议增加监控间隔时间，减少系统资源消耗

### 6.3 调试与排查
- **检查是否在守护进程模式**：组件仅在守护进程模式下工作
- **确认调试模式状态**：调试模式开启时（DEBUG=1），不会使用默认监控目录
- **查看文件权限**：确保进程有足够权限读取监控目录下的文件
- **检查文件修改时间**：使用 `ls -la` 或 `stat` 命令检查文件修改时间是否正确

## 7. 常见问题解答

### 7.1 为什么文件修改后没有自动重载？

**可能原因：**
1. 未在守护进程模式下运行
2. 调试模式未关闭（DEBUG=1）
3. 监控目录设置不正确
4. 文件扩展名不是 `.php`
5. 监控间隔时间过长

**解决方案：**
```php
// 确保在守护进程模式下运行
Worker::$daemonize = true;
// 确保关闭调试模式
define('DEBUG', 0);
// 检查监控目录
var_dump($monitor->_monitor_dir);
// 调整监控间隔
new Monitor($worker, [__DIR__], 1);
```

### 7.2 如何查看监控日志？

可以通过设置 Worker 的日志文件来查看监控和重载信息：

```php
// 设置日志文件
Worker::$logFile = __DIR__ . '/workerman.log';
```

### 7.3 如何在 Linux 平台上使用类似功能？

Linux 平台建议使用 PHP 的 `inotify` 扩展，配合 Workerman 的 `Worker::$onFileChange` 回调：

```php
// 仅在 Linux 平台且安装了 inotify 扩展时可用
Worker::$onFileChange = function($changed_file) {
    echo "File changed: $changed_file\n";
    // 可以在这里添加自定义的重载逻辑
};
```

## 8. 代码结构与实现原理

### 8.1 核心属性

| 属性名 | 类型 | 说明 |
|--------|------|------|
| `_monitor_dir` | array | 待监听的项目目录列表 |
| `_interval` | int | 热更新间隔时间，单位为秒 |
| `_last_time` | int | 最后一次同步时间 |
| `_worker` | Worker | Worker 对象实例 |

### 8.2 实现原理

1. **初始化阶段**：在构造函数中设置监控目录、时间间隔，并将 `worker->reloadable` 设置为 `false`
2. **启动监控**：在 Worker 的 `onWorkerStart` 事件中，通过 `Timer::add` 定期调用 `monitor` 方法
3. **文件检查**：在 `monitor` 方法中，使用 `RecursiveDirectoryIterator` 和 `RecursiveIteratorIterator` 遍历监控目录下的所有文件
4. **变更检测**：比较文件的修改时间（mtime）与最后一次检查时间，如果文件被修改则触发重载
5. **重载触发**：调用 `$this->_worker->reloadWorkerForWindows()` 方法触发 Workerman 进程重载

### 8.3 工作流程

```
+------------------+
| 初始化 Monitor  |
+------------------+
         ↓
+------------------+
| 注册定时器任务   |
+------------------+
         ↓
+------------------+
| 遍历监控目录     |
+------------------+
         ↓
+------------------+
| 检查文件 mtime   |
+------------------+
         ↓
+------------------+
| 检测到变更？     | → 是 → +------------------+
+------------------+        | 触发进程重载     |
         ↓ 否              +------------------+
+------------------+
| 等待下一次检查   |
+------------------+
```

## 9. 版本历史

| 版本 | 日期 | 更新内容 |
|------|------|----------|
| 1.0.0 | 2024-12-12 | 初始版本发布 |

## 10. 相关资源

- [Workerman 官方文档](http://doc.workerman.net/)
- [PHP RecursiveDirectoryIterator 文档](https://www.php.net/manual/en/class.recursivedirectoryiterator.php)
- [PHP RecursiveIteratorIterator 文档](https://www.php.net/manual/en/class.recursiveiteratoriterator.php)

## 11. 总结

Workerman FileMonitor 组件为 Windows 平台上的 Workerman 应用提供了便捷的文件热更新功能，能够有效提高开发效率。通过合理配置监控目录和时间间隔，可以在保证响应速度的同时，降低系统资源消耗。在生产环境中使用时，建议谨慎配置，避免频繁重载影响服务稳定性。