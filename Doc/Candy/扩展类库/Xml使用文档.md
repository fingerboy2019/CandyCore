# Xml XML操作类使用文档

## 简介与基本概念

Xml类是Candy框架中的XML操作工具类，提供了XML和数组之间的相互转换功能，支持简单和复杂的XML结构处理。该类采用静态方法设计，无需实例化即可直接使用，为开发者提供了便捷、安全的XML数据处理能力。

### 命名空间
```php
namespace Candy\Extend;
```

### 核心特性
- **数组与XML互转**：支持将PHP数组转换为XML格式字符串，以及将XML字符串解析为PHP数组
- **安全防护**：自动禁用外部实体加载，防止XML外部实体注入(XE)攻击
- **CDATA支持**：保留CDATA标签中的数据内容，避免特殊字符解析问题
- **递归处理**：支持多维数组的递归转换，自动为数值键添加默认的'item'标签
- **配置灵活**：可通过配置文件自定义XML版本、编码和根元素名

## 快速入门

### 基本用法
```php

use Candy\Extend\Xml;

// 将PHP数组转换为XML格式
$data = ['name' => 'Candy', 'version' => '1.0'];
$xml = Xml::arrayToXml($data);

// 将XML字符串转换为PHP数组
$array = Xml::xmlToArray($xml);

// 输出结果
print_r($array);
// 结果: ['name' => 'Candy', 'version' => '1.0']
```

## 常用操作方法

### arrayToXml() - 数组转XML

将PHP数组转换为标准XML格式字符串，支持多维结构和特殊字符处理。

#### 语法
```php
Xml::arrayToXml(array $data): string
```

#### 参数说明
- `$data`: 待转换的PHP数组，支持多维结构

#### 返回值
- XML格式字符串

#### 示例

```php
use Candy\Extend\Xml;

// 简单数组转换
$simpleData = ['name' => '张三', 'age' => 25, 'email' => 'zhangsan@example.com'];
$xml = Xml::arrayToXml($simpleData);
echo $xml;
// 输出:
// <?xml version="1.0" encoding="UTF-8"?>
// <root>
//     <name><![CDATA[张三]]></name>
//     <age>25</age>
//     <email><![CDATA[zhangsan@example.com]]></email>
// </root>

// 多维数组转换
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
echo $xml;
// 输出包含嵌套结构的XML
```

### xmlToArray() - XML转数组

将XML格式字符串转换为PHP数组结构，自动处理嵌套关系和CDATA内容。

#### 语法
```php
Xml::xmlToArray(string $xml): array
```

#### 参数说明
- `$xml`: XML格式的字符串数据

#### 返回值
- PHP数组，保持与XML结构一致的层次关系

#### 示例

```php
use Candy\Extend\Xml;

// 简单XML转换
$simpleXml = '<root><name>张三</name><age>25</age></root>';
$array = Xml::xmlToArray($simpleXml);
print_r($array);
// 结果: ['name' => '张三', 'age' => 25]

// 复杂XML转换，包含嵌套和CDATA
$complexXml = '<?xml version="1.0" encoding="UTF-8"?>
<root>
    <user>
        <id>1</id>
        <name><![CDATA[张三]]></name>
        <roles>
            <item>admin</item>
            <item>user</item>
        </roles>
    </user>
    <settings>
        <theme>dark</theme>
        <notifications>true</notifications>
    </settings>
</root>';
$array = Xml::xmlToArray($complexXml);
print_r($array);
// 结果: 包含完整嵌套结构的数组
```

## 处理特殊情况

### 处理包含HTML标签的数据

Xml类会自动为字符串值添加CDATA标签，确保HTML标签等特殊字符能被正确处理。

```php
use Candy\Extend\Xml;

// 处理包含HTML标签的数据
$htmlData = [
    'title' => '测试页面',
    'content' => '<p>这是一段<b>加粗</b>的测试内容，包含&符号等特殊字符。</p>'
];
$xml = Xml::arrayToXml($htmlData);
echo $xml;
// 输出会自动使用CDATA包裹HTML内容
```

### 处理数值键数组

当数组包含数值键时，Xml类会自动为其添加默认的'item'标签。

```php
use Candy\Extend\Xml;

// 处理数值键数组
$numericArray = ['apple', 'banana', 'orange'];
$xml = Xml::arrayToXml($numericArray);
echo $xml;
// 输出:
// <?xml version="1.0" encoding="UTF-8"?>
// <root>
//     <item><![CDATA[apple]]></item>
//     <item><![CDATA[banana]]></item>
//     <item><![CDATA[orange]]></item>
// </root>
```

### 处理布尔值和其他类型

Xml类会自动处理不同类型的值，将它们转换为合适的XML表示。

```php
use Candy\Extend\Xml;

// 处理各种类型的数据
$mixedData = [
    'boolean_true' => true,
    'boolean_false' => false,
    'integer' => 123,
    'float' => 123.45,
    'null_value' => null,
    'array_value' => [1, 2, 3]
];
$xml = Xml::arrayToXml($mixedData);
echo $xml;
// 输出会根据类型自动转换
```

## 注意事项与最佳实践

1. **安全防护**
   - Xml类内置防XML外部实体注入(XE)功能，无需额外配置
   - 处理来自不可信来源的XML数据时，建议进一步验证数据结构

2. **性能考虑**
   - 对于超大XML文件的处理，建议使用流式XML解析器
   - 复杂嵌套结构的转换可能会消耗较多内存，建议合理控制数据规模

3. **配置自定义**
   - 可通过配置文件(incpath/xml.php)自定义XML版本、编码和根元素名
   - 默认配置：版本1.0，编码UTF-8，根元素名'root'

4. **错误处理**
   - 无效的XML格式会导致转换失败，建议添加异常处理
   - 数组中的循环引用会导致无限递归，需确保输入数据结构合理

5. **兼容性**
   - 支持PHP 7.0及以上版本
   - 使用LIBXML库进行XML解析，需确保PHP已安装此扩展

## 常见问题

### 为什么XML中的中文显示为乱码？

Xml类默认使用UTF-8编码。如果出现乱码问题，请确保：
- 输入的PHP数组使用UTF-8编码
- 输出XML时，页面或响应头也使用UTF-8编码

### 如何修改XML的根元素名？

可通过配置文件(incpath/xml.php)自定义根元素名。例如：

```php
// incpath/xml.php
return [
    'root' => 'response'  // 将根元素名从默认的'root'改为'response'
];
```

### 如何保留XML中的属性？

Xml类主要处理简单的键值对转换，不直接支持XML属性。如果需要保留属性信息，可以使用特殊的键名格式，例如：

```php
$xmlWithAttributes = [
    'user' => [
        '@attributes' => ['id' => 1],  // 使用@attributes键来存储属性
        'name' => '张三',
        'age' => 25
    ]
];
```

## 实际应用场景

### API响应生成

在API开发中，Xml类可用于快速生成XML格式的响应。

```php
use Candy\Extend\Xml;

// API控制器中的响应生成
public function getUserXml($id)
{
    $user = [
        'user' => [
            'id' => $id,
            'name' => '张三',
            'age' => 25,
            'status' => 'active'
        ],
        'timestamp' => time(),
        'success' => true
    ];
    
    $xml = Xml::arrayToXml($user);
    header('Content-Type: application/xml; charset=utf-8');
    echo $xml;
}
```

### XML配置文件解析

Xml类可用于解析XML格式的配置文件。

```php
use Candy\Extend\Xml;

// 解析XML配置文件
$configXml = file_get_contents('config.xml');
$configArray = Xml::xmlToArray($configXml);

// 使用解析后的配置
$dbHost = $configArray['database']['host'];
$dbPort = $configArray['database']['port'];
```

### 数据交换格式转换

在与外部系统进行数据交换时，Xml类可用于格式转换。

```php
use Candy\Extend\Xml;

// 从外部系统接收XML数据
$externalXml = file_get_contents('http://external-api.com/data.xml');
$dataArray = Xml::xmlToArray($externalXml);

// 处理数据后转换为内部格式
$processedData = processData($dataArray);

// 将结果转换为JSON格式返回给前端
header('Content-Type: application/json');
echo json_encode($processedData);
```

## 总结

Xml类提供了简单、安全、高效的XML数据处理能力，是Candy框架中处理XML数据的首选工具。通过静态方法调用，开发者可以快速实现PHP数组与XML格式之间的相互转换，处理复杂数据结构，并确保数据安全性。

无论是在API开发、配置文件解析还是数据交换场景中，Xml类都能提供可靠的支持，帮助开发者提高开发效率，减少潜在的安全风险。