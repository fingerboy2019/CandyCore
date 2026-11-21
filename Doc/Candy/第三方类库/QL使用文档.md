# QueryList (QL) 类库使用文档

## 1. 类库概述

QueryList是一个基于phpQuery的通用列表采集类库，为Candy框架提供强大的网页解析和数据采集功能。

**主要特点：**
- 提供简洁易用的链式API
- 支持CSS选择器和jQuery风格语法
- 内置HTTP请求功能
- 支持多请求并发处理
- 灵活的规则配置系统
- 强大的插件扩展机制
- 完善的编码转换支持

**适用场景：**
- 网页数据采集和爬虫开发
- HTML文档解析和处理
- 数据抽取和结构化转换
- 批量数据采集任务

## 2. 核心组件

### 2.1 QueryList类

主入口类，提供核心API和服务转发功能。

**主要功能：**
- 初始化和配置QueryList实例
- 转发方法调用到Query或Kernel服务
- 提供静态方法访问

**关键方法：**
```php
// 创建实例
$ql = new QL\QueryList();

// 或使用静态方法
$ql = QL\QueryList::getInstance();
```

### 2.2 Query类

核心的HTML解析和数据提取类。

**主要功能：**
- HTML文档解析和管理
- CSS选择器查找元素
- 数据提取规则配置
- 数据采集和处理

**关键方法：**
- `setHtml($html)`: 设置HTML内容
- `find($selector)`: 查找匹配的元素
- `rules($rules)`: 配置数据提取规则
- `range($selector)`: 设置数据提取范围
- `getData()`: 获取采集结果

### 2.3 Elements类

HTML元素集合的包装器，提供丰富的DOM操作方法。

**主要功能：**
- 批量元素操作
- jQuery风格的链式调用
- 元素属性和内容获取
- 元素遍历和过滤

**关键方法：**
- `text()`: 获取元素文本
- `html()`: 获取元素HTML
- `attr($name)`: 获取元素属性
- `each($callback)`: 遍历元素
- `map($callback)`: 映射元素集合

### 2.4 服务组件

#### HttpService
提供HTTP请求功能，封装了Candy框架的Http类。

**方法：**
- `get($url, $args = null, $otherArgs = [])`: GET请求
- `post($url, $args = null, $otherArgs = [])`: POST请求
- `postJson($url, $args = null, $otherArgs = [])`: JSON POST请求

#### MultiRequestService
提供多请求并发处理功能。

**方法：**
- `multiGet($urls)`: 并发GET请求
- `multiPost($urls)`: 并发POST请求
- `success($callback)`: 设置成功回调
- `error($callback)`: 设置错误回调

#### EncodeService
提供编码转换功能。

**方法：**
- `encoding($outputEncoding, $inputEncoding = null)`: 转换编码

## 3. 快速开始

### 3.1 基本使用流程

```php
// 1. 导入命名空间
use QL\QueryList;

// 2. 创建QueryList实例
$ql = QueryList::getInstance();

// 3. 获取网页内容
$ql->get('http://example.com');

// 4. 配置采集规则
$rules = [
    'title' => ['h1', 'text'],
    'content' => ['.content', 'html'],
    'links' => ['a', 'href']
];

// 5. 设置采集规则并获取数据
$data = $ql->rules($rules)->query()->getData();

// 6. 处理采集结果
print_r($data->all());
```

### 3.2 使用CSS选择器

```php
// 查找所有a标签
$elements = $ql->find('a');

// 获取所有链接文本
$texts = $elements->texts();

// 获取所有链接地址
$hrefs = $elements->attrs('href');
```

## 4. 高级功能

### 4.1 数据提取规则

QueryList支持灵活的规则配置，每条规则包含4个参数：

```php
$rules = [
    '字段名' => ['选择器', '属性/内容类型', '标签过滤', '回调函数']
];
```

**属性/内容类型可选值：**
- `text`: 文本内容
- `html`: 内部HTML
- `htmlOuter`: 包含自身的HTML
- `attr(属性名)`: 指定属性值
- 其他HTML属性名（如href、src等）

**示例：**

```php
$rules = [
    'title' => ['.article-title', 'text'],
    'image' => ['.article-image', 'src'],
    'content' => ['.article-content', 'html', 'p span'], // 保留p和span标签
    'link' => ['.article-link', 'attr(href)', '', function($value) {
        return 'http://example.com' . $value; // 处理相对路径
    }]
];
```

### 4.2 范围限制

使用`range()`方法限制数据提取范围：

```php
// 只在.article-list范围内提取数据
$data = $ql->range('.article-list')
           ->rules($rules)
           ->query()
           ->getData();
```

### 4.3 多请求处理

```php
// 并发请求多个URL
$urls = [
    'http://example.com/page1',
    'http://example.com/page2',
    'http://example.com/page3'
];

$ql->multiGet($urls)
   ->success(function($ql, $response, $index) use ($rules) {
       // 处理每个请求的结果
       $data = $ql->rules($rules)->query()->getData();
       echo "Page {$index} data: " . json_encode($data->all()) . "\n";
   })
   ->error(function($ql, $reason, $index) {
       // 处理错误
       echo "Page {$index} error: " . $reason . "\n";
   })
   ->send();
```

### 4.4 编码转换

```php
// 自动检测并转换编码为UTF-8
$ql->get('http://example.com/gb2312-page')
   ->encoding('UTF-8');

// 指定输入编码和输出编码
$ql->get('http://example.com/gb2312-page')
   ->encoding('UTF-8', 'GB2312');
```

## 5. 插件系统

### 5.1 安装插件

```php
// 安装单个插件
$ql->use('QL\Plugin\MyPlugin');

// 安装插件并传递参数
$ql->use('QL\Plugin\MyPlugin', ['param1' => 'value1']);

// 安装多个插件
$ql->use([
    'QL\Plugin\Plugin1',
    'QL\Plugin\Plugin2'
]);
```

### 5.2 自定义插件

创建插件类：

```php
namespace QL\Plugin;

use QL\QueryList;

class MyPlugin {
    public static function install(QueryList $ql, $opt = []) {
        // 注册自定义方法
        $ql->bind('myMethod', function($param) {
            // 实现自定义功能
            return $this;
        });
        return $ql;
    }
}
```

### 5.3 绑定自定义方法

```php
// 在实例上绑定方法
$ql->bind('myMethod', function($param) {
    // 实现自定义功能
    echo "Custom method called with: {$param}\n";
    return $this;
});

// 使用自定义方法
$ql->myMethod('test');
```

## 6. 完整API参考

### 6.1 QueryList类方法

| 方法 | 描述 |
|------|------|
| `getHtml($rel = true)` | 获取HTML内容 |
| `setHtml($html)` | 设置HTML内容 |
| `html($html)` | 设置HTML内容（别名） |
| `find($selector)` | 查找匹配的元素 |
| `rules(array $rules)` | 配置数据提取规则 |
| `range($range)` | 设置数据提取范围 |
| `removeHead()` | 移除HTML头部 |
| `query(Closure $callback = null)` | 执行数据提取 |
| `getData(Closure $callback = null)` | 获取采集结果 |
| `queryData(Closure $callback = null)` | 获取数组格式的结果 |
| `setData(Collection $data)` | 设置数据 |
| `encoding(string $outputEncoding, string $inputEncoding = null)` | 转换编码 |
| `get($url, $args = null, $otherArgs = [])` | 发送GET请求 |
| `post($url, $args = null, $otherArgs = [])` | 发送POST请求 |
| `postJson($url, $args = null, $otherArgs = [])` | 发送JSON POST请求 |
| `multiGet($urls)` | 并发GET请求 |
| `multiPost($urls)` | 并发POST请求 |
| `use($plugins, ...$opt)` | 安装插件 |
| `pipe(Closure $callback = null)` | 管道操作 |

### 6.2 Elements类常用方法

| 方法 | 描述 |
|------|------|
| `text()` | 获取文本内容 |
| `html()` | 获取内部HTML |
| `htmlOuter()` | 获取包含自身的HTML |
| `attr($name)` | 获取指定属性 |
| `attrs($name)` | 获取所有元素的指定属性 |
| `find($selector)` | 查找子元素 |
| `each($callback)` | 遍历元素 |
| `map($callback)` | 映射元素集合 |
| `filter($selector)` | 过滤元素 |
| `hasClass($className)` | 检查是否有指定类 |
| `addClass($className)` | 添加类名 |
| `removeClass($className)` | 移除类名 |

## 7. 最佳实践

### 7.1 性能优化

```php
// 适当限制提取范围
$ql->range('.target-area');

// 避免不必要的DOM操作
$elements = $ql->find('.items');
$data = $elements->map(function($item) {
    return [
        'title' => $item->find('.title')->text(),
        'price' => $item->find('.price')->text()
    ];
});

// 及时销毁文档释放资源
$ql->destruct();
```

### 7.2 错误处理

```php
try {
    $ql->get('http://example.com')
       ->rules($rules)
       ->query();
    $data = $ql->getData();
} catch (Exception $e) {
    echo '采集失败: ' . $e->getMessage();
}
```

### 7.3 复杂数据处理

```php
$rules = [
    'items' => ['.item', 'html']
];

$data = $ql->rules($rules)
           ->query()
           ->getData(function($data) use ($ql) {
               // 二次解析复杂数据
               return array_map(function($item) use ($ql) {
                   $subQl = clone $ql;
                   return $subQl->setHtml($item)
                               ->rules([
                                   'title' => ['.title', 'text'],
                                   'tags' => ['.tags a', 'texts']
                               ])
                               ->query()
                               ->getData()
                               ->first();
               }, $data['items']);
           });
```

## 8. 注意事项

1. **资源管理**：
   - 长时间运行的采集任务应定期调用`destruct()`方法释放资源
   - 使用完QueryList实例后应及时销毁

2. **编码问题**：
   - 遇到乱码时使用`encoding()`方法转换编码
   - 必要时使用`removeHead()`方法移除HTML头部

3. **性能考虑**：
   - 避免在循环中频繁创建QueryList实例
   - 合理使用`range()`方法限制处理范围
   - 复杂任务考虑使用多进程或异步处理

4. **法律合规**：
   - 使用QueryList进行数据采集时请遵守相关网站的robots.txt协议
   - 尊重网站的版权和使用条款
   - 避免对目标网站造成过大负载

5. **依赖**：
    - phpQuery、Candy框架Http组件

---

**版权声明**：本类库基于jae-jae/QueryList优化，为Candy框架提供服务。