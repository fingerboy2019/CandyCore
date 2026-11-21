# Shmop 共享内存缓存驱动

## 概述

Shmop 是 Candy 框架的共享内存缓存驱动，基于 PHP 的 `shmop` 扩展实现，提供高性能的进程间共享内存存储。通过 `sysvsem` 信号量机制实现分布式锁，支持多种数据结构操作和高级功能。

## 核心特性

- **共享内存存储**: 基于 `shmop` 扩展，提供极快的读写速度
- **信号量机制**: 使用 `sysvsem` 实现进程间同步和分布式锁
- **LRU 热缓存**: 自动维护 200 个热点数据，减少磁盘 I/O
- **读/写分离锁**: 支持多个并发读取，单写入锁机制
- **多键操作**: 支持批量设置、获取、删除和存在性检查
- **位图操作**: 高效的位运算和批量扫描
- **流操作**: 支持消息队列风格的追加和读取
- **队列操作**: 支持左右队列模式
- **原子范围查询**: 支持排序和范围查询
- **向量键索引**: 维护向量键列表，支持前缀线性扫描（非 HNSW/ANN）
- **Cuckoo 过滤器**: 支持布隆过滤器变体的高效存在性检查

## 主要类和方法

### Candy\Core\Cache\Shmop 类

#### 构造函数

```php
public function __construct(array $options = []): void
```

- 接受配置数组，初始化共享内存连接
- 支持自定义内存段标识符和大小

#### 连接管理

```php
public function connect(): bool
```

- 建立或连接到共享内存段
- 失败时抛出异常或返回 false

```php
public function disconnect(): void
```

- 断开共享内存连接
- 清理相关资源

#### 基础缓存操作

```php
public function set(string $key, mixed $value, int $ttl = 0): bool
```

- 设置键值对
- `$ttl`: 过期时间（秒），0 表示永不过期
- 自动序列化数据，超过最大长度时写入多个内存段

```php
public function get(string $key, mixed $default = null): mixed
```

- 获取键值
- 返回不存在时返回 `$default`
- 自动反序列化数据

```php
public function delete(string $key): bool
```

- 删除指定键
- 返回是否成功删除

```php
public function has(string $key): bool
```

- 检查键是否存在
- 返回 true/false

```php
public function increment(string $key, int $step = 1): int
```

- 原子递增数值
- 返回新值

```php
public function decrement(string $key, int $step = 1): int
```

- 原子递减数值
- 返回新值

#### 多键操作

```php
public function mset(array $keys, array $values): bool
```

- 批量设置多个键值对
- `$keys`: 键数组
- `$values`: 值数组
- 返回是否全部成功

```php
public function mget(array $keys): array
```

- 批量获取多个键的值
- 返回关联数组，不存在的键返回 null

```php
public function mdelete(array $keys): int
```

- 批量删除多个键
- 返回成功删除的键数量

```php
public function mhas(array $keys): array
```

- 批量检查多个键的存在性
- 返回关联数组，值为 true/false

#### 位图操作

```php
public function bitSet(string $key, int $offset, bool $value): bool
```

- 设置位图指定位置的位
- `$offset`: 位偏移量
- `$value`: true 表示设置位为 1

```php
public function bitGet(string $key, int $offset): int
```

- 获取位图指定位置的位
- 返回 0 或 1

```php
public function bitCount(string $key, ?int $start = null, ?int $end = null): int
```

- 计算位图中 1 的数量
- `$start` 和 `$end`: 可选的范围限制
- 支持批量扫描优化（64 位批量处理）

```php
public function bitOp(string $operation, string $destKey, array $sourceKeys): bool
```

- 执行位运算操作
- `$operation`: AND/OR/NOT/XOR
- `$destKey`: 目标键
- `$sourceKeys`: 源键数组
- 支持批量扫描优化

```php
public function bitPos(string $key, int $start, int $count = 0, bool $value = true): array
```

- 查找位的第一个匹配位置
- `$value`: true 查找 1，false 查找 0
- 返回位置数组

#### 流操作 (Stream Operations)

```php
public function streamAdd(string $key, mixed $value, ?int $streamId = null): ?int
```

- 向流追加数据
- 返回流 ID 或已存在的流 ID
- 支持 `$streamId` 指定流

```php
public function streamRange(string $key, int $start, int $end): array
```

- 获取流的指定范围数据
- 返回数组包含流 ID 和数据

```php
public function streamRead(string $key, int $count = 1): array
```

- 从流读取数据
- `$count`: 读取数量
- 返回读取的数据数组

```php
public function streamReadGroup(string $key, string $group, int $count = 1): array
```

- 从消费者组读取数据
- `$group`: 消费者组名称
- 支持消息队列模式

```php
public function streamAck(string $key, string $group, array $messages): bool
```

- 确认已处理的消息
- 返回是否成功

```php
public function streamPending(string $key, string $group): array
```

- 获取待处理的消息
- 返回消息数组

#### 队列操作

```php
public function queuePush(string $key, mixed $value, bool $left = false): bool
```

- 向队列推入数据
- `$left`: true 表示从左侧推入
- 实现队列或栈模式

```php
public function queuePop(string $key, bool $left = false, int $count = 1): array
```

- 从队列弹出数据
- `$left`: true 表示从左弹出
- 支持批量弹出

```php
public function queueSize(string $key): int
```

- 获取队列当前长度

#### 原子范围查询

```php
public function atomicRange(string $key, int $start, int $end, bool $sort = false): array
```

- 原子性地获取范围数据
- `$sort`: true 表示排序返回
- 支持高并发场景

#### 锁机制

```php
public function acquireLock(string $key, int $timeout = 0, int $waitTime = 0): bool
```

- 获取分布式锁
- `$timeout`: 获取锁超时时间（秒）
- `$waitTime`: 等待时间（秒）
- 使用读/写分离锁机制

```php
public function releaseLock(string $key): bool
```

- 释放分布式锁
- 返回是否成功

```php
public function isLocked(string $key): bool
```

- 检查锁是否被占用

```php
public function setLock(string $key, int $ttl): bool
```

- 手动设置锁

```php
public function getLock(string $key): ?string
```

- 获取锁持有者信息

#### LRU 热缓存

```php
public function addToHotCache(string $key, mixed $value, int $ttl = 0): bool
```

- 将数据添加到 LRU 热缓存
- 最多维护 200 个热点数据
- 自动修剪超过限制的数据

```php
public function getFromHotCache(string $key): mixed
```

- 从热缓存获取数据
- 返回数据或 null

```php
public function removeFromHotCache(string $key): bool
```

- 从热缓存移除数据

```php
public function clearHotCache(): bool
```

- 清空所有热缓存数据

#### 向量（Cache Trait，非 ANN）

Shmop **无**独立 `vectorAdd` / `vectorSearch`。请使用：

- `vectorSet($key, $vector, $dimension, $expire=0)`
- `vectorGet` / `vectorDelete` / `vectorMSet`
- `vectorScanSearch($prefix, $query, $topK, $threshold)` — 无键枚举时可能返回 `[]`
- `vectorAnnSearch` 为废弃别名

正式 ANN：`V()` / `VectorServer`（勿在应用层再写 Vector Service）。

#### Cuckoo 过滤器操作

```php
public function cuckooAdd(string $key, mixed $value): bool
```

- 添加元素到 cuckoo 过滤器
- 用于高效的存在性检查

```php
public function cuckooExists(string $key, mixed $value): bool
```

- 检查元素是否存在
- 可能返回误判为存在

```php
public function cuckooRemove(string $key, mixed $value): bool
```

- 移除元素
- 返回是否成功

```php
public function cuckooSize(string $key): int
```

- 获取过滤器元素数量

#### 数据持久化

```php
public function dump(): bool
```

- 异步持久化数据到磁盘
- 支持配置持久化间隔

```php
public function gc(): bool
```

- 执行垃圾回收
- 清理过期和无效数据
- 支持增量 GC 状态管理

#### 辅助方法

```php
public function keys(string $pattern = ''): array
```

- 获取所有匹配的键
- 支持通配符模式

```php
public function info(): array
```

- 获取共享内存段信息

```php
public function config(array $options): self
```

- 配置共享内存参数
- 支持设置内存段大小、页大小等

```php
public function maxMemory(): int
```

- 获取最大可用内存

```php
public function freeMemory(): int
```

- 获取可用内存

#### 数据访问

```php
protected function read(string $key): string
```

- 内部读取方法
- 处理序列化数据

```php
protected function write(string $key, string $value): bool
```

- 内部写入方法
- 处理长度和页对齐

```php
protected function lockWrite(string $key): bool
```

- 获取写入锁
- 确保并发安全

```php
protected function unlockWrite(string $key): bool
```

- 释放写入锁

```php
protected function lockRead(string $key): bool
```

- 获取读取锁
- 支持多个并发读者

```php
protected function unlockRead(string $key): bool
```

- 释放读取锁

#### 内部辅助方法

```php
protected function serialize(mixed $value): string
```

- 序列化数据
- 使用 Pack\Binary 进行高效序列化

```php
protected function unserialize(string $data): mixed
```

- 反序列化数据

```php
protected function trimHotCache(): void
```

- 修剪热缓存至最大限制

```php
protected function checkMemory(): bool
```

- 检查可用内存

```php
protected function getPageOffset(): int
```

- 获取页偏移量（4KB 对齐）

```php
protected function calculateKeyOffset(string $key): int
```

- 计算键在内存中的偏移量

```php
protected function getSegmentSize(): int
```

- 获取内存段大小

```php
protected function getPageSize(): int
```

- 获取页大小

```php
protected function getSysvSemKey(string $key): int
```

- 计算信号量键

```php
protected function initSegment(): bool
```

- 初始化内存段

```php
protected function createSegment(): bool
```

- 创建新内存段

```php
protected function openSegment(): bool
```

- 打开现有内存段

```php
protected function destroySegment(): bool
```

- 销毁内存段

```php
protected function acquireSignal(string $key): bool
```

- 获取信号量

```php
protected function releaseSignal(string $key): bool
```

- 释放信号量

```php
protected function acquireWriteSignal(string $key): bool
```

- 获取写入信号量

```php
protected function releaseWriteSignal(string $key): bool
```

- 释放写入信号量

```php
protected function acquireReadSignal(string $key): bool
```

- 获取读取信号量

```php
protected function releaseReadSignal(string $key): bool
```

- 释放读取信号量

```php
protected function checkPendingWrites(): bool
```

- 检查待写入数据

```php
protected function processPendingWrites(): void
```

- 处理待写入数据

#### 错误处理

```php
protected function throwConnectionError(string $message): never
```

- 抛出连接错误

```php
protected function throwOperationError(string $key, string $message): never
```

- 抛出操作错误

#### 常量定义

- `NULL_CACHE_TTL = 5`: 非存在键的 TTL
- `MAX_HOT_CACHE_COUNT = 200`: 最大热缓存条目数
- `DEFAULT_PAGE_SIZE = 4096`: 默认页大小（4KB）
- `DEFAULT_SEGMENT_SIZE = 1024 * 1024`: 默认段大小（1MB）
- `DEFAULT_DUMP_INTERVAL = 60`: 默认持久化间隔（秒）
- `DEFAULT_GC_THRESHOLD = 0.7`: 默认 GC 触发阈值（内存使用率 70%）

## 使用示例

```php
use Candy\Core\Cache\Shmop;

// 创建实例
$cache = new Shmop([
    'segment_id' => 12345,
    'memory_size' => 1024 * 1024 * 1024, // 1GB
]);

// 基础操作
$cache->set('user:1:name', '张三', 3600);
$name = $cache->get('user:1:name');
$exists = $cache->has('user:1:name');
$cache->delete('user:1:name');

// 位图操作
$cache->bitSet('visit_log:user:1', 0, true);
$count = $cache->bitCount('visit_log:user:1');

// 流操作
$cache->streamAdd('messages', ['id' => 1, 'content' => 'Hello'], null);
$messages = $cache->streamRead('messages', 5);

// 队列操作
$cache->queuePush('task_queue', ['task' => 'process'], false);
$tasks = $cache->queuePop('task_queue', false, 10);

// 锁机制
if ($cache->acquireLock('lock:user:1', 5, 1)) {
    // 执行操作
    $cache->releaseLock('lock:user:1');
}

// LRU 热缓存
$cache->addToHotCache('hot_data', $data);
$hot = $cache->getFromHotCache('hot_data');

// 向量（Trait API；正式 ANN 用 V()）
$cache->vectorSet('docs:1', [0.1, 0.2, 0.3], 3);
$hits = $cache->vectorScanSearch('docs:', [0.15, 0.25, 0.35], 5);

// Cuckoo 过滤器
$cache->cuckooAdd('filters:blocked', 'spam@example.com');
$isBlocked = $cache->cuckooExists('filters:blocked', 'spam@example.com');

// 垃圾回收
$cache->gc();

// 数据持久化
$cache->dump();
```

## 注意事项

1. **内存限制**: 共享内存大小受系统限制，需合理设置
2. **并发控制**: 使用信号量机制确保线程安全
3. **持久化**: 定期调用 `dump()` 进行数据持久化
4. **GC 清理**: 定期调用 `gc()` 清理过期数据
5. **键长度**: 键名不宜过长，影响内存使用效率
6. **内存监控**: 建议监控内存使用情况，避免内存泄漏
7. **信号量竞争**: 高并发场景下注意信号量竞争问题

## 技术实现细节

- 数据采用 4KB 页对齐优化
- 使用 `sysvsem` 信号量实现读写分离锁
- LRU 算法维护热缓存，减少磁盘 I/O
- 支持异步持久化和增量 GC
- 批量操作优化，提升吞吐量
- 多数据结构支持，满足多样化场景需求