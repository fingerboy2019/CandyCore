# Str 类使用文档

## 简介

Str 类是 Candy 框架中的字符串操作工具类，提供了一系列静态方法，用于处理各种字符串操作，包括字符串判断、转换、格式化、随机生成等功能。所有方法都是静态的，无需实例化即可使用，同时类内部使用缓存机制优化性能。

## 命名空间

```php
use Candy\Extend\Str;
```

## 字符串检查

### 检查字符串是否包含指定子字符串

```php
// 检查单个子字符串
$contains = Str::contains('hello world', 'world');
// 结果: true

// 检查多个子字符串（任一匹配即可）
$contains = Str::contains('hello world', ['hello', 'test']);
// 结果: true

// 检查不存在的子字符串
$contains = Str::contains('hello world', 'test');
// 结果: false
```

### 检查字符串是否以指定子字符串开头

```php
// 检查单个开头子字符串
$startsWith = Str::startsWith('hello world', 'hello');
// 结果: true

// 检查多个开头子字符串（任一匹配即可）
$startsWith = Str::startsWith('hello world', ['hello', 'test']);
// 结果: true

// 检查不存在的开头子字符串
$startsWith = Str::startsWith('hello world', 'test');
// 结果: false
```

### 检查字符串是否以指定子字符串结尾

```php
// 检查单个结尾子字符串
$endsWith = Str::endsWith('hello world', 'world');
// 结果: true

// 检查多个结尾子字符串（任一匹配即可）
$endsWith = Str::endsWith('hello world', ['world', 'test']);
// 结果: true

// 检查不存在的结尾子字符串
$endsWith = Str::endsWith('hello world', 'test');
// 结果: false
```

## 大小写转换

### 转换为小写

```php
$lower = Str::lower('Hello World');
// 结果: 'hello world'
```

### 转换为大写

```php
$upper = Str::upper('Hello World');
// 结果: 'HELLO WORLD'
```

## 字符串测量与截取

### 获取字符串长度

```php
$length = Str::length('Hello World');
// 结果: 11

// 支持中文
$length = Str::length('你好，世界');
// 结果: 5
```

### 截取字符串

```php
// 从位置0开始截取5个字符
$substr = Str::substr('Hello World', 0, 5);
// 结果: 'Hello'

// 从位置6开始截取到字符串末尾
$substr = Str::substr('Hello World', 6);
// 结果: 'World'

// 支持中文
$substr = Str::substr('你好，世界', 0, 2);
// 结果: '你好'
```

## 命名格式转换

### 驼峰命名转下划线命名

```php
// 默认首字母小写
$snake = Str::snake('HelloWorld');
// 结果: 'hello_world'

// 首字母保持大写
$snake = Str::snake('HelloWorld', 'upper');
// 结果: 'Hello_world'

// 使用自定义分隔符
$snake = Str::snake('HelloWorld', 'lower', '-');
// 结果: 'hello-world'
```

### 下划线命名转驼峰命名（首字母小写）

```php
$camel = Str::camel('hello_world');
// 结果: 'helloWorld'
```

### 下划线命名转驼峰命名（首字母大写）

```php
$studly = Str::studly('hello_world');
// 结果: 'HelloWorld'
```

## 随机字符串生成

### 生成指定长度的随机字符串

```php
// 生成默认长度（4位）的随机字符串（数字+字母，字母大小写混合）
$random = Str::random();
// 结果示例: 'a3Bf'

// 生成10位的随机字符串
$random = Str::random(10);
// 结果示例: 'a3Bf7gHjK2'

// 生成10位的纯数字随机字符串
$random = Str::random(10, 1);
// 结果示例: '1234567890'

// 生成10位的纯字母随机字符串
$random = Str::random(10, 2);
// 结果示例: 'aBcDeFgHiJ'

// 生成10位的字母小写随机字符串
$random = Str::random(10, 3);
// 结果示例: 'abcdefghij'

// 生成10位的字母大写随机字符串
$random = Str::random(10, 4);
// 结果示例: 'ABCDEFGHIJ'

// 生成包含自定义字符的随机字符串
$random = Str::random(10, 0, '!@#$%');
// 结果示例: 'a3!B@f7gH#'
```

### 生成唯一标识符

```php
// 生成基本唯一标识符
$uniqid = Str::uniqidNumber();
// 结果示例: '1623456789012345'

// 生成UUID格式的唯一标识符
$uuid = Str::uniqidNumber(true);
// 结果示例: '5f3c2b1a-4d6e-8g9h-0i1j-2k3l4m5n6o7p'
```

### 生成基于日期的唯一标识符

```php
// 生成默认长度（16位）的基于日期的唯一标识符
$uniqidDate = Str::uniqidDate();
// 结果示例: '2023061212345678'

// 生成指定长度的基于日期的唯一标识符
$uniqidDate = Str::uniqidDate(20);
// 结果示例: '20230612123456789012'
```

## 敏感词检测与过滤

### 敏感词检测

```php
// 检测敏感词
$sensitiveResult = Str::getSensitive('这是一个包含敏感词的字符串');

// 结果示例:
// [
//     'status' => true, // 是否包含敏感词
//     'word' => ['敏感词'], // 检测到的敏感词列表
//     'filter' => '这是一个包含***的字符串' // 过滤后的字符串
// ]
```

## 最佳实践

1. **使用适当的命名转换**：
   - 数据库字段名通常使用下划线命名，而PHP变量和方法名通常使用驼峰命名。
   - 利用 `Str::snake()`、`Str::camel()` 和 `Str::studly()` 方法可以方便地在不同命名格式之间转换。

2. **随机字符串的使用场景**：
   - 验证码：使用 `Str::random(4, 1)` 生成纯数字验证码，或 `Str::random(6, 0)` 生成字母数字混合验证码。
   - 密码重置令牌：使用 `Str::random(32, 0)` 生成足够长的随机字符串作为密码重置令牌。
   - 唯一文件名：使用 `Str::uniqidDate()` 结合文件扩展名生成唯一的文件名。

3. **字符串检查的高效使用**：
   - 使用 `Str::contains()`、`Str::startsWith()` 和 `Str::endsWith()` 方法可以更直观地进行字符串检查。
   - 这些方法支持数组参数，可以一次检查多个条件，提高代码的简洁性。

4. **处理中文字符串**：
   - Str 类的方法都支持 UTF-8 编码，可以正确处理中文等非 ASCII 字符。
   - 当需要处理中文字符串时，使用 Str 类的方法替代原生 PHP 函数可以避免编码问题。

## 注意事项

1. **依赖扩展**：
   - Str 类依赖 mbstring 扩展，确保在使用前已安装并启用该扩展。

2. **版本要求**：
   - 需要 PHP 7.0+ 版本支持。

3. **性能考虑**：
   - 对于非常长的字符串，某些操作（如 `Str::contains()`、`Str::substr()`）可能会消耗较多资源。
   - 在循环中处理大量字符串时，应尽量减少不必要的字符串操作。

4. **随机字符串的安全性**：
   - `Str::random()` 方法生成的随机字符串适用于大多数普通场景。
   - 对于安全性要求极高的场景（如密码生成），建议使用更安全的随机数生成方法，如 `random_bytes()`。

5. **敏感词过滤的局限性**：
   - `Str::getSensitive()` 方法的敏感词库需要根据实际需求进行维护和更新。
   - 对于复杂的敏感词检测场景，可能需要更专业的敏感词过滤解决方案。

## 示例代码

### 生成验证码

```php
/**
 * 生成验证码
 * 
 * @param int $length 验证码长度
 * @param int $type 验证码类型（1-数字，2-字母，0-混合）
 * @return string 生成的验证码
 */
function generateCaptcha(int $length = 4, int $type = 1): string
{
    return Str::random($length, $type);
}

// 使用示例
$captcha = generateCaptcha(); // 生成4位数字验证码
$captcha = generateCaptcha(6, 0); // 生成6位字母数字混合验证码
```

### 文件上传时生成唯一文件名

```php
/**
 * 生成唯一文件名
 * 
 * @param string $originalFilename 原始文件名
 * @return string 生成的唯一文件名
 */
function generateUniqueFilename(string $originalFilename): string
{
    // 获取文件扩展名
    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
    
    // 生成唯一文件名
    $uniqueName = Str::uniqidDate() . '.' . $extension;
    
    return $uniqueName;
}

// 使用示例
$originalFilename = 'document.pdf';
$uniqueFilename = generateUniqueFilename($originalFilename);
// 结果示例: '2023061212345678.pdf'
```

### 处理用户输入的命名转换

```php
/**
 * 处理表单数据的命名转换
 * 
 * @param array $data 表单数据数组
 * @return array 转换后的数组
 */
function processFormData(array $data): array
{
    $processed = [];
    
    foreach ($data as $key => $value) {
        // 将表单字段名（下划线命名）转换为PHP变量名（驼峰命名）
        $processed[Str::camel($key)] = $value;
    }
    
    return $processed;
}

// 使用示例
$formData = [
    'user_name' => 'John',
    'user_age' => 30,
    'user_email' => 'john@example.com'
];

$processedData = processFormData($formData);
// 结果:
// [
//     'userName' => 'John',
//     'userAge' => 30,
//     'userEmail' => 'john@example.com'
// ]
```

## 总结

Str 类提供了全面的字符串操作功能，简化了字符串的处理。通过静态方法的设计，使用起来非常方便。在使用过程中，应注意版本要求、依赖扩展和性能考虑，根据实际需求选择合适的方法。Str 类的方法都支持 UTF-8 编码，可以正确处理中文等非 ASCII 字符，适用于各种国际化应用场景。