# Respond 类

## 类简介

Respond 类是 Candy 框架中的响应输出基础抽象类，提供 HTTP 响应管理、内容输出、头部设置、状态码控制等核心功能。支持不同响应类型（HTML、JSON 等）的灵活输出，包含缓存控制、Cookie 管理、Session 管理等特性。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * 响应基类，提供 HTTP 响应的基础功能和接口
 * 
 * 该类是一个抽象类，定义了响应系统的核心功能和接口，
 * 具体的响应类型（如 HTML、JSON 等）需要继承此类并实现相应的功能。
 */
abstract class Respond
{
    /**
     * 原始数据，存储待输出的原始内容
     * 
     * @var mixed 可以是任意类型的数据，最终会被处理为字符串输出
     */
    protected $data;

    /**
     * 当前内容类型 (contentType)
     * 
     * @var string 默认值为'text/html'
     */
    protected $contentType = 'text/html';

    /**
     * 字符集编码
     * 
     * @var string 默认值为'utf-8'
     */
    protected $charset = 'utf-8';

    /**
     * HTTP 状态码
     * 
     * @var integer 默认值为 200(成功)
     */
    protected $code = 200;

    /**
     * 是否允许请求缓存标志
     * 
     * @var bool 默认允许缓存(true)
     */
    protected $allowCache = true;

    /**
     * 输出选项参数数组
     * 
     * @var array 用于存储各种输出相关的配置选项
     */
    protected $options = [];

    /**
     * HTTP 响应头信息数组
     * 
     * @var array 存储待发送的 HTTP 响应头
     */
    protected $header = [];

    /**
     * 最终输出内容
     * 
     * @var string|null 默认为 null，表示尚未生成最终内容
     */
    protected $content = null;

    /**
     * Cookie 对象
     * 
     * @var \Candy\Extend\Cookie Cookie 操作对象实例
     */
    protected $cookie;

    /**
     * Session 对象
     * 
     * @var \Candy\Extend\Session Session 操作对象实例
     */
    protected $session;

    /**
     * 正文输出前的缓冲字符串
     * 
     * @var string 静态属性，存储所有响应共享的前置输出内容
     */
    public static $buff = '';
}
```

## 主要方法

### buff()

```php
/**
 * 添加内容到前置缓冲区
 * 
 * 用于在主响应内容输出前添加额外内容
 * 
 * @param string $data 要添加到缓冲区的数据，将被转换为字符串
 * @return void
 */
public static function buff(string $data = ''): void
```

### create()

```php
/**
 * 创建响应对象实例的静态工厂方法
 * 
 * 根据指定的响应类型动态加载对应的响应处理类
 * 支持直接指定类名或使用预定义的响应类型（如 html、json 等）
 * 自动添加前置缓冲区内容到输出数据
 * 
 * @param mixed $data 要输出的数据内容
 * @param string $type 响应类型或完整的类名，默认为'html'
 * @param int $code HTTP 状态码，默认为 200
 * @return Respond 返回响应对象实例
 * @throws \ReflectionException 当类反射创建失败时抛出
 */
public static function create(?string $data = '', string $type = 'html', int $code = 200): Respond
```

### send()

```php
/**
 * 将响应数据发送到客户端浏览器
 * 
 * 负责处理完整的响应流程：获取处理后的内容、发送 HTTP 状态码、
 * 设置响应头信息、输出内容到客户端
 * 在支持的环境中自动调用 fastcgi_finish_request 提高页面响应速度
 * 
 * @return void
 * @throws \InvalidArgumentException
 */
public function send(): void
```

### data()

```php
/**
 * 设置响应数据
 * 
 * 设置要输出的原始数据，数据将在后续处理中被转换为最终输出内容
 * 
 * @param string $data 输出数据
 * @return self 返回当前响应对象，支持链式调用
 */
public function data(string $data): self
```

### content()

```php
/**
 * 设置页面最终输出内容
 * 
 * 直接设置响应的内容字符串，跳过默认的数据处理流程
 * 会进行严格的类型检查，确保内容可转换为字符串
 * 
 * @param string $content 要设置的内容
 * @return self 返回当前响应对象实例，支持链式调用
 * @throws \InvalidArgumentException 当内容无法转换为字符串时抛出异常
 */
public function content(string $content): self
```

### getContent()

```php
/**
 * 获取最终的输出内容
 * 
 * 如果内容未设置，则会自动调用 output 方法处理原始数据
 * 确保返回的内容为字符串类型
 * 
 * @return string 返回处理后的字符串格式输出内容
 * @throws \InvalidArgumentException 当处理后的数据无法转换为字符串时抛出异常
 */
public function getContent(): string
```

### code()

```php
/**
 * 设置 HTTP 状态码
 * 
 * 设置响应的 HTTP 状态码，如 200(成功)、404(未找到)、500(服务器错误)等
 * 
 * @param int $code HTTP 状态码
 * @return self 返回当前响应对象，支持链式调用
 */
public function code(int $code): self
```

### contentType()

```php
/**
 * 设置 Content-Type 响应头
 * 
 * 指定响应的媒体类型和字符集
 * 
 * @param string $contentType 媒体类型，如'text/html'、'application/json'等
 * @param string $charset 字符编码，默认为'utf-8'
 * @return self 返回当前响应对象，支持链式调用
 */
public function contentType(string $contentType, string $charset = 'utf-8'): self
```

### header()

```php
/**
 * 设置 HTTP 响应头
 * 
 * 合并新的响应头到已有的响应头中
 * 
 * @param array $header 响应头数组，格式为[name => value]
 * @return self 返回当前响应对象，支持链式调用
 */
public function header(array $header = []): self
```

### cookie()

```php
/**
 * 设置 Cookie
 * 
 * 通过 Cookie 对象设置 HTTP Cookie
 * 
 * @param string $name cookie 名称
 * @param string $value cookie 值
 * @param mixed $option 可选参数，可包含过期时间、路径、域名等设置
 * @return self 返回当前响应对象，支持链式调用
 * @throws \Exception 当 Cookie 对象未初始化时可能抛出异常
 */
public function cookie(string $name, string $value, $option = null): self
```

### allowCache()

```php
/**
 * 设置是否允许请求缓存
 * 
 * 控制响应是否可以被浏览器或代理服务器缓存
 * 
 * @param bool $cache true 表示允许缓存，false 表示禁止缓存
 * @return self 返回当前响应对象，支持链式调用
 */
public function allowCache(bool $cache): self
```

### isAllowCache()

```php
/**
 * 获取是否允许请求缓存的状态
 * 
 * @return bool 返回当前缓存设置状态
 */
public function isAllowCache(): bool
```

### options()

```php
/**
 * 设置输出选项参数
 * 
 * 合并新的选项参数到已有的选项中
 * 
 * @param array $options 输出选项参数数组
 * @return self 返回当前响应对象，支持链式调用
 */
public function options(array $options = []): self
```

### lastModified()

```php
/**
 * 设置 Last-Modified 响应头
 * 
 * 用于标记资源的最后修改时间，帮助客户端缓存决策
 * 
 * @param string $time 最后修改时间，应符合 HTTP 日期格式
 * @return self 返回当前响应对象，支持链式调用
 */
public function lastModified(string $time): self
```

### expires()

```php
/**
 * 设置 Expires 响应头
 * 
 * 指定响应的过期时间，告知客户端何时需要重新请求资源
 * 
 * @param string $time 过期时间，应符合 HTTP 日期格式
 * @return self 返回当前响应对象，支持链式调用
 */
public function expires(string $time): self
```

### eTag()

```php
/**
 * 设置 ETag 响应头
 * 
 * 提供资源的实体标签，用于缓存验证和条件请求
 * 
 * @param string $eTag 实体标签值
 * @return self 返回当前响应对象，支持链式调用
 */
public function eTag(string $eTag): self
```

### cacheControl()

```php
/**
 * 设置 Cache-Control 响应头
 * 
 * 控制缓存行为，如 no-cache(不缓存)、max-age(缓存时长)等
 * 
 * @param string $cache 缓存控制指令
 * @return self 返回当前响应对象，支持链式调用
 */
public function cacheControl(string $cache): self
```

### getHeader()

```php
/**
 * 获取响应头信息
 * 
 * 获取指定的响应头值或所有响应头
 * 
 * @param string $name 头部名称，留空则返回所有头部
 * @return mixed 如果指定了名称则返回对应的值或 null(不存在时)，否则返回所有头部数组
 */
public function getHeader(string $name = ''): mixed
```

### getData()

```php
/**
 * 获取设置的原始响应数据
 * 
 * 返回未经处理的原始数据对象，不进行任何类型转换或格式化
 * 用于直接访问原始数据，可能需要后续处理
 * 
 * @return mixed 返回设置的原始数据，数据类型由调用方决定
 */
public function getData(): mixed
```

### getCode()

```php
/**
 * 获取 HTTP 状态码
 * 
 * @return int 返回当前设置的 HTTP 状态码
 */
public function getCode(): int
```

## 内部方法

以下是 Respond 类中的内部方法（受保护方法），仅供类内部或子类使用：

### init()

```php
/**
 * 初始化响应对象
 * 
 * 设置响应数据、状态码和内容类型
 * 
 * @param string $data 输出数据
 * @param int $code HTTP 状态码，默认为 200
 * @return void
 */
protected function init(string $data = '', int $code = 200): void
```

### output()

```php
/**
 * 处理响应数据的核心方法
 * 
 * 当数据为空时，尝试从当前控制器或 Action 对象获取内容
 * 支持自动关联容器中的控制器实例
 * 
 * @param string $data 要处理的数据
 * @return string 返回处理后的数据
 */
protected function output(string $data): string
```

### sendData()

```php
/**
 * 输出数据到客户端
 * 
 * 将处理好的响应数据输出到客户端，并在调试模式下显示调试信息
 * 
 * @param string $data 要输出的数据内容
 * @return void
 */
protected function sendData(string $data): void
```

### showDebugMsg()

```php
/**
 * 显示调试信息
 * 
 * 在调试模式下生成并返回调试信息 HTML 字符串
 * 包含框架运行时间、内存使用、SQL 查询、包含文件等详细信息
 * 
 * @return string 返回调试信息 HTML 字符串，非调试模式返回空字符串
 */
private function showDebugMsg(): string
```

## 使用说明

### 基本用法

创建响应对象并发送响应：

```php
// 创建默认 HTML 响应并发送
Respond::create('Hello World')->send();

// 创建 JSON 响应
Respond::create(['code' => 200, 'message' => 'success'], 'json')->send();

// 链式调用设置响应属性
Respond::create('Not Found')
    ->code(404)
    ->allowCache(false)
    ->send();
```

### 设置响应头

```php
// 设置自定义响应头
Respond::create('Custom Header')
    ->header(['X-Powered-By' => 'Candy Framework'])
    ->send();

// 设置内容类型
Respond::create($jsonData)
    ->contentType('application/json')
    ->send();
```

### 缓存控制

```php
// 设置缓存控制
Respond::create($content)
    ->cacheControl('max-age=3600')
    ->lastModified(gmdate('D, d M Y H:i:s', $lastModified) . ' GMT')
    ->expires(gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT')
    ->eTag('W/"abc123"')
    ->allowCache(true)
    ->send();

// 禁止缓存
Respond::create($dynamicContent)
    ->cacheControl('no-cache, no-store, must-revalidate')
    ->allowCache(false)
    ->send();
```

### 添加前置缓冲内容

```php
// 在主响应内容输出前添加额外内容
Respond::buff('<html><head><title>Prepend Title</title></head></html>');
Respond::create('Main Content')->send();
```

### Session 管理

```php
// 设置 Session 对象并操作
$respond = Respond::create($data)->setSession($session)->cookie('user_id', $userId);
```

### 获取响应信息

```php
// 获取当前响应数据
$data = $respond->getData();

// 获取当前状态码
$code = $respond->getCode();

// 获取响应头信息
$headers = $respond->getHeader();
$etag = $respond->getHeader('ETag');

// 检查是否允许缓存
$allowCache = $respond->isAllowCache();
```

## 注意事项

1. Respond 类是一个抽象类，不能直接实例化，必须通过静态方法 create() 创建具体响应类型实例
2. 响应内容必须是能够转换为字符串的数据类型，否则会抛出 InvalidArgumentException 异常
3. 当指定的响应类型不存在时，系统会默认使用 Api 响应类型
4. 在支持的环境中，响应发送后会自动调用 fastcgi_finish_request 以提高页面响应速度
5. 可以通过静态方法 buff() 在主响应内容输出前添加额外内容
6. 内容类型设置会自动包含字符集编码信息
7. 响应头信息会在内容输出前自动发送，但前提是 headers_sent() 返回 false
8. 在调试模式下，响应输出后会自动显示调试信息
9. ETag 响应头使用 MD5 哈希值的子串生成，格式为 W/"hash"
10. 缓存控制相关的响应头包括：Last-Modified、Expires、ETag、Cache-Control