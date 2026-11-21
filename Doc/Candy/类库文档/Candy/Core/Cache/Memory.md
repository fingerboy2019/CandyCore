# Memory缓存驱动类

## 类简介

Candy框架的内存缓存驱动类，用于处理基于内存的缓存操作。

**注意：** 该类为最终类，不允许继承。

## 命名空间

```php
namespace Candy\Core\Cache;
```

## 类定义

```php
final class Memory extends Cache {
    // 类实现...
}
```

## 类属性

### $lockOutTime

```php
/**
 * 锁超时时间 - 分布式锁的最大持有时间
 * @var int 锁超时时间，单位毫秒，-1表示不超时
 */
private int $lockOutTime;
```

### $heldLocks

```php
/**
 * 当前进程持有的锁 - 记录所有已获取的分布式锁
 * @var array 锁信息数组，键为lockName，值为锁句柄/信息，默认值为空数组
 */
private array $heldLocks = [];
```

## 主要方法

### __construct()

```php
/**
 * 构造函数，初始化内存缓存驱动
 * @param array $config 缓存配置数组
 *                      - lock_out_time: 锁超时时间，单位毫秒，-1表示不超时，默认值3000
 * @return void 无返回值
 */
public function __construct(array $config = [])
```

### __ttl()

```php
/**
 * 获取缓存有效时间
 * @param string $key 缓存键名
 * @return int 剩余有效时间（秒），-1表示不存在或已过期，0表示永不过期
 */
public function __ttl(string $key): int
```

### __clear()

```php
/**
 * 清除所有缓存内容
 * 根据不同缓存驱动实现缓存清空
 * @return bool 操作是否成功
 */
public function __clear(): bool
```

### __rawSet()

```php
/**
 * 缓存对象存储过程实现方法
 * 根据不同缓存驱动实现底层数据存储
 * @param string $key 缓存键名
 * @param mixed $value 缓存值
 * @param int $ttl 过期时间，单位秒，0表示永不过期
 * @return mixed 存储结果
 */
public function __rawSet(string $key, mixed $value = '', int $ttl = 0): mixed
```

### __rawGet()

```php
/**
 * 缓存对象读取过程实现方法
 * 根据不同缓存驱动实现底层数据读取
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
 * 根据不同缓存驱动实现底层数据删除
 * @param string $key 缓存键名
 * @return bool|int 删除结果
 */
public function __rawDelete(string $key): bool|int
```

### __rawHas()

```php
/**
 * 检查值是否存在
 * @param string $key 缓存键名
 * @return bool 是否存在
 */
public function __rawHas(string $key): bool
```

### __acquireLock()

```php
/**
 * 获取指定键的原子锁
 * @param string $lockName 锁的名称 (通常是 md5($key))
 * @return bool 成功获取锁返回true，否则false
 */
public function __acquireLock(string $lockName): bool
```

### __releaseLock()

```php
/**
 * 释放指定键的原子锁
 * @param string $lockName 锁的名称
 * @return bool 成功释放锁返回true，否则false
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

## 配置参数说明

Memory缓存驱动支持的配置参数在构造函数的@param注释中有详细说明。

## 注意事项

1. 内存缓存驱动在脚本执行结束后会自动清理，不适合长期数据存储
2. 此驱动主要用于开发环境测试或临时缓存数据
3. 支持基本的缓存操作和分布式锁机制
4. 由于是内存存储，默认不持久化；开启 dump 时可原子落盘
5. 向量：`vectorScanSearch` 前缀线性扫描（已移除 HNSW）；正式 ANN 用 `V()` / `VectorServer`

```php
$mem->vectorSet('docs:1', [0.1, 0.2, 0.3], 3);
$hits = $mem->vectorScanSearch('docs:', [0.1, 0.2, 0.3], 10);
```

## 作者信息

- **作者:** 刘森 (fingerboy@qq.com)
- **日期:** 2025-12-02 14:00:00