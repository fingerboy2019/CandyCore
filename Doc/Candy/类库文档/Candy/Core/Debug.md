# Debug 类

## 类简介

Debug 类是 Candy 框架的调试模式类和异常处理核心类，负责框架的错误捕获和处理、异常处理和格式化显示、调试信息的收集、管理和展示、脚本执行时间统计、文件加载跟踪、SQL 语句记录以及应用程序信息收集。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Debug 类 - 框架调试模式类和异常处理核心类
 * 
 * 该类是 Candy 框架的核心组件之一，负责以下功能：
 * - 框架的错误捕获和处理
 * - 异常处理和格式化显示
 * - 调试信息的收集、管理和展示
 * - 脚本执行时间统计
 * - 文件加载跟踪
 * - SQL 语句记录
 * - 应用程序信息收集
 */
final class Debug {
    // 类成员
}
```

## 类成员变量

### $includefile

```php
/**
 * 已加载文件列表
 * 
 * 存储已加载的框架文件和应用文件信息
 * 格式：['framework' => [...], 'app' => [...]]
 * @var array
 * @access public 公共属性，类外部可访问
 */
public static array $includefile = [];
```

### $application

```php
/**
 * 应用程序信息
 * 
 * 存储应用程序相关的调试信息
 * @var array
 * @access public 公共属性，类外部可访问
 */
public static array $application = [];
```

### $info

```php
/**
 * 一般调试信息
 * 
 * 存储通用的调试消息
 * @var array
 * @access public 公共属性，类外部可访问
 */
public static array $info = [];
```

### $sqls

```php
/**
 * SQL 语句记录
 * 
 * 存储执行过的 SQL 语句信息
 * @var array
 * @access public 公共属性，类外部可访问
 */
public static array $sqls = [];
```

### $paths

```php
/**
 * 路径信息
 * 
 * 存储与路径相关的调试信息
 * @var array
 * @access public 公共属性，类外部可访问
 */
public static array $paths = [];
```

### $errors

```php
/**
 * 错误信息列表
 * 
 * 存储捕获到的错误信息
 * @var array
 * @access public 公共属性，类外部可访问
 */
public static array $errors = [];
```

### $timers

```php
/**
 * 计时器数据
 * 
 * 存储不同名称计时器的开始时间
 * 格式：['timer_name' => start_time]
 * @var array
 * @access private 私有属性，类内部可访问
 */
private static array $timers = [];
```

### $msg

```php
/**
 * 错误类型映射表
 * 
 * 将 PHP 错误常量映射为人类可读的错误类型名称
 * @var array<string, string> 键为错误类型标识，值为错误描述
 * @access private 私有属性，类内部可访问
 */
private static array $msg = [
    E_WARNING => '运行时警告',
    E_NOTICE => '运行时提醒',
    E_USER_ERROR => '自定义错误', 
    E_USER_WARNING => '自定义警告',
    E_USER_NOTICE => '自定义提醒',
    E_DEPRECATED => '已弃用功能警告',
    'Unknown' => '未知错误'
];
```

## 主要方法

### start()

```php
/**
 * 初始化调试器
 * 
 * 在脚本开始处调用此方法，执行以下初始化操作：
 * 1. 启动默认计时器，用于记录脚本执行时间
 * 2. 设置自定义错误处理函数
 * 3. 设置自定义异常处理函数
 * 4. 注册脚本关闭时的处理函数
 * 5. 根据调试模式配置适当的错误报告级别
 * 6. 在 CLI 模式下清理静态数据数组
 *
 * @return void
 */
public static function start(): void
```

### spent()

```php
/**
 * 获取默认计时器的运行时间
 * 
 * 返回从脚本开始（调用 start 方法时）到当前时刻的执行时间，单位为毫秒
 * 这是 getTimer('default') 方法的便捷封装
 *
 * @return float 执行时间（毫秒），精确到小数点后两位
 */
public static function spent(): float
```

### catcher()

```php
/**
 * 错误处理函数
 * 
 * PHP 错误发生时自动调用，捕获并根据环境记录错误信息
 * 此方法会根据错误级别和环境决定如何处理错误：
 * - 在调试模式下，将错误信息添加到调试消息队列
 * - 非调试模式下，将错误记录到日志文件
 * - 忽略某些特定类型的错误（如 open_basedir 限制警告）
 *
 * @param int $errno 错误级别，如 E_WARNING、E_NOTICE 等
 * @param string $errstr 错误描述信息
 * @param string $errfile 发生错误的文件路径
 * @param int $errline 发生错误的行号
 * @return void
 */
public static function catcher(int $errno, string $errstr, string $errfile, int $errline): void
```

### exception()

```php
/**
 * 异常处理函数
 * 
 * PHP 异常发生时自动调用，处理并格式化展示异常信息
 * 此方法会对异常信息进行处理，移除框架路径前缀，使错误信息更清晰
 * 根据执行环境（CLI 或 Web）以不同方式展示异常
 *
 * @param \Throwable $e 异常对象，包含异常信息、文件路径和行号等
 * @return void
 */
public static function exception(\Throwable $e): void
```

### shutdown()

```php
/**
 * 关闭处理函数
 * 
 * 脚本执行结束时自动调用，捕获并处理未被自定义错误处理器捕获的致命错误
 * 例如：E_ERROR、E_PARSE、E_CORE_ERROR 等致命错误
 * 此方法会对错误信息进行处理，移除框架路径前缀，使错误信息更清晰
 *
 * @return void
 * @since 1.0.0
 * @uses error_get_last() 获取最后一个错误信息
 * @uses showMessage() 展示格式化的错误信息
 */
public static function shutdown(): void
```

### addMsg()

```php
/**
 * 添加调试消息
 * 
 * 根据不同类型将调试消息添加到相应的存储数组中，仅在调试模式下有效
 * 支持多种类型的调试信息分类存储，方便统一管理和展示
 *
 * @param string $msg 调试消息字符串，可以包含 HTML 标签以增强显示效果
 * @param int $type 消息类型，用于确定消息的存储位置
 *                 0 - 一般调试信息，存储在$info 数组
 *                 1 - 应用文件信息，存储在$includefile['app'] 数组
 *                 2 - SQL 语句信息，存储在$sqls 数组
 *                 3 - 路径信息，存储在$paths 数组
 *                 4 - 错误信息，存储在$errors 数组
 *                 5 - 框架文件信息，存储在$includefile['framework'] 数组
 *                 6 - 应用程序信息，存储在$application 数组
 * @return void
 */
public static function addMsg(string $msg, int $type = 0): void
```

### showMessage()

```php
/**
 * 展示错误信息
 * 
 * 根据不同的执行环境（CLI/HTTP）以不同方式展示格式化的错误信息
 * 在 Web 环境下，支持普通页面和 API 接口两种响应方式
 * API 接口支持 JSON 和 XML 两种数据格式
 *
 * @param string $msg 简洁的错误消息
 * @param string $type 错误类型标识，如'Exception'、'Shutdown'等
 * @param string $detailed 详细的错误信息，通常包含文件路径和行号
 * @return void 此方法会终止脚本执行（在 Web 环境下）
 */
public static function showMessage(string $msg, string $type = '', string $detailed = ''): void
```

### startTimer()

```php
/**
 * 开始计时器
 * 
 * 为指定名称的计时器记录开始时间点，用于后续计算代码执行时间
 * 可以创建多个不同名称的计时器，分别跟踪不同代码块的执行时间
 *
 * @param string $name 计时器名称，默认为'default'
 * @return void
 */
public static function startTimer(string $name = 'default'): void
```

### getTimer()

```php
/**
 * 获取计时器值
 * 
 * 返回指定名称计时器从开始到现在的运行时间，单位为毫秒
 * 如果指定的计时器不存在，返回 0.0
 * 结果会被四舍五入到小数点后两位
 *
 * @param string $name 计时器名称，默认为'default'
 * @return float 运行时间（毫秒），精确到小数点后两位
 */
public static function getTimer(string $name = 'default'): float
```

### exceptionFormat()

```php
/**
 * 格式化异常信息
 * 
 * 将异常信息和堆栈跟踪转换为更友好、更易读的格式
 * 特别处理包含自定义流前缀的文件路径，提高可读性
 * 主要用于开发环境中调试复杂的异常情况
 *
 * @param \Throwable $exception 异常对象，包含异常信息和堆栈跟踪
 * @return void 直接输出格式化后的异常信息到标准输出
 */
public static function exceptionFormat(\Throwable $exception): void
```

## 使用说明

### 基本用法

Debug 类通常在框架初始化时自动加载和调用，无需手动初始化。但在需要进行性能分析或自定义调试时，可以使用以下方法：

```php
// 开始计时器（非默认计时器）
Debug::startTimer('database_query');

// 执行一些操作
// ...

// 获取执行时间
$time = Debug::getTimer('database_query');
Debug::addMsg('数据库查询耗时：' . $time . 'ms');
```

### 添加调试信息

```php
// 添加一般调试信息
Debug::addMsg('用户登录成功，ID: ' . $user_id);

// 添加 SQL 语句信息
Debug::addMsg('SELECT * FROM users WHERE id = 1', 2);

// 添加错误信息
Debug::addMsg('参数验证失败：username 不能为空', 4);
```

### 获取脚本执行时间

```php
// 获取从脚本开始到现在的执行时间
$totalTime = Debug::spent();
echo '页面加载耗时：' . $totalTime . 'ms';
```

## 注意事项

1. Debug 类是 final 类，不允许被继承
2. 大部分调试功能仅在调试模式下生效，生产环境中应关闭调试模式
3. 调试信息会在页面底部以统一格式展示（仅在 Web 环境下）
4. 错误和异常会根据执行环境（CLI/Web）以不同方式展示
5. 脚本执行结束时会自动捕获并处理致命错误
6. API 模式下，错误信息会以 JSON 或 XML 格式返回

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 23:00:09