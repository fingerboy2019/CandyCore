# 会话与Cookie管理函数

## 功能说明
会话与Cookie管理函数是Candy框架中用于用户状态管理的核心工具，提供了便捷的会话数据存储和Cookie操作接口。这些函数封装了PHP原生的会话和Cookie功能，提供了更加安全和易用的API。

## 主要函数

### session() - 会话管理

```php
/**
 * 会话管理函数
 *
 * 该函数提供了会话数据的获取、设置和删除功能，支持数组和单个值操作，
 * 是对PHP原生session功能的封装和扩展。
 *
 * @param string $key 会话键名，为空时获取所有会话数据
 * @param mixed $value 会话值，null表示删除，空字符串表示获取
 * @return mixed 获取会话时返回会话值或会话数组，设置/删除时无返回值
 */
function session(string $key = '',mixed $value = ''): mixed
```

**使用示例**：
- 获取会话: `$user = session('user')` - 获取用户会话数据
- 设置会话: `session('user', ['id' => 1, 'name' => 'admin'])` - 设置用户会话
- 删除会话: `session('user', null)` - 删除用户会话
- 获取所有会话: `$allSession = session()` - 获取所有会话数据

### sessionId() - 会话ID获取

```php
/**
 * 获取当前会话的Session ID
 *
 * 该函数用于获取当前会话的Session ID，优先从Request对象获取，
 * 不存在时使用原生session_id()，确保在不同环境下都能正确获取。
 *
 * @return string 当前会话的Session ID
 */
function sessionId(): string
```

**使用示例**：
- 获取会话ID: `$sessionId = sessionId()` - 获取当前会话ID
- 会话跟踪: `log('User action with session: ' . sessionId())` - 记录会话ID

### cookie() - Cookie操作

```php
/**
 * Cookie操作函数，支持获取、设置和删除Cookie
 *
 * 该函数是对PHP原生Cookie操作的封装，提供了更便捷的接口，
 * 同时支持CLI模式下的特殊处理。可用于获取单个Cookie值、所有Cookie值，
 * 设置Cookie，以及删除单个或所有Cookie。
 *
 * @param string $name Cookie名称，空字符串时操作所有Cookie
 * @param mixed $value Cookie值，null表示删除，空字符串表示获取
 * @param int $expire 过期时间（秒），默认3600秒
 * @param string $path 有效路径，默认为根路径
 * @param string $domain 域名，默认为当前主机名
 * @return mixed 获取Cookie时返回Cookie值或Cookie数组，设置/删除时无返回值
 */
function cookie(string $name = '',mixed $value = '',int $expire = 0,string $path = '/',string $domain = ''):mixed
```

**使用示例**：
- 获取Cookie: `cookie('username')` - 获取username的值
- 设置Cookie: `cookie('username', 'admin', 3600)` - 设置username值为admin，有效期1小时
- 删除Cookie: `cookie('username', null)` - 删除username Cookie
- 获取所有Cookie: `cookie()` - 获取所有Cookie值
- 删除所有Cookie: `cookie('', null)` - 删除所有Cookie（保留会话ID）
