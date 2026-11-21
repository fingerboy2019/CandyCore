# Collect 使用文档

## 文档概述

Collect类是Candy框架的文章采集与内容提取工具类，**属于核心类库的补充类库**。该类提供了一套完整的网页内容采集和信息提取功能，主要负责获取远程网页HTML源码、提取特定区间内容、解析链接和标题、根据配置规则过滤和提取文章信息等。

## 快速入门

### 基本用法

```php
// 导入Collect类
use Extend\Collect;

// 1. 获取网页内容
$html = Collect::getContent('https://example.com/article');

// 2. 提取特定区间内容
$articleContent = Collect::getSubContent($html, '<div class="article">', '</div>');

// 3. 提取所有链接
$links = Collect::getAllUrl($html);

// 4. 按配置提取文章信息
$config = [
    'title_rule' => ['<h1>', '</h1>'],
    'title_html_rule' => ['<.*?>'],
    'time_rule' => ['<div class="time">', '</div>'],
    'time_html_rule' => ['<.*?>'],
    'content_rule' => ['<div class="content">', '</div>'],
    'content_html_rule' => ['<script.*?</script>', '<style.*?</style>']
];
$articleData = Collect::getFilterHtml($html, $config);
```

## 核心功能详解

### 网页内容获取

Collect类提供了`getContent()`方法，用于获取远程网页的HTML源码内容。

#### `getContent()`方法

```php
/**
 * 获取目标网址的HTML源码内容
 * 
 * @param string $url 目标网页的URL地址，必须是HTTP或HTTPS协议
 * @return string 返回获取到的HTML源码，已自动去除首尾空白字符
 */
public static function getContent(string $url): string
```

**功能特点**：
- 优先使用curl扩展（如果已启用）
- 如果curl不可用，则使用file_get_contents作为备选方案
- 自动处理URL重定向（CURLOPT_FOLLOWLOCATION）
- 支持HTTPS协议（禁用SSL验证以提高兼容性）
- 仅获取页面内容，不包含HTTP响应头
- 自动保存当前URL到静态属性，供后续方法使用

**使用示例**：

```php
$html = Collect::getContent('https://example.com/article');
echo "获取到的HTML内容长度：" . strlen($html);
```

### 特定内容提取

使用`getSubContent()`方法可以从HTML内容中提取指定起始和结束标记之间的子内容。

#### `getSubContent()`方法

```php
/**
 * 从HTML内容中提取指定起始和结束标记之间的子内容
 * 
 * @param string $html 要处理的完整HTML内容
 * @param string|array $start 起始标记，可以是普通字符串或正则表达式
 * @param string|array $end 结束标记，可以是普通字符串或正则表达式
 * @return string 返回提取的子内容字符串
 */      
public static function getSubContent(string $html, string $start, string $end): string
```

**功能特点**：
- 支持灵活的标记定位，使用字符串或正则表达式
- 自动处理嵌套标记情况（选择最外层匹配）
- 可选是否包含起始和结束标记本身
- 支持提取多个匹配区间

**使用示例**：

```php
// 提取文章正文
$articleContent = Collect::getSubContent($html, '<div class="article-body">', '</div>');

// 提取所有段落内容
$paragraphs = Collect::getSubContent($html, '<p>', '</p>');
```

### 链接提取

使用`getAllUrl()`方法可以从HTML内容中提取所有链接及关联标题。

#### `getAllUrl()`方法

```php
/**
 * 从HTML内容中提取所有链接及关联标题
 * 
 * @param string $html 区间内的HTML源码
 * @param string $urlContain 网址中必须包含的字符串，用于筛选特定链接
 * @param string $urlExcept 网址中不能包含的字符串，用于排除特定链接
 * @return array 返回提取的链接数组
 */    
public static function getAllUrl(string $html, string $urlContain='', string $urlExcept=''): array
```

**功能特点**：
- 提取所有链接中的href属性和文本内容
- 自动将相对URL转换为绝对URL
- 对链接文本进行清理，去除多余的空白字符
- 支持自定义链接筛选条件

**返回格式**：

```php
[
    'url' => [0 => '完整URL地址1', 1 => '完整URL地址2', ...],
    'title' => [0 => '链接标题1', 1 => '链接标题2', ...]
]
```

**使用示例**：

```php
// 提取所有链接
$allLinks = Collect::getAllUrl($html);

// 只提取包含'/article/'的链接
$articleLinks = Collect::getAllUrl($html, '/article/');

// 提取所有链接但排除包含'javascript:'的链接
$filteredLinks = Collect::getAllUrl($html, '', 'javascript:');

// 遍历链接
foreach ($allLinks['url'] as $key => $url) {
    echo "标题：" . $allLinks['title'][$key] . "，URL：" . $url . "\n";
}
```

### 文章信息提取

使用`getFilterHtml()`方法可以根据配置规则从HTML中提取和过滤文章数据。

#### `getFilterHtml()`方法

```php
/**
 * 根据文章内容页的HTML源码，过滤并提取相关信息
 * 
 * @param string $html 文章内容页面的HTML源码
 * @param array $config 过滤配置数组，包含各类提取和清洗规则
 * @return array 返回提取并过滤后的文章数据数组
 */    
public static function getFilterHtml(string $html, array $config = []): array
```

**配置参数结构**：

```php
$config = [
    'title_rule' => ['<h1>', '</h1>'], // 标题提取规则
    'title_html_rule' => ['<.*?>'], // 标题内容清洗规则
    'time_rule' => ['<div class="time">', '</div>'], // 时间提取规则
    'time_html_rule' => ['<.*?>'], // 时间内容清洗规则
    'content_rule' => ['<div class="content">', '</div>'], // 内容提取规则
    'content_html_rule' => ['<script.*?</script>', '<style.*?</style>'] // 内容清洗规则
];
```

**返回格式**：

```php
[
    'title' => '文章标题',
    'inputtime' => 1623456789, // UNIX时间戳
    'content' => '处理后的文章内容'
]
```

**使用示例**：

```php
$config = [
    'title_rule' => ['<h1 class="article-title">', '</h1>'],
    'title_html_rule' => ['<.*?>'],
    'time_rule' => ['<span class="publish-time">', '</span>'],
    'time_html_rule' => ['<.*?>'],
    'content_rule' => ['<div id="article-content">', '</div>'],
    'content_html_rule' => ['<script.*?</script>', '<style.*?</style>', '<div class="ad">.*?</div>']
];

$article = Collect::getFilterHtml($html, $config);

// 输出文章信息
echo "标题：" . $article['title'] . "\n";
echo "发布时间：" . date('Y-m-d H:i:s', $article['inputtime']) . "\n";
echo "正文：" . $article['content'] . "\n";
```

### 增强型字符串分割

使用`myexp()`方法可以进行增强型字符串分割处理。

#### `myexp()`方法

```php
/**
 * 增强型字符串分割处理方法
 * 
 * @param string $separator 分隔字符串
 * @param string $string 需要被分割处理的原始字符串
 * @return array 返回分割后的字符串数组
 */      
public static function myexp(string $separator, string $string): array
```

**功能特点**：
- 自动去除字符串中的换行符和回车符
- 自动清理末尾可能产生的空元素
- 为空字符串提供默认返回值，避免后续处理出现错误
- 支持任意字符串作为分隔符

**使用示例**：

```php
$string = "apple,banana,orange,grape\n";
$parts = Collect::myexp(',', $string);
print_r($parts); // 输出：Array ( [0] => apple [1] => banana [2] => orange [3] => grape )
```

## 高级用法

### 批量文章采集

```php
// 导入Collect类
use Extend\Collect;

// 获取文章列表页
$listHtml = Collect::getContent('https://example.com/news/list');

// 提取所有文章链接
$articleLinks = Collect::getAllUrl($listHtml, '/news/article/');

// 定义文章提取配置
$config = [
    'title_rule' => ['<h1>', '</h1>'],
    'title_html_rule' => ['<.*?>'],
    'time_rule' => ['<div class="publish-time">', '</div>'],
    'time_html_rule' => ['<.*?>'],
    'content_rule' => ['<div class="article-content">', '</div>'],
    'content_html_rule' => ['<script.*?</script>', '<style.*?</style>']
];

// 批量采集文章内容
$articles = [];
foreach ($articleLinks['url'] as $key => $url) {
    try {
        // 获取文章页面内容
        $articleHtml = Collect::getContent($url);
        
        // 提取文章信息
        $articleData = Collect::getFilterHtml($articleHtml, $config);
        
        // 添加文章URL和标题
        $articleData['url'] = $url;
        $articleData['original_title'] = $articleLinks['title'][$key];
        
        // 保存到结果数组
        $articles[] = $articleData;
        
        // 避免请求过快，添加延迟
        sleep(1);
    } catch (Exception $e) {
        echo "采集失败：" . $url . " - " . $e->getMessage() . "\n";
    }
}

// 输出采集结果
echo "成功采集 " . count($articles) . " 篇文章\n";
print_r($articles);
```

### 复杂内容提取

```php
// 获取网页内容
$html = Collect::getContent('https://example.com/complex-page');

// 提取多级嵌套内容
$mainContent = Collect::getSubContent($html, '<main>', '</main>');
$articleSection = Collect::getSubContent($mainContent, '<section class="article">', '</section>');
$articleBody = Collect::getSubContent($articleSection, '<div class="body">', '</div>');

// 提取图片链接
$images = [];
preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $articleBody, $imageMatches);
if (isset($imageMatches[1])) {
    foreach ($imageMatches[1] as $src) {
        // 转换为绝对URL
        $absoluteUrl = Collect::urlCheck($src, Collect::$url);
        $images[] = $absoluteUrl;
    }
}

// 输出结果
echo "提取的图片链接：" . "\n";
print_r($images);
```

## 注意事项

1. **环境要求**：
   - 确保PHP环境已启用curl扩展或允许使用file_get_contents函数
   - 对于HTTPS网站，需要确保服务器支持SSL

2. **法律合规**：
   - 采集功能可能受到目标网站反爬机制的限制
   - 建议遵守目标网站的robots.txt规则
   - 请确保采集行为符合相关法律法规

3. **性能优化**：
   - 在处理大量数据时，建议使用适当的缓存机制以提高性能
   - 对于批量采集，建议添加适当的延迟，避免对目标网站造成过大压力

4. **数据准确性**：
   - 对于复杂的网页结构，可能需要仔细设计提取规则以获得准确结果
   - 定期检查目标网站的HTML结构，及时更新提取规则

5. **错误处理**：
   - 建议使用try-catch块捕获可能的异常
   - 对获取到的内容进行必要的验证，确保数据完整性

## 常见问题

### Q: 为什么无法获取某些网站的内容？

A: 可能的原因包括：
- 目标网站有反爬机制，阻止了你的请求
- 目标网站使用了JavaScript动态加载内容
- 你的服务器无法访问目标网站（网络问题或DNS解析错误）
- PHP环境未启用curl扩展且file_get_contents函数被禁用

### Q: 如何处理相对URL？

A: Collect类会自动将相对URL转换为绝对URL，前提是你先调用了getContent方法，这样它才能获取到当前URL作为基准。

### Q: 如何处理JavaScript动态加载的内容？

A: Collect类主要处理静态HTML内容。对于需要JavaScript动态加载的内容，你可能需要使用无头浏览器或专门的爬虫工具来获取完整的页面内容。

### Q: 如何提高内容提取的准确性？

A: 可以通过以下方法提高准确性：
- 使用更精确的HTML标记作为提取规则
- 结合CSS选择器或XPath（可能需要额外的扩展）
- 对提取的内容进行后处理和验证
- 定期更新提取规则以适应目标网站的结构变化

### Q: 如何处理编码问题？

A: Collect类会自动处理常见的编码问题。如果遇到编码问题，可以尝试在获取内容后手动转换编码：

```php
$html = Collect::getContent($url);
$html = mb_convert_encoding($html, 'UTF-8', '自动');
```

## 最佳实践

1. **遵守爬虫礼仪**：
   - 尊重目标网站的robots.txt规则
   - 控制爬取频率，避免对目标网站造成过大压力
   - 提供合理的User-Agent标识

2. **数据处理流程**：
   - 先获取页面内容，再进行提取
   - 对提取的数据进行验证和清洗
   - 使用适当的数据结构存储采集结果

3. **错误处理机制**：
   - 实现完善的异常处理
   - 记录采集日志，便于排查问题
   - 实现重试机制，处理临时网络问题

4. **性能优化策略**：
   - 使用缓存机制减少重复请求
   - 对大量数据使用批量处理
   - 考虑使用多线程或异步请求提高采集效率

通过遵循这些最佳实践，你可以更高效、更可靠地使用Collect类进行网页内容采集和提取。