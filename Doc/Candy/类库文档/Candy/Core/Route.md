# Route 类

## 类简介

Route 类是 Candy 框架的路由处理核心类，负责 URL 路由解析、规则注册、请求分发和参数处理的核心组件。
支持静态路由、动态路由、正则路由、分组路由等多种路由类型，提供灵活的 URL 管理机制。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Candy 框架 路由处理核心类
 * 
 * 负责 URL 路由解析、规则注册、请求分发和参数处理的核心组件
 * 支持静态路由、动态路由、正则路由、分组路由等功能
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-26 11:31:47
 * @final 该类为最终类，不允许被继承
 */
final class Route {
    /**
     * 存储所有注册的路由规则
     * 
     * 二维数组结构：$rules[分组名][请求方法][路由规则] = [路由信息]
     * 包含所有静态路由、动态路由和正则路由的注册信息
     * 
     * @var array 路由规则数组
     * @access protected static
     */
    protected static $rules = [];
    
    /**
     * 支持的 HTTP 请求方法列表
     * 
     * 包含通配符 * 和所有标准 HTTP 方法
     * 
     * @var array HTTP 请求方法数组
     * @access protected static
     */
    protected static $methodRules = ['*', 'get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    // 类成员
}
```

## 主要方法

### rule()

```php
/**
 * 注册路由规则
 * 
 * 将路由规则注册到系统中，支持多种路由类型和 HTTP 方法
 * 
 * @param string|array $rule 路由规则，可以是字符串或数组
 * @param string|callable|array $route 路由地址，可以是控制器@操作、闭包函数或数组
 * @param string $method 请求类型，默认'*'表示支持所有方法
 * @param array $option 路由参数，包含额外的路由选项配置
 * @param array $pattern 变量规则，用于验证路由参数格式
 * @return void
 * @example self::rule('user/:id', 'User/read', 'get', ['ext' => 'html'], ['id' => '\d+'])
 */
public static function rule($rule, $route, $method = '*', array $option = [], array $pattern = []): void
```

### reset() / onWorkerReload() / bindWorkerReload()

进程内通过 `Route::rule()` 注册的路由表在 Workerman 常驻 worker 中会跨请求保留（有意设计，利于性能）。热重载或插件重复注册时需清表：

```php
Route::reset(); // 清空 $rules 与 parseRoute 缓存

// Workerman reload 回调
Route::onWorkerReload($worker);

// 绑定到自定义 Worker（WebServer 已在 run() 内自动绑定）
Route::bindWorkerReload($worker);
```

**说明**：`Route.inc.php` 中的 `URL_MAP_RULES` / `URL_ROUTE_RULES` 由 `initConfig()` 每请求从文件重载，不在 `$rules` 静态表内。

### getRule()

```php
/**
 * 读取路由规则
 * 
 * 根据路由规则、分组和请求方法获取对应的路由配置信息
 * 如果指定方法未找到路由，则尝试获取通配符 (*) 路由
 * 
 * @param string $rule 路由规则
 * @param string $group 路由分组名称
 * @param string $method HTTP 请求方法
 * @return array 路由配置信息数组，包含 uri、callback、route、option、pattern 等
 * @example self::getRule('read', 'user', 'get')
 */
public static function getRule($rule, $group, $method):array
```

### parseRoute()

```php
/**
 * 解析路由 URI
 * 
 * 将 URI 解析为分组和路由规则两部分
 * 自动移除查询参数并按斜杠分隔 URI
 * 
 * @param string $uri 完整的 URI 路径，可能包含查询参数
 * @return array 返回包含分组名和路由规则的数组 [分组名，路由规则]
 * @example self::parseRoute('user/profile?id=123') => ['user', 'profile']
 */
public static function parseRoute(string $uri): array
```

### initConfig()

```php
/**
 * 初始化路由配置
 * 
 * 处理全局路由配置、路径分隔符保护、域名绑定等功能
 * 负责 XSS 过滤、应用项目初始化、控制器解析等核心工作
 * 支持单例模式和多应用模式的路由处理
 * 
 * @return void
 * @uses XSS 过滤处理 GET/POST 参数
 * @uses 支持项目默认重置和多层目录检测
 * @uses 支持插件 (Addon) 和扩展路径解析
 */
public static function initConfig(): void
```

### contrastRoute()

```php
/**
 * 匹配并处理路由规则
 * 
 * 根据配置的路由规则对 URL 路径进行匹配处理
 * 支持静态路由 (URL 映射) 和动态路由规则匹配
 * 支持正则路由和规则路由两种模式
 * 支持前置和后置的路由处理钩子
 * 
 * @param string $pathinfo 当前请求的 PATH_INFO 路径
 * @param array $config 路由配置数组，如果为空则使用全局配置
 * @return string 处理后的路由地址
 * @example self::contrastRoute('/user/123', $routeConfig)
 */
public static function contrastRoute(string $pathinfo,array $config = []): string
```

## 内部方法

以下是 Route 类中的内部方法（私有方法），仅供类内部使用：

### checkUrlMatch()

```php
/**
 * 检测 URL 和规则路由是否匹配
 * 
 * 对比 URL 路径和路由规则，检查是否匹配并提取动态变量
 * 支持动态变量定义、类型限制和排除规则
 * 
 * @param string $regx URL 路径字符串
 * @param string $rule 路由规则字符串
 * @return array|false 匹配成功返回包含动态变量的数组，失败返回 false
 * @example self::checkUrlMatch('/user/123', 'user/:id') => ['id' => '123']
 */
private static function checkUrlMatch(string $regx,string $rule)
```

### parseRule()

```php
/**
 * 解析规则路由
 * 
 * 将规则路由解析为具体的控制器/操作地址或重定向 URL
 * 支持动态变量替换、参数传递和 HTTP 重定向功能
 * 
 * @param string $rule 路由规则字符串
 * @param string|array $route 路由地址或数组
 * @param string $regx 原始 URL 路径
 * @return string 解析后的路由地址
 * @example self::parseRule('news/:id', 'News/read', '/news/123') => 'News/read/id/123'
 */
private static function parseRule(string $rule, $route,string $regx): string
```

### parseRegex()

```php
/**
 * 解析正则路由
 * 
 * 处理正则表达式匹配成功后的路由解析
 * 支持参数提取、动态变量替换和 HTTP 重定向
 * 
 * @param array $matches 正则表达式匹配结果数组
 * @param string|array $route 路由地址或数组
 * @param string $regx 原始 URL 路径
 * @return string 解析后的路由地址
 * @example self::parseRegex(['/news/123', '123'], 'News/read?id=:1', '/news/123') => 'News/read/id/123'
 */
private static function parseRegex(array $matches, $route,string $regx): string
```

### invokeRegx()

```php
/**
 * 执行正则匹配下的闭包方法
 * 
 * 通过反射机制调用路由匹配中的闭包函数
 * 自动处理参数绑定，支持默认参数值
 * 
 * @param callable $closure 要执行的闭包函数
 * @param array $var 正则匹配捕获的参数数组
 * @return mixed 闭包函数的返回值
 * @example self::invokeRegx(function($id) { return $id; }, ['/user/123', '123']) => '123'
 */
private static function invokeRegx($closure,array $var = []):mixed
```

### invokeRule()

```php
/**
 * 执行规则匹配下的闭包方法
 * 
 * 通过反射机制调用规则路由匹配中的闭包函数
 * 根据参数名称自动绑定路由变量，支持默认参数值
 * 
 * @param callable $closure 要执行的闭包函数
 * @param array $var 规则匹配提取的参数数组 (键值对形式)
 * @return mixed 闭包函数的返回值
 * @example self::invokeRule(function($id) { return $id; }, ['id' => '123']) => '123'
 */
private static function invokeRule($closure,array $var = []):mixed
```

### ctypeAlnumInfo()

```php
/**
 * 分析字符串中的分隔符信息
 * 
 * 用于检测 URL 中的第一个非字母数字字符作为分隔符
 * 支持保护特定符号不被识别为分隔符
 * 
 * @param string $str 要分析的字符串 (通常是 URL 路径)
 * @param array $protectSeparator 不被识别为分隔符的保护符号数组
 * @return array 返回包含分隔符和位置的数组 ['spr' => 分隔符，'seq' => 位置]，或空数组表示未找到分隔符
 * @example self::ctypeAlnumInfo('user/profile/123', ['-']) => ['spr' => '/', 'seq' => 4]
 */
private static function ctypeAlnumInfo(string $str='',array $protectSeparator = []):array
```

## 使用说明

### 基本用法

在项目中使用 Route 类进行路由配置和处理：

```php
// 注册静态路由
Route::rule('index', 'Index/index', 'get');

// 注册动态路由，带参数验证
Route::rule('user/:id', 'User/read', 'get', ['ext' => 'html'], ['id' => '\d+']);

// 注册正则路由
Route::rule('/^article\/(\d+)$/', 'Article/detail?id=:1', 'get');

// 使用闭包函数处理路由
Route::rule('hello/:name', function($name) {
    return 'Hello, ' . $name;
}, 'get');

// 带条件的路由
Route::rule('admin/:action', 'Admin/:action', 'get', ['ext' => 'html', 'method' => 'get']);
```

### 路由规则定义格式

#### 规则路由

```php
// 基本格式
'路由规则' => '控制器/操作?参数 1=值 1&参数 2=值 2...'

// 带动态变量
'user/:id' => 'User/read'

// 带类型限制的动态变量
'user/:id\d' => 'User/read'

// 带排除规则的动态变量
'user/:id^notadmin' => 'User/read'

// 使用闭包
'hello/:name' => function($name) { return 'Hello, ' . $name; }

// 重定向
'oldurl' => ['/newurl', 301]
```

#### 正则路由

```php
// 基本格式
'/^article\/(\d+)$/' => 'Article/detail?id=:1'

// 多个参数
'/^search\/(\w+)\/(\d+)$/' => 'Search/index?keyword=:1&page=:2'

// 使用闭包
'/^test\/(\d+)$/' => function($id) { return 'Test: ' . $id; }
```

### 路由配置文件

在项目中可以创建路由配置文件进行集中管理：

```php
// INCPATH/Route.inc.php 全局路由配置
return [
    // Api 对外前缀 / 专用域名（出站见 U()；入站见下方正则）
    'API_URL' => [
        'prefix' => 'api/v1',
        'domain' => '',
        'mode'   => false,
    ],
    // URL 映射规则（静态路由）
    'URL_MAP_RULES' => [
        'about' => 'Home/about',
        'contact' => 'Home/contact'
    ],
    // 动态路由规则
    'URL_ROUTE_RULES' => [
        '/^api\/v\d+\/(.+)$/' => ':1', // Api 前缀剥除（置顶；目标勿以 / 开头）
        'news/:id' => 'News/read',
        '/^user\/(\d+)$/' => 'User/detail?id=:1'
    ],
    // URL 分隔符
    'URL_DEPR' => '/',
    // 保护的分隔符
    'PROTECT_SEP' => '-,.',
    // URL 伪静态后缀
    'URL_HTML_SUFFIX' => 'html'
];
```

> Api 前缀入站/出站完整说明：[路由使用 · Api 统一前缀](../../框架使用/路由使用.md#43-api-统一前缀api_url)。

## 注意事项

1. 路由匹配优先级：静态路由 (URL 映射) > 正则路由 > 规则路由 > 普通 URL
2. 点 (.) 符号不能作为 URL 分隔符，会导致系统错误
3. 在定义路由规则时，动态变量以冒号 (:) 开头，如`:id`
4. 使用正则路由时，捕获组可以在路由地址中通过`:1`、`:2`等方式引用
5. 路由规则中的参数会被自动过滤 XSS，确保安全性
6. 可以通过路由选项参数限制 URL 后缀和 HTTP 请求方法
7. 支持域名绑定项目，可以为不同项目绑定不同域名
8. 框架支持单例模式和多应用模式，路由处理会根据不同模式进行调整

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-07-26 11:31:47