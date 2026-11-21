# Workerman Crontab 定时任务使用文档

## 1. 组件介绍

Workerman Crontab 是一个基于 Workerman 框架的定时任务管理组件，提供了强大的定时任务调度功能，支持多种任务类型和灵活的配置选项。

### 主要功能特点

- **标准 Crontab 语法支持**：完全兼容 Linux Crontab 语法，支持秒级精度
- **多种任务类型**：支持命令任务、类任务、URL 任务、Shell 任务和 EVAL 任务
- **任务管理**：支持任务的添加、删除、暂停、恢复等操作
- **并发控制**：支持任务的单例执行，避免任务重叠执行
- **任务日志**：详细记录任务执行情况，便于调试和监控
- **分布式支持**：支持多服务器环境下的任务调度
- **异步执行**：支持将任务投递到异步进程执行，提高性能

## 2. 核心组件

### 2.1 Crontab 类

核心的定时任务类，用于创建和管理定时任务。

### 2.2 Parser 类

Crontab 规则解析器，用于解析和验证 Crontab 表达式。

### 2.3 Server 类

定时任务服务器，负责任务的调度和执行。

### 2.4 Client 类

客户端类，用于与定时任务服务器进行通信。

## 3. Crontab 语法

Workerman Crontab 支持标准的 Crontab 语法，格式如下：

```
*    *    *    *    *    *
-    -    -    -    -    -
|    |    |    |    |    |
|    |    |    |    |    +----- 星期几 (0 - 6) (周日=0)
|    |    |    |    +----- 月份 (1 - 12)
|    |    |    +------- 日期 (1 - 31)
|    |    +--------- 小时 (0 - 23)
|    +----------- 分钟 (0 - 59)
+------------- 秒 (0 - 59)
```

### 支持的特殊字符

- `*`：匹配所有值
- `,`：列表分隔符，如 "1,3,5"
- `-`：范围，如 "1-5"
- `/`：步长，如 "*/2" 表示每 2 个单位

### 示例

- `\* \* \* \* \* \*`：每秒执行一次
- `0 \* \* \* \* \*`：每分钟的第 0 秒执行一次
- `0 0 \* \* \* \*`：每小时的第 0 分 0 秒执行一次
- `0 0 0 \* \* \*`：每天的 0 点 0 分 0 秒执行一次
- `0 0 0 \* \* 1`：每周一的 0 点 0 分 0 秒执行一次
- `0 0 0 1 \* \*`：每月 1 号的 0 点 0 分 0 秒执行一次
- `*/5 \* \* \* \* \*`：每 5 秒执行一次
- `0 \*/5 \* \* \* \*`：每 5 分钟执行一次
- `0 0 \*/2 \* \* \*`：每 2 小时执行一次
- `0 0 0 1 1 \*`：每年 1 月 1 号的 0 点 0 分 0 秒执行一次

## 4. 基本用法

### 4.1 创建简单定时任务

```php
<?php
use Workerman\Crontab\Crontab;

// 每秒执行一次
new Crontab('* * * * * *', function() {
    echo "任务执行于 " . date('Y-m-d H:i:s') . "\n";
});

// 每分钟执行一次
new Crontab('0 * * * * *', function() {
    echo "每分钟执行一次任务\n";
});

// 每天凌晨执行一次
new Crontab('0 0 0 * * *', function() {
    echo "每天凌晨执行一次任务\n";
});
```

### 4.2 使用命名任务

```php
<?php
use Workerman\Crontab\Crontab;

// 创建命名任务
$crontab = new Crontab('* * * * * *', function() {
    echo "命名任务执行\n";
}, "my_task");

// 获取任务名称
echo $crontab->getName(); // 输出: my_task
```

### 4.3 管理任务

```php
<?php
use Workerman\Crontab\Crontab;

// 创建任务
$crontab = new Crontab('* * * * * *', function() {
    echo "任务执行\n";
});

// 获取所有任务
$all_tasks = Crontab::getAll();

// 获取任务ID
$task_id = $crontab->getId();

// 删除任务
Crontab::remove($task_id);
// 或通过实例删除
$crontab->destroy();
```

## 5. 任务类型

### 5.1 命令任务 (COMMAND_CRONTAB)

执行 webman 命令行任务：

```php
<?php
use Workerman\Crontab\Crontab;
use Workerman\Crontab\Server;

// 每分钟执行一次命令任务
new Crontab('0 * * * * *', function() {
    $task_data = [
        'type'       => Server::COMMAND_CRONTAB,
        'target'     => 'app:task',
        'parameter'  => 'param1=value1&param2=value2',
        'singleton'  => 1,
        'status'     => Server::NORMAL_STATUS
    ];
    // 将任务添加到服务器
    // ...
});
```

### 5.2 类任务 (CLASS_CRONTAB)

执行指定类的方法：

```php
<?php
use Workerman\Crontab\Crontab;
use Workerman\Crontab\Server;

// 每小时执行一次类任务
new Crontab('0 0 * * * *', function() {
    $task_data = [
        'type'       => Server::CLASS_CRONTAB,
        'target'     => 'App\Tasks\MyTask@execute', // 类名@方法名
        'parameter'  => json_encode(['param1' => 'value1']),
        'singleton'  => 1,
        'status'     => Server::NORMAL_STATUS
    ];
    // 将任务添加到服务器
    // ...
});
```

### 5.3 URL 任务 (URL_CRONTAB)

发送 HTTP 请求：

```php
<?php
use Workerman\Crontab\Crontab;
use Workerman\Crontab\Server;

// 每天执行一次 URL 任务
new Crontab('0 0 0 * * *', function() {
    $task_data = [
        'type'       => Server::URL_CRONTAB,
        'target'     => 'http://example.com/task',
        'parameter'  => '',
        'singleton'  => 1,
        'status'     => Server::NORMAL_STATUS
    ];
    // 将任务添加到服务器
    // ...
});
```

### 5.4 Shell 任务 (SHELL_CRONTAB)

执行 Shell 命令：

```php
<?php
use Workerman\Crontab\Crontab;
use Workerman\Crontab\Server;

// 每小时执行一次 Shell 任务
new Crontab('0 0 * * * *', function() {
    $task_data = [
        'type'       => Server::SHELL_CRONTAB,
        'target'     => 'echo "Hello World" >> /tmp/log.txt',
        'parameter'  => '',
        'singleton'  => 1,
        'status'     => Server::NORMAL_STATUS
    ];
    // 将任务添加到服务器
    // ...
});
```

### 5.5 EVAL 任务 (EVAL_CRONTAB)

执行 PHP 代码：

```php
<?php
use Workerman\Crontab\Crontab;
use Workerman\Crontab\Server;

// 每分钟执行一次 EVAL 任务
new Crontab('0 * * * * *', function() {
    $task_data = [
        'type'       => Server::EVAL_CRONTAB,
        'target'     => 'file_put_contents("/tmp/eval_log.txt", date("Y-m-d H:i:s") . "\n", FILE_APPEND);',
        'parameter'  => '',
        'singleton'  => 1,
        'status'     => Server::NORMAL_STATUS
    ];
    // 将任务添加到服务器
    // ...
});
```

## 6. 服务器端使用

### 6.1 初始化服务器

```php
<?php
use Workerman\Crontab\Server;
use Workerman\Worker;

// 创建定时任务服务器
$server = new Server();

// 创建 Worker 监听 TCP 端口
$worker = new Worker('tcp://0.0.0.0:2345');
$worker->count = 1;
$worker->name = 'CrontabServer';

// 设置回调函数
$worker->onWorkerStart = [$server, 'onWorkerStart'];
$worker->onConnect    = [$server, 'onConnect'];
$worker->onMessage    = [$server, 'onMessage'];

// 启动所有 worker
Worker::runAll();
```

### 6.2 配置文件

服务器需要配置文件支持，通常位于 `Config/Task.php`：

```php
<?php

return [
    // 监听地址
    'listen' => '127.0.0.1:2345',
    // 异步任务监听地址
    'async_listen' => '127.0.0.1:2346',
    // 是否写入日志
    'write_log' => true,
    // 命令是否后台运行
    'runInBackground' => false,
];
```

## 7. 客户端使用

### 7.1 连接服务器

```php
<?php
use Workerman\Crontab\Client;

// 获取客户端实例
$client = Client::instance();
```

### 7.2 发送请求

```php
<?php
use Workerman\Crontab\Client;

$client = Client::instance();

// 请求所有任务列表
$result = $client->request([
    'method' => 'crontabIndex',
    'args'   => []
]);

print_r($result);
```

## 8. 高级功能

### 8.1 单例执行

确保任务同一时间只执行一次：

```php
<?php
use Workerman\Crontab\Server;

$task_data = [
    'type'       => Server::CLASS_CRONTAB,
    'target'     => 'App\Tasks\LongRunningTask@execute',
    'parameter'  => '',
    'singleton'  => 1, // 设置为 1 表示单例执行
    'status'     => Server::NORMAL_STATUS
];
```

### 8.2 后台运行

将命令任务在后台运行：

```php
<?php
use Workerman\Crontab\Server;

// 在服务器配置中启用
$config['runInBackground'] = true;
```

### 8.3 任务日志

启用任务执行日志：

```php
<?php
use Workerman\Crontab\Server;

// 在服务器配置中启用
$config['write_log'] = true;
```

## 9. API 参考

### 9.1 Crontab 类

#### 构造函数

```php
public function __construct($rule, $callback, $name = '')
```

- `$rule`：Crontab 规则字符串
- `$callback`：任务回调函数
- `$name`：任务名称（可选）

#### 主要方法

| 方法名 | 描述 | 参数 |
|-------|------|------|
| `getRule()` | 获取任务规则 | 无 |
| `getCallback()` | 获取任务回调 | 无 |
| `getName()` | 获取任务名称 | 无 |
| `getId()` | 获取任务 ID | 无 |
| `destroy()` | 删除任务 | 无 |
| `getAll()` | 获取所有任务（静态方法） | 无 |
| `remove()` | 删除指定任务（静态方法） | `$id` - 任务 ID 或实例 |

### 9.2 Parser 类

#### 解析方法

```php
public function parse($crontab_string, $start_time = null)
```

- `$crontab_string`：Crontab 规则字符串
- `$start_time`：开始时间戳（可选）

#### 验证方法

```php
public function isValid($crontab_string)
```

- `$crontab_string`：Crontab 规则字符串
- 返回：布尔值，表示规则是否有效

### 9.3 Server 类

#### 主要常量

| 常量名 | 描述 | 值 |
|-------|------|-----|
| `FORBIDDEN_STATUS` | 禁用状态 | '0' |
| `NORMAL_STATUS` | 正常状态 | '1' |
| `COMMAND_CRONTAB` | 命令任务类型 | '1' |
| `CLASS_CRONTAB` | 类任务类型 | '2' |
| `URL_CRONTAB` | URL 任务类型 | '3' |
| `EVAL_CRONTAB` | EVAL 任务类型 | '4' |
| `SHELL_CRONTAB` | Shell 任务类型 | '5' |

#### 主要方法

| 方法名 | 描述 | 参数 |
|-------|------|------|
| `onWorkerStart()` | Worker 启动回调 | `$worker` - Worker 实例 |
| `onConnect()` | 客户端连接回调 | `$connection` - 连接实例 |
| `onMessage()` | 接收消息回调 | `$connection` - 连接实例, `$data` - 消息数据 |
| `crontabRun()` | 执行定时任务 | `$data` - 任务数据 |
| `crontabInit()` | 初始化定时任务 | 无 |

### 9.4 Client 类

#### 主要方法

| 方法名 | 描述 | 参数 |
|-------|------|------|
| `instance()` | 获取客户端实例（单例） | 无 |
| `request()` | 发送请求 | `$param` - 请求参数 |

## 10. 完整示例

### 10.1 简单定时任务

```php
<?php

use Workerman\Crontab\Crontab;
use Workerman\Worker;

// 创建定时任务
new Crontab('* * * * * *', function() {
    echo "任务执行于 " . date('Y-m-d H:i:s') . "\n";
}, "simple_task");

// 创建多个任务
new Crontab('*/5 * * * * *', function() {
    echo "每 5 秒执行一次\n";
});

new Crontab('0 */2 * * * *', function() {
    echo "每 2 分钟执行一次\n";
});

// 启动 Worker
Worker::runAll();
```

### 10.2 完整服务器示例

```php
<?php

use Workerman\Crontab\Server;
use Workerman\Worker;

// 创建服务器实例
$server = new Server();

// 创建监听端口
$worker = new Worker('tcp://0.0.0.1:2345');
$worker->count = 1;
$worker->name = 'CrontabServer';

// 设置回调
$worker->onWorkerStart = [$server, 'onWorkerStart'];
$worker->onConnect    = [$server, 'onConnect'];
$worker->onMessage    = [$server, 'onMessage'];

// 启动
Worker::runAll();
```

### 10.3 客户端示例

```php
<?php

use Workerman\Crontab\Client;

// 获取客户端实例
$client = Client::instance();

// 获取任务列表
$tasks = $client->request([
    'method' => 'crontabIndex',
    'args'   => []
]);

print_r($tasks);
```

## 11. 注意事项

### 11.1 性能考虑

- 避免在定时任务中执行耗时操作，建议使用异步处理
- 合理设置任务执行频率，避免过于频繁的任务占用过多资源
- 对于耗时任务，建议使用单例执行模式

### 11.2 可靠性考虑

- 重要任务建议启用日志功能，便于排查问题
- 对于关键业务，建议实现任务失败重试机制
- 考虑使用监控工具监控定时任务执行情况

### 11.3 安全建议

- 避免使用 EVAL 任务类型，存在安全风险
- 严格控制 Shell 任务的权限
- 对 URL 任务的目标地址进行验证
- 生产环境中建议关闭调试模式

## 12. 故障排除

### 12.1 任务不执行

1. 检查 Crontab 规则是否正确
2. 检查 Worker 是否已启动
3. 检查任务状态是否为正常
4. 查看日志文件是否有错误信息

### 12.2 任务执行多次

1. 检查是否启用了单例执行模式
2. 检查是否存在多个 Worker 进程执行同一任务
3. 检查缓存服务是否正常工作

### 12.3 性能问题

1. 减少任务执行频率
2. 优化任务逻辑，减少执行时间
3. 使用异步处理耗时操作
4. 增加服务器资源

---

## 附录：Crontab 规则速查

### 时间字段

| 字段 | 允许值 | 允许的特殊字符 |
|------|--------|----------------|
| 秒 | 0-59 | *, /, -, , |
| 分 | 0-59 | *, /, -, , |
| 小时 | 0-23 | *, /, -, , |
| 日期 | 1-31 | *, /, -, , |
| 月份 | 1-12 | *, /, -, , |
| 星期 | 0-6 (0=周日) | *, /, -, , |

### 常用示例

| 规则 | 描述 |
|------|------|
| \* \* \* \* \* \* | 每秒执行 |
| 0 \* \* \* \* \* | 每分钟执行 |
| 0 0 \* \* \* \* | 每小时执行 |
| 0 0 0 \* \* \* | 每天执行 |
| 0 0 0 \* \* 1 | 每周一执行 |
| 0 0 0 1 \* \* | 每月执行 |
| 0 0 0 1 1 \* | 每年执行 |
| \*/5 \* \* \* \* \* | 每 5 秒执行 |
| 0 \*/5 \* \* \* \* | 每 5 分钟执行 |
| 0 0 \*/2 \* \* \* | 每 2 小时执行 |
| 0 0 9-17 \* \* \* | 每天 9-17 点执行 |
| 0 0 0 \* \* 1-5 | 工作日执行 |

---

## 附录：API 速查

### Crontab 类

| 方法名 | 描述 |
|-------|------|
| `__construct()` | 创建定时任务 |
| `getRule()` | 获取任务规则 |
| `getCallback()` | 获取任务回调 |
| `getName()` | 获取任务名称 |
| `getId()` | 获取任务 ID |
| `destroy()` | 删除任务 |
| `getAll()` | 获取所有任务 |
| `remove()` | 删除指定任务 |

### Parser 类

| 方法名 | 描述 |
|-------|------|
| `parse()` | 解析 Crontab 规则 |
| `isValid()` | 验证 Crontab 规则 |

### Server 类

| 方法名 | 描述 |
|-------|------|
| `onWorkerStart()` | Worker 启动回调 |
| `onConnect()` | 客户端连接回调 |
| `onMessage()` | 接收消息回调 |

### Client 类

| 方法名 | 描述 |
|-------|------|
| `instance()` | 获取客户端实例 |
| `request()` | 发送请求 |
