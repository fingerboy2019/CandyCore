# Http

## 类简介
Http请求处理类，提供全面的HTTP请求功能支持，包括GET、POST、PUT、DELETE等多种HTTP方法，文件上传下载，表单提交等功能。使用CURL实现底层请求，支持代理、Cookie管理、SSL验证等高级特性。

## 命名空间
Candy\Extend\Network

## 类定义
```php
/**
 * Http请求处理类
 * 
 * 提供全面的HTTP请求功能，支持多种HTTP方法、文件上传下载、表单提交等。
 * 使用CURL实现底层请求，支持代理、Cookie管理、SSL验证等高级特性。
 * 
 * @author 刘森
 */
final class Http
```

## 主要方法

### get()
```php
/**
 * 发送GET请求
 * 
 * 发送标准的HTTP GET请求，获取指定URL的内容。
 * 支持设置超时时间、请求头、Cookie等高级选项。
 * 
 * @param string $url 请求的URL地址
 * @param array $options 请求选项数组，包含以下可选参数：
 *        - timeout: 超时时间（秒）
 *        - headers: 请求头数组
 *        - cookie: Cookie信息
 *        - cookie_file: Cookie文件路径
 *        - referer: HTTP引用来源
 *        - proxy: 代理服务器设置
 * @param callable|null $callback 流式输出回调函数，为null时使用普通请求模式
 * @return array|bool 请求结果数组（普通模式）或布尔值（流式模式）
 */
public static function get(string $url, array $options = [], ?callable $callback = null)
```

### post()
```php
/**
 * 发送POST请求
 * 
 * 发送标准的HTTP POST请求，可用于表单提交、API调用等场景。
 * 支持设置超时时间、请求头、Cookie、请求数据等高级选项。
 * 
 * @param string $url 请求的URL地址
 * @param mixed $data 请求数据，可以是关联数组或已编码的查询字符串
 * @param array $header 请求头数组
 * @return array|bool 请求结果数组（普通模式）或布尔值（流式模式）
 */
public static function post(string $url, mixed $data = [], array $header = [])
```

### delete()
```php
/**
 * 发送DELETE请求
 * 
 * 发送标准的HTTP DELETE请求，常用于RESTful API中的资源删除操作。
 * 支持设置超时时间、请求头、Cookie等高级选项。
 * 
 * @param string $url 请求的URL地址
 * @param array $options 请求选项数组
 * @param callable|null $callback 流式输出回调函数，为null时使用普通请求模式
 * @return array|bool 请求结果数组（普通模式）或布尔值（流式模式）
 */
public static function delete(string $url, array $options = [], ?callable $callback = null)
```

### put()
```php
/**
 * 发送PUT请求
 * 
 * 发送标准的HTTP PUT请求，常用于RESTful API中的资源更新操作。
 * 支持设置超时时间、请求头、Cookie、请求数据等高级选项。
 * 
 * @param string $url 请求的URL地址
 * @param mixed $content 请求数据内容
 * @param array $header 请求头数组
 * @return array|bool 请求结果数组（普通模式）或布尔值（流式模式）
 */
public static function put(string $url, mixed $content = [], array $header = [])
```

### down()
```php
/**
 * 下载远程文件到本地
 * 
 * 通过HTTP请求下载远程文件并保存到本地指定路径。
 * 支持设置超时时间、请求头、Cookie等高级选项。
 * 
 * @param string $url 远程文件URL
 * @param string $save_path 本地保存路径
 * @param array $options 请求选项数组
 * @return bool 下载是否成功
 */
public static function down(string $url, string $save_path, array $options = []): bool
```

### upload()
```php
/**
 * 上传文件到远程服务器
 * 
 * 将本地文件通过HTTP请求上传到远程服务器。
 * 支持设置超时时间、请求头、Cookie等高级选项。
 * 
 * @param string $url 远程服务器上传接口URL
 * @param array $file 文件信息数组，格式为['field' => '字段名', 'name' => '文件名', 'path' => '本地文件路径']
 * @param array $data 其他表单字段数据
 * @param array $header 请求头数组
 * @return array|bool 请求结果数组（普通模式）或布尔值（流式模式）
 */
public static function upload(string $url, array $file, array $data = [], array $header = [])
```

### list()
```php
/**
 * 获取WebDAV文件列表
 * 
 * 通过WebDAV协议获取远程服务器上的文件列表。
 * 支持WebDAV认证、设置超时时间等高级选项。
 * 
 * @param string $url WebDAV目录URL
 * @param array $webdav WebDAV认证信息，格式为['user' => '用户名', 'password' => '密码']
 * @return array|bool WebDAV文件列表或布尔值表示操作是否成功
 */
public static function list(string $url, array $webdav = [])
```

### submit()
```php
/**
 * 提交表单数据
 * 
 * 模拟HTML表单提交，支持普通表单数据和文件上传。
 * 支持设置超时时间、请求头、Cookie等高级选项。
 * 
 * @param string $url 表单提交目标URL
 * @param array $data 表单字段数据
 * @param array|null $file 文件上传字段，格式为['field' => '字段名', 'name' => '文件名', 'content' => '文件内容']
 * @param array $header 请求头数组
 * @return array|bool 请求结果数组（普通模式）或布尔值（流式模式）
 */
public static function submit(string $url, array $data = [], array $file = null, array $header = [])
```

### request()
```php
/**
 * 底层HTTP请求实现方法
 * 
 * 所有HTTP请求的核心实现方法，处理各种HTTP方法（GET、POST、PUT、DELETE等）的底层逻辑。
 * 负责初始化curl会话、设置请求选项、执行请求并处理响应结果。支持各种高级功能如cookie管理、
 * 代理设置、SSL验证、超时控制等。
 * 
 * @param string $method HTTP请求方法，支持GET、POST、PUT、DELETE等标准方法
 * @param string $location 请求的URL地址
 * @param array $options 高级请求选项，支持以下键值：
 *        - timeout: 超时时间（秒）
 *        - headers: 请求头数组
 *        - cookie: Cookie信息
 *        - cookie_file: Cookie文件路径
 *        - query: GET查询参数
 *        - data: 请求数据
 *        - referer: HTTP引用来源
 *        - proxy: 代理服务器设置
 *        - proxypass: 代理认证信息
 *        - webdav: WebDAV认证信息
 *        - ssl: SSL验证选项
 * @param callable|null $callback 流式输出回调函数，为null时使用普通请求模式
 * @return array|bool 请求结果数组（普通模式）或布尔值（流式模式）。
 *         普通模式返回数组包含以下键值：
 *         - http_code: HTTP状态码
 *         - content: 响应内容
 *         - header: 响应头信息数组
 *         - header_size: 响应头大小
 *         - size_download: 下载数据大小
 *         - time_total: 请求总耗时
 *         流式模式下返回布尔值表示请求是否成功
 */
public static function request(string $method, string $location, array $options = [], ?callable $callback = null): array|bool
```

### async()
```php
/**
 * 批量异步请求
 * 
 * 同时发送多个HTTP请求，显著提高批量请求的执行效率。
 * 支持GET、POST等多种请求方法，并可处理文件上传。
 * 
 * @param array $requests 请求数据数组，每个元素包含url、method、options等信息
 * @param callable|null $callback 回调函数，接收请求结果数组
 * @return array 请求结果数组，包含每个请求的响应信息
 */
public static function async(array $requests, ?callable $callback = null): array
```

### requestAsync()
```php
/**
 * 单个异步请求
 * 
 * 发送单个异步HTTP请求，内部调用async()方法
 * 
 * @param string $method HTTP请求方法
 * @param string $url 请求URL
 * @param array $options 请求选项
 * @param callable|null $callback 回调函数
 * @return array 请求结果数组
 */
public static function requestAsync(string $method, string $url, array $options = [], ?callable $callback = null): array
```

### getAsync()
```php
/**
 * 异步GET请求
 * 
 * 发送异步GET请求，内部调用requestAsync()方法
 * 
 * @param string $url 请求URL
 * @param mixed $query GET参数
 * @param array $options 请求选项
 * @param callable|null $callback 回调函数
 * @return array 请求结果数组
 */
public static function getAsync(string $url, mixed $query = '', array $options = [], ?callable $callback = null): array
```

### postAsync()
```php
/**
 * 异步POST请求
 * 
 * 发送异步POST请求，内部调用requestAsync()方法
 * 
 * @param string $url 请求URL
 * @param mixed $data POST数据
 * @param array $options 请求选项
 * @param callable|null $callback 回调函数
 * @return array 请求结果数组
 */
public static function postAsync(string $url, mixed $data = '', array $options = [], ?callable $callback = null): array
```

### putAsync()
```php
/**
 * 异步PUT请求
 * 
 * 发送异步PUT请求，内部调用requestAsync()方法
 * 
 * @param string $url 请求URL
 * @param mixed $data PUT数据
 * @param array $options 请求选项
 * @param callable|null $callback 回调函数
 * @return array 请求结果数组
 */
public static function putAsync(string $url, mixed $data = '', array $options = [], ?callable $callback = null): array
```

### deleteAsync()
```php
/**
 * 异步DELETE请求
 * 
 * 发送异步DELETE请求，内部调用requestAsync()方法
 * 
 * @param string $url 请求URL
 * @param mixed $data DELETE数据
 * @param array $options 请求选项
 * @param callable|null $callback 回调函数
 * @return array 请求结果数组
 */
public static function deleteAsync(string $url, mixed $data = '', array $options = [], ?callable $callback = null): array
```

## 内部方法

### buildQueryData()
```php
/**
 * 将数组或字符串构建为查询字符串
 * 
 * 内部辅助方法，用于将关联数组或已有的查询字符串处理成标准的URL查询格式。
 * 处理POST数据时会检查并正确处理文件上传内容，支持CURLFile对象。
 * 如果设置了不编译数据($build=false)且包含文件，则返回数组形式的原始数据。
 * 
 * @param mixed $data 要处理的数据，可以是关联数组或已编码的查询字符串
 * @param bool $build 是否将数据编译为字符串，默认为true
 * @return array|string 如果build为true且数据为数组则返回字符串，否则返回原始数据
 */
private static function buildQueryData(mixed $data, bool $build = true): array|string
```

### buildFormData()
```php
/**
 * 构建multipart/form-data表单数据
 * 
 * 内部辅助方法，用于构建适合文件上传的multipart/form-data格式的请求体。
 * 支持添加常规表单字段和文件字段，自动生成正确的boundary分隔符。
 * 
 * @param array $data 常规表单字段数组
 * @param array $file 文件上传字段数组，格式为[field,name,content]
 * @return array 返回包含header和content的数组，用于设置请求头和请求体
 */
private static function buildFormData(array $data = [], $file = []): array
```

### getUserAgent()
```php
/**
 * 获取随机的User-Agent字符串
 * 
 * 内部辅助方法，用于随机返回一个常见的浏览器User-Agent字符串。
 * 这有助于模拟不同浏览器环境的请求，避免被服务器识别为机器人。
 * 
 * @param string $method HTTP请求方法
 * @param string $location 请求地址
 * @return string 随机生成的浏览器User-Agent字符串
 */
private static function getUserAgent($method, $location): string
```

### getRandip()
```php
/**
 * 生成随机的IP地址字符串
 * 
 * 内部辅助方法，用于生成一个随机的IPv4地址字符串。
 * 这在需要模拟不同IP来源请求的场景下很有用，可以帮助绕过一些基于IP的限制。
 * 
 * @return string 随机生成的IPv4地址字符串
 */
private static function getRandip()
```

## 使用说明

### 基本用法
```php
// 发送GET请求
$result = Http::get('https://api.example.com/data');

// 发送POST请求
$result = Http::post('https://api.example.com/submit', ['name' => 'test', 'value' => '123']);

// 下载文件
Http::down('https://example.com/file.zip', '/path/to/save/file.zip');

// 上传文件
Http::upload('https://example.com/upload', 
    ['field' => 'file', 'name' => 'test.txt', 'path' => '/path/to/file.txt'],
    ['description' => '测试文件']
);
```

### 高级用法
```php
// 设置请求头和超时
$result = Http::get('https://api.example.com/data', [
    'timeout' => 30,
    'headers' => ['Content-Type: application/json', 'Authorization: Bearer token123'],
    'cookie' => 'session=abc123; user=test',
    'referer' => 'https://example.com'
]);

// 使用代理
$result = Http::get('https://api.example.com/data', [
    'proxy' => '127.0.0.1:8080',
    'proxypass' => 'username:password'
]);
```

### 异步并发请求用法
```php
// 批量异步GET请求
$requests = [
    [
        'url' => 'http://api.example.com/users',
        'method' => 'GET',
        'options' => [
            'query' => ['page' => 1, 'limit' => 10]
        ]
    ],
    [
        'url' => 'http://api.example.com/products',
        'method' => 'GET',
        'options' => [
            'query' => ['category' => 'electronics']
        ]
    ],
    [
        'url' => 'http://api.example.com/orders',
        'method' => 'GET',
        'options' => [
            'query' => ['status' => 'pending']
        ]
    ]
];

// 执行批量异步请求
$results = Http::async($requests, function($results) {
    echo "共收到 " . count($results) . " 个响应\n";
    foreach ($results as $key => $response) {
        echo "请求 " . ($key + 1) . " 状态码：" . $response['response']['http_code'] . "\n";
    }
});

// 单个异步POST请求
$result = Http::postAsync('http://api.example.com/login', [
    'username' => 'admin',
    'password' => '123456'
]);

// 混合请求（GET和POST）
$mixedRequests = [
    'userinfo' => [
        'url' => 'http://api.example.com/user/123',
        'method' => 'GET'
    ],
    'update' => [
        'url' => 'http://api.example.com/user/123',
        'method' => 'POST',
        'options' => [
            'data' => [
                'name' => 'New Name',
                'email' => 'newemail@example.com'
            ]
        ]
    ]
];

$results = Http::async($mixedRequests);
```

## 注意事项
1. 使用前请确保服务器已安装并启用CURL扩展。
2. 文件上传功能需要服务器支持文件上传和足够的权限。
3. 设置代理时请确保代理服务器可用且配置正确。
4. WebDAV功能需要远程服务器支持WebDAV协议。
5. 流式请求模式下回调函数的实现会影响性能，请合理设计。
6. 部分高级功能可能受到服务器安全限制。
7. 异步并发请求注意事项：
   - 建议单次并发请求数量不超过50个，以免超出系统资源限制
   - 对于批量请求，建议合理设置超时时间，避免单个请求阻塞整体流程
   - 应检查每个请求的响应状态码和错误信息
   - 批量请求可能会占用较多内存，特别是当响应内容较大时
   - 具体性能提升取决于网络环境和服务器响应速度

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-01-23 14:00:00