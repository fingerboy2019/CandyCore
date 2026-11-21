# Pinyin使用文档

## 类简介

Pinyin类是Candy框架中的汉字拼音转换工具类，提供将中文汉字转换为拼音的功能。支持多种转换模式，包括完整拼音转换、拼音首字母提取、自定义分隔符、首字母大写转换等功能。该类采用查表方式实现汉字到拼音的高效转换，支持所有常用汉字。

## 快速开始

### 基本用法

Pinyin类提供了静态方法，可以直接调用：

```php
// 引入命名空间
use Extend\Pinyin;

// 基本转换 - 获取完整拼音
$pinyin = Pinyin::get('中文测试');
// 结果: zhongwentest

// 获取拼音首字母
$firstLetter = Pinyin::get('中文测试', true);
// 结果: zwts

// 使用分隔符
$pinyinWithDelimiter = Pinyin::get('中文测试', false, '-');
// 结果: zhong-wen-ce-shi

// 首字母大写
$capitalizedPinyin = Pinyin::get('中文测试', false, '', true);
// 结果: ZhongWenCeShi
```

## 核心功能详情

### get() 方法

将中文字符串转换为拼音的核心方法，提供灵活的转换选项：

```php
/**
 * 将中文字符串转换为拼音
 * 
 * @param string $chinese 要转换的中文字符串
 * @param bool $onlyfirst 是否只返回拼音首字母（true:仅返回首字母, false:返回完整拼音）
 * @param string $delimiter 拼音之间的分隔符（默认为空字符串）
 * @param bool $ucfirst 是否将每个拼音的首字母大写
 * @param string $charset 文字编码（默认为'utf-8'）
 * @return mixed 返回转换后的拼音字符串
 */
public static function get(string $chinese = '', bool $onlyfirst = false, string $delimiter = '', bool $ucfirst = false, string $charset = 'utf-8'): mixed
```

#### 参数说明

| 参数 | 类型 | 说明 | 默认值 |
|------|------|------|--------|
| $chinese | string | 要转换的中文字符串 | '' |
| $onlyfirst | bool | 是否只返回拼音首字母 | false |
| $delimiter | string | 拼音之间的分隔符 | '' |
| $ucfirst | bool | 是否将每个拼音的首字母大写 | false |
| $charset | string | 文字编码 | 'utf-8' |

#### 返回值

转换后的拼音字符串。

## 高级用法

### 首字母大写且带分隔符

```php
// 首字母大写且带分隔符
$formattedPinyin = Pinyin::get('中文测试', false, '-', true);
// 结果: Zhong-Wen-Ce-Shi
```

### 首字母模式且首字母大写

```php
// 首字母模式且首字母大写
$capitalizedFirstLetter = Pinyin::get('中文测试', true, '', true);
// 结果: ZWTS
```

### 实际应用场景

#### 1. 生成拼音搜索关键词

```php
// 为商品名称生成拼音关键词用于搜索
function generateSearchKeywords($productName) {
    $fullPinyin = Pinyin::get($productName);
    $firstLetter = Pinyin::get($productName, true);
    return [$productName, $fullPinyin, $firstLetter];
}

// 使用示例
$keywords = generateSearchKeywords('苹果手机');
// 结果: ['苹果手机', 'pingguoshouji', 'pgsj']
```

#### 2. 生成URL友好的标识符

```php
// 将中文标题转换为URL友好的标识符
function generateSlug($title) {
    return Pinyin::get($title, false, '-', true);
}

// 使用示例
$slug = generateSlug('如何使用Candy框架');
// 结果: 'Ru-He-Shi-Yong-Candy-Kuang-Jia'
```

#### 3. 中文排序辅助

```php
// 基于拼音首字母对中文数组进行排序
function sortByPinyin(array $items) {
    $pinyinMap = [];
    foreach ($items as $item) {
        $pinyinMap[Pinyin::get($item)] = $item;
    }
    ksort($pinyinMap);
    return array_values($pinyinMap);
}

// 使用示例
$cities = ['北京', '上海', '广州', '深圳'];
$sortedCities = sortByPinyin($cities);
// 结果: ['北京', '广州', '上海', '深圳']
```

## 注意事项

1. 本类仅支持常见汉字的拼音转换，对于生僻字可能无法正确转换
2. 该类不处理多音字，每个汉字只返回一个固定的拼音
3. 默认字符编码为UTF-8，如需使用其他编码，请指定charset参数
4. 非中文字符将保持原样，不会进行转换
5. 该类为final类，不允许继承和重写
6. 转换后的结果会自动过滤非字母、数字、下划线和连字符的字符