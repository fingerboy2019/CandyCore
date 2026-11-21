# Snoopy类库使用文档

## 1. 概述

Snoopy类库是Candy框架的扩展类库，是一个功能强大的网络爬虫工具，基于QueryList库构建，提供了丰富的网页内容获取、分析和处理功能。

### 主要功能特性

- ✅ 基于QueryList的网页内容获取与解析
- ✅ 自动编码转换，支持多种字符集
- ✅ 支持HTTP/HTTPS协议
- ✅ 丰富的内容提取方法（链接、文本、表单等）
- ✅ 表单提交与文件上传功能
- ✅ 网页元数据信息提取
- ✅ 完整的HTTP响应信息获取
- ✅ 链式调用API设计
- ✅ 静态方法调用，无需实例化

## 2. 类定义与命名空间

```php
/**
 * Candy框架 网络爬虫类
 * 
 * Snoopy类是一个强大的网络爬虫工具，提供了丰富的网页内容获取、分析和处理功能。
 * 它基于QueryList库构建，支持网页内容抓取、表单提交、链接提取、编码转换等功能。
 * 
 * @package Candy\Class\Network
 * @author 刘森 (fingerboy@qq.com)
 * @date 2023-06-09 14:00:00
 * @final 此类不允许被继承
 */
final class Snoopy {
    // 类成员
}
```

**命名空间**：`namespace Extend;`

## 3. 核心功能与API

### 3.1 主要方法

#### get()

```php
/**
 * 获取网页内容并返回QueryList对象
 * 
 * 通过HTTP请求获取指定URL的网页内容，并自动进行编码转换后返回QueryList对象，
 * 便于后续使用QueryList的CSS选择器进行内容提取和分析。
 *
 * @param string $URI 请求地址，支持HTTP和HTTPS协议
 * @return string|QueryList 失败时返回错误信息字符串，成功时返回QueryList对象
 */
public static function get(string $URI = ''): QueryList|string
```

#### getInstance()

```php
/**
 * 获取QueryList实例
 * 
 * 直接返回QueryList的实例，方便直接使用QueryList提供的所有功能。
 *
 * @return QueryList 返回QueryList类的实例
 */
public static function getInstance(): QueryList
```

#### fetch()

```php
/**
 * 获取网页内容并进行编码转换
 * 
 * 这是核心的网页内容获取方法，支持HTTP和HTTPS协议，自动处理URL解析、编码转换，
 * 并创建QueryList对象用于后续内容提取。
 *
 * @param string $URI 请求地址
 * @param array|string $query 查询参数，支持数组或字符串格式
 * @param array $options 请求选项，可配置超时、代理等参数
 * @return string|bool 成功时返回转换编码后的网页内容，失败时返回false
 */
public static function fetch(string $URI,array|string $query = '',array $options = []): string|bool
```

#### submit()

```php
/**
 * 提交表单数据并获取响应
 * 
 * 用于模拟HTML表单提交，支持POST数据、文件上传和自定义HTTP头信息，
 * 自动处理响应内容的编码转换，并创建QueryList对象用于后续内容提取。
 *
 * @param string $URI 请求地址
 * @param array $formvars 表单参数数组，格式为['字段名' => '字段值']
 * @param array $formfiles 文件上传信息，格式为['字段名' => ['name' => '文件名', 'tmp_name' => '临时路径']]
 * @param array $header 自定义HTTP头信息数组
 * @return string|bool 成功时返回转换编码后的响应内容，失败时返回错误信息
 */
public static function submit(string $URI,array $formvars = [],array $formfiles = [],array $header = [])
```

#### fetchLinks()

```php
/**
 * 提取网页中的链接
 * 
 * 获取指定URL页面中的所有链接，并自动将相对链接补全为绝对链接。
 * 可通过node参数指定要提取链接的HTML元素，默认为'a'标签。
 *
 * @param string $URI 请求地址
 * @param string $node HTML元素选择器，默认为'a'标签，用于指定从哪些元素中提取链接
 * @return string|array 失败时返回错误信息，成功时返回链接数组
 */
public static function fetchLinks(string $URI,string $node = ''): array|string
```

#### fetchForm()

```php
/**
 * 提取网页中的表单元素
 * 
 * 获取指定URL页面中的所有表单元素，包括input、select、textarea等，
 * 返回包含完整表单结构的HTML字符串。
 *
 * @param string $URI 请求地址
 * @return string|array 失败时返回错误信息，成功时返回表单HTML字符串
 */
public static function fetchForm(string $URI): array|string
```

#### fetchText()

```php
/**
 * 提取网页中的纯文本内容
 * 
 * 获取指定URL页面中的纯文本内容，自动去除HTML标签、JavaScript代码和特殊字符，
 * 返回干净的文本内容。
 *
 * @param string $URI 请求地址
 * @return string|array 失败时返回错误信息，成功时返回清理后的纯文本内容
 */
public static function fetchText(string $URI): array|string
```

#### fetchFrame()

```php
/**
 * 提取网页中的框架(frame)地址
 * 
 * 获取指定URL页面中所有frame标签的src属性值，并自动将相对URL转换为绝对URL。
 * 这对于处理包含框架的复杂网页很有用。
 *
 * @param string $URI 请求地址
 * @return string|array 失败时返回错误信息，成功时返回框架URL数组
 */
public static function fetchFrame(string $URI): array|string
```

#### fetchInfo()

```php
/**
 * 获取网页的元数据信息
 * 
 * 提取指定URL页面的标题(title)、关键词(keywords)、描述(description)和IP地址等元数据信息。
 * 这些信息对于SEO分析和网页内容初步判断非常有用。
 *
 * @param string $URI 请求地址
 * @return string|array 失败时返回错误信息，成功时返回包含元数据的关联数组
 */
public static function fetchInfo(string $URI): array|string
```

#### mirror()

```php
/**
 * 获取网页的完整响应信息
 * 
 * 获取指定URL的完整HTTP响应，包括状态码、头信息和内容，并对文本内容进行编码转换。
 * 与fetch方法不同，此方法返回完整的响应对象，而不仅仅是内容。
 *
 * @param string $URI 请求地址
 * @param array|string $query 查询参数，支持数组或字符串格式
 * @param array $options 请求选项，可配置超时、代理等参数
 * @return string|array 失败时返回错误信息，成功时返回完整的响应数组
 */
public static function mirror(string $URI,array|string $query = '',array $options = []): string|array
```

## 4. 内部方法

以下是Snoopy类中的内部方法（私有方法），仅供类内部使用，了解这些方法可以帮助更好地理解类库的工作原理：

- `_stripForm()`: 从HTML文档中提取表单元素
- `_stripText()`: 从HTML文档中提取纯文本内容
- `_expandLinks()`: 将相对链接转换为绝对链接
- `_t()`: 检测字符串的编码格式
- `_uniDecode()`: 将Unicode HTML实体转换为UTF-8字符
- `_getCharset()`: 自动检测网页内容的字符编码

## 5. 使用示例

### 5.1 基本用法 - 获取网页内容

```php
use \Extend\Snoopy;

// 获取网页内容并返回QueryList对象
$ql = Snoopy::get('https://example.com');

// 使用CSS选择器提取内容
$title = $ql->find('title')->text();
$links = $ql->find('a')->attrs('href')->all();

// 输出结果
echo "网页标题: {$title}\n";
echo "链接数量: " . count($links) . "\n";
```

### 5.2 提取特定内容

```php
use \Extend\Snoopy;

// 提取网页中的所有链接
$links = Snoopy::fetchLinks('https://example.com');
print_r($links);

// 提取网页中的纯文本内容
$text = Snoopy::fetchText('https://example.com');
echo substr($text, 0, 200) . "...";

// 获取网页元数据
$info = Snoopy::fetchInfo('https://example.com');
print_r($info);
```

### 5.3 表单提交与数据处理

```php
use \Extend\Snoopy;

// 模拟登录表单提交
$response = Snoopy::submit('https://example.com/login', [
    'username' => 'user123',
    'password' => 'pass456'
]);

// 处理响应
if ($response !== false) {
    // 提取登录后的链接
    $links = Snoopy::fetchLinks('https://example.com/dashboard');
    print_r($links);
} else {
    echo "登录失败";
}
```

### 5.4 高级用法 - 配置请求选项

```php
use \Extend\Snoopy;

// 配置请求选项
$options = [
    'timeout' => 30, // 超时时间30秒
    'proxy' => 'http://127.0.0.1:8888', // 代理设置
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
];

// 使用配置获取网页内容
$content = Snoopy::fetch('https://example.com', [], $options);

if ($content !== false) {
    echo "获取成功，内容长度: " . strlen($content);
} else {
    echo "获取失败";
}
```

## 6. 注意事项

1. **依赖要求**：本类基于QueryList库构建，使用前请确保QueryList库已正确安装
2. **编码处理**：自动编码转换功能支持多种编码格式，但对于一些特殊编码可能无法准确识别
3. **法律合规**：爬取网页内容时应遵守网站的robots.txt规则，尊重网站的版权和使用协议
4. **反爬虫机制**：频繁爬取可能会触发网站的反爬虫机制，建议设置合理的爬取间隔
5. **继承限制**：此类是final类型，不允许被继承
6. **命名空间**：注意导入正确的命名空间路径

## 7. 版本信息

- **作者**：刘森 (fingerboy@qq.com)
- **创建日期**：2023-06-09 14:00:00
- **最后更新**：2025-12-16  
- **适用框架**：Candy框架

## 8. 异常处理

```php
use \Extend\Snoopy;

// 错误处理示例
$result = Snoopy::get('https://invalid-url.example');

if (is_string($result)) {
    // 失败时返回错误信息字符串
    echo "错误信息: {$result}";
} else {
    // 成功时返回QueryList对象
    $title = $result->find('title')->text();
    echo "网页标题: {$title}";
}
```

## 9. 性能优化建议

1. **减少请求次数**：合理组织爬取逻辑，避免不必要的重复请求
2. **设置超时时间**：根据网络情况设置合适的超时时间，避免长时间等待
3. **缓存机制**：对于频繁访问的静态内容，可以实现缓存机制减少网络请求
4. **并发处理**：对于大量页面的爬取，可以考虑使用并发处理提高效率
5. **资源释放**：在爬取大量页面后，及时释放资源避免内存泄漏
