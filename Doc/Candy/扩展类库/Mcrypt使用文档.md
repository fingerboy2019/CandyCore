# Mcrypt 使用文档

## 功能简介

Mcrypt 是一个强大的字符串加密解密工具集，提供了三种不同安全级别的加密算法，适用于各种数据保护场景：

- **code()** - 高性能加密，支持数据完整性验证和过期时间控制
- **symmetry()** - 简单对称加密，适用于非敏感数据的快速处理
- **des()** - AES-256-CBC加密（虽然方法名含DES，但实际是AES），提供最高安全级别

## 快速入门

### 基本加密解密

```php
<?php
use Candy\Extend\Mcrypt;

// 使用默认密钥加密
$encrypted = Mcrypt::code('Hello World');

// 解密数据
$decrypted = Mcrypt::code($encrypted, '', 'de');

// 使用自定义密钥
$key = 'my-secret-key';
$encrypted = Mcrypt::code('敏感数据', $key);
$decrypted = Mcrypt::code($encrypted, $key, 'de');
```

## 详细使用指南

### 1. code() 方法 - 高性能加密（推荐）

`code()` 方法提供了高级加密功能，支持数据完整性验证和过期时间控制。

#### 加密数据

```php
/**
 * @param string $string 待加密数据
 * @param string $key 密钥（可选，空则使用默认密钥）
 * @param string $type 操作类型：'en'（加密）或'de'（解密）
 * @param int $expiry 有效期（秒），0表示永久有效
 */

// 基本加密
$encrypted = Mcrypt::code('敏感数据', 'secret-key');

// 设置30分钟有效期的加密
$tempEncrypted = Mcrypt::code('临时数据', 'secret-key', 'en', 1800);
```

#### 解密数据

```php
// 解密普通数据
$decrypted = Mcrypt::code($encrypted, 'secret-key', 'de');

// 解密有过期时间的数据
// 如果已过期，将返回空字符串
$decrypted = Mcrypt::code($tempEncrypted, 'secret-key', 'de');
```

### 2. symmetry() 方法 - 简单对称加密

适用于非敏感数据的快速加密，性能高效但安全性相对较低。

```php
// 加密非敏感数据
$encrypted = Mcrypt::symmetry('普通数据', 'simple-key');

// 解密数据
$decrypted = Mcrypt::symmetry($encrypted, 'simple-key', 'de');
```

### 3. des() 方法 - AES-256-CBC加密

尽管方法名为des，但实际使用的是更安全的AES-256-CBC算法，提供最高级别的安全性。

```php
// 加密重要数据
$encrypted = Mcrypt::des('重要敏感数据', 'strong-key');

// 解密数据
$decrypted = Mcrypt::des($encrypted, 'strong-key', 'de');
```

### 4. 默认密钥设置

可以修改默认密钥以提高安全性：

```php
// 修改默认密钥
Mcrypt::$defaultKey = 'your-strong-custom-key';

// 使用新的默认密钥进行加密
$encrypted = Mcrypt::code('敏感数据');
```

## 实际应用场景

### 1. 用户密码保护

```php
<?php
use Candy\Extend\Mcrypt;

class UserService {
    private $key = 'user-password-secret';
    
    // 加密用户密码
    public function encryptPassword($password) {
        // 注意：实际生产环境中，密码应使用哈希函数（如bcrypt）而不是可逆加密
        // 此示例仅用于演示加密功能
        return Mcrypt::code($password, $this->key);
    }
    
    // 验证用户密码
    public function verifyPassword($inputPassword, $storedEncrypted) {
        $decrypted = Mcrypt::code($storedEncrypted, $this->key, 'de');
        return $inputPassword === $decrypted;
    }
}
```

### 2. 临时令牌生成

```php
<?php
// 生成具有过期时间的临时访问令牌
$userId = 123;
$tokenData = [
    'user_id' => $userId,
    'timestamp' => time()
];
$tokenKey = 'temp-token-secret';

// 加密并设置1小时有效期
$token = Mcrypt::code(json_encode($tokenData), $tokenKey, 'en', 3600);

// 验证令牌
function validateToken($token) {
    $tokenKey = 'temp-token-secret';
    $decrypted = Mcrypt::code($token, $tokenKey, 'de');
    
    if (!$decrypted) {
        return false; // 令牌无效或已过期
    }
    
    $tokenData = json_decode($decrypted, true);
    // 进一步验证令牌数据...
    return $tokenData;
}
```

### 3. URL参数加密

```php
<?php
// 加密URL参数以保护敏感信息
$params = [
    'user_id' => 456,
    'action' => 'view_profile'
];

$encryptionKey = 'url-params-secret';
$encryptedParams = Mcrypt::code(json_encode($params), $encryptionKey);

// 生成安全的URL
$safeUrl = "http://example.com/action?data={$encryptedParams}";

// 解密URL参数
function decryptUrlParams($encryptedData) {
    $encryptionKey = 'url-params-secret';
    $decrypted = Mcrypt::code($encryptedData, $encryptionKey, 'de');
    return json_decode($decrypted, true);
}
```

### 4. Cookie数据加密

```php
<?php
// 安全存储用户信息到Cookie
$userInfo = [
    'id' => 789,
    'name' => '张三'
];

$cookieKey = 'cookie-encryption-secret';
$encryptedUserInfo = Mcrypt::code(json_encode($userInfo), $cookieKey);

// 设置Cookie（已编码为URL安全格式）
setcookie('user_info', $encryptedUserInfo, time() + 86400, '/');

// 读取并解密Cookie
function getUserInfoFromCookie() {
    if (!isset($_COOKIE['user_info'])) {
        return null;
    }
    
    $cookieKey = 'cookie-encryption-secret';
    $decrypted = Mcrypt::code($_COOKIE['user_info'], $cookieKey, 'de');
    return json_decode($decrypted, true);
}
```

### 5. API数据传输加密

```php
<?php
// API请求数据加密
function encryptApiRequest($requestData, $apiKey) {
    $dataJson = json_encode($requestData);
    return Mcrypt::code($dataJson, $apiKey);
}

// API响应数据解密
function decryptApiResponse($responseData, $apiKey) {
    $decrypted = Mcrypt::code($responseData, $apiKey, 'de');
    return json_decode($decrypted, true);
}

// 示例使用
$apiKey = 'api-communication-secret';
$request = ['method' => 'get_user', 'params' => ['id' => 123]];
$encryptedRequest = encryptApiRequest($request, $apiKey);

// 发送请求到API服务器
// $response = sendApiRequest($encryptedRequest);

// 解密API响应
// $decryptedResponse = decryptApiResponse($response, $apiKey);
```

## 选择合适的加密方法

| 方法 | 安全性 | 性能 | 特点 | 适用场景 |
|------|--------|------|------|----------|
| code() | 高 | 中 | 支持过期时间、数据完整性验证 | 敏感数据、临时令牌、URL参数 |
| symmetry() | 低 | 高 | 轻量级 | 非敏感数据、快速加密 |
| des() | 极高 | 中 | AES-256-CBC算法 | 极敏感数据、金融信息 |

## 安全最佳实践

### 1. 密钥管理

```php
<?php
// 错误示例：硬编码密钥
Mcrypt::code('数据', 'hardcoded-key');

// 正确示例：从环境变量获取密钥
$key = getenv('ENCRYPTION_KEY');
Mcrypt::code('数据', $key);

// 正确示例：使用配置文件中的密钥
$key = Config::get('security.encryption_key');
Mcrypt::code('数据', $key);
```

### 2. 数据验证

```php
<?php
// 始终验证解密结果
$decrypted = Mcrypt::code($encrypted, $key, 'de');
if (empty($decrypted)) {
    // 解密失败或数据已过期
    throw new Exception('无效的加密数据');
}
```

### 3. 避免使用默认密钥

```php
<?php
// 错误示例：使用默认密钥
Mcrypt::code('敏感数据');

// 正确示例：设置自定义默认密钥
Mcrypt::$defaultKey = 'your-strong-random-key';
```

## 注意事项

1. **URL安全编码**：`code()` 和 `symmetry()` 方法使用URL安全的base64编码，将 '+'、'/'、'=' 替换为 '-'、'_'、'.'，适合在URL参数或Cookie中使用。

2. **OpenSSL依赖**：`des()` 方法依赖PHP的OpenSSL扩展，请确保服务器已安装并启用此扩展。

3. **过期时间**：只有 `code()` 方法支持设置密文过期时间，`symmetry()` 和 `des()` 方法不支持。

4. **数据完整性**：`code()` 方法包含数据完整性校验码，能有效防止密文被篡改。

5. **默认密钥**：默认密钥是公开的，生产环境务必修改默认密钥以提高安全性。

## 常见问题

### Q: 解密失败返回空字符串是什么原因？
A: 可能的原因包括：密钥错误、密文被篡改、密文已过期、密文格式不正确。

### Q: 为什么 `des()` 方法叫这个名字但实际是AES加密？
A: 这是为了保持向后兼容性而保留的方法名，实际实现使用了更安全的AES-256-CBC算法。

### Q: 可以加密非字符串数据吗？
A: 可以，但需要先将数据转换为字符串，例如使用 `json_encode()` 转换数组或对象。

```php
<?php
// 加密数组
$data = ['name' => '张三', 'age' => 25];
$encrypted = Mcrypt::code(json_encode($data), 'secret-key');

// 解密并还原为数组
$decrypted = Mcrypt::code($encrypted, 'secret-key', 'de');
$data = json_decode($decrypted, true);
```

### Q: 如何处理长数据的加密？
A: Mcrypt 可以处理任意长度的数据，但加密后的密文长度会比原数据长。对于非常大的数据，建议考虑其他加密方案或分块处理。