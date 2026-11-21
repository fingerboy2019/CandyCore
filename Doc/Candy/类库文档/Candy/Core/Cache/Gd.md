# GlobalData 缓存驱动类

## 类简介

Candy 框架的 GlobalData 缓存驱动类，提供功能强大的分布式缓存解决方案，支持多种数据结构和高级特性。

**注意：** 该类为最终类，不允许继承。

## 命名空间

```php
namespace Candy\Core\Cache;
```

## 类定义

```php
final class Gd extends Cache {
    // 类实现...
}
```

## 类属性

### $address

```php
/**
 * 服务器地址 - 存储 GlobalData 服务器的地址
 * @var string 服务器地址，格式为 IP:PORT
 */
private string $address;
```

### $lastCleanupTime

```php
/**
 * 上次清理时间 - 记录最后一次清理连接池的时间戳
 * @var int 时间戳，单位秒，默认值为 0
 */
private $lastCleanupTime = 0;
```

### $connection_cleanup_interval

```php
/**
 * 连接池清理间隔 - 自动清理过期连接的时间间隔
 * @var int 时间间隔，单位秒，默认值为 300
 */
private $connection_cleanup_interval = 300;
```

### $connectionMetadata

```php
/**
 * 连接池元数据 - 记录连接的创建时间和最后使用时间
 * @var array 连接元数据数组，默认值为空数组
 */
private $connectionMetadata = [];
```

### $connectionPool

```php
/**
 * 静态连接池 - 存储和重用缓存服务器连接
 * @var array 连接池数组，键为连接标识，值为连接对象，默认值为空数组
 */
private $connectionPool = [];
```

### $cache

```php
/**
 * 缓存对象实例 - 具体的缓存驱动实现对象
 * @var object|null 缓存驱动对象或 null，默认值为 null
 */
private $cache = null;
```

### $lockOutTime

```php
/**
 * 锁超时时间 - 分布式锁的最大持有时间
 * @var int 锁超时时间，单位毫秒，-1 表示不超时
 */
private int $lockOutTime;
```

### $heldLocks

```php
/**
 * 当前进程持有的锁 - 记录所有已获取的分布式锁
 * @var array 锁信息数组，键为 lockName，值为锁句柄/信息，默认值为空数组
 */
private array $heldLocks = [];
```

### $hotCache

```php
/**
 * 热缓存 - LRU 缓存存储频繁访问的数据
 * @var array 热缓存数组，默认值为空数组
 */
private array $hotCache = [];
```

### $lruRecords

```php
/**
 * LRU 记录 - 记录缓存访问顺序以实现 LRU 淘汰
 * @var array LRU 记录数组，键为 key，值为时间戳，默认值为空数组
 */
private array $lruRecords = [];
```

### $BUCKET_PREFIX

```php
/**
 * Bucket 前缀 - 用于组织缓存数据的命名空间前缀
 * @var string 前缀字符串，默认值为'gd_'
 */
private string $BUCKET_PREFIX = 'gd_';
```

### $lockTtl

```php
/**
 * 锁 TTL - 分布式锁的过期时间
 * @var int 锁过期时间，单位毫秒，默认值为 5000
 */
private int $lockTtl = 5000;
```

### $retryInterval

```php
/**
 * 重试间隔 - 重试操作的间隔时间
 * @var int 重试间隔时间，单位毫秒，默认值为 10
 */
private int $retryInterval = 10;
```

### $enableLocalCache

```php
/**
 * 启用本地缓存 - 是否启用 LRU 本地缓存
 * @var bool 是否启用，默认值为 true
 */
private bool $enableLocalCache = true;
```

### $MAX_HOT_CACHE_COUNT

```php
/**
 * 热缓存最大数量 - LRU 热缓存的最大条目数
 * @var int 最大数量，默认值为 200
 */
private const int $MAX_HOT_CACHE_COUNT = 200;
```

## 主要方法

### __construct()

```php
/**
 * 构造函数，初始化 GlobalData 缓存驱动
 * @param array $config 缓存配置数组
 *                      - servers: 服务器地址配置，支持单服务器和多服务器模式
 *                      - timeout: 连接超时时间，默认值为 2
 *                      - retry_interval: 重试间隔时间，默认值为 10
 *                      - lock_out_time: 锁超时时间，单位毫秒，-1 表示不超时，默认值为 3000
 *                      - connection_cleanup_interval: 连接清理间隔，默认值为 300
 * @return void 无返回值
 */
public function __construct(array $config = [])
```

### __ttl()

```php
/**
 * 获取缓存有效时间
 * @param string $key 缓存键名
 * @return int 剩余有效时间（秒），-1 表示不存在或已过期，0 表示永不过期
 */
public function __ttl(string $key): int
```

### __clear()

```php
/**
 * 清除所有缓存内容
 * 由于安全考虑，GlobalData 缓存不允许清空操作
 * @return bool 返回 false，GlobalData 缓存不支持清空操作
 */
public function __clear(): bool
```

### __rawSet()

```php
/**
 * 缓存对象存储过程实现方法
 * 将数据存储到 GlobalData 缓存中
 * @param string $key 缓存键名
 * @param mixed $value 缓存值
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return mixed 存储结果
 */
public function __rawSet(string $key, mixed $value = '', int $ttl = 0): mixed
```

### __rawGet()

```php
/**
 * 缓存对象读取过程实现方法
 * 从 GlobalData 缓存中读取数据
 * @param string $key 缓存键名
 * @param mixed $default 默认值，当键不存在时返回
 * @return mixed 缓存值或默认值
 */
public function __rawGet(string $key, mixed $default = null): mixed
```

### __rawDelete()

```php
/**
 * 缓存对象删除过程实现方法
 * 从 GlobalData 缓存中删除指定键
 * @param string $key 缓存键名
 * @return bool|int 删除结果
 */
public function __rawDelete(string $key): bool|int
```

### __rawHas()

```php
/**
 * 检查值是否存在
 * 检查指定键是否存在于缓存中
 * @param string $key 缓存键名
 * @return bool 是否存在
 */
public function __rawHas(string $key): bool
```

### __acquireLock()

```php
/**
 * 获取指定键的原子锁。
 * 获取指定键的原子锁，支持可重入锁和锁过期检测
 * @param string $lockName 锁的名称 (通常是 md5($key))
 * @return bool 成功获取锁返回 true，否则 false
 */
public function __acquireLock(string $lockName): bool
```

### __releaseLock()

```php
/**
 * 释放指定键的原子锁。
 * 释放指定键的原子锁
 * @param string $lockName 锁的名称
 * @return bool 成功释放锁返回 true，否则 false
 */
public function __releaseLock(string $lockName): bool
```

### __releaseAllLocksOnShutdown()

```php
/**
 * 注册关闭函数，确保在脚本结束时释放所有持有的锁
 * @return void 无返回值
 */
public function __releaseAllLocksOnShutdown(): void
```

### __getCache()

```php
/**
 * 获取缓存对象方法
 * 管理连接池，重用或创建新的 GlobalData 缓存连接
 * @return bool|object 缓存对象或 false
 */
private function __getCache(): bool|object
```

### __cleanupConnectionPool()

```php
/**
 * 清理连接池
 * 定期清理过期和闲置的连接
 * @return void 无返回值
 */
private function __cleanupConnectionPool(): void
```

### __cacheStatus()

```php
/**
 * 检查缓存连接是否成功
 * 检查 GlobalData 缓存连接是否成功
 * @return bool 连接成功返回 true，否则 false
 */
public function __cacheStatus(): bool
```

### parseNestedKey()

```php
/**
 * 解析嵌套键 - 将多层级键解析为数组结构
 * @param string $key 嵌套键名，如 'user.profile.name'
 * @return array 键值数组，如 ['user' => ['profile' => ['name' => ...]]]
 */
public function parseNestedKey(string $key): array
```

### setNestedValue()

```php
/**
 * 设置嵌套值 - 支持多维键的原子设置
 * @param array $keyPath 键路径数组
 * @param mixed $value 要设置的值
 * @return bool 设置是否成功
 */
public function setNestedValue(array $keyPath, mixed $value): bool
```

### getNestedValue()

```php
/**
 * 获取嵌套值 - 支持多维键的原子获取
 * @param array $keyPath 键路径数组
 * @param mixed $default 默认值
 * @return mixed 值或默认值
 */
public function getNestedValue(array $keyPath, mixed $default = null): mixed
```

### updateAccessMeta()

```php
/**
 * 更新访问元数据 - 记录缓存键的访问时间以支持 LRU 淘汰
 * @param string $key 缓存键名
 * @return void
 */
private function updateAccessMeta(string $key): void
```

### lruElimination()

```php
/**
 * LRU 淘汰 - 移除冷数据以腾出热缓存空间
 * 移除 20% 的冷数据
 * @return void
 */
private function lruElimination(): void
```

### __rawIncr()

```php
/**
 * 原子递增操作
 * @param string $key 缓存键名
 * @param int $step 递增步长
 * @param int $type 操作类型
 * @return mixed 操作结果
 */
public function __rawIncr(string $key, int $step = 1, int $type = 1): mixed
```

### __rawIncrByFloat()

```php
/**
 * 原子浮点递增操作
 * @param string $key 缓存键名
 * @param float $by 递增量
 * @return float 递增后的值
 */
public function __rawIncrByFloat(string $key, float $by): float
```

### __rawExpire()

```php
/**
 * 设置键的过期时间
 * @param string $key 缓存键名
 * @param int $seconds 过期时间（秒）
 * @return bool 设置是否成功
 */
public function __rawExpire(string $key, int $seconds): bool
```

### __rawPersist()

```php
/**
 * 移除键的过期时间 - 设置为永不过期
 * @param string|array $key 缓存键名或键名数组
 * @return bool 操作是否成功
 */
public function __rawPersist(string|array $key): bool
```

### __rawRename()

```php
/**
 * 原子重命名键
 * @param string $oldKey 原键名
 * @param string $newKey 新键名
 * @return bool 重命名是否成功
 */
public function __rawRename(string $oldKey, string $newKey): bool
```

### __rawSIsMember()

```php
/**
 * 检查成员是否存在于集合中
 * @param string $key 缓存键名
 * @param string $member 成员
 * @return bool 成员是否存在
 */
public function __rawSIsMember(string $key, string $member): bool
```

### __rawSPop()

```php
/**
 * 从集合中弹出成员
 * @param string $key 缓存键名
 * @param int $count 弹出数量
 * @return array 弹出的成员数组
 */
public function __rawSPop(string $key, int $count = 1): array
```

### __rawSRandMember()

```php
/**
 * 随机获取集合成员
 * @param string $key 缓存键名
 * @param int $count 获取数量
 * @param bool $withReplacement 是否允许重复
 * @return array 随机成员数组
 */
public function __rawSRandMember(string $key, int $count = 1, bool $withReplacement = false): array
```

### __rawSDiff()

```php
/**
 * 集合差集操作
 * @param string|array $keys 第一个集合或多个集合键名
 * @param string|array $otherKeys 其他集合键名
 * @return array 差集结果
 */
public function __rawSDiff(string|array $keys, string|array $otherKeys = []): array
```

### __rawHExists()

```php
/**
 * 检查哈希字段是否存在
 * @param string $key 缓存键名
 * @param string $field 字段名
 * @return bool 字段是否存在
 */
public function __rawHExists(string $key, string $field): bool
```

### __rawHGetAll()

```php
/**
 * 获取哈希中所有字段
 * @param string $key 缓存键名
 * @return array 字段值数组
 */
public function __rawHGetAll(string $key): array
```

### __rawHVals()

```php
/**
 * 获取哈希中所有值
 * @param string $key 缓存键名
 * @return array 值数组
 */
public function __rawHVals(string $key): array
```

### __rawHMGet()

```php
/**
 * 批量获取哈希字段
 * @param string $key 缓存键名
 * @param array $fields 字段名数组
 * @return array 字段值数组
 */
public function __rawHMGet(string $key, array $fields): array
```

### __rawZRem()

```php
/**
 * 从有序集合中删除成员
 * @param string $key 缓存键名
 * @param string|array $member 成员或成员数组
 * @return int 删除的成员数量
 */
public function __rawZRem(string $key, string|array $member): int
```

### __rawZRevRange()

```php
/**
 * 获取有序集合按分数倒序的元素
 * @param string $key 缓存键名
 * @param int $start 起始索引
 * @param int $stop 结束索引
 * @return array 元素数组
 */
public function __rawZRevRange(string $key, int $start = 0, int $stop = -1): array
```

### __rawZCount()

```php
/**
 * 计算有序集合中分数在区间内的成员数
 * @param string $key 缓存键名
 * @param float $min 最小分数
 * @param float $max 最大分数
 * @return int 成员数量
 */
public function __rawZCount(string $key, float $min, float $max): int
```

### __rawZIncrBy()

```php
/**
 * 有序集合成员分数增量
 * @param string $key 缓存键名
 * @param int $increment 增量
 * @param string $member 成员
 * @return float 新的分数
 */
public function __rawZIncrBy(string $key, int $increment, string $member): float
```

### __rawZRangeByScore()

```php
/**
 * 获取分数在区间内的元素
 * @param string $key 缓存键名
 * @param string $min 最小分数
 * @param string $max 最大分数
 * @param int $offset 偏移量
 * @param int $count 数量
 * @return array 元素数组
 */
public function __rawZRangeByScore(string $key, string $min, string $max, int $offset = 0, int $count = 100): array
```

### __rawZPopMin()

```php
/**
 * 弹出并返回分数最低的元素
 * @param string $key 缓存键名
 * @param int $count 弹出数量
 * @return array 元素数组
 */
public function __rawZPopMin(string $key, int $count = 1): array
```

### __rawZPopMax()

```php
/**
 * 弹出并返回分数最高的元素
 * @param string $key 缓存键名
 * @param int $count 弹出数量
 * @return array 元素数组
 */
public function __rawZPopMax(string $key, int $count = 1): array
```

### __rawGeoPos()

```php
/**
 * 获取成员的地理位置
 * @param string $key 缓存键名
 * @param string|array $member 成员或成员数组
 * @return array 地理位置数组
 */
public function __rawGeoPos(string $key, string|array $member): array
```

### __rawGeoHash()

```php
/**
 * 获取成员地理位置的 geohash
 * @param string $key 缓存键名
 * @param string|array $member 成员或成员数组
 * @return array geohash 数组
 */
public function __rawGeoHash(string $key, string|array $member): array
```

### __rawGeoRadiusByMember()

```php
/**
 * 获取成员在指定半径范围内的成员
 * @param string $key 缓存键名
 * @param string $member 中心成员
 * @param float $radius 半径（米）
 * @param string $unit 单位（m/km/ft/mi）
 * @param array $options 选项数组
 * @return array 成员数组
 */
public function __rawGeoRadiusByMember(string $key, string $member, float $radius, string $unit = 'm', array $options = []): array
```

### __rawLLen()

```php
/**
 * 获取列表长度
 * @param string $key 缓存键名
 * @return int 列表长度
 */
public function __rawLLen(string $key): int
```

### __rawLIndex()

```php
/**
 * 获取列表元素
 * @param string $key 缓存键名
 * @param int $index 索引
 * @return mixed 元素值
 */
public function __rawLIndex(string $key, int $index): mixed
```

### __rawLSet()

```php
/**
 * 设置列表元素
 * @param string $key 缓存键名
 * @param int $index 索引
 * @param string $value 值
 * @return bool 设置是否成功
 */
public function __rawLSet(string $key, int $index, string $value): bool
```

### __rawLRange()

```php
/**
 * 获取列表元素范围
 * @param string $key 缓存键名
 * @param int $start 起始索引
 * @param int $stop 结束索引
 * @return array 元素数组
 */
public function __rawLRange(string $key, int $start, int $stop): array
```

### __rawLTrim()

```php
/**
 * 裁剪列表
 * @param string $key 缓存键名
 * @param int $start 起始索引
 * @param int $stop 结束索引
 * @return bool 操作是否成功
 */
public function __rawLTrim(string $key, int $start, int $stop): bool
```

### __rawXAdd()

```php
/**
 * 添加消息到流
 * @param string $key 缓存键名
 * @param array $args 消息参数
 * @param string $id 消息 ID
 * @return string 消息 ID
 */
public function __rawXAdd(string $key, array $args, ?string $id = null): string
```

### __rawXRange()

```php
/**
 * 获取流消息范围
 * @param string $key 缓存键名
 * @param string $start 开始 ID
 * @param string $end 结束 ID
 * @param int $count 数量
 * @return array 消息数组
 */
public function __rawXRange(string $key, string $start, string $end, int $count = 100): array
```

### __rawXRead()

```php
/**
 * 读取多个流
 * @param array $keys 键名数组
 * @param string $start 开始 ID
 * @param string $end 结束 ID
 * @param int $count 数量
 * @return array 消息数组
 */
public function __rawXRead(array $keys, string $start, string $end, int $count = 100): array
```

### __rawXGroup()

```php
/**
 * 创建消费组
 * @param string $key 缓存键名
 * @param string $groupName 消费组名
 * @param string $consumerName 消费者名
 * @return bool 创建是否成功
 */
public function __rawXGroup(string $key, string $groupName, string $consumerName): bool
```

### __rawXReadGroup()

```php
/**
 * 消费组读取消息
 * @param string $key 缓存键名
 * @param string $groupName 消费组名
 * @param string $consumerName 消费者名
 * @param array $keys 键名数组
 * @param string $start 开始 ID
 * @param string $end 结束 ID
 * @param int $count 数量
 * @return array 消息数组
 */
public function __rawXReadGroup(string $key, string $groupName, string $consumerName, array $keys, string $start, string $end, int $count = 100): array
```

### __rawXAck()

```php
/**
 * 确认消息已消费
 * @param string $key 缓存键名
 * @param string $groupName 消费组名
 * @param string $consumerName 消费者名
 * @param array $messages 消息数组
 * @return int 确认的消息数量
 */
public function __rawXAck(string $key, string $groupName, string $consumerName, array $messages): int
```

### __rawXRevRange()

```php
/**
 * 获取流消息范围（倒序）
 * @param string $key 缓存键名
 * @param string $start 开始 ID
 * @param string $end 结束 ID
 * @param int $count 数量
 * @return array 消息数组
 */
public function __rawXRevRange(string $key, string $start, string $end, int $count = 100): array
```

### __rawXLen()

```php
/**
 * 获取流消息数量
 * @param string $key 缓存键名
 * @return int 消息数量
 */
public function __rawXLen(string $key): int
```

### __rawXDel()

```php
/**
 * 删除流消息
 * @param string $key 缓存键名
 * @param string|array $ids 消息 ID 或 ID 数组
 * @return int 删除的消息数量
 */
public function __rawXDel(string $key, string|array $ids): int
```

### __rawXTrim()

```php
/**
 * 修剪流消息
 * @param string $key 缓存键名
 * @param int $maxLen 最大长度
 * @return int 删除的消息数量
 */
public function __rawXTrim(string $key, int $maxLen): int
```

### __rawBit()

```php
/**
 * 位运算操作
 * @param string $key 缓存键名
 * @param int $id 位 ID
 * @param array $options 操作选项
 *                      - mode: 'rw' (读写), 'count' (计数), 'op' (按位操作), 'pos' (查找位置)
 *                      - op: 按位操作类型 (AND/OR/XOR/NOT)
 *                      - val: 按位操作值
 * @return mixed 操作结果
 */
public function __rawBit(string $key, int $id, array $options = []): mixed
```

### __rawPush()

```php
/**
 * 队列推入操作
 * @param string $key 缓存键名
 * @param array $args 元素数组
 * @param bool $cover 是否覆盖
 * @param bool $head 是否推入头部
 * @return int 队列长度
 */
public function __rawPush(string $key, array $args, bool $cover = false, bool $head = false): int
```

### __rawPop()

```php
/**
 * 队列弹出操作
 * @param string $key 缓存键名
 * @param bool $head 是否从头部弹出
 * @return array 元素数组
 */
public function __rawPop(string $key, bool $head = false): array
```

### __rawRange()

```php
/**
 * 范围查询
 * @param string $key 缓存键名
 * @param int $offset 偏移量
 * @param int $length 长度
 * @param string $sort 排序方向 ('ASC'/'DESC')
 * @param string $order 排序键 ('BY_SCORE'/'BY_COUNT')
 * @return array 结果数组
 */
public function __rawRange(string $key, int $offset = 0, int $length = 100, string $sort = 'ASC', string $order = 'BY_SCORE'): array
```

### db()

```php
/**
 * 设置数据库别名
 * 支持多数据库实例管理
 * @param string $alias 数据库别名
 * @param array $config 自定义配置
 * @return self 返回当前实例
 */
public function db(string $alias, array $config = []): self
```

### __getHost()

```php
/**
 * 获取主机地址
 * @return string 主机地址
 */
private function __getHost(): string
```

### __getPort()

```php
/**
 * 获取端口号
 * @return int 端口号
 */
private function __getPort(): int
```

### __getConnectionKey()

```php
/**
 * 获取连接键
 * @return string 连接键
 */
private function __getConnectionKey(): string
```

### __spinWait()

```php
/**
 * 自旋等待 - 用于分布式锁的自旋等待
 * @param int $maxSpinCount 最大自旋次数
 * @return bool 是否成功获取锁
 */
private function __spinWait(int $maxSpinCount = 100): bool
```

## 功能特性

### 连接池管理

- **连接池**: 支持连接池管理，自动重用和创建连接
- **自动清理**: 定期清理过期和闲置的连接，默认间隔 300 秒
- **连接元数据**: 记录连接的创建时间和最后使用时间

### LRU 热缓存

- **热缓存**: 最大 200 个条目，存储频繁访问的数据
- **LRU 淘汰**: 自动移除 20% 的冷数据以腾出空间
- **本地缓存**: 支持启用/禁用本地缓存功能

### 多维键支持

- **嵌套键解析**: 支持 `key.path.path.path` 结构
- **嵌套值设置**: 支持原子性地设置嵌套值
- **嵌套值获取**: 支持原子性地获取嵌套值

### CAS 原子操作

- **原子递增**: 支持整数递增操作
- **原子浮点递增**: 支持浮点数递增操作
- **过期时间设置**: 设置键的过期时间
- **移除过期时间**: 设置为永不过期
- **原子重命名**: 支持键的原子重命名

### 集合操作 (Set)

- **成员检查**: 检查成员是否存在于集合中
- **弹出成员**: 从集合中弹出成员
- **随机成员**: 随机获取集合成员
- **集合差集**: 集合差集操作

### 哈希操作 (Hash)

- **字段存在检查**: 检查哈希字段是否存在
- **获取所有字段**: 获取哈希中所有字段
- **获取所有值**: 获取哈希中所有值
- **批量获取字段**: 批量获取哈希字段

### 有序集合操作 (ZSet)

- **删除成员**: 从有序集合中删除成员
- **倒序范围**: 获取有序集合按分数倒序的元素
- **分数区间计数**: 计算分数在区间内的成员数
- **分数增量**: 有序集合成员分数增量
- **分数区间元素**: 获取分数在区间内的元素
- **弹出最低分**: 弹出并返回分数最低的元素
- **弹出最高分**: 弹出并返回分数最高的元素

### 地理操作 (Geo)

- **地理位置**: 获取成员的地理位置
- **Geohash**: 获取成员地理位置的 geohash
- **半径查询**: 获取成员在指定半径范围内的成员

### 列表操作 (List)

- **列表长度**: 获取列表长度
- **列表元素**: 获取列表元素
- **设置元素**: 设置列表元素
- **元素范围**: 获取列表元素范围
- **裁剪列表**: 裁剪列表

### 流操作 (Stream)

- **添加消息**: 添加消息到流
- **消息范围**: 获取流消息范围
- **多流读取**: 读取多个流
- **消费组**: 创建消费组
- **组读取**: 消费组读取消息
- **确认消息**: 确认消息已消费
- **倒序范围**: 获取流消息范围（倒序）
- **消息数量**: 获取流消息数量
- **删除消息**: 删除流消息
- **修剪流**: 修剪流消息

### 位运算操作 (Bit)

- **读写模式**: 读写位模式
- **计数模式**: 计数模式
- **按位操作**: 按位 AND/OR/XOR/NOT 操作
- **位置查找**: 查找位位置

### 队列操作 (Queue)

- **推入操作**: 队列推入操作，支持覆盖和头部插入
- **弹出操作**: 队列弹出操作，支持头部弹出

### 范围查询 (Range)

- **偏移量**: 支持偏移量参数
- **长度限制**: 支持长度限制参数
- **排序方向**: 支持 ASC/DESC 排序
- **排序键**: 支持 BY_SCORE/BY_COUNT 排序

### 分布式锁定

- **自旋等待**: 支持分布式锁的自旋等待机制
- **锁超时**: 支持锁超时时间配置
- **多进程锁**: 支持多进程间的分布式锁

### 多数据库支持

- **数据库别名**: 支持多个数据库实例
- **自定义配置**: 支持为每个实例配置自定义参数

## 注意事项

1. 使用前确保 GlobalData 服务已正确配置和运行
2. 默认连接地址为 127.0.0.1:2207，可通过配置修改
3. 该驱动支持 LRU 本地缓存，可提升性能
4. 连接池会自动清理闲置超过 300 秒的连接
5. 支持分布式锁机制，可用于并发控制
6. 热缓存最大容量为 200 个条目
7. 支持多维键结构，方便处理嵌套数据

## 作者信息

- **作者:** 刘森 (fingerboy@qq.com)
- **日期:** 2025-12-02 14:00:00