# Page使用文档

## 概述

Page类是Candy框架中的分页处理工具类，用于生成各种形式的分页HTML代码。支持自定义样式、URL格式、每页显示数量等功能，提供灵活的分页配置选项，适用于各种Web应用的分页需求。

> 注意：Page类是Candy框架核心类库的补充类库，提供分页处理功能。

### 主要功能特性

- **链式调用**：支持方法链式调用，简化代码结构
- **自动URL生成**：可自动从当前页面URL生成分页链接
- **自定义URL模板**：支持使用自定义URL模板，包含{page}占位符
- **灵活的样式配置**：可自定义分页的HTML结构和样式
- **中心页码**：可设置当前页在页码列表中的位置
- **页码数量控制**：可控制显示的页码数量
- **扩展功能**：支持分页说明等扩展功能

## 快速入门

### 命名空间

```php
use Extend\Page;
```

### 基本使用示例

```php
// 创建分页实例
$page = new Page();

// 设置分页参数（支持链式调用）
$page->total(1000)  // 总记录数
     ->num(20)      // 每页显示20条记录
     ->url()        // 自动生成URL
     ->page();      // 自动获取当前页码

// 输出生成分页HTML
$pagination = $page->output();

// 在模板中显示
echo $pagination;
```

## 核心功能详解

### 构造函数

```php
/**
 * 构造函数
 *
 * 初始化分页组件的默认配置
 *
 * @return bool
 */
public function __construct()
```

示例：

```php
$page = new Page();
```

### 设置分页参数

所有设置参数的方法都支持链式调用，提高代码的可读性和简洁性。

#### 设置总记录数

```php
/**
 * 设置总记录数
 *
 * @param int|null $total_post 总记录数
 * @return Page 返回当前对象实例，支持链式调用
 */
public function total($total_post = null): Page
```

示例：

```php
$page->total(1000);  // 总共有1000条记录
```

#### 设置每页显示数量

```php
/**
 * 设置每页显示的记录数
 *
 * @param int|null $num 每页显示的记录数，默认为10
 * @return Page 返回当前对象实例，支持链式调用
 */
public function num($num = null): Page
```

示例：

```php
$page->num(20);  // 每页显示20条记录
```

#### 设置URL模板

```php
/**
 * 设置分页URL模板
 *
 * 若未提供URL，则自动从当前页面URL生成
 * 支持{page}占位符，用于动态生成不同页码的链接
 *
 * @param string|null $url URL模板，包含{page}占位符
 * @return Page|bool 设置成功返回当前对象，失败返回false
 */
public function url($url = null): Page|bool
```

示例：

```php
// 自动生成URL
$page->url();

// 自定义URL模板
$page->url('/article/list-{page}.html');

// 使用查询参数
$page->url('/article/list.php?page={page}');
```

#### 设置当前页码

```php
/**
 * 设置当前页码
 *
 * 若未提供页码，则尝试从配置或GET参数获取
 *
 * @param int|null $page 当前页码
 * @return Page|bool 设置成功返回当前对象，失败返回false
 */
public function page($page = null): Page|bool
```

示例：

```php
// 自动获取当前页码
$page->page();

// 手动设置当前页码
$page->page(5);
```

### 配置分页样式

```php
/**
 * 设置分页样式配置
 *
 * @param array $config 分页样式配置数组
 *        - div: HTML容器结构，默认 '<div id="pages">{content}</div>'
 *        - total: 总数显示格式，默认 '<a>{content}</a>'
 *        - nowpage: 当前页样式，默认 '<span>{content}</span>'
 *        - page: 普通页码样式，默认 '<a href="{url}">{content}</a>'
 *        - pre: 上一页样式，默认 '<a href="{url}">{content}</a>'
 *        - next: 下一页样式，默认 '<a href="{url}">{content}</a>'
 *        - note: 分页说明样式，默认 '<a>{content}</a>'
 * @return void
 */
public function loadConfig(array $config = []): void
```

示例：

```php
$page->loadConfig([
    'div'     => '<div class="pagination-container">{content}</div>',
    'nowpage' => '<span class="current-page">{content}</span>',
    'page'    => '<a href="{url}" class="page-link">{content}</a>',
    'pre'     => '<a href="{url}" class="page-prev">上一页</a>',
    'next'    => '<a href="{url}" class="page-next">下一页</a>',
    'total'   => '<span class="page-total">{content}</span>',
    'note'    => '<span class="page-note">{content}</span>'
]);
```

### 设置页码显示样式

#### 设置页码列表显示的最大数量

```php
/**
 * 设置页码列表显示的最大数量
 *
 * @param int $num 页码数量
 * @return Page|bool 设置成功返回当前对象，失败返回false
 */
public function circle($num): Page|bool
```

示例：

```php
$page->circle(10);  // 显示10个页码
```

#### 设置当前页在页码列表中的位置索引

```php
/**
 * 设置当前页在页码列表中的位置索引
 *
 * @param int $num 中心位置索引
 * @return Page|bool 设置成功返回当前对象，失败返回false
 */
public function center($num): Page|bool
```

示例：

```php
$page->center(5);  // 当前页在中间位置
```

### 控制分页功能

#### 设置扩展功能开关

```php
/**
 * 设置扩展功能开关
 *
 * @param bool $ext 是否开启扩展功能，默认为true
 * @return Page 返回当前对象实例，支持链式调用
 */
public function ext(bool $ext = true): Page
```

示例：

```php
$page->ext(false);  // 关闭扩展功能
```

#### 设置分页隐藏状态

```php
/**
 * 设置分页隐藏状态
 *
 * @param bool $item 隐藏开关，默认为true
 * @return Page 返回当前对象实例，支持链式调用
 */
public function hide(bool $item = true): Page
```

示例：

```php
$page->hide(true);  // 隐藏分页
```

### 生成并输出生成分页HTML

```php
/**
 * 生成并返回分页HTML代码
 *
 * 根据当前配置自动计算分页参数，生成包含页码列表、首页、上一页、下一页、末页等元素的完整HTML代码
 * 当总记录数不足一页时，返回空字符串
 *
 * @return string 完整的分页HTML代码或空字符串
 */
public function output(): string
```

示例：

```php
$pagination = $page->output();
echo $pagination;
```

## 高级用法

### 1. 自定义URL模板

```php
// 自定义URL模板，使用{page}作为页码占位符
$page->url('/category/1/article-{page}.html');

// 带多个参数的URL
$page->url('/search.php?keyword=test&page={page}');
```

### 2. Bootstrap样式的分页

```php
$page->loadConfig([
    'div'     => '<nav aria-label="Page navigation"><ul class="pagination">{content}</ul></nav>',
    'nowpage' => '<li class="page-item active"><a class="page-link">{content}</a></li>',
    'page'    => '<li class="page-item"><a class="page-link" href="{url}">{content}</a></li>',
    'pre'     => '<li class="page-item"><a class="page-link" href="{url}" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a></li>',
    'next'    => '<li class="page-item"><a class="page-link" href="{url}" aria-label="Next"><span aria-hidden="true">&raquo;</span></a></li>'
]);
```

### 3. 结合数据库查询使用

```php
// 数据库查询示例（假设使用PDO）
$totalRecords = $pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();

// 创建分页实例
$page = new Page();
$page->total($totalRecords)
     ->num(20)
     ->url()
     ->page();

// 计算LIMIT参数
$offset = ($page->page - 1) * $page->num;

// 查询当前页的数据
$stmt = $pdo->prepare("SELECT * FROM articles LIMIT :offset, :num");
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':num', $page->num, PDO::PARAM_INT);
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 显示数据和分页
foreach ($articles as $article) {
    echo "<h2>{$article['title']}</h2>";
    echo "<p>{$article['content']}</p>";
}

// 显示分页
echo $page->output();
```

### 4. 完全自定义分页样式

```php
// 完全自定义分页HTML结构和样式
$page->loadConfig([
    'div'     => '<div class="my-pagination">{content}</div>',
    'total'   => '<div class="total">共{content}条记录</div>',
    'nowpage' => '<button class="page-btn current">{content}</button>',
    'page'    => '<button class="page-btn" onclick="goToPage({content})">{content}</button>',
    'pre'     => '<button class="page-btn prev" onclick="goToPage({content})">上一页</button>',
    'next'    => '<button class="page-btn next" onclick="goToPage({content})">下一页</button>',
    'note'    => '<div class="note">第{content}页/共{total_page}页</div>'
]);

// JavaScript分页跳转函数
?> <script>
function goToPage(page) {
    window.location.href = "/articles/page-" + page + ".html";
}
</script> <?php
```

## 注意事项

1. **总记录数与每页显示数**：
   - 当总记录数小于等于每页显示数量时，不会生成任何分页HTML
   - 确保总记录数是准确的，否则会导致分页计算错误

2. **URL模板**：
   - URL模板中必须包含{page}占位符用于生成不同页码的链接
   - 若不指定URL模板，系统会自动从当前URL生成

3. **链式调用**：
   - 链式调用时，必须确保方法调用的顺序合理
   - 通常先设置基本参数（total、num），再设置URL和页码，最后加载配置

4. **配置数组**：
   - 在使用loadConfig()方法时，确保所有必要的模板键都已定义
   - 未定义的键会使用默认值

5. **页码获取**：
   - 默认情况下，系统会自动从当前URL的GET参数中获取页码（参数名：page）
   - 也可以手动指定当前页码

6. **性能考虑**：
   - 对于大量数据的分页，确保总记录数的查询效率
   - 建议使用COUNT(*)查询总记录数，而非查询全部数据后count()

7. **兼容性**：
   - 生成的HTML代码兼容所有现代浏览器
   - 可以通过CSS轻松调整样式，适应不同的网站设计

## 应用场景

1. **文章列表分页**：将大量文章按页码显示，提高页面加载速度和用户体验
2. **商品列表分页**：电商网站中商品列表的分页展示
3. **搜索结果分页**：搜索引擎结果的分页展示
4. **数据报表分页**：系统管理后台的数据报表分页展示
5. **评论列表分页**：文章或商品评论的分页展示

## 版本信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-12-08 13:49:18
- **所属框架**: Candy框架核心类库的补充类库

## 相关链接

- [Candy框架文档](https://github.com/fingerboy/candy)
- [Bootstrap分页组件](https://getbootstrap.com/docs/5.3/components/pagination/) - 可以参考其样式设计
- [PHP官方文档](https://www.php.net/manual/zh/) - PHP语言官方文档