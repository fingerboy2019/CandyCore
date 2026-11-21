# Str 类

## 类简介

Str类是Candy框架中的字符串操作工具类，提供了一系列静态方法，用于处理各种字符串操作，包括字符串判断、转换、格式化、随机生成等功能。
所有方法都是静态的，无需实例化即可使用，同时类内部使用缓存机制优化性能。

## 命名空间

```php
namespace Candy\Extend;
```

## 类定义

```php
/**
 * Candy框架字符串操作工具类
 * 
 * 提供了字符串包含判断、大小写转换、随机字符串生成、命名格式转换等多种功能。
 * 所有方法都是静态的，无需实例化即可使用。
 */
final class Str {
    // 类成员
}
```

## 主要方法

### contains()

```php
/**
 * 检查字符串中是否包含指定的子字符串
 * 
 * @param string $haystack 要检查的目标字符串
 * @param string|array $needles 要查找的子字符串或子字符串数组
 * @return bool 如果包含任一子字符串则返回true，否则返回false
 */
public static function contains(string $haystack, string|array $needles): bool
```

### endsWith()

```php
/**
 * 检查字符串是否以指定的子字符串结尾
 * 
 * @param string $haystack 要检查的目标字符串
 * @param string|array $needles 要匹配的结束子字符串或子字符串数组
 * @return bool 如果以任一子字符串结尾则返回true，否则返回false
 */
public static function endsWith(string $haystack, string|array $needles): bool
```

### startsWith()

```php
/**
 * 检查字符串是否以指定的子字符串开头
 * 
 * @param string $haystack 要检查的目标字符串
 * @param string|array $needles 要匹配的开头子字符串或子字符串数组
 * @return bool 如果以任一子字符串开头则返回true，否则返回false
 */
public static function startsWith(string $haystack, string|array $needles): bool
```

### random()

```php
/**
 * 生成指定长度的随机字符串
 * 
 * @param int $length 生成的随机字符串长度，默认为4
 * @param int $type 字符类型
 * @param string $addChars 附加字符
 * @return string 生成的随机字符串
 */
public static function random(int $length = 4, int $type = 0, string $addChars = ''): string
```

### lower()

```php
/**
 * 将字符串转换为小写
 * 
 * @param string $value 要转换的字符串
 * @return string 转换后的小写字符串
 */
public static function lower(string $value): string
```

### upper()

```php
/**
 * 将字符串转换为大写
 * 
 * @param string $value 要转换的字符串
 * @return string 转换后的大写字符串
 */
public static function upper(string $value): string
```

### length()

```php
/**
 * 获取字符串的长度
 * 
 * @param string $value 要测量长度的字符串
 * @return int 字符串的字符数
 */
public static function length(string $value): int
```

### substr()

```php
/**
 * 截取字符串的指定部分
 * 
 * @param string $string 要截取的源字符串
 * @param int $start 开始截取的位置
 * @param int|null $length 要截取的长度
 * @return string 截取后的子字符串
 */
public static function substr(string $string, int $start, ?int $length = null): string
```

### snake()

```php
/**
 * 将驼峰命名格式的字符串转换为下划线命名格式
 * 
 * @param string $value 要转换的驼峰命名字符串
 * @param string $first 首字母处理方式
 * @param string $delimiter 分隔符
 * @return string 转换后的下划线命名格式字符串
 */
public static function snake(string $value, string $first = 'lower', string $delimiter = '_'): string
```

### camel()

```php
/**
 * 将下划线命名格式的字符串转换为驼峰命名格式（首字母小写）
 * 
 * @param string $value 要转换的下划线命名字符串
 * @return string 转换后的驼峰命名格式字符串
 */
public static function camel(string $value): string
```

### studly()

```php
/**
 * 将下划线命名格式的字符串转换为驼峰命名格式（首字母大写）
 * 
 * @param string $value 要转换的下划线命名字符串
 * @return string 转换后的驼峰命名格式字符串
 */
public static function studly(string $value): string
```

### uniqidNumber()

```php
/**
 * 生成唯一标识符
 * 
 * @param bool $md5 是否以UUID格式返回
 * @return string 生成的唯一标识符
 */
public static function uniqidNumber(bool $md5 = false): string
```

### uniqidDate()

```php
/**
 * 生成基于日期的唯一标识符
 * 
 * @param int $size 生成的唯一标识符的长度
 * @return string 基于日期的唯一标识符
 */
public static function uniqidDate(int $size = 16): string
```

### getSensitive()

```php
/**
 * 敏感词检测与过滤
 * 
 * @param string $string 要检测的字符串
 * @return array 返回过滤结果数组
 */
public static function getSensitive(string $string): array
```

## 使用说明

```php
// 基本使用示例
$result = Str::contains('hello world', 'world'); // true
$random = Str::random(10, 2); // 生成随机字符串
$snake = Str::snake('HelloWorld'); // hello_world
$camel = Str::camel('hello_world'); // helloWorld
```

## 注意事项

1. Str类是最终类（final class），不允许被继承
2. 需要PHP 7.0+版本支持，依赖mbstring扩展
3. 所有方法都支持UTF-8编码，可正确处理中文

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-02-03