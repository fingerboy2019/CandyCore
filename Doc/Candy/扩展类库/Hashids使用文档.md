# Hashids 使用文档

## 功能简介

Hashids 是一个安全、可逆的数字ID哈希转换工具，主要用于：
- 将数字ID转换为短字符串（例如：12345 → NkK9）
- 生成URL友好的短链接
- 混淆显示敏感数字信息（如用户ID、订单号）
- 支持多数字编码、十六进制处理

## 快速入门

### 基本用法

```php
<?php
use Candy\Extend\Hashids;

// 初始化Hashids实例（盐值是必填项）
$hashids = new Hashids('your-secret-salt');

// 编码单个数字
$hash = $hashids->encode(12345); // 返回类似 "NkK9"

// 解码回原始数字
$id = $hashids->decode($hash); // 返回 "12345"
```

### 多数字编码

```php
// 编码多个数字
$hash = $hashids->encode(1, 2, 3); // 返回类似 "laHquq"

// 解码多个数字
$ids = $hashids->decode($hash); // 返回 [1, 2, 3]
```

## 详细使用指南

### 1. 创建实例

Hashids 构造函数支持自定义参数：

```php
/**
 * @param string $salt 盐值（必填，确保哈希唯一性）
 * @param string $prefix 哈希前缀（可选，用于区分不同类型的哈希）
 * @param int $minHashLength 最小哈希长度（可选，默认0）
 * @param string $alphabet 自定义字符集（可选）
 */
$hashids = new Hashids(
    'your-secret-salt',    // 盐值
    'user_',               // 前缀
    8,                     // 最小长度
    'abcdefghijklmnopqrstuvwxyz'  // 仅小写字母字符集
);
```

### 2. 数字编码与解码

#### 编码单个数字

```php
$userId = 456;
$userHash = $hashids->encode($userId); // 例如："usNkK9"
```

#### 解码单个数字

```php
$userHash = "usNkK9";
$userId = $hashids->decode($userHash); // 返回 "456"
```

#### 编码数字数组

```php
$orderData = [2024, 10, 15, 12345];
$orderHash = $hashids->encode(...$orderData); // 使用扩展运算符
```

#### 解码为数组

```php
$orderHash = "a1B2c3D4";
$orderData = $hashids->decode($orderHash); // 返回数组
```

### 3. 十六进制处理

Hashids 支持直接处理十六进制值：

```php
// 编码十六进制值
$hex = "DEADBEEF";
$hexHash = $hashids->encodeHex($hex); // 返回类似 "kRNrpKlJ"

// 解码十六进制哈希
$decodedHex = $hashids->decodeHex($hexHash); // 返回 "DEADBEEF"
```

### 4. 自定义前缀

使用前缀可以区分不同类型的哈希：

```php
// 用户ID哈希（带user_前缀）
$userHashids = new Hashids('user-salt', 'u_');
$userHash = $userHashids->encode(789); // 返回 "u_NkK9"

// 订单ID哈希（带order_前缀）
$orderHashids = new Hashids('order-salt', 'o_');
$orderHash = $orderHashids->encode(1024); // 返回 "o_aBc1"
```

### 5. 最小哈希长度

确保生成的哈希至少达到指定长度：

```php
// 生成至少10个字符的哈希
$hashids = new Hashids('salt', '', 10);
$hash = $hashids->encode(123); // 返回类似 "abcdefghij"
```

## 实际应用场景

### 1. URL短链接生成

```php
<?php
use Candy\Extend\Hashids;

class ShortUrlService {
    private $hashids;
    
    public function __construct() {
        $this->hashids = new Hashids('url-shortener-salt', '', 6);
    }
    
    // 生成短链接
    public function generateShortUrl($originalUrl) {
        // 保存原始URL到数据库并获取ID
        $urlId = $this->saveUrlToDatabase($originalUrl);
        
        // 编码ID为短哈希
        $shortCode = $this->hashids->encode($urlId);
        
        return "http://example.com/{$shortCode}";
    }
    
    // 通过短链接获取原始URL
    public function getOriginalUrl($shortCode) {
        // 解码短哈希获取ID
        $urlId = $this->hashids->decode($shortCode);
        
        // 从数据库获取原始URL
        return $this->getUrlFromDatabase($urlId);
    }
}
```

### 2. 敏感ID保护

```php
<?php
// 隐藏实际用户ID，避免暴露系统数据量
$userHashids = new Hashids('user-id-protection', '', 8);
$userId = 1234;
$publicId = $userHashids->encode($userId); // 公开显示的ID
```

### 3. 多参数编码

```php
<?php
// 将多个参数编码为单个哈希，用于简化API调用
$apiHashids = new Hashids('api-params-salt', 'api_');
$params = [2024, 12, 15, 789];
$apiHash = $apiHashids->encode(...$params);

// API URL示例：
// http://api.example.com/data/api_a1B2c3D4

// 解码获取参数
list($year, $month, $day, $userId) = $apiHashids->decode($apiHash);
```

### 4. 十六进制颜色转换

```php
<?php
// 安全转换和存储颜色值
$colorHashids = new Hashids('color-salt', 'c_');
$colorHex = "FF5733";
$colorHash = $colorHashids->encodeHex($colorHex);

// 存储哈希值而不是直接的十六进制
$product->color = $colorHash;
$product->save();

// 显示时解码
$displayColor = "#" . $colorHashids->decodeHex($product->color);
```

## 最佳实践

### 1. 盐值管理

- 盐值应保密且具有足够复杂性
- 不同用途应使用不同的盐值
- 避免在代码中硬编码盐值，建议从配置文件读取

### 2. 性能优化

- 为应用创建全局Hashids实例，避免重复创建
- 对于高并发场景，考虑使用单例模式管理Hashids实例

### 3. 输入验证

```php
<?php
// 验证输入是有效的正整数
function safeEncode(Hashids $hashids, $input) {
    if (is_numeric($input) && $input >= 0 && intval($input) == $input) {
        return $hashids->encode(intval($input));
    }
    return false;
}
```

## 注意事项

1. **BC Math扩展依赖**：确保已安装并启用PHP的BC Math扩展

2. **字母表要求**：
   - 长度至少16个字符
   - 不包含重复字符
   - 不包含空格

3. **前缀限制**：最多只能有2个字符，超出部分会被截断

4. **输入类型**：
   - 仅接受正整数或零
   - 十六进制编码仅接受有效的十六进制字符

5. **哈希冲突**：虽然可能性极低，但在关键应用中建议实现冲突检测

## 常见问题

### Q: 为什么解码返回数组而不是单个值？
A: 当编码多个数字时，解码会返回数组。即使只编码了一个数字，如果使用了数组形式传入，解码也会返回数组。

### Q: 如何处理大数字？
A: Hashids依赖BC Math扩展处理大整数，确保已安装此扩展即可。

### Q: 可以自定义字母表吗？
A: 可以，但字母表长度必须至少为16个字符，且不包含重复字符。

### Q: 哈希长度如何确定？
A: 哈希长度取决于输入数字的大小和构造函数中设置的最小长度，实际长度不会小于最小长度。