# Markdown 类

## 类简介

Markdown 类是 Candy 框架中用于文档渲染与博客系统的核心类，提供 Markdown 文档解析、渲染和导航生成功能。
支持两种运行模式：框架文档模式和博客模式，自动处理文档目录结构，生成导航菜单，支持自定义排序。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Markdown 类 - Candy 框架文档渲染与博客系统
 * 
 * 提供 Markdown 文档解析、渲染和导航生成功能，支持两种运行模式：框架文档模式和博客模式。
 * 自动处理文档目录结构，生成导航菜单，支持上一篇/下一篇文章切换，并根据.order 文件进行自定义排序。
 * 
 * @final 该类不允许被继承
 */
final class Markdown {
    // 类成员
}
```

## 类成员变量

### $conf

```php
/**
 * 配置选项数组
 * 
 * 存储 Markdown 渲染和博客系统的配置选项，包括文档根目录、模板路径等
 * 在博客模式下从 Source/Inc/BlogConfig.inc.php 加载，在文档模式下为空
 * 
 * @var array 配置选项键值对数组
 */
private static $conf = [];
```

### $index

```php
/**
 * 默认首页文件名
 * 
 * 定义目录默认显示的 Markdown 文件名称，默认为'README.md'
 * 当访问目录时会自动加载该文件
 * 
 * @var string 默认首页文件名
 */
private static $index = 'README.md';
```

### $filesDir

```php
/**
 * 文档文件目录路径
 * 
 * 存储 Markdown 文件所在的根目录路径，根据运行模式自动设置：
 * - 框架文档模式：默认为 Doc/Candy/
 * - 博客模式：从配置中加载或使用 Doc/Blog/
 * 
 * @var string 文档文件目录路径
 */
private static $filesDir;
```

### $basePath

```php
/**
 * 基础访问路径
 * 
 * 存储 URL 基础路径前缀，用于生成正确的链接地址
 * 默认值为'/'，当 URL 以'/doc/'开头时会更新为'/Doc/'
 * 
 * @var string URL 基础路径前缀
 */
private static $basePath = '/';
```

## 主要方法

### start()

```php
/**
 * Markdown 功能入口
 * 
 * 初始化 Markdown 系统，根据运行环境自动选择框架文档模式或博客模式
 * - 在博客模式下，读取 Source/Inc/BlogConfig.inc.php 配置并处理初始化请求
 * - 在文档模式下，使用默认配置处理文档显示
 * 自动加载并解析 Markdown 文件内容，最终通过 Respond 类输出 HTML 响应
 * 
 * @return void
 */
public static function start(): void
```

## 内部方法

以下是 Markdown 类中的内部方法（私有方法），仅供类内部使用：

### getMarkdownContent()

```php
/**
 * 获取并解析 Markdown 文件内容
 * 
 * 根据当前 URL 请求解析文件路径，读取并解析 Markdown 文件，生成 HTML 内容
 * 处理 URL 路径规范化、文档目录默认页面逻辑、菜单生成和模板渲染
 * 根据运行模式（文档或博客）采用不同的处理流程和模板
 * 
 * @return string 生成的 HTML 内容，包含解析后的 Markdown 和导航菜单
 */
private static function getMarkdownContent(): string
```

### generateMenuStructure()

```php
/**
 * 生成动态菜单结构
 * 
 * 获取当前访问路径，规范化处理后调用 getNavList 方法生成完整的菜单 HTML 结构
 * 同时返回当前页面的上一篇和下一篇文章信息，用于页面导航功能
 * 
 * @param string $search 搜索关键词
 * @return array 包含菜单 HTML、上一篇页面信息和下一篇页面信息的数组
 */
private static function generateMenuStructure(string $search): array
```

### loadOrderList()

```php
/**
 * 加载.order 文件中的排序规则
 * 
 * 读取指定目录下的.order 文件，解析其中的内容作为目录和文件的排序规则
 * 忽略空行和以#开头的注释行，返回有效的排序项数组
 * 
 * @param string $dirPath 要加载.order 文件的目录路径
 * @return array 排序规则数组，包含需要按顺序显示的文件名和目录名
 */
private static function loadOrderList(string $dirPath): array
```

### sortDirs()

```php
/**
 * 目录和文件排序处理
 * 
 * 根据自定义排序规则（.order 文件）或默认规则对目录和文件进行排序
 * 首先按照.order 文件中指定的顺序排列，如果没有自定义排序则按默认规则（目录在前，文件在后）
 * 
 * @param string $basePath 要排序的基础目录路径
 * @param array $customOrder 自定义排序规则数组
 * @return array 排序后的目录和文件路径数组
 */
private static function sortDirs(string $basePath, array $customOrder): array
```

### getNavList()

```php
/**
 * 获取目录导航列表并生成 HTML 结构
 * 
 * 递归遍历目录结构，生成嵌套的导航菜单 HTML，并计算当前页面的上一篇和下一篇文章信息
 * 处理活动状态标识，为当前访问的页面添加 active 类
 * 
 * @param string $currentPath 当前访问的 URL 路径
 * @param string $itemPath 当前处理的目录路径
 * @param string $search 搜索关键词
 * @return array 包含导航菜单 HTML、上一篇页面信息和下一篇页面信息的数组
 */
private static function getNavList(string $currentPath, string $itemPath, string $search): array
```

## 使用说明

### 框架文档模式

默认情况下，Markdown 类以框架文档模式运行，自动加载 Doc/Candy/ 目录下的文档文件：

```php
// 在应用中启动 Markdown 文档系统
\Candy\Core\Markdown::start();
```

### 博客模式

访问 `/Blog/` 时由 `UrlParser` 劫持进入博客模式，并读取 `Source/Inc/BlogConfig.inc.php`（`Candy\Extend\Doc\Blog\Config::load()`）：

```php
// 初始化博客系统
\Candy\Core\Markdown::start();

// 或者通过 URL 参数初始化
// http://yourdomain.com/?init=mdblog
```

### 自定义排序

在文档目录中创建.order 文件可以自定义导航菜单的显示顺序：

```
# .order 文件示例
README.md
快速入门.md
安装指南.md
配置管理.md
模型使用.md
控制器使用.md
```

## 注意事项

1. Markdown 类是 final 类，不允许被继承
2. 支持两种运行模式：框架文档模式和博客模式，根据配置自动切换
3. 访问目录时会自动加载 README.md 作为默认页面
4. 文档文件必须使用.md 扩展名
5. 通过.order 文件可以自定义导航菜单的排序规则
6. 支持页面导航功能，提供上一篇/下一篇链接
7. 博客模式下可以自定义模板路径和文档根目录
8. 框架文档模式下文档目录默认为 Doc/Candy/

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 23:00:09
```