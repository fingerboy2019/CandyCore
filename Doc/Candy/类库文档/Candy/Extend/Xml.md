# Xml 类

## 类简介

Xml类是Candy框架中的XML操作工具类，提供了XML和数组之间的相互转换功能，支持简单和复杂的XML结构处理。
该类为最终类，所有方法都是静态的，无需实例化即可使用。

## 命名空间

```php
namespace Candy\Extend;
```

## 类定义

```php
/**
 * XML操作类
 * 
 * 该类提供了XML和数组之间的相互转换功能，支持简单和复杂的XML结构处理。
 * 主要功能包括将PHP数组转换为标准XML格式字符串，以及将XML字符串解析为PHP数组。
 */
final class Xml {
    // 类成员
}
```

## 主要方法

### arrayToXml()

```php
/**
 * 将PHP数组转换为XML格式字符串
 * 
 * 该方法接收一个PHP数组，并将其转换为标准的XML格式字符串。
 * 支持多维数组的递归处理，自动为数值键添加默认的'item'标签。
 * 自动设置适当的Content-Type头信息，适用于API响应或XML文件生成。
 * 
 * @param array $data 待转换的PHP数组，支持多维结构
 * @return string 转换后的XML格式字符串
 */
public static function arrayToXml(array $data): string
```

### xmlToArray()

```php
/**
 * 将XML格式字符串转换为PHP数组
 * 
 * 该方法接收一个XML格式的字符串，并将其转换为PHP数组结构。
 * 自动禁用外部实体加载，防止XML外部实体注入(XE)攻击，提高安全性。
 * 使用LIBXML_NOCDATA选项保留CDATA标签中的数据内容。
 * 
 * @param string $xml XML格式的字符串数据
 * @return array 转换后的PHP数组，保持与XML结构一致的层次关系
 */
public static function xmlToArray(string $xml): array
```

## 内部方法

以下是Xml类中的内部方法（私有方法），仅供类内部使用：

### loadXmlConfig()

```php
/**
 * 初始化XML配置
 * 
 * 该方法重置内部状态变量，加载默认配置并与用户配置合并。
 * 默认配置包括XML版本(1.0)、编码(UTF-8)和根元素名(root)。
 * 支持通过配置文件(incpath/xml.php)自定义这些参数。
 * 
 * @return void
 */
private static function loadXmlConfig(): void
```

### buildXml()

```php
/**
 * 递归构建XML结构
 * 
 * 该方法递归遍历数组，构建完整的XML结构。处理根元素和子元素的创建、嵌套关系，
 * 以及数值键的特殊处理。
 * 
 * @param array $data 要构建XML的数组数据
 * @param bool $eIsArray 是否为子数组的标记，用于判断是否需要添加根元素
 * @return void
 */
private static function buildXml(array $data, bool $eIsArray = false): void
```

### writeElement()

```php
/**
 * 创建并写入XML元素内容
 * 
 * 该方法创建一个XML元素，根据值的类型决定是否使用CDATA标签包裹。
 * 数值类型直接写入，字符串类型使用CDATA标签包裹以避免特殊字符解析问题。
 * 
 * @param string $key XML元素的名称
 * @param mixed $value XML元素的值，可以是字符串、数值或其他类型
 * @return void
 */
private static function writeElement(string $key, mixed $value): void
```

### startElement()

```php
/**
 * 创建XML元素的开始标签
 * 
 * 该方法生成并添加XML元素的开始标签，并将元素名称添加到内部栈中以跟踪嵌套关系。
 * 
 * @param string $element 元素的名称
 * @return void
 */
private static function startElement(string $element): void
```

### startDocument()

```php
/**
 * 创建XML文档声明
 * 
 * 该方法生成并添加XML文档的声明部分，包含版本和编码信息。
 * 声明内容基于配置参数生成。
 * 
 * @return void
 */
private static function startDocument(): void
```

### endElement()

```php
/**
 * 创建XML元素的结束标签
 * 
 * 该方法从内部栈中弹出最近添加的元素名称，并生成相应的结束标签。
 * 确保XML结构的正确嵌套和闭合。
 * 
 * @return void
 */
private static function endElement(): void
```

## 使用说明

### 基本用法

```php
// 将PHP数组转换为XML格式
$data = ['name' => 'Candy', 'version' => '1.0'];
$xml = Xml::arrayToXml($data);

// 将XML字符串转换为PHP数组
$array = Xml::xmlToArray($xml);
```

### 处理复杂数据结构

```php
// 转换多维数组
$complexData = [
    'user' => [
        'id' => 1,
        'name' => '张三',
        'roles' => ['admin', 'user']
    ],
    'settings' => [
        'theme' => 'dark',
        'notifications' => true
    ]
];
$xml = Xml::arrayToXml($complexData);
```

### 处理包含特殊字符的数据

```php
// 处理包含HTML标签的数据，会自动使用CDATA标签
$htmlContent = ['content' => '<p>测试内容 & 特殊字符</p>'];
$xml = Xml::arrayToXml($htmlContent);
```

## 注意事项

1. Xml类是最终类（final class），不允许被继承
2. 内置防XML外部实体注入(XE)功能，提高安全性
3. 支持自动为数值键添加默认的'item'标签
4. 字符串类型的值会自动使用CDATA标签包裹
5. 支持通过配置文件自定义XML版本、编码和根元素名

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-05 14:00:00