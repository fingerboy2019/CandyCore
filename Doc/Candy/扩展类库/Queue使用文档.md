# Queue使用文档

## 概述

Queue类是Candy框架中的队列操作管理器，提供基于缓存的队列操作功能。该类支持基本的队列操作如入队、出队、删除指定元素等。通过缓存系统实现队列的持久化存储，支持阻塞式出队操作，可用于处理异步任务、消息队列等场景。

> 注意：Queue类是Candy框架核心类库的补充类库，提供基于缓存的队列操作功能。

## 快速入门

### 命名空间

```php
use Extend\Queue;
```

### 基本使用示例

```php
// 添加元素到队列
Queue::push('email_queue', json_encode(['to' => 'user@example.com', 'subject' => 'Hello']));
Queue::push('email_queue', json_encode(['to' => 'admin@example.com', 'subject' => 'Notification']));

// 从队列中取出元素（非阻塞，立即返回）
$emailTask = Queue::pop('email_queue', 0);
if ($emailTask) {
    $emailData = json_decode($emailTask, true);
    // 处理邮件任务
}

// 从队列中删除特定元素
Queue::srem('email_queue', json_encode(['to' => 'admin@example.com', 'subject' => 'Notification']));
```

## 核心功能详解

### 1. 队列初始化

```php
/**
 * 初始化队列服务
 *
 * @return object 返回缓存处理器实例
 */
public static function init(): object
```

通常不需要显式调用，push和pop方法会自动调用初始化。

```php
// 显式初始化队列服务
$queueHandler = Queue::init();
```

### 2. 入队操作

```php
/**
 * 将元素加入队列
 *
 * @param string $key 队列标识符
 * @param string $value 要添加的元素值
 * @return boolean 添加成功返回true，失败返回false
 */
public static function push(string $key,string $value): bool
```

示例：

```php
// 添加任务到队列
Queue::push('my_queue', 'task_data');

// 添加JSON格式数据
$taskData = ['type' => 'send_email', 'to' => 'user@example.com'];
Queue::push('email_queue', json_encode($taskData));
```

### 3. 出队操作

```php
/**
 * 阻塞式出队操作
 *
 * @param string $key 队列标识符
 * @param int $timeout 等待超时时间（秒），0表示无限等待
 * @return mixed|null 成功返回出队元素值，超时或失败返回null
 */
public static function pop(string $key,int $timeout=0):mixed
```

示例：

```php
// 阻塞式出队，最多等待5秒
$task = Queue::pop('my_queue', 5);

// 无限等待直到有数据
$task = Queue::pop('my_queue', 0);

// 非阻塞检查（立即返回）
$task = Queue::pop('my_queue', 0);
```

### 4. 删除指定元素

```php
/**
 * 删除集合中指定的元素
 *
 * @param string $key 队列标识符
 * @param string $son_key 要删除的元素值
 * @return boolean 删除成功返回true，失败返回false
 */
public static function srem(string $key,string $son_key): bool
```

示例：

```php
// 删除队列中的特定元素
Queue::srem('my_queue', 'specific_value');

// 删除JSON格式的特定任务
$taskToRemove = json_encode(['to' => 'admin@example.com', 'subject' => 'Notification']);
Queue::srem('email_queue', $taskToRemove);
```

### 5. 关闭队列连接

```php
/**
 * 关闭队列连接
 *
 * @return boolean 操作成功返回true
 */
public function close(): bool
```

> 注意：close方法是非静态的，需要实例化Queue类后调用。

示例：

```php
// 关闭队列连接，释放资源
$queue = new Queue();
$queue->close();
```

## 高级用法

### 1. 异步任务处理

**生产者** - 添加任务到队列：

```php
function addTaskToQueue($taskType, $taskData) {
    $task = json_encode(['type' => $taskType, 'data' => $taskData, 'timestamp' => time()]);
    return Queue::push('task_queue', $task);
}

// 添加不同类型的任务
addTaskToQueue('send_email', ['to' => 'user@example.com', 'subject' => 'Welcome']);
addTaskToQueue('generate_report', ['userId' => 123, 'date' => '2023-05-15']);
addTaskToQueue('backup_data', ['table' => 'users', 'time' => date('Y-m-d H:i:s')]);
```

**消费者** - 从队列获取并处理任务：

```php
function processTasks($maxWaitTime = 60) {
    while (true) {
        // 最多等待60秒
        $task = Queue::pop('task_queue', $maxWaitTime);
        
        if ($task) {
            $taskData = json_decode($task, true);
            
            switch ($taskData['type']) {
                case 'send_email':
                    sendEmail($taskData['data']);
                    break;
                case 'generate_report':
                    generateReport($taskData['data']);
                    break;
                case 'backup_data':
                    backupDatabase($taskData['data']);
                    break;
                default:
                    logError('Unknown task type: ' . $taskData['type']);
            }
        }
    }
}

// 启动消费者进程
processTasks();
```

### 2. 多队列优先级管理

```php
function processDifferentQueues() {
    $queues = ['high_priority', 'normal_priority', 'low_priority'];
    
    while (true) {
        $taskFound = false;
        
        // 按优先级顺序检查队列
        foreach ($queues as $queue) {
            // 非阻塞检查
            $task = Queue::pop($queue, 0);
            
            if ($task) {
                // 处理任务
                echo "Processing task from {$queue} queue...\n";
                processTask($task);
                $taskFound = true;
                break; // 处理完一个任务后重新开始检查
            }
        }
        
        // 如果没有找到任务，短暂休息避免CPU占用过高
        if (!$taskFound) {
            sleep(1);
        }
    }
}
```

## 注意事项

1. **缓存配置**：使用队列功能前，必须确保已正确配置Cache组件，否则会抛出错误
2. **数据持久化**：队列操作基于缓存系统实现，因此队列数据的持久化取决于所使用的缓存驱动
3. **阻塞操作**：阻塞式出队操作（pop方法）在队列为空时会阻塞进程，在生产环境中应注意设置合理的超时时间
4. **继承限制**：该类为final类，不允许继承和重写
5. **方法类型**：close方法是非静态的，需要实例化Queue类后调用
6. **性能依赖**：队列操作的性能和可靠性依赖于底层缓存系统的性能和可靠性
7. **适用场景**：该队列实现适用于轻量级的消息队列需求，对于大规模的消息队列需求，建议使用专业的消息队列系统如Redis、RabbitMQ等

## 应用场景

1. **异步任务处理**：将耗时操作（如邮件发送、文件处理）放入队列，异步执行
2. **任务队列**：实现任务的异步调度和执行
3. **消息队列**：实现简单的消息传递机制
4. **流量削峰**：在高并发场景下，将请求放入队列，平滑处理
5. **后台任务**：处理需要在后台运行的任务

## 版本信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-03 08:31:01
- **所属框架**: Candy框架核心类库的补充类库

## 相关链接

- [Candy框架文档](https://github.com/fingerboy/candy)
- [Cache组件文档](https://github.com/fingerboy/candy/blob/master/Doc/Cache.md)