# Mcrypt 字符串加解密类库

## 类简介

该类提供了多种字符串加密和解密方法，支持不同安全级别的数据加密需求。主要用于用户密码的安全存储和验证、API密钥和Token的安全传输、敏感数据的临时加密存储以及URL参数或Cookie中敏感信息的保护。

**主要功能：**
- 高性能的字符加密解密方法，支持设置过期时间
- 简单对称加密算法，适用于非敏感数据的快速加解密
- DES/AES加密解密，提供更高级别的安全性
- 支持自定义密钥和默认密钥机制

## 命名空间

```php
namespace Candy\Extend;
```

## 类定义

```php
/**
 * Mcrypt类 - 字符串加解密类库
 * 
 * 该类提供了多种字符串加密和解密方法，支持不同安全级别的数据加密需求。
 * 适用于用户密码的安全存储和验证、API密钥、Token的安全传输等场景。
 */
final class Mcrypt
{
    // 类实现...
}
```

## 主要方法

### code()

```php
/**
 * 高性能字符加密解密方法
 * 
 * 该方法实现了自定义的高级加密算法，提供数据完整性验证和过期时间控制功能。
 * 采用多层密钥变换和数据混淆技术，适用于需要较高安全性的场景。
 * 
 * @param string $string 待加密的原文或待解密的密文
 * @param string $key 加密/解密密钥，空字符串则使用默认密钥
 * @param string $type 操作类型：'en' 表示加密，'de' 表示解密
 * @param int $expiry 密文有效期，单位秒，0 表示永久有效
 * @return string 加密时返回经过 URL 安全的 base64 编码的密文；解密时返回原文，若解密失败或已过期则返回空字符串
 * @throws \InvalidArgumentException 当 $type 参数不是 'en' 或 'de' 时可能导致意外行为
 * @note 
 * - 加密后的密文包含4字节的完整性校验码
 * - 支持URL安全的编码，将 '+'、'/'、'=' 替换为 '-'、'_'、'.'
 * - 当解密已过期的密文时，返回空字符串
 * @example
 * \```
 * // 加密数据
 * $encrypted = Mcrypt::code('Hello World', 'secret-key');
 * 
 * // 解密数据
 * $decrypted = Mcrypt::code($encrypted, 'secret-key', 'de');
 * 
 * // 设置5分钟有效期的加密
 * $tempEncrypted = Mcrypt::code('临时数据', 'secret-key', 'en', 300);
 * \```
 */
public static function code(string $string, string $key = '', string $type = 'en', int $expiry = 0): string
```

### symmetry()

```php
/**
 * 简单对称加密算法实现
 * 
 * 该方法提供了一种轻量级的对称加密机制，适用于非敏感数据的快速加密和解密。
 * 算法基于简单的字符替换和混淆，性能高效但安全性相对较低。
 * 
 * @param string $string 待加密的原文或待解密的密文
 * @param string $key 加密/解密密钥，空字符串则使用默认密钥
 * @param string $type 操作类型：'en' 表示加密，'de' 表示解密
 * @return bool|string 加密时返回经过 URL 安全的 base64 编码的密文；解密时返回原文
 * @note 
 * - 该方法主要用于非敏感数据的保护
 * - 支持URL安全的编码，将 '+'、'/'、'=' 替换为 '-'、'_'、'.'
 * - 当解密过程出现错误时可能返回false或不完整的字符串
 * @example
 * \```
 * // 加密简单数据
 * $encrypted = Mcrypt::symmetry('简单数据', 'simple-key');
 * 
 * // 解密数据
 * $decrypted = Mcrypt::symmetry($encrypted, 'simple-key', 'de');
 * \```
 */
public static function symmetry(string $string = '', string $key = '', string $type = 'en'): bool|string
```

### des()

```php
/**
 * AES-256-CBC加密解密方法
 * 
 * 该方法利用PHP的OpenSSL扩展实现AES-256-CBC加密算法，提供高强度的加密保护。
 * 虽然方法名中包含DES，但实际使用的是更安全的AES-256-CBC算法。
 * 
 * @param string $string 待加密的原文或待解密的密文
 * @param string $key 加密/解密密钥，空字符串则使用默认密钥
 * @param string $type 操作类型：'en' 表示加密，'de' 表示解密
 * @return string 加密时返回base64编码的密文；解密时返回原文
 * @throws \RuntimeException 当OpenSSL扩展不可用或加密/解密过程出现错误时可能抛出异常
 * @note 
 * - 该方法依赖PHP的OpenSSL扩展
 * - 使用AES-256-CBC算法，但方法名保留为des以保持兼容性
 * - 加密结果经过base64编码处理
 * @requires PHP 5.3.0+
 * @requires openssl PHP扩展
 * @example
 * \```
 * // AES加密数据
 * $encrypted = Mcrypt::des('敏感数据', 'strong-key');
 * 
 * // AES解密数据
 * $decrypted = Mcrypt::des($encrypted, 'strong-key', 'de');
 * \```
 */
public static function des(string $string = '', string $key = '', string $type = 'en'): string
```

## 静态属性

### defaultKey

```php
/**
 * 默认加密密钥
 * 
 * 当调用加密方法时未提供密钥参数时使用的默认密钥。
 * 出于安全考虑，建议在生产环境中修改此默认密钥。
 * 
 * @var string
 */
public static $defaultKey = 'c,n=X!OjvpiBbw(QC0ma';
```

## 使用说明

### 基本用法

```php
// 使用默认密钥进行加密
$encrypted = \Candy\Extend\Mcrypt::code('敏感数据');

// 使用自定义密钥进行加密
$encrypted = \Candy\Extend\Mcrypt::code('敏感数据', 'my-secret-key');

// 设置1小时有效期的加密
$encrypted = \Candy\Extend\Mcrypt::code('敏感数据', 'my-secret-key', 'en', 3600);

// 解密数据
$decrypted = \Candy\Extend\Mcrypt::code($encrypted, 'my-secret-key', 'de');
```

### 不同加密方法的使用

```php
// 使用简单对称加密（适用于非敏感数据）
$symEncrypted = \Candy\Extend\Mcrypt::symmetry('非敏感数据', 'simple-key');
$symDecrypted = \Candy\Extend\Mcrypt::symmetry($symEncrypted, 'simple-key', 'de');

// 使用AES加密（适用于敏感数据）
$desEncrypted = \Candy\Extend\Mcrypt::des('重要数据', 'des-key');
$desDecrypted = \Candy\Extend\Mcrypt::des($desEncrypted, 'des-key', 'de');
```

### 修改默认密钥

```php
// 修改默认密钥以提高安全性
\Candy\Extend\Mcrypt::$defaultKey = 'your-custom-strong-key';

// 之后可以不指定密钥使用
$encrypted = \Candy\Extend\Mcrypt::code('敏感数据');
```

## 注意事项

1. **密钥安全**：密钥是加密安全的核心，应设置为复杂且难以猜测的值。对于生产环境，务必修改默认密钥。

2. **选择适当的加密方法**：
   - `code()` 方法：适用于需要数据完整性验证和过期控制的场景
   - `symmetry()` 方法：适用于非敏感数据的快速加密，性能高但安全性较低
   - `des()` 方法：适用于敏感数据，提供最高级别的安全性，但依赖OpenSSL扩展

3. **过期控制**：`code()` 方法支持设置密文过期时间，适用于需要时效性的敏感数据，如临时令牌。

4. **OpenSSL依赖**：`des()` 方法依赖PHP的OpenSSL扩展，请确保服务器环境已安装并启用此扩展。

5. **URL安全编码**：`code()` 和 `symmetry()` 方法都采用URL安全的base64编码，将 '+'、'/'、'=' 替换为 '-'、'_'、'.'，适合在URL参数或Cookie中使用。

6. **数据完整性验证**：`code()` 方法在加密时会添加数据完整性校验码，能有效防止密文被篡改。

7. **异常处理**：虽然代码中未显式抛出异常，但在使用过程中应做好错误处理，特别是对解密结果的有效性验证。

## 其他信息

- 依赖：OpenSSL PHP扩展（用于des()方法）
- 适用环境：PHP 7.0+

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **更新日期**：2019-08-05 23:39:50
