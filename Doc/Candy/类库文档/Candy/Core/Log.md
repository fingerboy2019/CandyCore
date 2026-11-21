# Log 类

## 类简介

Log类是Candy框架中的日志管理系统，提供统一的日志记录、读取和管理功能。
支持多种日志级别（调试、信息、警告、错误、致命错误和SQL错误），自动格式化日志内容，
支持数组、对象和异常等多种数据类型的记录，并提供HTML友好的日志展示方式。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Log类 - Candy框架日志管理系统
 * 
 * 提供统一的日志记录、读取和管理功能，支持多种日志级别，包括调试、信息、警告、错误、致命错误和SQL错误。
 * 自动格式化日志内容，支持数组、对象和异常等多种数据类型的记录，并提供HTML友好的日志展示方式。
 * 
 * @final 该类不允许被继承
 */
final class Log {
    // 类成员
}
```

## 类成员变量

### $_handle

```php
/**
 * 文件句柄缓存数组
 * 
 * 存储已打开的日志文件句柄，键为日志文件路径，值为对应的文件句柄资源
 * 用于避免重复打开同一文件，提高性能并确保日志正确写入
 * 
 * @var resource[] 文件句柄资源数组
 */
private static $_handle = [];
```

## 主要方法

### input()

```php
/**
 * 写入日志记录
 * 
 * 将数据写入指定的日志文件，支持多种数据类型和日志等级。自动处理文件创建、路径生成
 * 和句柄管理，并为SQL错误和PDO异常提供特殊处理，记录更详细的错误信息。
 * 
 * @param mixed $data 要记录的数据，可以是字符串、数组、对象或异常
 * @param int $type 日志等级
 *                  -1: 无等级
 *                   0: DEBUG (调试信息)
 *                   1: INFO (一般信息)
 *                   2: WARN (警告信息)
 *                   3: ERROR (错误信息)
 *                   4: FATAL (致命错误)
 *                   5: SQL_ERROR (SQL错误)
 * @param string|null $path 日志文件路径，为空时使用默认路径(按日期)
 * @return void
 */
public static function input(mixed $data, int $type = 0, ?string $path = null): void
```

### read()

```php
/**
 * 读取日志文件内容
 * 
 * 读取指定路径的日志文件内容，支持两种返回格式：原始数组格式或HTML表格格式。
 * 当指定display为true时，返回格式化后的HTML表格，便于在浏览器中查看；
 * 否则返回日志行的数组，便于程序处理。
 * 
 * @param string|null $path 日志文件路径，为空时使用默认路径(按日期)
 * @param bool $display 是否返回HTML表格格式，默认为false（返回数组）
 * @return mixed 日志内容数组或HTML表格字符串
 */
public static function read(?string $path = null, bool $display = false): mixed
```

## 内部方法

以下是Log类中的内部方法（私有方法），仅供类内部使用：

### getRequest()

```php
/**
 * 转换数据为字符串格式
 * 
 * 将各种数据类型（数组、对象、异常、资源等）转换为标准的字符串格式，
 * 以便于记录到日志文件中。为不同类型的数据提供专门的格式化处理。
 * 
 * @param mixed $request 需要转换的数据，可以是任意类型
 * @return string 转换后的字符串
 */
private static function getRequest($request): string
```

### getDescTitle()

```php
/**
 * 将日志等级代码转换为描述文本
 * 
 * 根据传入的日志等级整数，返回对应的文本描述。用于在日志记录中添加等级标识，
 * 便于区分日志的重要程度和类型。
 * 
 * @param int $type 日志等级代码，范围-1到5
 * @return string 对应的日志等级描述文本
 */
private static function getDescTitle(int $type): string
```

### close()

```php
/**
 * 关闭指定的日志文件句柄
 * 
 * 关闭并释放已打开的日志文件句柄资源，同时从句柄缓存数组中移除
 * 避免资源泄漏，确保日志文件正确写入和关闭。
 * 
 * @param string $val 日志文件路径，作为句柄缓存数组的键
 * @return void
 */
private static function close(string $val): void
```

## 使用说明

### 基本用法

```php
// 记录调试信息
\Candy\Core\Log::input('调试信息', 0);

// 记录一般信息
\Candy\Core\Log::input('系统启动成功', 1);

// 记录警告信息
\Candy\Core\Log::input('磁盘空间不足', 2);

// 记录错误信息
\Candy\Core\Log::input('数据库连接失败', 3);

// 记录致命错误
\Candy\Core\Log::input('服务器内存溢出', 4);

// 记录SQL错误
\Candy\Core\Log::input(['sql' => $sql, 'error' => $error], 5);
```

### 记录复杂数据

```php
// 记录数组
\Candy\Core\Log::input(['user_id' => 1, 'username' => 'admin']);

// 记录对象
$user = new stdClass();
$user->id = 1;
$user->name = 'admin';
\Candy\Core\Log::input($user);

// 记录异常
\Candy\Core\Log::input($exception);
```

### 读取日志

```php
// 读取默认路径的日志文件内容（数组格式）
$logs = \Candy\Core\Log::read();

// 读取指定日期的日志文件
$logs = \Candy\Core\Log::read(C('LOGPATH') . '2024-01-01.log');

// 以HTML表格格式读取日志
$html = \Candy\Core\Log::read(null, true);
echo $html;
```

### 自定义日志路径

```php
// 记录到自定义路径的日志文件
\Candy\Core\Log::input('特殊操作日志', 1, C('LOGPATH') . 'special.log');
```

## 注意事项

1. Log类是一个final类，不能被继承
2. 默认情况下，日志文件按日期命名，存储在配置的LOGPATH目录下
3. 系统只有在APP_INIT_STATUS为true时才会记录日志
4. 对于SQL错误和PDO异常，会自动记录更详细的错误信息，包括错误消息、代码和堆栈跟踪
5. 日志系统会自动创建日志目录，无需手动创建
6. 资源类型的数据会被转换为通用描述文本
7. 日志文件句柄会在写入完成后自动关闭，避免资源泄漏

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 23:00:09