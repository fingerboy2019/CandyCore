# Hook 类

## 类简介

Hook类是Candy框架的钩子系统核心类，提供了插件管理、行为扩展、标签监听等功能。
它支持两种主要的钩子机制：静态文件钩子（用于模板和控制器编译）和动态执行钩子（基于事件标签）。
通过Hook类，开发者可以轻松扩展框架功能，实现插件系统和事件驱动的应用架构。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Hook类 - 框架钩子系统
 * 
 * Hook类是Candy框架的钩子系统核心类，提供了插件管理、行为扩展、标签监听等功能。
 * 它支持两种主要的钩子机制：静态文件钩子（用于模板和控制器编译）和动态执行钩子（基于事件标签）。
 * 
 * @final 该类不允许被继承
 */
final Class Hook {
    // 类成员
}
```

## 类成员变量

### $tags

```php
/**
 * 钩子标签与行为的映射关系
 * 存储所有已注册的标签及其对应的行为列表
 * @var array 键为标签名，值为行为列表数组
 * @access private 私有静态属性，仅在类内部访问
 */
private static $tags = [];
```

### $bind

```php
/**
 * 行为别名映射
 * 存储行为名称到具体实现的映射关系，便于通过别名调用行为
 * @var array 键为行为别名，值为实际行为类名或闭包
 */
protected static $bind = [];
```

### $hookConfig

```php
/**
 * 插件配置缓存
 * 缓存已加载的插件配置信息，避免重复读取
 * @var array|null 插件配置数组或null
 * @access private 私有静态属性，仅在类内部访问
 */
private static $hookConfig = null;
```

## 主要方法

### getEnableHooks()

```php
/**
 * 获取已启用的插件列表
 * 
 * 根据插件的安装状态和启用状态筛选出当前可用的插件。
 * 支持强制刷新和缓存机制，提高性能。
 * 
 * @param bool $force 是否强制刷新插件列表，true表示重新获取，false表示使用缓存
 * @return array 已启用的插件数组，键为插件名称，值为插件配置
 */
public static function getEnableHooks(bool $force = true): array
```

### getHooks()

```php
/**
 * 获取所有插件配置
 * 
 * 加载并缓存所有插件的配置信息，包括插件目录中的配置文件和全局插件配置。
 * 支持插件排序功能，并添加当前项目同名插件。
 * 
 * @return array 所有插件的配置数组，已按rank排序
 */
public static function getHooks(): array
```

### alias()

```php
/**
 * 注册行为别名
 * 
 * 为行为定义别名，方便通过简短名称调用具体行为。
 * 支持单个别名注册和批量注册多个别名。
 * 
 * @param string|array $name 行为别名或包含多个别名的数组
 * @param mixed $behavior 实际行为类名或闭包（当$name为字符串时有效）
 * @return string 当前类名，支持链式调用
 */
public static function alias($name, $behavior = null)
```

### add()

```php
/**
 * 动态添加行为到标签
 * 
 * 为指定标签动态注册行为，支持单个行为和行为数组的注册。
 * 提供覆盖模式和位置控制功能，可以控制行为的执行顺序。
 * 
 * @param string $tag 标签名称
 * @param mixed $behavior 行为名称、闭包或行为数组
 * @param bool $first 是否添加到队列开头（优先级高）
 * @return void
 */
public static function add($tag, $behavior, $first = false)
```

### import()

```php
/**
 * 批量导入标签与行为配置
 * 
 * 一次性导入多个标签及其对应的行为配置。
 * 支持递归合并和直接覆盖两种模式，并自动设置标签常量。
 * 
 * @param array $tags 标签配置数组，格式为[标签名 => 行为列表]
 * @param bool $recursive 是否递归合并现有配置，true为合并，false为覆盖
 * @return void
 */
public static function import(array $tags, $recursive = true)
```

### get()

```php
/**
 * 获取标签配置信息
 * 
 * 获取指定标签的行为配置，或所有已注册标签的配置信息。
 * 当不指定标签名时，返回所有标签的完整配置。
 * 
 * @param string $tag 标签名称，留空获取所有标签配置
 * @return array 指定标签的行为配置数组，或所有标签的配置数组
 */
public static function get($tag = '')
```

### listen()

```php
/**
 * 监听并触发标签事件
 * 
 * 监听指定标签并按顺序执行所有注册的行为。
 * 支持单次模式（只获取一个有效返回值）和完整模式（获取所有行为的返回值）。
 * 当某个行为返回false时，会中断执行链。
 * 
 * @param string $tag 标签名称
 * @param mixed $params 传递给行为的参数
 * @param bool $once 是否只获取第一个有效返回值
 * @return mixed 如果$once为true则返回单个返回值，否则返回所有行为的返回值数组
 */
public static function listen($tag, $params = null, $once = false)
```

### exec()

```php
/**
 * 执行指定行为
 * 
 * 执行单个行为，可以是闭包、数组形式的方法调用或通过别名注册的行为。
 * 自动处理行为别名解析，并使用容器执行行为。
 * 
 * @param mixed $class 行为类名、别名、闭包或[类名, 方法名]格式的数组
 * @param mixed $params 传递给行为的参数
 * @return mixed 行为执行的返回值
 */
public static function exec($class, $params = null)
```

### clearCache()

```php
/**
 * 清除所有钩子相关缓存
 * 
 * 重置所有与钩子系统相关的静态变量，包括插件配置缓存、标签映射和行为绑定。
 * 用于在插件更新或配置变更后强制重新加载最新配置。
 * 
 * @return void
 */
public static function clearCache(): void
```

## 内部方法

以下是Hook类中的内部方法（私有方法），仅供类内部使用：

### processHook()

```php
/**
 * 处理钩子文件内容
 * 
 * 读取并处理指定钩子文件的内容，支持千人千面特性的文件优先级。
 * 处理过程包括去除文件开头的exit语句和PHP标签，确保内容可以安全合并。
 * 
 * @param string $hookfile 钩子文件路径
 * @return string 处理后的钩子文件内容字符串
 */
private static function processHook(string $hookfile): string
```

### processHookCallback()

```php
/**
 * 处理钩子回调接口
 * 
 * 作为正则表达式替换回调函数，用于在模板或代码中动态替换钩子标签。
 * 从匹配结果中提取钩子文件路径并调用processHook方法处理。
 * 
 * @param array $matchs 正则表达式匹配结果数组，索引1包含钩子文件路径
 * @return string 处理后的钩子内容
 */
private static function processHookCallback(array $matchs): string
```

### execTag()

```php
/**
 * 执行标签对应的单个行为
 * 
 * 执行标签中的单个行为，支持闭包、静态方法调用和实例方法调用。
 * 提供异常捕获和备用执行方案，确保行为执行的稳定性。
 * 
 * @access protected 保护方法，仅在类内部调用
 * @param mixed $class 行为类名、闭包或静态方法名
 * @param string $tag 标签名称
 * @param mixed $params 传递给行为的参数
 * @return mixed 行为执行的返回值
 */
protected static function execTag($class, $tag = '', $params = null)
```

## 使用说明

### 行为注册与监听

```php
// 注册行为别名
\Candy\Core\Hook::alias('testBehavior', 'App\Behavior\TestBehavior');

// 注册多个行为别名
\Candy\Core\Hook::alias([
    'behavior1' => 'App\Behavior\Behavior1',
    'behavior2' => 'App\Behavior\Behavior2'
]);

// 添加行为到标签
\Candy\Core\Hook::add('app_init', 'testBehavior');

// 添加闭包行为
\Candy\Core\Hook::add('app_init', function($params) {
    // 闭包行为逻辑
    return true;
});

// 批量导入标签配置
\Candy\Core\Hook::import([
    'app_init' => ['behavior1', 'behavior2'],
    'app_end' => ['behavior3']
]);

// 监听并触发标签
$result = \Candy\Core\Hook::listen('app_init', $params);

// 只获取第一个有效返回值
$result = \Candy\Core\Hook::listen('app_init', $params, true);
```

### 直接执行行为

```php
// 执行单个行为
\Candy\Core\Hook::exec('testBehavior', $params);

// 执行闭包行为
\Candy\Core\Hook::exec(function($params) {
    // 闭包逻辑
    return $result;
}, $params);

// 执行类方法
\Candy\Core\Hook::exec(['\App\Behavior\Test', 'run'], $params);
```

### 插件管理

```php
// 获取所有插件配置
$allHooks = \Candy\Core\Hook::getHooks();

// 获取已启用的插件
$enableHooks = \Candy\Core\Hook::getEnableHooks();

// 不刷新缓存获取已启用插件
$enableHooksCached = \Candy\Core\Hook::getEnableHooks(false);

// 清除缓存（插件更新后使用）
\Candy\Core\Hook::clearCache();
```

## 注意事项

1. Hook类是一个final类，不能被继承
2. 行为注册时，默认按注册顺序执行，可通过$first参数控制行为优先级
3. 当行为执行返回false时，会中断整个执行链
4. 插件配置会被缓存，修改插件后需要调用clearCache()方法刷新
5. 支持千人千面功能，通过HALLMARK常量区分不同用户组的钩子文件
6. 当前项目同名插件会被自动添加到插件列表中，排序优先级较高
7. 行为别名系统可以简化行为调用，提高代码可读性

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 23:00:09