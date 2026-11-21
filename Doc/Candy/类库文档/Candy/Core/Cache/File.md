# File 文件缓存类

## 类简介

`File` 是一个功能强大的基于文件系统的缓存类，继承自 `Cache` 抽象类。它提供了多种高级缓存解决方案，包括多数据库实例、LRU 热缓存、增量垃圾回收、位操作、队列操作、范围查询、原子操作和 Redis 流兼容操作等，适用于复杂的缓存场景。

## 命名空间

```php
namespace Candy\Core\Cache;
```

## 类定义

```php
class File extends Cache
{
    // 类属性和方法...
}
```

## 类常量

### SHARD_COUNT

```php
private const int SHARD_COUNT = 16; // 分片数量
```

## 类属性

### cacheDir

```php
/**
 * 缓存目录
 *
 * @var string
 */
private string $cacheDir; // 缓存目录路径
```

### lockType

```php
/**
 * 锁类型，可选值：'file'、'semaphore'
 *
 * @var string
 */
private string $lockType = 'file';
```

### maxVarNumber

```php
/**
 * 最大变量数
 *
 * @var int
 */
private int $maxVarNumber; // 最大变量数量
```

### segmentSize

```php
/**
 * 分区大小，单位：字节
 *
 * @var int
 */
private int $segmentSize; // 分区大小
```

### blockSize

```php
/**
 * 区块大小，单位：字节
 *
 * @var int
 */
private int $blockSize; // 区块大小
```

### gcInterval

```php
/**
 * GC 间隔，单位：毫秒
 *
 * @var int
 */
private int $gcInterval; // GC 间隔
```

### prefix

```php
/**
 * 缓存键前缀
 *
 * @var string
 */
private string $prefix; // 缓存键前缀
```

### dbAlias

```php
/**
 * 数据库别名
 *
 * @var array
 */
private array $dbAlias = []; // 数据库别名映射
```

### dbInstances

```php
/**
 * 数据库实例
 *
 * @var array
 */
private array $dbInstances = []; // 数据库实例存储
```

### hotCache

```php
/**
 * 热缓存
 *
 * @var array
 */
private array $hotCache = []; // 热缓存存储
```

### lruRecords

```php
/**
 * LRU 记录
 *
 * @var array
 */
private array $lruRecords = []; // LRU 记录存储
```

### MAX_HOT_CACHE_COUNT

```php
private const int MAX_HOT_CACHE_COUNT = 200; // 最大热缓存数量
```

## 主要方法

### __construct()

```php
/**
 * 构造函数
 *
 * 初始化缓存配置参数
 *
 * @param array $config 配置参数
 *                      - cache_dir: 缓存目录，默认为缓存目录
 *                      - lock_type: 锁类型，可选值：'file'、'semaphore'，默认为'file'
 *                      - max_var_number: 最大变量数，默认为 100
 *                      - segment_size: 分区大小，单位字节，默认为 1048576
 *                      - block_size: 区块大小，单位字节，默认为 4096
 *                      - gc_interval: GC 间隔，单位毫秒，默认为 60000
 *                      - prefix: 缓存键前缀，默认为空字符串
 * @return void
 */
public function __construct(array $config = []): void
{
    // 实现代码...
}
```

### db()

```php
/**
 * 获取数据库实例
 *
 * 支持多数据库实例，可通过别名访问不同的缓存数据库
 *
 * @param string|null $alias 数据库别名，为 null 时返回主实例
 * @param array $customConfig 自定义配置，仅当 alias 为 null 时有效
 * @return File 数据库实例
 */
public function db(?string $alias = null, array $customConfig = []): File
{
    // 实现代码...
}
```

### __ttl()

```php
/**
 * 获取缓存有效时间
 *
 * @param string $key 缓存键名
 * @return int 剩余有效时间（秒），-1 表示不存在或已过期，0 表示永不过期
 */
public function __ttl(string $key): int
{
    // 实现代码...
}
```

### __clear()

```php
/**
 * 清除所有缓存
 *
 * 重新初始化缓存文件，清除所有缓存数据
 *
 * @return bool 是否成功清除
 */
public function __clear(): bool
{
    // 实现代码...
}
```

### __rawSet()

```php
/**
 * 向缓存中写入数据
 *
 * @param string $key 缓存键名
 * @param mixed $value 缓存值
 * @param int $ttl 过期时间，0 表示永不过期
 * @return bool 是否成功写入
 */
public function __rawSet(string $key, mixed $value = '', int $ttl = 0): bool
{
    // 实现代码...
}
```

### __rawGet()

```php
/**
 * 从缓存中读取数据
 *
 * @param string $key 缓存键名
 * @param mixed $default 默认值
 * @return mixed 缓存值或默认值
 */
public function __rawGet(string $key, mixed $default = null): mixed
{
    // 实现代码...
}
```

### __rawDelete()

```php
/**
 * 删除缓存数据
 *
 * 将指定键的缓存数据标记为无效
 *
 * @param string $key 缓存键名
 * @return bool 是否成功删除
 */
public function __rawDelete(string $key): bool
{
    // 实现代码...
}
```

### __rawHas()

```php
/**
 * 检查缓存键是否存在
 *
 * @param string $key 缓存键名
 * @return bool 是否存在
 */
public function __rawHas(string $key): bool
{
    // 实现代码...
}
```

### __acquireLock()

```php
/**
 * 获取锁
 *
 * 根据配置使用文件锁或信号锁
 *
 * @param string $lockName 锁名称
 * @param int|null $timeout 超时时间，单位毫秒，null 表示不超时
 * @return bool 是否成功获取锁
 */
public function __acquireLock(string $lockName, ?int $timeout = null): bool
{
    // 实现代码...
}
```

### __releaseLock()

```php
/**
 * 释放锁
 *
 * 根据配置使用文件锁或信号锁
 *
 * @param string $lockName 锁名称
 * @return bool 是否成功释放锁
 */
public function __releaseLock(string $lockName): bool
{
    // 实现代码...
}
```

### __releaseAllLocksOnShutdown()

```php
/**
 * 注册关闭函数，确保在脚本结束时释放所有持有的锁
 *
 * @return void
 */
public function __releaseAllLocksOnShutdown(): void
{
    // 实现代码...
}
```

### runIncrementalGC()

```php
/**
 * 运行增量垃圾回收
 *
 * 使用状态机机制进行增量 GC，清理过期的缓存数据
 *
 * @param int $maxSteps 最大步数，默认 100
 * @param string|null $dbAlias 数据库别名，null 表示主实例
 * @return void
 */
public function runIncrementalGC(int $maxSteps = 100, ?string $dbAlias = null): void
{
    // 实现代码...
}
```

### __rawBit()

```php
/**
 * 位操作
 *
 * 支持位读写、计数、位运算和查找操作
 *
 * @param string $key 缓存键名
 * @param int $id 位 ID
 * @param array $options 操作选项
 *                      - mode: 操作模式，可选值：'read'、'write'、'count'、'op'、'pos'
 *                      - op: 位运算类型（mode 为'op'时），可选值：'AND'、'OR'、'XOR'
 *                      - value: 要写入的值（mode 为'write'时）
 * @param int $expire 过期时间，单位秒，0 表示永不过期
 * @return mixed 操作结果
 */
public function __rawBit(string $key, int $id, array $options = [], int $expire = 0): mixed
{
    // 实现代码...
}
```

### __rawBitCount()

```php
/**
 * 统计位中 1 的数量
 *
 * @param string $key 缓存键名
 * @param int $id 位 ID
 * @return int 1 的数量
 */
public function __rawBitCount(string $key, int $id): int
{
    // 实现代码...
}
```

### __rawBitOp()

```php
/**
 * 位运算
 *
 * @param string $key 缓存键名
 * @param int $id 位 ID
 * @param array $options 操作选项
 *                      - op: 位运算类型，可选值：'AND'、'OR'、'XOR'
 *                      - keys: 参与运算的键名数组
 *                      - values: 参与运算的位值数组
 * @return int 运算结果
 */
public function __rawBitOp(string $key, int $id, array $options = []): int
{
    // 实现代码...
}
```

### __rawBitPos()

```php
/**
 * 查找第一个 1 的位置
 *
 * @param string $key 缓存键名
 * @param int $id 位 ID
 * @return int|null 第一个 1 的位置，不存在返回 null
 */
public function __rawBitPos(string $key, int $id): ?int
{
    // 实现代码...
}
```

### __rawPush()

```php
/**
 * 队列入队
 *
 * 支持头部入队或尾部入队
 *
 * @param string $key 缓存键名
 * @param array $args 入队参数
 * @param bool $cover 是否覆盖，true 表示覆盖原有队列
 * @param bool $head 是否头部入队，true 表示头部入队，false 表示尾部入队
 * @return bool 是否成功入队
 */
public function __rawPush(string $key, array $args, bool $cover = false, bool $head = false): bool
{
    // 实现代码...
}
```

### __rawPop()

```php
/**
 * 队列出队
 *
 * 支持头部出队或尾部出队
 *
 * @param string $key 缓存键名
 * @param bool $head 是否头部出队，true 表示头部出队，false 表示尾部出队
 * @return mixed 出队的值，队列为空返回 null
 */
public function __rawPop(string $key, bool $head = false): mixed
{
    // 实现代码...
}
```

### __rawRange()

```php
/**
 * 范围查询
 *
 * 支持按偏移量和长度获取缓存数据
 *
 * @param string $key 缓存键名
 * @param int $offset 偏移量
 * @param int $length 长度
 * @param string $sort 排序方式，可选值：'ASC'、'DESC'
 * @param string $order 排序字段
 * @return array 查询结果
 */
public function __rawRange(string $key, int $offset, int $length, string $sort = 'ASC', string $order = 'id'): array
{
    // 实现代码...
}
```

### __rawIncr()

```php
/**
 * 原子递增/递减
 *
 * @param string $key 缓存键名
 * @param int $step 步长，负数表示递减
 * @param string $type 操作类型，可选值：'INCR'、'DECR'
 * @return int 操作后的值
 */
public function __rawIncr(string $key, int $step, string $type = 'INCR'): int
{
    // 实现代码...
}
```

### getShardLockName()

```php
/**
 * 获取分片锁名称
 *
 * 用于实现分片锁机制
 *
 * @param string $lockName 原始锁名称
 * @return string 分片锁名称
 */
public function getShardLockName(string $lockName): string
{
    // 实现代码...
}
```

### __rawXAdd()

```php
/**
 * 添加流消息
 *
 * Redis 流兼容操作
 *
 * @param string $key 流键名
 * @param array $message 消息数据
 * @param int|null $streamId 流 ID，为 null 时自动分配
 * @return string 流 ID
 */
public function __rawXAdd(string $key, array $message, ?int $streamId = null): string
{
    // 实现代码...
}
```

### __rawXRange()

```php
/**
 * 获取流消息范围
 *
 * Redis 流兼容操作
 *
 * @param string $key 流键名
 * @param string $start 起始 ID
 * @param string $end 结束 ID
 * @param array $options 查询选项
 * @return array 消息列表
 */
public function __rawXRange(string $key, string $start, string $end, array $options = []): array
{
    // 实现代码...
}
```

### __rawXRead()

```php
/**
 * 读取流消息
 *
 * Redis 流兼容操作
 *
 * @param array $keys 流键名数组
 * @param array $counts 每个键的计数
 * @param int $block 阻塞时间，单位毫秒，0 表示非阻塞
 * @return array 读取结果
 */
public function __rawXRead(array $keys, array $counts, int $block = 0): array
{
    // 实现代码...
}
```

### __rawXGroup()

```php
/**
 * 创建消费者组
 *
 * Redis 流兼容操作
 *
 * @param string $key 流键名
 * @param string $group 组名
 * @param string|null $startId 起始 ID，为 null 表示从头开始
 * @return bool 是否成功创建
 */
public function __rawXGroup(string $key, string $group, ?string $startId = null): bool
{
    // 实现代码...
}
```

### __rawXReadGroup()

```php
/**
 * 消费者组读取消息
 *
 * Redis 流兼容操作
 *
 * @param string $key 流键名
 * @param string $group 组名
 * @param string $consumer 消费者名
 * @param string $startId 起始 ID
 * @param int $count 计数
 * @param int $block 阻塞时间，单位毫秒
 * @return array 读取结果
 */
public function __rawXReadGroup(string $key, string $group, string $consumer, string $startId, int $count = 10, int $block = 0): array
{
    // 实现代码...
}
```

### __rawXAck()

```php
/**
 * 确认消息已读
 *
 * Redis 流兼容操作
 *
 * @param string $key 流键名
 * @param string $group 组名
 * @param array $messages 消息 ID 数组
 * @return bool 是否成功确认
 */
public function __rawXAck(string $key, string $group, array $messages): bool
{
    // 实现代码...
}
```

## 复杂文件结构

### 文件头部（Header）

- **Magic Number**: `0xDEADBEEF` - 文件魔数，用于验证文件有效性
- **Version**: `5` - 文件版本
- **Data Capacity**: 数据容量信息
- **Writing Flag**: 写标志（带超时保护）
- **Block Size**: 区块大小
- **Max Var Number**: 最大变量数

### 索引区域（Index Area）

- **CRC32**: 数据校验和
- **v_CRC32**: 验证校验和
- **Meta Order**: 元数据顺序

### 位图区域（Bitmap Area）

- **Bit-based Free Space Tracking**: 基于位图的空闲空间追踪

### 元数据区域（Metadata Area）

- 每个元数据条目 25 字节：
  - **Status**: 状态
  - **Timestamp**: 时间戳
  - **Block/String Length**: 区块/字符串长度
  - **Offset**: 偏移量
  - **Last Use Time**: 最后使用时间
  - **Data CRC32**: 数据校验和

## 多数据库实例支持

File 缓存类支持多数据库实例，允许在同一进程中维护多个独立的缓存数据库：

```php
// 获取主数据库实例
$cache = $fileCache->db();

// 获取带别名的数据库实例
$slaveCache = $fileCache->db('slave', ['cache_dir' => '/tmp/cache_slave']);
```

## LRU 热缓存机制

File 缓存类实现了 LRU（最近最少使用）热缓存机制，将频繁访问的数据存储在内存中：

- **MAX_HOT_CACHE_COUNT**: 最大热缓存数量为 200
- **hotCache**: 热缓存存储数组
- **lruRecords**: LRU 记录存储数组
- 热缓存数据优先访问，提升性能

## 增量垃圾回收

File 缓存类实现了增量垃圾回收机制，使用状态机进行高效的缓存清理：

- **runIncrementalGC()**: 运行增量 GC，可指定最大步数和数据库别名
- **gcInterval**: GC 间隔时间，单位毫秒
- 自动清理过期数据，保持缓存整洁

## 分片锁机制

File 缓存类支持分片锁机制，提高并发性能：

- **SHARD_COUNT**: 分片数量为 16
- **getShardLockName()**: 获取分片锁名称
- 将锁分散到多个分片，减少锁竞争

## 注意事项

1. **文件权限**：确保缓存目录具有正确的读写权限。
2. **锁机制**：支持基于 file_handle 的文件锁或信号锁，可根据需要选择。
3. **性能考虑**：适用于各种规模的缓存数据，通过 LRU 热缓存和增量 GC 优化性能。
4. **并发访问**：通过锁机制和分片锁保证并发访问的安全性。
5. **数据持久化**：所有数据存储在文件系统中，重启应用后数据仍然存在。
6. **多数据库支持**：支持在同一进程中维护多个独立的缓存数据库。
7. **高级操作**：提供位操作、队列操作、范围查询、原子操作和 Redis 流兼容操作等高级功能。

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2025-12-02 14:00:00