# Event 类

## 类简介

Event类是Candy框架的事件处理系统核心类，负责管理和分发系统中的各类事件，实现观察者模式。
它支持精确事件和通配符事件监听，提供灵活的事件注册与触发机制。
实现了事件回调的自动解析和执行，以及事件响应的收集和处理。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Event类 - 事件处理系统核心类
 * 
 * 负责管理和分发系统中的各类事件，实现观察者模式。
 * 支持精确事件和通配符事件监听，提供灵活的事件注册与触发机制。
 * 
 * @final 该类不允许被继承
 */
final class Event {
    // 类成员
}
```

## 类成员变量

### $events

```php
/**
 * 事件配置存储数组
 * @var array 存储事件配置信息的数组
 */
protected static $events = [];
```

### $eventMap

```php
/**
 * 精确事件监听器映射
 * @var array 键为精确事件名称，值为监听器数组，格式为[id => 监听器]
 */
protected static $eventMap = [];
```

### $prefixEventMap

```php
/**
 * 前缀事件监听器映射
 * @var array 键为事件前缀，值为监听器数组，格式为[id => 监听器]
 */
protected static $prefixEventMap = [];
```

### $id

```php
/**
 * 监听器ID计数器
 * @var int 用于为每个监听器分配唯一ID的计数器
 */
protected static $id = 0;
```

### $eventConfig

```php
/**
 * 事件配置对象缓存
 * @var array|null 缓存的事件配置，避免重复读取配置文件
 * @access private 私有静态属性，仅在类内部访问
 */
private static $eventConfig = null;
```

## 主要方法

### emit()

```php
/**
 * 触发指定名称的事件
 * 
 * 根据事件名称获取对应的监听器列表，并依次执行这些监听器。
 * 可以控制是否在第一个非空响应时停止执行，以及收集所有监听器的响应。
 * 
 * @param string $event_name 事件名称
 * @param mixed $data 传递给事件监听器的数据
 * @param bool $halt 是否在接收到第一个非空响应时停止执行
 * @param string $type 事件触发类型，默认为'emit'
 * @return array|null 当$halt为true时返回第一个非空响应或null，否则返回所有监听器的响应数组
 */
public static function emit(string $event_name, mixed $data, bool $halt = false, string $type = 'emit'): ?array
```

### dispatch()

```php
/**
 * 分发指定名称的事件（emit方法的别名）
 * 
 * 这是emit方法的别名，提供了一致的API调用方式。
 * 功能与emit方法完全相同，只是默认的事件触发类型为'dispatch'。
 * 
 * @param string $event_name 事件名称
 * @param mixed $data 传递给事件监听器的数据
 * @param bool $halt 是否在接收到第一个非空响应时停止执行
 * @param string $type 事件触发类型，默认为'dispatch'
 * @return array|null 当$halt为true时返回第一个非空响应或null，否则返回所有监听器的响应数组
 */
public static function dispatch(string $event_name, mixed $data, bool $halt = false, string $type = 'dispatch'): ?array
```

### list()

```php
/**
 * 获取所有已注册的事件监听器列表
 * 
 * 返回系统中所有已注册的事件监听器，包括精确匹配和通配符匹配的监听器。
 * 结果以ID为键，值为[事件名称, 监听器]的二维数组，并按ID排序。
 * 
 * @return array 已注册的事件监听器列表，格式为[id => [事件名称, 监听器]]
 */
public static function list(): array
```

### getListeners()

```php
/**
 * 获取指定事件的监听器列表
 * 
 * 根据事件名称获取对应的监听器，包括精确匹配的监听器和通配符匹配的监听器。
 * 如果事件尚未初始化，则会通过getEvents方法初始化该事件的监听器。
 * 
 * @param string $event_name 事件名称
 * @param string $type 事件类型，默认为'dispatch'
 * @return callable[] 事件监听器数组，按ID排序
 */
public static function getListeners(string $event_name, string $type = 'dispatch'): array
```

### hasListener()

```php
/**
 * 检查指定事件是否有监听器
 * 
 * 判断某个事件是否已经注册了监听器，包括精确匹配和通配符匹配的监听器。
 * 
 * @param string $event_name 事件名称
 * @return bool 如果事件有监听器则返回true，否则返回false
 */
public static function hasListener(string $event_name): bool
```

### clearCache()

```php
/**
 * 清除事件缓存
 * 
 * 重置所有与事件相关的静态变量，包括事件配置、事件映射和前缀事件映射。
 * 这将强制系统重新从配置文件加载事件定义，适用于需要刷新事件配置的场景。
 * 
 * @return void
 */
public static function clearCache(): void
```

## 内部方法

以下是Event类中的内部方法（私有/保护方法），仅供类内部使用：

### getEvents()

```php
/**
 * 初始化事件监听器
 * 
 * 根据事件配置文件初始化指定事件的监听器。
 * 支持精确事件和通配符事件的初始化，会自动解析回调函数。
 * 
 * @param string $event_name 事件名称
 * @param string $type 事件类型
 * @return void
 */
protected static function getEvents(string $event_name, string $type): void
```

### convertCallable()

```php
/**
 * 将配置的回调转换为可调用对象
 * 
 * 解析配置文件中的回调定义，将其转换为有效的PHP可调用对象。
 * 支持类方法格式的回调，并处理可能出现的异常。
 * 
 * @param array $callbacks 回调配置数组，格式为[类名, 方法名]
 * @param string $type 事件类型，用于错误处理
 * @return array|bool 成功时返回[实例, 方法名]的可调用数组，失败时返回false
 */
protected static function convertCallable(array $callbacks, string $type): array|bool
```

## 使用说明

### 触发事件

```php
// 触发简单事件并获取所有响应
$responses = \Candy\Core\Event::emit('user.login', $userData);

// 触发事件并在第一个非空响应时停止
$firstResponse = \Candy\Core\Event::emit('user.login', $userData, true);

// 使用dispatch方法（与emit功能相同）
$responses = \Candy\Core\Event::dispatch('system.startup', $appData);
```

### 检查事件监听器

```php
// 检查特定事件是否有监听器
if (\Candy\Core\Event::hasListener('user.register')) {
    // 事件有监听器，可以执行相关逻辑
}

// 获取特定事件的所有监听器
$listeners = \Candy\Core\Event::getListeners('user.login');

// 获取所有注册的事件监听器
$allListeners = \Candy\Core\Event::list();
```

### 清除事件缓存

```php
// 清除事件缓存，重新加载配置
\Candy\Core\Event::clearCache();
```

### 配置事件监听器

在配置文件中定义事件监听器：

```php
// Event.php 配置文件示例
return [
    // 精确事件配置
    'user.login' => [
        ['\App\Listener\UserListener', 'onLogin']
    ],
    // 通配符事件配置（会匹配所有以user.开头的事件）
    'user.*' => [
        ['\App\Listener\UserListener', 'onUserEvent']
    ]
];
```

## 注意事项

1. Event类是一个final类，不能被继承
2. 支持精确事件和通配符事件（格式为'prefix.*'）
3. 当事件监听器返回false时，会中断后续监听器的执行
4. 事件配置会被缓存，修改配置后需要调用clearCache()方法刷新
5. 监听器可以是任何有效的PHP可调用对象，系统会自动解析
6. 错误处理机制会根据事件类型（emit/dispatch）采取不同的处理方式
7. 事件监听器执行时会接收两个参数：事件数据和事件名称

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 23:00:09