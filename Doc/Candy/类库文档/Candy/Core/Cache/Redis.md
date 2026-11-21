# Redis 缓存驱动

Redis 缓存驱动提供高性能的 Redis 缓存解决方案，支持多种 Redis 数据结构操作、分布式锁、电路熔断等高级功能。

## 核心特性

### 连接池管理
- **懒式初始化**：首次调用时自动创建连接
- **连接复用**：基于空闲时间复用连接（1800 秒阈值）
- **自动清理**：每 300 秒清理一次过期连接
- **多实例支持**：支持单实例和集群模式

### 本地 LRU 二级缓存
- **200 个热点缓存**：自动管理 `hotCache` 数组，最多 200 个条目
- **自动修剪**：超过阈值时自动调用 `trimLru()` 修剪
- **减少 Redis 交互**：热点数据本地缓存，提升性能

### 电路熔断机制
- **失败阈值**：5 次失败后触发熔断
- **恢复时间**：熔断 10 秒后自动恢复
- **方法**：
  - `isFuseAllow()`: 检查是否允许访问
  - `recordSuccess()`: 记录成功
  - `recordFailure()`: 记录失败

### 多维键支持
- **嵌套键操作**：支持 `key.path.path.path` 结构
- **方法**：
  - `parseNestedKey()`: 解析嵌套键
  - `setNestedValue()`: 设置嵌套值
  - `getNestedValue()`: 获取嵌套值

## 基础缓存操作

### 设置缓存
```php
// 设置缓存，支持 TTL
$this->cache($key, $value, $ttl);

// 支持多维键
$this->cache('user.profile.name', 'John');
```

### 获取缓存
```php
// 获取缓存
$value = $this->get($key);

// 获取嵌套值
$name = $this->get('user.profile.name');
```

### 缓存删除
```php
// 删除单个键
$this->delete($key);

// 删除多个键
$this->delete($keys);

// 删除模式匹配
$this->deleteByPattern('user:*');
```

### 缓存存在性检查
```php
// 检查是否存在
$exists = $this->has($key);

// 批量检查
$this->hasMultiple($keys);
```

### 缓存过期设置
```php
// 设置过期时间
$this->expire($key, $ttl);

// 永久有效
$this->persist($key);
```

## Redis 数据结构操作

### Sets（集合）
- `sAdd()`: 添加成员
- `sRem()`: 删除成员
- `sMembers()`: 获取所有成员
- `sCard()`: 获取集合大小
- `sUnion()`: 并集
- `sInter()`: 交集
- `sDiff()`: 差集
- `sIsMember()`: 检查成员是否存在
- `sPop()`: 随机弹出成员
- `sRandMember()`: 随机获取成员

### Hashes（哈希）
- `hSet()`: 设置字段
- `hMSet()`: 批量设置字段
- `hGet()`: 获取字段
- `hGetAll()`: 获取所有字段
- `hKeys()`: 获取所有键
- `hVals()`: 获取所有值
- `hLen()`: 获取字段数量
- `hDel()`: 删除字段
- `hExists()`: 检查字段是否存在
- `hMGet()`: 批量获取字段

### Sorted Sets（有序集合）
- `zAdd()`: 添加成员
- `zIncrBy()`: 增加分数
- `zRange()`: 获取范围
- `zRevRange()`: 反向获取范围
- `zRank()`: 获取排名
- `zRevRank()`: 反向获取排名
- `zScore()`: 获取分数
- `zCount()`: 计数
- `zRangeByScore()`: 按分数范围获取
- `zPopMin()`: 弹出最小
- `zPopMax()`: 弹出最大
- `zCard()`: 获取集合大小
- `zRem()`: 删除成员
- `zInter()`: 交集
- `zUnion()`: 并集

### Lists（列表）
- `lLen()`: 获取长度
- `lIndex()`: 获取指定位置元素
- `lSet()`: 设置指定位置元素
- `lRange()`: 获取范围元素
- `lTrim()`: 裁剪列表

### Geo（地理位置）
- `geoAdd()`: 添加地理位置
- `geoPos()`: 获取位置
- `geoDist()`: 计算距离
- `geoHash()`: 获取地理哈希
- `geoRadius()`: 半径搜索
- `geoRadiusByMember()`: 按成员搜索

### Streams（流）
- `xAdd()`: 添加消息
- `xRange()`: 获取消息范围
- `xRead()`: 读取消息
- `xGroup()`: 创建消费者组
- `xReadGroup()`: 组读取消息
- `xAck()`: 确认消息
- `xRevRange()`: 反向获取范围
- `xLen()`: 获取流长度
- `xDel()`: 删除消息
- `xTrim()`: 修剪流

### HyperLogLog（超日志日志）
- `hLogAdd()`: 添加元素
- `hLogCount()`: 估算元素数量
- `hLogMerge()`: 合并多个 HLL

## 位操作（Bit Operations）

### 位读写操作
- `__rawBit()`: 位操作支持多种模式
  - **rw 模式**: 读写位
  - **count 模式**: 计数
  - **op 模式**: 位运算（AND/OR/XOR）

## 队列操作

### 队列方法
- `__rawPush()`: 队列入队
- `__rawPop()`: 队列出队

## 原子操作

### 原子方法
- `__rawIncr()`: 自增
- `__rawIncrByFloat()`: 浮点自增
- `__rawExpire()`: 设置过期时间
- `__rawPersist()`: 永久有效
- `__rawRename()`: 重命名

## 管道优化

### 批量操作
- `__rawMSet()`: 批量设置
- `__rawMGet()`: 批量获取
- `__rawMDelete()`: 批量删除
- `__rawMHas()`: 批量检查

## 向量（缓存内小集合，非 ANN）

适合百～千级临时近邻；正式 embedding 请用 `V()` / `VectorServer`（或运维 `S('Pgvector')`）。

| 方法 | 说明 |
|------|------|
| `vectorSet($key, $vector, $dimension, $expire=0)` | 结构化写入 `{dimension,vector,norm,timestamp}`，并登记索引 |
| `vectorGet($key)` | 读回结构或 `null` |
| `vectorMSet($items, $expire=0)` | 批量，`$items` 为 `[[key, vector, dimension], ...]` |
| `vectorDelete($key)` | 删除并更新索引 |
| `vectorScanSearch($prefix, $query, $topK=10, $threshold=0.0)` | **前缀线性扫描**，返回 `[['key'=>..., 'score'=>...], ...]` |
| `vectorAnnSearch(...)` | **废弃别名**，内部转调 `vectorScanSearch`（非 ANN） |
| `vectorCosineSimilarity($a, $b)` | 纯计算 |

```php
$redis->vectorSet('docs:1', [0.1, 0.2, 0.3], 3);
$redis->vectorSet('docs:2', [0.15, 0.25, 0.35], 3);
$hits = $redis->vectorScanSearch('docs:', [0.1, 0.2, 0.3], 10);
// [['key' => '...docs:1', 'score' => 1.0], ...]
```

## Cuckoo Filter（布谷鸟过滤器）

### 过滤器操作
- `cFilterInit()`: 初始化
- `cFilterAdd()`: 添加元素
- `cFilterExists()`: 检查元素是否存在
- `cFilterDelete()`: 删除元素
- `cFilterClear()`: 清空过滤器

## 栈操作

### 栈方法
- `stackPush()`: 入栈
- `stackPop()`: 出栈
- `stackPeek()`: 查看栈顶
- `stackIsEmpty()`: 检查是否为空
- `stackSize()`: 获取栈大小
- `stackClear()`: 清空栈

## 分布式锁

### 锁方法
- `lock()`: 获取锁
- `unlock()`: 释放锁
- `refresh()`: 续期锁

### 锁配置
- `spinCount`: 自旋次数，默认 50
- `lockPrefix`: 锁键前缀 `'redis_lock_'`
- `lockTimeout`: 锁超时时间，默认 3000ms

## 常量定义

```php
const NULL_CACHE_TTL = 5;  // 非存在键的 TTL
const MAX_HOT_CACHE_COUNT = 200;  // 最大热点缓存条目
const DEFAULT_SPIN_COUNT = 50;  // 默认自旋次数
const SHARD_COUNT = 16;  // 分片数（与 File/Shmop 对齐）
const FUSE_FAILURE_THRESHOLD = 5;  // 熔断失败阈值
const FUSE_RECOVERY_TIME = 10;  // 熔断恢复时间（秒）
const LOCK_PREFIX = 'redis_lock_';  // 锁键前缀
```

## 二进制序列化

- 使用 `Pack\Binary` 进行高效数据序列化
- 支持复杂对象序列化

## 使用示例

```php
// 单实例连接
$redis = new \Candy\Cache\Redis();

// 集群模式
$redis = new \Candy\Cache\Redis(['mode' => Cluster]);

// 设置缓存
$redis->cache('user:1', ['name' => 'John'], 3600);

// 获取缓存
$user = $redis->get('user:1');

// 使用多维键
$redis->cache('user:1.profile.name', 'John');
$name = $redis->get('user:1.profile.name');

// 分布式锁
$lock = $redis->lock('myLock', 3000);
if ($lock) {
    // 执行关键操作
    $redis->unlock('myLock');
}

// 集合操作
$redis->sAdd('tags', 'php');
$redis->sAdd('tags', 'redis');
$tags = $redis->sMembers('tags');

// 有序集合
$redis->zAdd('leaderboard', 100, 'player1');
$redis->zAdd('leaderboard', 150, 'player2');
$top5 = $redis->zRange('leaderboard', 0, 4);

// 向量：前缀线性扫描（非 ANN）
$redis->vectorSet('docs:1', [0.1, 0.2, 0.3], 3);
$redis->vectorSet('docs:2', [0.15, 0.25, 0.35], 3);
$hits = $redis->vectorScanSearch('docs:', [0.1, 0.2, 0.3], 10);
```