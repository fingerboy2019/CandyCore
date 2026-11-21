# Session 会话控制类库

## 类简介

Session类提供了基于缓存的自定义会话管理机制，支持将session数据存储在各种缓存系统中。该类通过实现PHP的session_set_save_handler接口，将默认的文件存储会话方式替换为缓存存储，可以显著提升会话管理的性能，尤其适用于分布式系统和多服务器环境。

**主要功能：**
- 使用自定义会话处理器替代默认的文件存储
- 支持通过缓存对象管理session数据
- 实现完整的session生命周期管理
- 支持自定义session ID
- 自动处理session的创建、读取、写入和销毁

## 命名空间

```php
namespace Candy\Extend;
```

## 类定义

```php
/**
 * Session类 - 基于缓存的会话控制类
 * 
 * 该类提供了基于缓存的自定义会话管理机制，支持将session数据存储在各种缓存系统中。
 * 通过实现PHP的session_set_save_handler接口，实现了会话数据的自定义存储和管理。
 */
final class Session
{
    // 类实现...
}
```

## 主要方法

### start()

```php
/**
 * 初始化并启动会话
 * 
 * 该方法负责设置自定义会话处理器并启动PHP会话。它是使用Session类的入口点，
 * 必须在任何会话操作之前调用。
 * 
 * @param object $cacheobj 缓存对象，必须实现get/set/delete等标准缓存接口方法
 * @param string $sessionid 可选的自定义会话ID，为空时由PHP自动生成
 * @return bool 会话启动成功返回true，失败返回false
 * @throws \Exception 当会话启动失败时会捕获异常
 * @note 
 * - 如果会话已激活，将先销毁现有会话
 * - 设置SECACHE常量标记会话已初始化
 * - 使用传入的缓存对象作为会话数据存储后端
 * @example
 * \```
 * // 基本用法
 * $cache = new \Candy\Core\Cache();
 * if (\Candy\Extend\Session::start($cache)) {
 *     // 会话已成功启动
 *     $_SESSION['user'] = 'admin';
 * }
 * 
 * // 使用自定义session ID
 * \Candy\Extend\Session::start($cache, 'custom-session-id');
 * \```
 */
public static function start(object $cacheobj, string $sessionid = ''): bool
```

### instance()

```php
/**
 * 创建Session类的实例
 * 
 * 获取Session类的单例实例。虽然Session类的主要方法都是静态的，
 * 但此方法提供了面向对象的使用方式。
 * 
 * @return Session 返回Session类的新实例
 * @note 
 * - 由于Session类中的大部分方法都是静态的，通常不需要获取实例
 * - 此类被声明为final，不能被继承
 * @example
 * \```
 * // 获取Session实例
 * $session = (new \Candy\Extend\Session())->instance();
 * // 但通常直接使用静态方法更方便
 * \Candy\Extend\Session::start($cache);
 * \```
 */
public function instance(): Session
```

## 内部方法

以下是Session类中的内部方法，主要用于实现PHP会话机制的回调接口，通常不需要手动调用：

### open()

```php
/**
 * 会话打开回调函数
 * 
 * 这是PHP会话机制的回调方法，在会话开始时自动调用。
 * 在当前实现中，由于使用缓存对象存储会话数据，此方法只需返回true。
 * 
 * @param string $path 会话文件保存路径（标准PHP会话参数，当前实现未使用）
 * @param string $name 会话名称（标准PHP会话参数，当前实现未使用）
 * @return bool 始终返回true，表示会话打开成功
 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
 */
public static function open(string $path, string $name): bool
```

### close()

```php
/**
 * 会话关闭回调函数
 * 
 * 这是PHP会话机制的回调方法，在会话结束时自动调用。
 * 在当前实现中，由于使用缓存对象存储会话数据，此方法只需返回true。
 * 
 * @return bool 始终返回true，表示会话关闭成功
 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
 */
public static function close(): bool
```

### read()

```php
/**
 * 从缓存中读取会话数据
 * 
 * 这是PHP会话机制的回调方法，用于读取指定会话ID对应的会话数据。
 * 数据实际存储在通过start方法传入的缓存对象中。
 * 
 * @param string $PHPSESSID 要读取的会话ID
 * @return string 返回会话数据字符串，如果会话不存在或读取失败则返回空字符串
 * @note 
 * - 会话ID会通过sessionKey方法转换为缓存键
 * - 如果缓存中不存在该会话数据或读取失败，返回空字符串
 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
 */
public static function read(string $PHPSESSID): string
```

### write()

```php
/**
 * 将会话数据写入缓存
 * 
 * 这是PHP会话机制的回调方法，用于将会话数据写入缓存系统。
 * 在会话结束或调用session_write_close()时自动执行。
 * 
 * @param string $PHPSESSID 会话ID
 * @param mixed $data 要存储的会话数据（通常是序列化后的字符串）
 * @return bool 数据写入成功返回true，失败返回false
 * @note 
 * - 会话ID会通过sessionKey方法转换为缓存键
 * - 依赖于缓存对象的set方法实现数据存储
 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
 */
public static function write(string $PHPSESSID, mixed $data): bool
```

### destroy()

```php
/**
 * 销毁指定的会话数据
 * 
 * 这是PHP会话机制的回调方法，用于删除指定会话ID的所有数据。
 * 在调用session_destroy()时自动执行。
 * 
 * @param string $PHPSESSID 要销毁的会话ID
 * @return bool 会话销毁成功返回true，失败返回false
 * @note 
 * - 实际是调用缓存对象的delete方法删除对应的缓存数据
 * - 会话ID会通过sessionKey方法转换为缓存键
 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
 */
public static function destroy(string $PHPSESSID): bool
```

### gc()

```php
/**
 * 会话垃圾回收回调函数
 * 
 * 这是PHP会话机制的回调方法，用于清理过期的会话数据。
 * 由于当前实现使用缓存系统存储会话数据，缓存系统通常有自己的过期策略，
 * 因此此方法无需执行额外的回收操作。
 * 
 * @param int $lifetime 会话最大生存时间（秒）
 * @return bool 始终返回true，表示垃圾回收操作成功
 * @note 
 * - 缓存系统（如Memcache）有自己的过期回收机制
 * - 此方法在PHP会话系统的垃圾回收周期触发
 * @internal 此方法由PHP会话系统自动调用，通常不需要手动调用
 */
public static function gc(int $lifetime): bool
```

### sessionKey()

```php
/**
 * 生成会话缓存键
 * 
 * 此私有方法用于将会话ID转换为缓存系统中使用的键名。
 * 通过添加表前缀来避免缓存键冲突，确保会话数据的唯一性。
 * 
 * @param string $PHPSESSID 原始会话ID
 * @return string 返回格式化后的缓存键名，格式为"表前缀+会话ID"
 * @internal 此方法仅在Session类内部使用，用于构建缓存键名
 * @note 
 * - 缓存键由全局配置中的表前缀(TABPREFIX)和会话ID组合而成
 * - 使用G()函数获取全局配置的表前缀
 * - 此方法确保了在多应用场景下会话数据不会发生冲突
 */
private static function sessionKey(string $PHPSESSID): string
```

## 使用说明

### 基本用法

```php
// 初始化缓存对象
$cache = new \Candy\Core\Cache();

// 启动会话
if (\Candy\Extend\Session::start($cache)) {
    // 会话已成功启动，可以正常使用$_SESSION全局变量
    $_SESSION['user_id'] = 123;
    $_SESSION['username'] = 'admin';
    
    // 获取会话数据
    $user_id = $_SESSION['user_id'] ?? null;
}
```

### 使用自定义会话ID

```php
// 使用自定义会话ID启动会话
$customSessionId = 'user_123_session';
\Candy\Extend\Session::start($cache, $customSessionId);
```

### 销毁会话

```php
// 销毁当前会话
$_SESSION = [];
session_destroy();
```

## 注意事项

1. **缓存对象要求**：使用Session类前，必须提供一个实现了get/set/delete等标准缓存接口方法的缓存对象。

2. **初始化顺序**：必须在任何会话操作之前调用Session::start()方法，否则会使用PHP默认的会话处理机制。

3. **会话已激活处理**：如果会话已经激活，Session::start()方法会先销毁现有会话，然后重新初始化。

4. **缓存配置**：确保缓存系统已正确配置且可用，会话数据的性能和可靠性依赖于缓存系统。

5. **过期策略**：会话的过期时间由PHP配置的session.gc_maxlifetime决定，但实际的过期回收依赖于缓存系统的过期机制。

6. **全局变量使用**：使用此会话管理器后，仍然通过$_SESSION全局变量来访问和操作会话数据，保持了与标准PHP会话使用方式的一致性。

7. **安全性考虑**：在处理敏感信息时，应确保缓存系统的安全性，避免会话数据泄露。

## 其他信息

- 依赖：PHP 7.0+
- 依赖组件：实现了标准缓存接口的缓存对象（如Candy\Core\Cache类）

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**：2019-07-26 11:31:47