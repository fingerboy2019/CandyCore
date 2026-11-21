# Session 使用文档

## 功能简介

Session 类提供了基于缓存的自定义会话管理机制，通过实现 PHP 的 `session_set_save_handler` 接口，将默认的文件存储会话方式替换为缓存存储。这种方式可以显著提升会话管理性能，尤其适用于：

- 分布式系统和多服务器环境
- 高并发应用场景
- 需要快速会话访问的Web应用
- 需要共享会话数据的集群环境

## 快速入门

### 基本使用

```php
<?php
use Candy\Extend\Session;
use Candy\Core\Cache;

// 初始化缓存对象
$cache = new Cache();

// 启动会话
if (Session::start($cache)) {
    // 会话已成功启动，可以使用 $_SESSION 全局变量
    $_SESSION['user_id'] = 123;
    $_SESSION['username'] = 'admin';
    
    // 读取会话数据
    $userId = $_SESSION['user_id'] ?? null;
}
```

## 详细使用指南

### 1. start() 方法 - 核心入口

`start()` 方法是使用 Session 类的核心入口，负责初始化会话并设置自定义会话处理器。

```php
/**
 * @param object $cacheobj 缓存对象（必须实现 get/set/delete 等方法）
 * @param string $sessionid 可选的自定义会话ID
 * @return bool 会话启动成功返回 true
 */
$cache = new Cache();
$customSessionId = 'user_456_session';

// 使用自定义会话ID启动会话
$success = Session::start($cache, $customSessionId);
```

### 2. 会话数据操作

启动会话后，可以使用标准的 PHP `$_SESSION` 全局变量进行会话数据的读写操作。

```php
// 写入会话数据
$_SESSION['user'] = [
    'id' => 123,
    'name' => '张三',
    'role' => 'admin'
];

// 读取会话数据
$user = $_SESSION['user'] ?? null;
$userId = $_SESSION['user']['id'] ?? null;

// 更新会话数据
$_SESSION['user']['last_login'] = time();

// 删除会话数据
unset($_SESSION['user']);
```

### 3. 会话管理

#### 销毁会话

```php
// 清除会话数据
$_SESSION = [];

// 销毁会话
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 销毁服务器端会话数据
session_destroy();
```

#### 重新生成会话ID

```php
// 重新生成会话ID（用于提高安全性，如登录后）
session_regenerate_id(true);
```

#### 检查会话状态

```php
// 检查会话是否已启动
if (session_status() === PHP_SESSION_ACTIVE) {
    // 会话已启动
}
```

## 实际应用场景

### 1. 基本用户认证

```php
<?php
use Candy\Extend\Session;
use Candy\Core\Cache;

class AuthService {
    private $cache;
    
    public function __construct() {
        $this->cache = new Cache();
        // 启动会话
        Session::start($this->cache);
    }
    
    // 用户登录
    public function login($username, $password) {
        // 验证用户凭据
        $user = User::findByCredentials($username, $password);
        
        if ($user) {
            // 清除旧会话数据
            $_SESSION = [];
            // 重新生成会话ID提高安全性
            session_regenerate_id(true);
            // 存储用户信息到会话
            $_SESSION['user'] = [
                'id' => $user->id,
                'username' => $user->username,
                'role' => $user->role
            ];
            return true;
        }
        return false;
    }
    
    // 检查用户是否已登录
    public function isLoggedIn() {
        return isset($_SESSION['user']) && !empty($_SESSION['user']);
    }
    
    // 获取当前登录用户
    public function getCurrentUser() {
        return $this->isLoggedIn() ? $_SESSION['user'] : null;
    }
    
    // 用户登出
    public function logout() {
        $_SESSION = [];
        session_destroy();
    }
}
```

### 2. 分布式系统中的会话共享

```php
<?php
use Candy\Extend\Session;
use Candy\Core\Cache;

// 配置缓存连接到共享缓存服务器
$cacheConfig = [
    'type' => 'memcache',
    'host' => '192.168.1.100',
    'port' => 11211,
    'timeout' => 30
];

// 初始化共享缓存
$cache = new Cache($cacheConfig);

// 启动会话
Session::start($cache);

// 现在会话数据存储在共享缓存中，可以在多台服务器之间共享
```

### 3. 自定义会话ID管理

```php
<?php
use Candy\Extend\Session;
use Candy\Core\Cache;

// 初始化缓存
$cache = new Cache();

// 从Cookie或其他地方获取自定义会话标识
$userId = $_COOKIE['user_id'] ?? null;
if ($userId) {
    // 使用用户ID作为会话ID的一部分，便于调试和跟踪
    $customSessionId = 'user_' . $userId;
    Session::start($cache, $customSessionId);
} else {
    // 没有用户ID，使用自动生成的会话ID
    Session::start($cache);
}
```

### 4. 会话数据安全

```php
<?php
use Candy\Extend\Session;
use Candy\Core\Cache;
use Candy\Extend\Mcrypt;

// 初始化加密会话数据的配置
$encryptionKey = getenv('SESSION_ENCRYPTION_KEY');

class SecureSessionService {
    private $cache;
    private $encryptionKey;
    
    public function __construct($encryptionKey) {
        $this->cache = new Cache();
        $this->encryptionKey = $encryptionKey;
        Session::start($this->cache);
    }
    
    // 安全存储敏感会话数据
    public function setSecureData($key, $value) {
        $encryptedValue = Mcrypt::code(json_encode($value), $this->encryptionKey);
        $_SESSION['secure_' . $key] = $encryptedValue;
    }
    
    // 获取安全的会话数据
    public function getSecureData($key) {
        $encryptedValue = $_SESSION['secure_' . $key] ?? null;
        if ($encryptedValue) {
            $decrypted = Mcrypt::code($encryptedValue, $this->encryptionKey, 'de');
            return json_decode($decrypted, true);
        }
        return null;
    }
}

// 使用安全会话服务
$secureSession = new SecureSessionService($encryptionKey);
$secureSession->setSecureData('user_token', 'abc123def456');
$token = $secureSession->getSecureData('user_token');
```

## 最佳实践

### 1. 缓存配置优化

```php
<?php
// 为会话专门配置缓存
$sessionCacheConfig = [
    'type' => 'redis',
    'host' => '127.0.0.1',
    'port' => 6379,
    'timeout' => 10,
    'persistent' => true, // 使用持久连接提高性能
    'select' => 1, // 使用单独的Redis数据库
    'expire' => 1440, // 会话过期时间（秒）
    'prefix' => 'session_', // 会话键前缀
    'password' => ''
];

$cache = new Cache($sessionCacheConfig);
Session::start($cache);
```

### 2. 会话启动前的检查

```php
<?php
// 在启动会话前检查PHP配置
function ensureSessionConfig() {
    // 设置适当的会话cookie参数
    session_set_cookie_params([
        'lifetime' => 86400, // 24小时
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']), // HTTPS环境下使用secure cookie
        'httponly' => true, // 防止JavaScript访问
        'samesite' => 'Lax' // 防止CSRF攻击
    ]);
    
    // 设置会话名称
    session_name('APP_SESSION');
}

ensureSessionConfig();
$cache = new Cache();
Session::start($cache);
```

### 3. 会话数据管理

```php
<?php
// 限制会话数据大小
function cleanSessionData() {
    $maxSessionSize = 1024 * 10; // 10KB
    
    $sessionData = serialize($_SESSION);
    if (strlen($sessionData) > $maxSessionSize) {
        // 清理不必要的会话数据
        unset($_SESSION['temp_data']);
        unset($_SESSION['large_cache']);
    }
}

// 定期清理会话数据
register_shutdown_function('cleanSessionData');
```

## 注意事项

1. **缓存对象要求**：必须提供一个实现了标准缓存接口的对象（至少包含 `get`、`set`、`delete` 方法）。

2. **初始化顺序**：`Session::start()` 必须在任何会话操作之前调用，否则会使用 PHP 默认的会话处理机制。

3. **会话已激活处理**：如果会话已经激活，`Session::start()` 会先销毁现有会话，然后重新初始化。

4. **缓存过期策略**：会话的过期时间由 PHP 配置的 `session.gc_maxlifetime` 决定，但实际的过期回收依赖于缓存系统的过期机制。

5. **安全性考虑**：
   - 始终使用 `https` 保护会话 cookie
   - 启用 `httponly` 和 `samesite` cookie 属性
   - 登录后重新生成会话 ID
   - 不要在会话中存储敏感信息（如密码）

6. **性能优化**：
   - 避免在会话中存储大量数据
   - 使用高效的缓存系统（如 Redis、Memcached）
   - 配置适当的缓存过期时间

## 常见问题

### Q: 可以使用哪些缓存系统？
A: 任何实现了标准缓存接口（get/set/delete 方法）的缓存系统都可以使用，如 Candy 框架的 Cache 类支持的 Redis、Memcached、File 等。

### Q: 如何与默认的 PHP 会话兼容？
A: Session 类完全兼容标准的 PHP 会话操作，启动会话后可以正常使用 `$_SESSION` 全局变量和 `session_*` 系列函数。

### Q: 会话数据在缓存中如何存储？
A: 会话数据以序列化的字符串形式存储在缓存中，键名为 `{表前缀}{会话ID}`，其中表前缀由全局配置中的 `TABPREFIX` 定义。

### Q: 如何处理会话冲突？
A: 通过使用唯一的会话 ID 和适当的缓存键前缀，可以避免在多应用或多实例环境中的会话冲突。

### Q: 会话启动失败怎么办？
A: 可以使用 `try-catch` 块捕获异常，或者检查 `Session::start()` 的返回值来处理会话启动失败的情况。

```php
<?php
try {
    $cache = new Cache();
    if (!Session::start($cache)) {
        throw new Exception('会话启动失败');
    }
} catch (Exception $e) {
    // 记录错误日志
    error_log('会话错误: ' . $e->getMessage());
    // 提供备选方案或显示错误页面
}
```

### Q: 如何在 CLI 环境中使用？
A: Session 类主要用于 Web 环境，但在 CLI 环境中也可以使用，只是需要手动管理会话 ID。

```php
<?php
// CLI环境中使用Session
$cache = new Cache();
$cliSessionId = 'cli_' . uniqid();
Session::start($cache, $cliSessionId);

$_SESSION['cli_task'] = 'background_job';
// 完成任务后手动关闭会话
session_write_close();
```