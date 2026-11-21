# Workerman\Http\Http 使用文档

## 概述

`Workerman\Http\Http` 是 Candy 在 Workerman 环境下的 **入站 HTTP 适配层**（由原 `Extend\Http` 迁入），提供入站 HTTP 请求解析、响应编码、Cookie 与 Session 管理。供 `Adapter` + `reload.func` 重写 `header` / `session_*` 等系统函数。

**使用范围：** 仅 CLI / Workerman 入站适配。不要与下列类混用：

| 类 | 用途 |
|---|---|
| `Candy\Extend\Network\Http` | 出站同步 HTTP 客户端（cURL） |
| `Workerman\Protocols\Http` | 上游协议 input/decode/encode |
| `Workerman\Http\Client` | 出站异步 HTTP 客户端 |
| `Workerman\Http\Http` | 本类：入站 header/session + 协议辅助 |

路径：`Candy/Vendor/Workerman/Http/Http.php`。

## 快速入门

### 基本使用流程

```php
use Workerman\Http\Http;

// 解析HTTP请求
$requestData = Http::decode($rawHttpRequest, $connection);

// 设置响应头和状态码
Http::header('Content-Type: application/json');
Http::responseCode(200);

// 设置Cookie
Http::setCookie('user_id', '12345', time() + 3600);

// 生成响应
$response = Http::encode(json_encode(['success' => true]));

// 发送响应
$connection->send($response);

// 重置环境
Http::reset();
```

## 核心功能详解

### 1. HTTP请求解析

```php
/**
 * 解析原始HTTP请求数据
 *
 * @param string $recv_buffer 原始HTTP请求数据
 * @param TcpConnection $connection TCP连接对象
 * @return array 解析后的请求信息数组
 */
$request = Http::decode($rawHttpRequest, $connection);

// 解析后的请求数据结构示例
/*
array(
    'method' => 'POST',
    'uri' => '/api/user',
    'version' => 'HTTP/1.1',
    'get' => array(...),  // GET参数
    'post' => array(...), // POST参数
    'files' => array(...), // 文件上传
    'cookie' => array(...), // Cookie信息
    'session' => array(...), // 会话数据
    'server' => array(...), // 服务器环境变量
    'header' => array(...), // 请求头信息
    'body' => '...' // 请求体内容
)
*/
```

### 2. HTTP响应生成

#### 设置响应状态码
```php
/**
 * 设置HTTP响应状态码
 *
 * @param int $code HTTP状态码
 * @return void
 */
Http::responseCode(200); // OK
Http::responseCode(201); // Created
Http::responseCode(404); // Not Found
Http::responseCode(500); // Internal Server Error
```

#### 设置响应头
```php
/**
 * 设置HTTP响应头
 *
 * @param string $header 响应头字符串
 * @param bool $replace 是否替换同名响应头
 * @return void
 */
Http::header('Content-Type: application/json');
Http::header('Cache-Control: no-cache');
Http::header('Access-Control-Allow-Origin: *');
```

#### 生成响应数据
```php
/**
 * 生成完整的HTTP响应
 *
 * @param string $buffer 响应体内容
 * @return string 完整的HTTP响应数据
 */
$body = json_encode(['status' => 'success', 'data' => $data]);
$response = Http::encode($body);
```

### 3. Cookie管理

#### 设置Cookie
```php
/**
 * 设置HTTP Cookie
 *
 * @param string $name Cookie名称
 * @param string $value Cookie值
 * @param int $expire 过期时间戳
 * @param string $path Cookie路径
 * @param string $domain Cookie域名
 * @param bool $secure 是否仅通过HTTPS发送
 * @param bool $httponly 是否仅HTTP可用
 * @return void
 */

// 基本设置
Http::setCookie('user_id', '12345');

// 设置过期时间（1小时后）
Http::setCookie('user_id', '12345', time() + 3600);

// 完整设置
Http::setCookie(
    'user_id',               // 名称
    '12345',                 // 值
    time() + 86400,          // 过期时间（1天）
    '/',                     // 路径
    '.example.com',          // 域名
    true,                    // 仅HTTPS
    true                     // 仅HTTP可用
);
```

### 4. 会话管理

#### 启动会话
```php
/**
 * 启动会话
 *
 * @param array $options 会话配置选项
 * @return bool 是否成功启动
 */

// 基本启动
Http::startSession();

// 自定义配置
Http::startSession([
    'name' => 'MYSESSION',
    'lifetime' => 3600,
    'cookie_lifetime' => 3600,
    'gc_maxlifetime' => 3600
]);
```

#### 使用会话数据
```php
// 设置会话数据
$_SESSION['user'] = ['id' => 1, 'name' => '张三'];
$_SESSION['token'] = 'abc123xyz';

// 获取会话数据
$userId = $_SESSION['user']['id'];
$token = $_SESSION['token'];
```

#### 销毁会话
```php
/**
 * 销毁会话
 *
 * @return void
 */
Http::destroySession();
```

### 5. 环境重置

```php
/**
 * 重置HTTP环境
 *
 * 在处理完一个请求后调用，准备处理下一个请求
 * @return void
 */
Http::reset();
```

## 注意事项

1. **静态类特性**：该类是静态工具类，所有方法都使用静态方式调用，无需实例化
2. **环境重置**：在Workerman等环境中使用时，必须在每个请求结束后调用`reset()`方法重置环境
3. **会话管理**：
   - 需要确保有写入权限的临时目录存储会话文件
   - 在分布式环境中可能需要使用Redis等外部存储
4. **文件上传**：
   - 依赖于正确的请求体解析
   - 需要确保临时目录有足够的存储空间
5. **Cookie处理**：
   - 自动进行URL编码处理
   - 对于敏感数据，建议加密后存储
6. **响应头顺序**：响应头的添加顺序会影响最终的响应输出顺序

## 常见问题

1. **为什么响应没有正确发送？**
   - 检查是否调用了`encode()`方法生成完整响应
   - 确认在发送响应后调用了`reset()`方法
   - 检查TCP连接是否正常

2. **为什么Cookie没有设置成功？**
   - 检查是否在`encode()`方法调用之前设置了Cookie
   - 确认Cookie域名和路径设置正确
   - 检查浏览器是否禁用了Cookie

3. **会话数据为什么丢失？**
   - 检查是否在请求结束时没有调用`reset()`
   - 确认会话目录有写入权限
   - 检查会话ID是否正确传递

4. **如何处理文件上传？**
   ```php
   $request = Http::decode($rawHttpRequest, $connection);
   $files = $request['files'];
   
   foreach ($files as $file) {
       move_uploaded_file($file['tmp_name'], './uploads/' . $file['name']);
   }
   ```

## 代码示例

### 完整的HTTP请求处理示例

```php
use Workerman\Http\Http;
use Workerman\Connection\TcpConnection;

// 模拟HTTP请求处理函数
function handleHttpRequest($rawHttpRequest, TcpConnection $connection) {
    try {
        // 1. 解析请求
        $request = Http::decode($rawHttpRequest, $connection);
        $method = $request['method'];
        $uri = $request['uri'];
        $getParams = $request['get'];
        $postParams = $request['post'];
        
        // 2. 启动会话
        Http::startSession();
        
        // 3. 处理业务逻辑
        $responseData = ['success' => false];
        $statusCode = 200;
        
        if ($uri === '/api/user' && $method === 'GET') {
            // 获取用户信息
            $responseData = [
                'success' => true,
                'data' => [
                    'id' => 1,
                    'name' => '张三',
                    'email' => 'zhangsan@example.com'
                ]
            ];
        } elseif ($uri === '/api/login' && $method === 'POST') {
            // 登录逻辑
            if ($postParams['username'] === 'admin' && $postParams['password'] === '123456') {
                $_SESSION['user'] = ['id' => 1, 'username' => 'admin'];
                Http::setCookie('token', 'abc123xyz', time() + 86400);
                $responseData = ['success' => true, 'message' => '登录成功'];
            } else {
                $responseData = ['success' => false, 'message' => '用户名或密码错误'];
                $statusCode = 401;
            }
        } else {
            $responseData = ['success' => false, 'message' => '接口不存在'];
            $statusCode = 404;
        }
        
        // 4. 设置响应头和状态码
        Http::header('Content-Type: application/json');
        Http::header('X-Powered-By: CandyFramework');
        Http::responseCode($statusCode);
        
        // 5. 生成并发送响应
        $responseBody = json_encode($responseData, JSON_UNESCAPED_UNICODE);
        $response = Http::encode($responseBody);
        $connection->send($response);
        
    } catch (Exception $e) {
        // 错误处理
        Http::responseCode(500);
        Http::header('Content-Type: application/json');
        $errorResponse = Http::encode(json_encode([
            'success' => false,
            'message' => '服务器内部错误',
            'error' => $e->getMessage()
        ]));
        $connection->send($errorResponse);
    } finally {
        // 6. 重置环境
        Http::reset();
    }
}
```

### RESTful API响应示例

```php
use Workerman\Http\Http;

// 成功响应
function sendSuccessResponse($data, $message = '操作成功') {
    Http::header('Content-Type: application/json');
    Http::responseCode(200);
    
    $response = [
        'code' => 200,
        'message' => $message,
        'data' => $data
    ];
    
    return Http::encode(json_encode($response));
}

// 资源创建响应
function sendCreatedResponse($data, $message = '资源创建成功') {
    Http::header('Content-Type: application/json');
    Http::responseCode(201);
    
    $response = [
        'code' => 201,
        'message' => $message,
        'data' => $data
    ];
    
    return Http::encode(json_encode($response));
}

// 错误响应
function sendErrorResponse($code, $message, $details = []) {
    Http::header('Content-Type: application/json');
    Http::responseCode($code);
    
    $response = [
        'code' => $code,
        'message' => $message
    ];
    
    if (!empty($details)) {
        $response['details'] = $details;
    }
    
    return Http::encode(json_encode($response));
}
```
