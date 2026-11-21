# App 类

## 类简介

App 类是 Candy 框架的核心控制类，负责整个框架的初始化、运行流程控制和资源管理。
作为框架的中心控制器，它协调各组件工作，处理请求路由，管理类自动加载，并确保
应用的正确初始化与执行。

此类被定义为 `final`，禁止被继承，确保框架核心逻辑的稳定性和一致性。

核心功能包括：
- 应用程序初始化和配置加载
- 路由解析和控制器分发
- 类自动加载和编译
- 会话管理和缓存控制
- 中间件处理和事件监听
- 长连接支持和 WebSocket 处理

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * App 类 - 框架核心类
 * 
 * 提供了框架的核心功能：
 * 1. 应用程序初始化和运行
 * 2. WebSocket 服务支持
 * 3. 数据库配置管理
 * 4. 会话加载和初始化
 * 5. 控制器加载和执行
 * 6. 类自动加载和编译
 * 7. 中间件处理
 * 
 * @final 禁止被继承
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-01 23:00:09
 * @package Candy\Core
 */
final class App
{
    // 缓存数组
    private static array $runtimeCache = [];
    
    // 类成员
}
```

## 主要方法

### __construct()

```php
/**
 * 构造函数
 * 
 * App 类的构造函数，为空实现，主要用于静态调用。
 * 
 * @return void 无返回值
 * @access public 公开方法
 */
public function __construct(): void
```

### Init()

```php
/**
 * 长链接模式初始化方法
 * 
 * 在 WebSocket 等长链接环境下，根据 worker 进程 ID 进行框架部分内容的初始化。
 * 此方法为长链接服务提供必要的初始化支持，确保框架在多进程环境中正确运行。
 * 
 * @param mixed $worker worker 进程对象，包含进程 ID 等信息
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function Init(mixed $worker): void
```

### wsRun()

```php
/**
 * WebSocket 长链接请求处理方法
 * 
 * 处理 WebSocket 客户端发送的请求，将 WebSocket 消息转换为标准的 HTTP 请求格式，
 * 并初始化框架工作环境，使 WebSocket 通信能够无缝融入框架的标准请求处理流程。
 * 
 * 主要功能：
 * - 解析 WebSocket 消息为 API 请求格式
 * - 规范化请求数据并初始化 $_SERVER 环境变量
 * - 构造 PATH_INFO 和处理请求参数
 * - 调用标准的应用执行流程
 * 
 * @param string $client_id WebSocket 客户端连接 ID
 * @param string $message WebSocket 消息内容，通常为 JSON 格式
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function wsRun(string $client_id, string $message): void
```

### run()

```php
/**
 * 应用程序核心运行方法
 * 
 * 作为 Candy 框架的主入口方法，负责协调整个应用程序的初始化、运行和资源管理。
 * 该方法按照预定的顺序执行各项初始化任务，确保框架各组件正确加载和运行。
 * 
 * 执行流程：
 * - PHP 版本检查，确保环境兼容性（不低于 8.4）
 * - 加载调试和异常处理类
 * - 创建缓存实例并加载配置
 * - 注册数据流操作和包含函数库
 * - 运行安全中间件和黑名单检测
 * - 启动会话管理
 * - 解析 URL 和路由处理
 * - 加载语言库和实例化控制器
 * 
 * @param bool $docType 文档中心模式，默认 false
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function run(bool $docType = false): void
```

### dbConfig()

```php
/**
 * 数据库配置解析方法
 * 
 * 负责加载和解析应用程序的数据库配置信息，设置缓存驱动，并初始化数据库连接参数。
 * 该方法从配置文件中读取数据库配置，并将其设置到全局配置中，供框架其他部分使用。
 * 
 * 主要功能：
 * - 加载配置文件并提取数据库配置
 * - 设置缓存服务器类型和配置
 * - 初始化默认数据库连接参数
 * - 处理多数据库配置信息
 * - 设置语言和其他全局配置
 * 
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access private 私有方法，仅在类内部调用
 */
private static function dbConfig(): void
```

### sessionLoading()

```php
/**
 * 会话管理初始化方法
 * 
 * 负责初始化和配置 PHP 会话管理，支持从请求中获取 sessionid 并正确设置，
 * 为应用程序提供用户会话状态管理功能。
 * 
 * 主要功能：
 * - 从请求参数中提取 sessionid（如果有）
 * - 设置会话 ID 并启动 PHP 会话
 * - 在调试模式下记录会话启动信息
 * - 支持 CLI 模式下的会话处理
 * - 使用缓存 Session（分布式缓存管理）或普通文件 Session
 * 
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access private 私有方法，仅在类内部调用
 */
private static function sessionLoading(): void
```

### loading()

```php
/**
 * 应用环境初始化方法
 * 
 * 负责初始化应用程序的基本环境配置，包括路径定义、全局变量设置和资源目录初始化。
 * 该方法为应用程序提供必要的路径信息和环境变量，确保框架组件能够正确访问资源。
 * 
 * 主要功能：
 * - 定义项目根路径和相关目录路径
 * - 设置全局变量（如 ROOTPATH、C_PUBLIC 等）
 * - 初始化模板路径和上传目录
 * - 在调试模式下记录路径信息
 * - 处理运行时缓存目录
 * 
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access private 私有方法，仅在类内部调用
 */
private static function loading(): void
```

### doRun()

```php
/**
 * 控制器实例化与执行方法
 * 
 * 作为框架启动的核心入口，负责确定并实例化控制器类，然后调用其工作方法处理请求。
 * 该方法实现了 MVC 架构中的控制器分发功能，确保请求能够被正确的控制器处理。
 * 
 * 主要功能：
 * - 确定当前控制器类的路径
 * - 设置缓存控制头（调试模式下）
 * - 引入项目标签配置
 * - 触发应用程序开始事件
 * - 根据请求参数确定控制器类名
 * - 使用依赖注入容器实例化控制器并执行
 * 
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access private 私有方法，仅在类内部调用
 */
private static function doRun(): void
```

### common()

```php
/**
 * 公共类自动加载与生成方法
 * 
 * 负责加载和生成应用程序的公共类库，确保类能够被正确实例化并继承必要的父类。
 * 该方法是框架类自动加载机制的一部分，专门处理公共类库的加载。
 * 
 * @param string $className 要加载的公共类名称
 * @param string $app 项目名称（可选），指定要加载哪个项目的类
 * @return bool 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function common(string $className, string $app = ''): bool
```

### model()

```php
/**
 * 数据模型类自动加载与生成方法
 * 
 * 负责加载和生成应用程序的数据模型类，确保模型类能够被正确实例化并继承数据库驱动类。
 * 该方法是框架类自动加载机制的一部分，专门处理数据模型类的加载。
 * 
 * @param string $className 要加载的数据模型类名称
 * @param string $app 项目名称（可选），指定要加载哪个项目的模型
 * @return bool 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function model(string $className, string $app = ''): bool
```

### controler()

```php
/**
 * 控制器类自动加载与生成方法
 * 
 * 负责加载和生成应用程序的控制器类，确保控制器能够被正确实例化并继承必要的父类。
 * 该方法是框架类自动加载机制的一部分，专门处理控制器类的加载。
 * 
 * @param string $className 要加载的控制器类名称
 * @param string $app 项目名称（可选），指定要加载哪个项目的控制器
 * @return bool 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function controler(string $className, string $app = ''): bool
```

### getRootPath()

```php
/**
 * 获取应用程序根目录
 * 
 * 返回 Candy 框架应用程序的根目录路径，为应用程序提供统一的根路径访问。
 * 该方法可用于获取应用程序的基础目录，用于文件路径拼接和资源访问。
 * 
 * @return string 应用程序根目录的绝对路径
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function getRootPath(): string
```

### getBasePath()

```php
/**
 * 获取应用程序基础目录
 * 
 * 返回 Candy 框架应用程序的基础目录路径，通常指向 Application 目录，
 * 为应用程序提供统一的基础目录访问，用于加载应用模块和配置。
 * 
 * @return string 应用程序基础目录的绝对路径
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function getBasePath(): string
```

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 23:00:09