# Webdav 类

## 类简介

Webdav类是Candy框架中用于WebDAV协议客户端操作的工具类，提供对WebDAV服务器的完整操作支持，包括文件/目录属性获取、创建目录、文件移动/重命名、复制、锁定和解锁等功能。
继承自Http类，支持各种HTTP请求选项，自动解析XML响应内容。

## 命名空间

```php
namespace Candy\Extend\Network;
```

## 类定义

```php
/**
 * WebDAV协议客户端操作类
 * 
 * 提供对WebDAV服务器的完整操作支持，包括文件/目录属性获取、创建目录、
 * 文件移动/重命名、复制、锁定和解锁等功能。
 * 
 * @package Candy\Extend\Network
 * @final 此类不可被继承
 */
final class Webdav {
    // 类成员
}
```

## 主要方法

### propfind()

```php
/**
 * 获取WebDAV资源的属性信息
 * 
 * 通过PROPFIND方法检索WebDAV服务器上资源的属性信息，如资源名称、大小、类型、最后修改时间等。
 * 支持递归获取多个层级的资源信息。
 * 
 * @param string $path 要查询的资源路径，必须是完整的URL
 * @param array $options HTTP请求选项数组，支持以下参数：
 *                      - headers: 自定义HTTP头信息数组
 *                      - timeout: 请求超时时间（秒）
 *                      - user_agent: 自定义User-Agent
 *                      - auth: 认证信息数组，格式为['username', 'password']
 *                      - webdav: WebDAV特定选项数组
 *                      - depth: 递归深度，0表示仅当前资源，1表示当前资源及其直接子资源
 * @return array|false 成功时返回包含资源属性的关联数组，失败时返回false
 */
public static function propfind(string $path, array $options = [])
```

### mkcol()

```php
/**
 * 在WebDAV服务器上创建新目录
 * 
 * 通过MKCOL方法在指定路径创建新的目录。路径必须指向新目录的完整URL。
 * 目录创建成功返回true，失败返回false。
 * 
 * @param string $path 要创建的目录路径，必须是完整的URL，通常以斜杠结尾
 * @param array $options HTTP请求选项数组，支持以下参数：
 *                      - headers: 自定义HTTP头信息数组
 *                      - timeout: 请求超时时间（秒）
 *                      - user_agent: 自定义User-Agent
 *                      - auth: 认证信息数组，格式为['username', 'password']
 * @return bool 目录创建成功返回true，失败返回false
 */
public static function mkcol(string $path, array $options = [])
```

### move()

```php
/**
 * 移动或重命名WebDAV服务器上的资源
 * 
 * 通过MOVE方法将源路径的资源移动到目标路径，可用于文件或目录的移动和重命名操作。
 * 操作成功返回true，失败返回false。
 * 
 * @param string $source 源资源路径，必须是完整的URL
 * @param string $destination 目标路径，必须是完整的URL
 * @param array $options HTTP请求选项数组，支持以下参数：
 *                      - headers: 自定义HTTP头信息数组
 *                      - timeout: 请求超时时间（秒）
 *                      - user_agent: 自定义User-Agent
 *                      - auth: 认证信息数组，格式为['username', 'password']
 * @return bool 移动/重命名成功返回true，失败返回false
 */
public static function move(string $source, string $destination, array $options = [])
```

### copy()

```php
/**
 * 复制WebDAV服务器上的资源
 * 
 * 通过COPY方法将资源从源路径复制到目标路径，可用于复制文件或整个目录结构。
 * 支持设置是否覆盖已存在的目标资源。
 * 
 * @param string $source 源资源路径，必须是完整的URL
 * @param string $destination 目标资源路径，必须是完整的URL
 * @param array $options HTTP请求选项数组，支持以下参数：
 *                      - headers: 自定义HTTP头信息数组
 *                      - timeout: 请求超时时间（秒）
 *                      - user_agent: 自定义User-Agent
 *                      - auth: 认证信息数组，格式为['username', 'password']
 *                      - overwrite: 是否覆盖已存在的目标资源，默认false
 * @return bool 复制成功返回true，失败返回false
 */
public static function copy(string $source, string $destination, array $options = [])
```

### lock()

```php
/**
 * 锁定WebDAV服务器上的资源
 * 
 * 通过LOCK方法创建资源的独占锁，防止其他客户端修改该资源。锁定成功后返回锁令牌，
 * 该令牌用于后续的解锁操作或更新操作。
 * 
 * @param string $path 要锁定的资源路径，必须是完整的URL
 * @param array $options HTTP请求选项数组，支持以下参数：
 *                      - headers: 自定义HTTP头信息数组
 *                      - timeout: 请求超时时间（秒）
 *                      - user_agent: 自定义User-Agent
 *                      - auth: 认证信息数组，格式为['username', 'password']
 *                      - owner: 锁所有者信息，默认为'Candy Framework'
 * @return string|false 锁定成功返回锁令牌字符串，失败返回false
 */
public static function lock(string $path, array $options = [])
```

### unlock()

```php
/**
 * 解锁WebDAV服务器上的资源
 * 
 * 通过UNLOCK方法解除先前锁定的资源，允许其他客户端访问和修改该资源。
 * 解锁操作需要提供有效的锁令牌。
 * 
 * @param string $path 要解锁的资源路径，必须是完整的URL
 * @param string $token 锁令牌，由lock方法返回
 * @param array $options HTTP请求选项数组，支持以下参数：
 *                      - headers: 自定义HTTP头信息数组
 *                      - timeout: 请求超时时间（秒）
 *                      - user_agent: 自定义User-Agent
 *                      - auth: 认证信息数组，格式为['username', 'password']
 * @return bool 解锁成功返回true，失败返回false
 */
public static function unlock(string $path, string $token, array $options = [])
```

## 内部方法

以下是Webdav类中的内部方法（私有方法），仅供类内部使用：

### parsePropfindResponse()

```php
/**
 * 解析WebDAV PROPFIND方法返回的XML响应内容
 * 
 * 该私有方法负责将WebDAV服务器返回的XML格式响应解析为结构化的PHP数组，
 * 提取出资源的各种属性信息，如文件路径、显示名称、内容长度、创建日期等。
 * 
 * @param string $xml WebDAV服务器返回的XML响应内容
 * @return array 返回解析后的资源属性数组，每个元素包含一个资源的完整属性
 */
private static function parsePropfindResponse(string $xml): array
```

### parseLockToken()

```php
/**
 * 从WebDAV LOCK方法返回的XML响应中提取锁令牌
 * 
 * 该私有方法负责解析WebDAV服务器返回的锁响应XML，提取出资源的锁令牌信息，
 * 该令牌用于后续的UNLOCK操作或带锁的资源更新操作。
 * 
 * @param string $xml WebDAV服务器返回的XML响应内容
 * @return string|false 成功时返回锁令牌字符串，失败时返回false
 */
private static function parseLockToken(string $xml)
```

## 使用说明

### 获取文件/目录属性

```php
// 获取目录内容列表
$files = Webdav::propfind('https://example.com/webdav/', ['depth' => 1]);
foreach ($files as $file) {
    echo $file['displayname'] . '<br>';
}
```

### 创建目录

```php
// 创建新目录
$success = Webdav::mkcol('https://example.com/webdav/new_folder/');
if ($success) {
    echo '目录创建成功';
}
```

### 移动/重命名文件

```php
// 移动文件
$success = Webdav::move(
    'https://example.com/webdav/file.txt',
    'https://example.com/webdav/folder/file.txt'
);

// 重命名文件
$success = Webdav::move(
    'https://example.com/webdav/old_name.txt',
    'https://example.com/webdav/new_name.txt'
);
```

### 复制文件

```php
// 复制文件
$success = Webdav::copy(
    'https://example.com/webdav/original.txt',
    'https://example.com/webdav/copy.txt',
    ['overwrite' => true] // 允许覆盖
);
```

### 资源锁定与解锁

```php
// 锁定资源
$token = Webdav::lock(
    'https://example.com/webdav/important.txt',
    ['owner' => 'admin@example.com']
);

// 使用锁定的资源...

// 解锁资源
if ($token) {
    $success = Webdav::unlock(
        'https://example.com/webdav/important.txt',
        $token
    );
    if ($success) {
        echo '解锁成功';
    }
}
```

## 注意事项

1. 所有WebDAV操作的路径必须是完整的URL，包括协议、域名和路径
2. 对于需要认证的WebDAV服务器，请在options数组中提供auth参数
3. 锁定资源后，必须保存返回的锁令牌以便后续解锁
4. 复制操作默认不覆盖已存在的目标资源，如需覆盖请设置overwrite=true
5. 该类继承自Http类，但代码中直接调用了Http::request()而不是parent::request()，可能导致继承关系问题

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-01-23 14:00:00