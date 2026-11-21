# Cache

## 类简介
Cache 是 Candy 框架中的缓存抽象类，提供统一的缓存操作接口，支持多种缓存驱动（如 Redis、Memcached 等）。该类设计为抽象类，需要通过具体的驱动实现类来实例化使用。

### 功能特点
- 抽象类设计，可适配多种缓存驱动
- 支持分布式锁，确保高并发环境下的数据一致性
- 自动驱动检测和选择
- 支持二维数组操作
- 内置异常处理和日志记录
- 支持事务操作（multi、exec、discard）
- 提供 Redis 专用操作（适用于 Redis 驱动）
- 支持限流和统计功能

## 使用示例
```php
// 初始化缓存实例（这里以 Redis 为例）
$cache = new \Candy\Core\Cache\RedisCache();

// 设置缓存
$cache->set('user.info', ['id' => 1, 'name' => '张三'], 3600);

// 获取缓存
$userInfo = $cache->get('user.info');

// 检查缓存是否存在
if ($cache->has('user.info')) {
    echo '缓存存在';
}

// 删除缓存
$cache->delete('user.info');

// 使用二维数组键名
$cache->set('user.1.info', ['name' => '李四'], 3600);
$userInfo = $cache->get('user.1.info');

// 数组操作
$cache->lPush('queue', ['task1']);
$task = $cache->lpop('queue');

// 事务操作
$cache->multi();
$cache->set('key1', 'value1');
$cache->set('key2', 'value2');
$cache->exec();

// Redis 专用操作（仅 Redis 驱动支持）
$cache->mSet(['key1' => 'value1', 'key2' => 'value2']);
$cache->sAdd('tags', 'new');
```

### 注意事项
- 使用 Shmop 驱动时缓存键名不允许包含 \_fragment\_字符
- 分布式锁操作会在发生异常时自动释放
- 所有修改操作都支持分布式锁保护
- 过期时间单位为秒，0 表示永不过期
- Redis 专用操作仅在使用 Redis 驱动时可用
- 限流和统计功能需要 Redis 驱动支持

## 命名空间
```php
namespace Candy\Core;
```

## 类定义
```php
abstract class Cache
{
    // 类主体...
}
```

## 类属性

### $retryInterval
```php
/**
 * 重试间隔时间，单位微秒
 * 用于分布式锁获取失败时的重试等待时间
 *
 * @var int
 * @access private 私有属性，只能在类内部访问
 */
private int $retryInterval = 5000;
```

### $cachePrefix
```php
/**
 * 缓存键前缀
 * 用于区分不同应用的缓存数据
 *
 * @var string
 * @access private 私有属性，只能在类内部访问
 */
private string $cachePrefix = 'CandyCache_';
```

### $data
```php
/**
 * 缓存数据存储数组
 * 用于存储当前脚本执行过程中操作过的缓存数据
 *
 * @var array
 * @access public 公共属性，可以在类外部直接访问
 */
public array $data = [];
```

## 主要方法

### 基础操作方法

#### add()
```php
/**
 * 添加缓存
 * 向缓存中添加数据，如果键已存在则不覆盖
 *
 * @param string $key 缓存键名，使用 Shmop 驱动时不允许包含_fragment_字符
 * @param mixed $value 缓存值，支持任意 PHP 类型
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return bool 添加是否成功
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.data'
 */
public function add(string $key, mixed $value = '', int $ttl = 0): bool
```

#### getset()
```php
/**
 * 获取并设置缓存
 * 内部辅助方法，用于 getset 操作
 *
 * @param string $key 缓存键名
 * @param mixed $value 缓存值，支持任意 PHP 类型
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return mixed 原始缓存值或 null
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.data'
 */
public function getset(string $key, mixed $value = '', int $ttl = 0): mixed
```

#### set()
```php
/**
 * 新增或修改缓存
 * 向缓存中设置数据，支持新增和修改
 * 当键已存在时会覆盖原有值
 * 支持分布式锁保护，确保高并发环境下的数据一致性
 *
 * @param string $key 缓存键名，使用 Shmop 驱动时不允许包含_fragment_字符
 * @param mixed $value 缓存值，支持任意 PHP 类型
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return bool 设置是否成功
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.data'
 * @throws Exception 当获取分布式锁失败时抛出异常
 */
public function set(string $key, mixed $value = '', int $ttl = 0): bool
```

#### get()
```php
/**
 * 获取缓存
 * 从缓存中获取数据
 * 支持分布式锁保护，确保高并发环境下的数据一致性
 *
 * @param string $key 缓存键名
 * @param mixed $default 默认值，当缓存不存在时返回该值
 * @return mixed 缓存值或默认值
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.data'
 * @throws Exception 当获取分布式锁失败时抛出异常
 */
public function get(string $key, mixed $default = null): mixed
```

#### delete()
```php
/**
 * 删除缓存
 * 从缓存中删除指定的键
 * 支持分布式锁保护，确保高并发环境下的数据一致性
 *
 * @param string $key 缓存键名
 * @return bool 删除是否成功
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.data'
 * @throws Exception 当获取分布式锁失败时抛出异常
 */
public function delete(string $key): bool
```

#### has()
```php
/**
 * 检查缓存是否存在
 * 检查指定的缓存键是否存在
 *
 * @param string $key 缓存键名
 * @return bool 是否存在
 * @access public 公共方法，可在类外部调用
 */
public function has(string $key): bool
```

### ttl()
```php
/**
 * 获取缓存有效时间
 *
 * @param string $key 缓存键名
 * @return int 剩余有效时间（秒），-1 表示不存在或已过期，0 表示永不过期
 */
public function ttl(string $key): int
```

### clear()
```php
/**
 * 清除所有缓存内容
 * 根据不同缓存驱动实现缓存清空
 *
 * @return bool 操作是否成功
 */
public function clear(): bool
```

### 数组操作方法

#### lPush()
```php
/**
 * 向数组头部添加元素
 * 向指定的缓存键对应的数组头部添加元素
 * 支持分布式锁保护，确保高并发环境下的数据一致性
 *
 * @param string $key 缓存键名
 * @param array $data 要添加的数据
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return bool 添加是否成功
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.queue'
 * @throws Exception 当获取分布式锁失败时抛出异常
 */
public function lPush(string $key, array $data, int $ttl = 0): bool
```

#### rpush()
```php
/**
 * 向数组尾部添加元素
 * 向指定的缓存键对应的数组尾部添加元素
 * 支持分布式锁保护，确保高并发环境下的数据一致性
 *
 * @param string $key 缓存键名
 * @param array $data 要添加的数据
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return bool 添加是否成功
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.queue'
 * @throws Exception 当获取分布式锁失败时抛出异常
 */
public function rpush(string $key, array $data, int $ttl = 0): bool
```

#### arrayMerge()
```php
/**
 * 合并数组
 * 核心方法，用于合并数组缓存
 * 支持分布式锁保护，确保高并发环境下的数据一致性
 *
 * @param string $key 缓存键名
 * @param array $data 要合并的数据
 * @param string $type 合并类型，'lPush'表示从头部合并，'rPush'表示从尾部合并
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return bool 合并是否成功
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.queue'
 * @throws Exception 当获取分布式锁失败或缓存操作出错时抛出异常
 */
public function arrayMerge(string $key, array $data, string $type = 'rPush', int $ttl = 0): bool
```

#### lpop()
```php
/**
 * 移除并返回数组的第一个元素
 * 从指定的缓存键对应的数组头部弹出一个元素
 * 如果数组为空，返回 false
 * 内部调用 arrayDelete 方法实现，设置 type 为'lpop'
 *
 * @param string $key 缓存键名
 * @return mixed 弹出的元素或 false（如果数组为空或操作失败）
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.queue'
 */
public function lpop(string $key): mixed
```

#### rpop()
```php
/**
 * 移除并返回数组的最后一个元素
 * 从指定的缓存键对应的数组尾部弹出一个元素
 * 如果数组为空，返回 false
 * 内部调用 arrayDelete 方法实现，设置 type 为'rpop'
 *
 * @param string $key 缓存键名
 * @return mixed 弹出的元素或 false（如果数组为空或操作失败）
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.stack'
 */
public function rpop(string $key): mixed
```

#### lrange()
```php
/**
 * 获取数组指定范围内的元素
 * 从指定的缓存键对应的数组中获取指定范围的元素
 * 支持排序和分页查询功能
 * 如果键不存在，返回空数组
 *
 * @param string $name 缓存键名
 * @param int $offset 起始偏移量，从 0 开始计数
 * @param int $length 要获取的元素个数
 * @param string|null $sort 可选，用于排序的键名
 * @param string|null $order 可选，排序方式，'asc'升序或'desc'降序
 * @return array 查询结果数组或空数组
 * @access public 公共方法，可在类外部调用
 * @note 支持二维数组键名格式，如'user.items'
 */
public function lrange(string $name, int $offset, int $length = 0, $sort = '', string $order = 'asc'): array
```

### 数值操作方法

#### incr()
```php
/**
 * 自增一
 * 将缓存键对应的值加 1
 *
 * @param string $name 缓存键名
 * @return bool 操作是否成功
 */
public function incr(string $name): bool
```

#### decr()
```php
/**
 * 自减一
 * 将缓存键对应的值减 1，最低为 0
 *
 * @param string $name 缓存键名
 * @return bool 操作是否成功
 */
public function decr(string $name): bool
```

### 事务操作方法

#### multi()
```php
/**
 * 开启事务
 * 开启缓存事务模式，后续的操作将被记录但暂不执行
 * 直到调用 exec() 方法才会批量执行
 *
 * @return void
 * @access public 公共方法，可在类外部调用
 */
public function multi(): void
```

#### exec()
```php
/**
 * 执行事务
 * 批量执行之前 multi() 开启的事务中的所有操作
 *
 * @return bool 执行是否成功
 * @access public 公共方法，可在类外部调用
 */
public function exec(): bool
```

#### discard()
```php
/**
 * 丢弃事务
 * 放弃已开启但未执行的事务，所有已记录的操作将被取消
 *
 * @return void
 * @access public 公共方法，可在类外部调用
 */
public function discard(): void
```

### Redis 专用操作方法（仅 Redis 驱动支持）

#### mSet()
```php
/**
 * 批量设置多个键值对
 * Redis 专用方法，一次性设置多个缓存键值对
 *
 * @param array $data 键值对数组，格式为 ['key1' => 'value1', 'key2' => 'value2', ...]
 * @return bool 操作是否成功
 * @access public 公共方法，可在类外部调用
 * @note 仅 Redis 驱动支持
 */
public function mSet(array $data): bool
```

#### sAdd()
```php
/**
 * 向集合中添加元素
 * Redis 专用方法，向集合中添加一个或多个元素
 *
 * @param string $key 缓存键名
 * @param array|string $data 要添加的元素，支持单个元素或数组
 * @return int 添加成功的新元素数量
 * @access public 公共方法，可在类外部调用
 * @note 仅 Redis 驱动支持
 */
public function sAdd(string $key, array|string $data): int
```

#### zAdd()
```php
/**
 * 向有序集合中添加元素
 * Redis 专用方法，向有序集合中添加元素并设置分数
 *
 * @param string $key 缓存键名
 * @param array $data 键值对数组，格式为 ['score' => 1.0, 'value' => 'data', ...]
 * @return int 添加成功的新元素数量
 * @access public 公共方法，可在类外部调用
 * @note 仅 Redis 驱动支持
 */
public function zAdd(string $key, array $data): int
```

#### delete()
```php
/**
 * 批量删除多个键
 * Redis 专用方法，一次性删除多个缓存键
 *
 * @param array|string $keys 要删除的键，支持单个键或数组
 * @return int 成功删除的键数量
 * @access public 公共方法，可在类外部调用
 * @note 仅 Redis 驱动支持
 */
public function delete(array|string $keys): int
```

#### lSet()
```php
/**
 * 设置数组指定索引位置的元素
 * Redis 专用方法，设置列表指定位置的元素
 *
 * @param string $key 缓存键名
 * @param int $index 索引位置
 * @param mixed $value 要设置的值
 * @return bool 操作是否成功
 * @access public 公共方法，可在类外部调用
 * @note 仅 Redis 驱动支持
 */
public function lSet(string $key, int $index, mixed $value): bool
```

### 限流操作方法

#### rateLimit()
```php
/**
 * 限流检查
 * 检查指定键在指定时间窗口内的操作次数是否超过限制
 *
 * @param string $key 缓存键名
 * @param int $limit 最大允许的操作次数
 * @param int $window 时间窗口，单位秒
 * @return bool 是否允许执行操作
 * @access public 公共方法，可在类外部调用
 * @note 需要 Redis 驱动支持
 */
public function rateLimit(string $key, int $limit, int $window): bool
```

### 统计操作方法

#### getStats()
```php
/**
 * 获取缓存统计信息
 * 获取当前缓存驱动的统计信息
 *
 * @return array 统计信息数组，包含内存使用、连接状态等信息
 * @access public 公共方法，可在类外部调用
 * @note 需要 Redis 驱动支持
 */
public function getStats(): array
```

#### keys()
```php
/**
 * 获取所有键名
 * 获取当前缓存驱动中的所有键名
 *
 * @param string $pattern 可选，键名匹配模式
 * @return array 键名数组
 * @access public 公共方法，可在类外部调用
 * @note 仅 Redis 驱动支持，其他驱动可能不支持
 */
public function keys(string $pattern = '*'): array
```

## 抽象方法（自建缓存驱动必须实现的方法）

#### __ttl()（获取缓存有效时间）
```php
/**
 * 获取缓存有效时间
 *
 * @param string $key 缓存键名
 * @return int 剩余有效时间（秒），-1 表示不存在或已过期，0 表示永不过期
 */
abstract function __ttl(string $key): int;
```

#### __clear()（清除所有缓存内容）
```php
/**
 * 清除所有缓存内容
 * 根据不同缓存驱动实现缓存清空
 *
 * @return bool 操作是否成功
 */
abstract function __clear(): bool;
```

#### __rawSet()（缓存对象存储过程实现方法）
```php
/**
 * 缓存对象存储过程实现方法
 * 根据不同缓存驱动实现底层数据存储
 *
 * @param string $key 缓存键名
 * @param mixed $value 缓存值
 * @param int $ttl 过期时间，单位秒，0 表示永不过期
 * @return mixed 存储结果
 */
abstract function __rawSet(string $key, mixed $value = '', int $ttl = 0): mixed;
```

#### __rawGet()（缓存对象读取过程实现方法）
```php
/**
 * 缓存对象读取过程实现方法
 * 根据不同缓存驱动实现底层数据读取
 *
 * @param string $key 缓存键名
 * @return mixed 缓存值或 false
 */
abstract function __rawGet(string $key, mixed $default = null): mixed;
```

#### __rawDelete()（缓存对象删除过程实现方法）
```php
/**
 * 缓存对象删除过程实现方法
 * 根据不同缓存驱动实现底层数据删除
 *
 * @param string $key 缓存键名
 * @return bool|int 删除结果
 */
abstract function __rawDelete(string $key): bool | int;
```

#### __rawHas()（检查值是否存在）
```php
/**
 * 检查值是否存在
 * 
 * @param string $key 缓存键名
 * @return bool 是否存在
 */
abstract function __rawHas(string $key): bool;
```

#### __acquireLock()（获取原子锁）
```php
/**
 * 获取指定键的原子锁。
 * @param string $lockName 锁的名称 (通常是 md5($key))。
 *                     -1: 永久阻塞 (会一直等待直到获取锁)。
 *                      0: 非阻塞 (立即尝试一次，失败返回 false)。
 *                     >0: 带超时时间的阻塞 (在指定毫秒内循环等待)。
 * @return bool 成功获取锁返回 true，否则 false。
 */
abstract function __acquireLock(string $lockName): bool;
```

#### __releaseLock()（释放原子锁）
```php
/**
 * 释放指定键的原子锁。
 * @param string $lockName 锁的名称。
 * @return bool 成功释放锁返回 true，否则 false。
 */
abstract function __releaseLock(string $lockName): bool;
```

#### __releaseAllLocksOnShutdown()（注册关闭函数，确保在脚本结束时释放所有持有的锁）
```php
/**
 * 注册关闭函数，确保在脚本结束时释放所有持有的锁
 */
abstract function __releaseAllLocksOnShutdown(): void;
```

除以上必须实现的方法外，可根据自建缓存驱动的需要创建其他方法。保证必要方法的值能正常返回。

## 缓存驱动接口说明

创建构造函数对类和父类进行初始化 例子如下

```php
/**
 * 构造函数，初始化本类配置参数并注册关闭函数
 * @param array $config 缓存配置数组，可选参数：
 *                      - lockOutTime: 锁超时时间，单位毫秒，-1 表示不超时
 */
public function __construct($config = []) {
    //初始化父类
    parent::__construct($config);

    $this->lockOutTime = $config['lockOutTime'] ??= -1;

    // 注册关闭函数，确保在脚本结束时释放所有持有的锁
    register_shutdown_function([$this, '__releaseAllLocksOnShutdown']);
}
```

## 使用说明
### 基本缓存操作
```php
// 创建缓存实例
$cache = new \Candy\Core\Cache\RedisCache(); // 假设使用 Redis 驱动

// 设置缓存
$cache->set('user.1.info', ['id' => 1, 'name' => '张三'], 3600);

// 获取缓存
$userInfo = $cache->get('user.1.info', ['id' => 0]);

// 检查缓存
if ($cache->has('user.1.info')) {
    // 缓存存在
}

// 删除缓存
$cache->delete('user.1.info');
```

### 数组操作
```php
// 向数组头部添加元素
$cache->lPush('message.queue', ['id' => 1, 'content' => '消息内容']);

// 向数组尾部添加元素
$cache->rpush('message.queue', ['id' => 2, 'content' => '另一条消息']);

// 获取数组范围元素
$messages = $cache->lrange('message.queue', 0, 10);

// 弹出数组元素
$firstMessage = $cache->lpop('message.queue');
$lastMessage = $cache->rpop('message.queue');
```

### 计数器操作
```php
// 初始化计数器
$cache->set('page.view.counter', 0);

// 递增计数
$cache->incr('page.view.counter');

// 递减计数
$cache->decr('page.view.counter');
```

### 事务操作
```php
// 开启事务
$cache->multi();

// 添加多个操作
$cache->set('key1', 'value1');
$cache->set('key2', 'value2');
$cache->delete('key3');

// 执行事务
$result = $cache->exec();
```

### 限流控制
```php
// 检查限流
if ($cache->rateLimit('api.requests', 100, 60)) {
    // 执行 API 请求
}
```

## 注意事项
1. 使用 Shmop 驱动时缓存键名不允许包含 \_fragment\_字符，这是框架保留字符
2. 所有涉及数据修改的操作都会自动获取分布式锁，确保高并发环境下的数据一致性
3. 分布式锁会在脚本结束时自动释放，防止死锁
4. 异常处理机制会捕获操作过程中的错误并记录日志
5. 对于大型数据，建议使用适当的过期时间，避免内存占用过大
6. 事务操作需要小心使用，未执行的事务不会自动回滚
7. Redis 专用操作仅在使用 Redis 驱动时可用
8. 限流和统计功能需要 Redis 驱动支持

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2023-10-23 10:43:14