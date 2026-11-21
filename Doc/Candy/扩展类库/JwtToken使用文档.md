# JwtToken 使用文档

## 概述

JwtToken类是Candy框架中的JWT认证工具类，提供了完整的JWT令牌生成、验证、刷新和注销功能。支持多种加密算法（HS256/RS256/RS512等）、令牌缓存管理和单点登录功能，用于用户身份认证和会话管理。

**注意：** 此类属于核心补充类库，是Candy框架核心功能的扩展。

## 快速入门

### 基本使用流程

```php
use Candy\Vendor\Extend\JwtToken;

// 1. 生成令牌
$tokens = JwtToken::generateToken('user123', 'web', ['username' => 'admin', 'role' => 'admin']);
$accessToken = $tokens['access_token'];
$refreshToken = $tokens['refresh_token'];

// 2. 验证令牌
try {
    $userInfo = JwtToken::verify($accessToken);
    // 令牌有效，处理业务逻辑
} catch (Exception $e) {
    // 令牌无效，处理错误
}

// 3. 刷新令牌
$newTokens = JwtToken::refreshToken($refreshToken);

// 4. 注销令牌
JwtToken::clear('user123', 'web');
```

## 核心功能详解

### 1. 生成令牌

```php
/**
 * 生成JWT令牌
 *
 * @param string $uid 用户ID
 * @param string $client 客户端类型
 * @param array $userInfo 用户附加信息
 * @return array 返回包含access_token和refresh_token的数组
 */
$tokens = JwtToken::generateToken(
    'user123',              // 用户ID
    'web',                  // 客户端类型
    [                       // 用户附加信息
        'username' => 'admin',
        'role' => 'admin',
        'email' => 'admin@example.com'
    ]
);

// 生成的令牌数组结构
/*
array(
    'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
    'refresh_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'
)
*/
```

### 2. 验证令牌

```php
/**
 * 验证JWT令牌
 *
 * @param string $token 要验证的令牌
 * @param string $type 令牌类型（默认：ACCESS_TOKEN）
 * @return array 验证成功返回令牌载荷数据
 * @throws Exception 验证失败抛出异常
 */

try {
    // 验证访问令牌
    $userInfo = JwtToken::verify($accessToken);
    // 或指定令牌类型
    $userInfo = JwtToken::verify($accessToken, JwtToken::ACCESS_TOKEN);
    
    // 验证刷新令牌
    $userInfo = JwtToken::verify($refreshToken, JwtToken::REFRESH_TOKEN);
    
    // 验证成功后的用户信息
    /*
    array(
        'uid' => 'user123',
        'client' => 'web',
        'username' => 'admin',
        'role' => 'admin',
        'email' => 'admin@example.com',
        'iat' => 1620000000,
        'exp' => 1620003600
    )
    */
    
} catch (Exception $e) {
    // 处理验证失败
    echo '令牌验证失败：' . $e->getMessage();
}
```

### 3. 刷新令牌

```php
/**
 * 刷新JWT令牌
 *
 * @param string $refreshToken 刷新令牌
 * @return array 返回新的access_token和refresh_token
 * @throws Exception 刷新失败抛出异常
 */

try {
    $newTokens = JwtToken::refreshToken($refreshToken);
    // 获取新的令牌
    $newAccessToken = $newTokens['access_token'];
    $newRefreshToken = $newTokens['refresh_token'];
    
    // 更新客户端的令牌
    // ...
    
} catch (Exception $e) {
    // 刷新失败，需要重新登录
    echo '令牌刷新失败：' . $e->getMessage();
}
```

### 4. 注销令牌

```php
/**
 * 注销JWT令牌
 *
 * @param string $uid 用户ID
 * @param string $client 客户端类型
 * @return bool 清理成功返回true
 */

// 注销指定用户在指定客户端的令牌
$success = JwtToken::clear('user123', 'web');

if ($success) {
    echo '令牌已成功注销';
} else {
    echo '令牌注销失败';
}
```

### 5. 生成配置文件

```php
/**
 * 生成JWT配置文件
 *
 * 创建默认的JWT配置文件到指定目录
 * @return void
 */
JwtToken::makeInc();
```

## 配置说明

### 默认配置结构

```php
$config = [
    // 访问令牌配置
    'access' => [
        'algorithm' => 'HS256',       // 加密算法
        'key' => 'your-access-key',    // 签名密钥
        'ttl' => 3600,                // 过期时间（秒）
        'iss' => 'candy-framework',    // 令牌签发者
    ],
    // 刷新令牌配置
    'refresh' => [
        'algorithm' => 'HS256',       // 加密算法
        'key' => 'your-refresh-key',   // 签名密钥
        'ttl' => 86400 * 30,          // 过期时间（秒）
        'iss' => 'candy-framework',    // 令牌签发者
    ],
    // Redis缓存配置
    'cache' => [
        'enable' => true,              // 是否启用缓存
        'host' => '127.0.0.1',         // Redis主机
        'port' => 6379,                // Redis端口
        'password' => '',              // Redis密码
        'database' => 0,               // Redis数据库
        'prefix' => 'jwt:',            // 缓存前缀
    ],
    // 单点登录配置
    'single_login' => true,            // 是否启用单点登录
];
```

### 自定义配置

```php
// 初始化时传入自定义配置
$jwt = new JwtToken([
    'access' => [
        'algorithm' => 'RS256',       // 使用非对称加密
        'key' => '/path/to/private.key',  // 使用私钥文件
        'ttl' => 7200,                // 访问令牌有效期2小时
    ],
    'refresh' => [
        'algorithm' => 'RS256',
        'key' => '/path/to/private.key',
        'ttl' => 86400 * 15,          // 刷新令牌有效期15天
    ],
]);
```

## 注意事项

1. **令牌安全**：
   - JWT令牌一旦生成无法直接撤销，只能通过过期或缓存验证实现注销
   - 推荐使用RS256等非对称加密算法提高安全性
   - 生产环境中应使用强密钥并定期更换

2. **缓存依赖**：
   - 令牌缓存和单点登录功能依赖Redis服务
   - 确保Redis服务正常运行且可访问

3. **客户端类型**：
   - 不同客户端类型（web、app、api等）的令牌是隔离的
   - 注销操作只会影响指定客户端的令牌

4. **异常处理**：
   - 验证和刷新令牌时应捕获异常
   - 根据异常类型进行不同的错误处理

5. **性能考虑**：
   - 大量用户同时请求时，Redis性能可能成为瓶颈
   - 可通过增加Redis实例或调整缓存策略优化性能

## 常见问题

1. **令牌验证失败怎么办？**
   - 检查令牌是否完整且未被篡改
   - 验证令牌是否已过期
   - 检查签名密钥是否正确
   - 确认客户端类型是否匹配

2. **如何实现单点登录？**
   - 确保配置中`single_login`设置为true
   - 同一用户在新设备登录时，旧设备的令牌会自动失效
   - 通过Redis缓存实现令牌的唯一性验证

3. **如何处理令牌过期？**
   - 使用刷新令牌获取新的访问令牌
   - 刷新令牌也过期时，需要用户重新登录
   - 在客户端实现自动刷新令牌的逻辑

4. **如何保护敏感信息？**
   - 不要在令牌中存储敏感信息（如密码）
   - 只存储必要的用户标识和权限信息
   - 考虑对令牌内容进行加密

## 代码示例

### 完整的认证流程示例

```php
use Candy\Vendor\Extend\JwtToken;

class AuthController {
    
    /**
     * 用户登录
     */
    public function login() {
        // 验证用户身份
        $username = I('post.username');
        $password = I('post.password');
        $client = I('post.client', 'web');
        
        // 假设用户验证成功
        $user = [
            'id' => 'user123',
            'username' => $username,
            'role' => 'admin',
            'email' => 'admin@example.com'
        ];
        
        // 生成令牌
        $tokens = JwtToken::generateToken($user['id'], $client, $user);
        
        return json([
            'success' => true,
            'data' => [
                'user' => $user,
                'tokens' => $tokens
            ]
        ]);
    }
    
    /**
     * 验证令牌中间件
     */
    public function verifyToken() {
        $token = I('header.Authorization');
        if (empty($token)) {
            $token = I('get.token');
        }
        
        if (empty($token)) {
            return json(['success' => false, 'message' => '令牌不存在'], 401);
        }
        
        try {
            // 移除Bearer前缀
            if (strpos($token, 'Bearer ') === 0) {
                $token = substr($token, 7);
            }
            
            $userInfo = JwtToken::verify($token);
            
            // 将用户信息存入会话
            $_SESSION['user'] = $userInfo;
            
            return true;
            
        } catch (Exception $e) {
            return json(['success' => false, 'message' => '令牌无效'], 401);
        }
    }
    
    /**
     * 刷新令牌
     */
    public function refreshToken() {
        $refreshToken = I('post.refresh_token');
        
        if (empty($refreshToken)) {
            return json(['success' => false, 'message' => '刷新令牌不存在'], 400);
        }
        
        try {
            $newTokens = JwtToken::refreshToken($refreshToken);
            
            return json([
                'success' => true,
                'data' => $newTokens
            ]);
            
        } catch (Exception $e) {
            return json(['success' => false, 'message' => '刷新令牌无效'], 401);
        }
    }
    
    /**
     * 用户登出
     */
    public function logout() {
        if (!isset($_SESSION['user'])) {
            return json(['success' => false, 'message' => '用户未登录'], 401);
        }
        
        $userId = $_SESSION['user']['uid'];
        $client = I('post.client', 'web');
        
        // 注销令牌
        JwtToken::clear($userId, $client);
        
        // 清空会话
        unset($_SESSION['user']);
        
        return json(['success' => true, 'message' => '登出成功']);
    }
}
```

### 非对称加密配置示例

```php
// 使用RS256非对称加密算法
$config = [
    'access' => [
        'algorithm' => 'RS256',
        'key' => [
            'private' => file_get_contents('/path/to/private.key'),
            'public' => file_get_contents('/path/to/public.key')
        ],
        'ttl' => 3600,
    ],
    'refresh' => [
        'algorithm' => 'RS256',
        'key' => [
            'private' => file_get_contents('/path/to/private.key'),
            'public' => file_get_contents('/path/to/public.key')
        ],
        'ttl' => 86400 * 30,
    ],
];

$jwt = new JwtToken($config);
```
