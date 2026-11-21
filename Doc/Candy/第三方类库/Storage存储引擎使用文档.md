# Storage存储引擎使用文档

## 1. 组件概述

Storage是Candy框架提供的一个统一的文件存储引擎，支持多种存储方式，包括本地文件系统、阿里云OSS、七牛云存储、FTP服务器以及 **S3 兼容**（AWS S3 / Cloudflare R2 / MinIO）。该组件采用抽象基类设计，提供了一致的API接口，使开发者能够轻松切换不同的存储方式而无需修改业务代码。

### 1.1 核心特性

- **统一API接口**：所有存储驱动实现相同的接口方法，便于切换和扩展
- **多驱动支持**：本地存储、阿里云OSS、七牛云、FTP、S3 兼容五种驱动
- **文件上传管理**：支持单个/多个文件上传，自动验证文件类型和大小
- **灵活的文件命名**：支持自定义文件命名规则和路径
- **安全模式**：区分普通文件和安全文件存储路径
- **文件信息管理**：获取文件URL、路径、大小等信息
- **文件操作**：上传、下载、读取、删除等完整的文件操作功能

## 2. 配置说明

### 2.1 基本配置

在使用Storage组件之前，需要在配置文件中设置存储引擎的基本参数：

```php
// 存储引擎配置
'storage' => [
    // 默认存储驱动类型: local, alioss, qiniu, ftp, s3
    'type' => 'local',
    // 链接类型: full(完整链接), default(相对链接)
    'link_type' => 'full',
    // 允许上传的文件类型
    'allow_type' => ['jpg', 'gif', 'png', 'jpeg', 'bmp', 'txt', 'doc', 'docx', 'pdf', 'zip', 'rar'],
    // 允许上传的文件大小(字节)
    'max_size' => 1024 * 1024 * 2,
]
```

### 2.2 驱动特定配置

#### 2.2.1 本地存储配置

```php
'storage' => [
    // ... 基本配置 ...
    // 本地存储配置
    'local_http_protocol' => 'auto', // http协议: auto, http, https
]
```

#### 2.2.2 阿里云OSS配置

```php
'storage' => [
    // ... 基本配置 ...
    // 阿里云OSS配置
    'alioss_point' => 'oss-cn-hangzhou.aliyuncs.com', // OSS数据中心
    'alioss_bucket' => 'your-bucket-name', // 存储空间名称
    'alioss_http_domain' => 'your-domain.com', // 绑定的访问域名
    'alioss_access_key' => 'your-access-key', // AccessKeyId
    'alioss_secret_key' => 'your-secret-key', // AccessKeySecret
    'alioss_http_protocol' => 'auto', // http协议: auto, http, https
]
```

#### 2.2.3 七牛云配置

```php
'storage' => [
    // ... 基本配置 ...
    // 七牛云配置
    'qiniu_region' => '华东', // 空间区域: 华东, 华北, 华南, 北美, 东南亚
    'qiniu_bucket' => 'your-bucket-name', // 存储空间名称
    'qiniu_http_domain' => 'your-domain.com', // 绑定的访问域名
    'qiniu_access_key' => 'your-access-key', // AccessKey
    'qiniu_secret_key' => 'your-secret-key', // SecretKey
    'qiniu_http_protocol' => 'auto', // http协议: auto, http, https
]
```

#### 2.2.4 FTP配置

```php
'storage' => [
    // ... 基本配置 ...
    // FTP配置
    'server' => 'ftp.example.com', // FTP服务器地址
    'port' => 21, // FTP端口号
    'timeout' => 90, // 超时时间
    'user' => 'ftp-user', // FTP用户名
    'password' => 'ftp-password', // FTP密码
    'http_protocol' => 'http://ftp-domain.com', // FTP文件访问域名
]
```

#### 2.2.5 S3 兼容配置（AWS / R2 / MinIO）

轻量 SigV4 驱动，类名 `S3Storage`。Admin「系统设置 → 存储」中类型选 **S3 兼容存储**。

```php
'storage' => [
    'type' => 's3',
    's3_endpoint' => 's3.us-east-1.amazonaws.com', // 或不含协议的 R2/MinIO 主机
    's3_region' => 'us-east-1', // R2 常用 auto
    's3_bucket' => 'your-bucket',
    's3_access_key' => 'AKIA...',
    's3_secret_key' => '...',
    's3_http_domain' => '', // 可选 CDN/自定义域
    's3_http_protocol' => 'https', // http|https|auto
    's3_path_style' => '1', // 1=Path-Style（R2/MinIO 推荐），0=Virtual-Hosted
    's3_dir' => '', // 可选对象前缀
]
```

R2 示例：`s3_endpoint` = `<accountid>.r2.cloudflarestorage.com`，`s3_region` = `auto`，`s3_path_style` = `1`。

## 3. 驱动支持列表

| 驱动类型 | 类名 | 说明 |
|---------|------|------|
| local | LocalStorage | 本地文件系统存储 |
| alioss | AliossStorage | 阿里云OSS存储 |
| qiniu | QiniuStorage | 七牛云存储 |
| ftp | FtpStorage | FTP服务器存储 |
| s3 | S3Storage | S3 兼容（AWS / R2 / MinIO） |

## 4. 核心功能使用方法

### 4.1 初始化存储引擎

```php
// 使用默认驱动
$storage = Storage::instance();

// 指定驱动类型
$storage = Storage::instance('alioss');
$storage = Storage::instance('qiniu');
$storage = Storage::instance('ftp');
$storage = Storage::instance('s3');
```

### 4.2 文件上传

#### 4.2.1 单个文件上传

```php
// 基本上传
$fileField = 'file'; // 表单文件字段名
$result = Storage::instance()->uploadFile($fileField);

// 自定义上传配置
$config = [
    'allowType' => ['jpg', 'png', 'gif'], // 允许的文件类型
    'maxSize' => 5000000, // 最大文件大小(字节)
    'isRandName' => true, // 是否随机命名文件
    'filePath' => 'images/', // 文件存储路径
];
$result = Storage::instance()->uploadFile($fileField, $config);
```

#### 4.2.2 多个文件上传

```php
// 多个文件上传(表单字段名需为数组形式: file[])
$fileField = 'file';
$result = Storage::instance()->uploadFile($fileField);
```

### 4.3 文件操作

#### 4.3.1 保存文件

```php
// 保存文件内容
$content = 'Hello, World!';
$filePath = 'test.txt';
$result = Storage::instance()->set($filePath, $content);

// 安全模式保存
$result = Storage::instance()->set($filePath, $content, true);
```

#### 4.3.2 读取文件

```php
// 读取文件内容
$filePath = 'test.txt';
$content = Storage::instance()->get($filePath);

// 安全模式读取
$content = Storage::instance()->get($filePath, true);
```

#### 4.3.3 删除文件

```php
// 删除文件
$filePath = 'test.txt';
$result = Storage::instance()->del($filePath);

// 安全模式删除
$result = Storage::instance()->del($filePath, true);
```

#### 4.3.4 检查文件是否存在

```php
// 检查文件是否存在
$filePath = 'test.txt';
$exists = Storage::instance()->has($filePath);

// 安全模式检查
$exists = Storage::instance()->has($filePath, true);
```

#### 4.3.5 获取文件URL

```php
// 获取文件URL
$filePath = 'test.txt';
$url = Storage::instance()->url($filePath);

// 安全模式获取
$url = Storage::instance()->url($filePath, true);

// 获取带下载参数的URL
$url = Storage::instance()->url($filePath, false, 'download.txt');
```

#### 4.3.6 获取文件路径

```php
// 获取文件存储路径
$filePath = 'test.txt';
$path = Storage::instance()->path($filePath);

// 安全模式获取
$path = Storage::instance()->path($filePath, true);
```

#### 4.3.7 获取文件信息

```php
// 获取文件信息
$filePath = 'test.txt';
$info = Storage::instance()->info($filePath);

// 安全模式获取
$info = Storage::instance()->info($filePath, true);

// 返回示例
/*
[
    'url' => 'http://example.com/uploads/test.txt',
    'key' => 'test.txt',
    'file' => '/path/to/uploads/test.txt'
]
*/
```

### 4.4 文件命名与下载

#### 4.4.1 生成文件名称

```php
// 生成文件名称
$url = 'https://example.com/image.jpg';
$fileName = Storage::name($url);

// 自定义前缀和命名方法
$fileName = Storage::name($url, 'jpg', 'images/', 'md5');
```

#### 4.4.2 下载文件

```php
// 下载文件到本地
$url = 'https://example.com/image.jpg';
$result = Storage::down($url);

// 强制下载(不使用缓存)
$result = Storage::down($url, true);

// 设置缓存时间(秒)
$result = Storage::down($url, false, 3600);
```

### 4.5 静态方法调用

Storage组件支持静态方法调用，自动创建默认驱动实例：

```php
// 静态方法调用示例
Storage::uploadFile('file');
Storage::set('test.txt', 'content');
Storage::get('test.txt');
Storage::del('test.txt');
Storage::has('test.txt');
```

## 5. 各驱动详细使用指南

### 5.1 本地存储驱动 (LocalStorage)

#### 5.1.1 配置

```php
'storage' => [
    'type' => 'local',
    'link_type' => 'full',
    'local_http_protocol' => 'auto', // http, https, auto
]
```

#### 5.1.2 核心功能

本地存储驱动将文件存储在项目的`Public/uploads`目录下：

```php
// 基本使用
$storage = Storage::instance('local');
$storage->uploadFile('file');

// 安全文件存储在Public/uploads/safefile目录
$storage->set('secret.txt', 'content', true);
```

### 5.2 阿里云OSS驱动 (AliossStorage)

#### 5.2.1 配置

```php
'storage' => [
    'type' => 'alioss',
    'link_type' => 'full',
    'alioss_point' => 'oss-cn-hangzhou.aliyuncs.com',
    'alioss_bucket' => 'your-bucket',
    'alioss_http_domain' => 'your-domain.oss-cn-hangzhou.aliyuncs.com',
    'alioss_access_key' => 'your-access-key',
    'alioss_secret_key' => 'your-secret-key',
    'alioss_http_protocol' => 'auto', // http, https, auto
]
```

#### 5.2.2 核心功能

```php
// 基本使用
$storage = Storage::instance('alioss');
$storage->uploadFile('file');

// 获取上传令牌(用于前端直传)
$token = $storage->buildUploadToken('path/to/file.jpg', 3600);

// 获取文件上传地址
$uploadUrl = $storage->upload();
```

### 5.3 七牛云驱动 (QiniuStorage)

#### 5.3.1 配置

```php
'storage' => [
    'type' => 'qiniu',
    'link_type' => 'full',
    'qiniu_region' => '华东', // 华东, 华北, 华南, 北美, 东南亚
    'qiniu_bucket' => 'your-bucket',
    'qiniu_http_domain' => 'your-domain.bkt.clouddn.com',
    'qiniu_access_key' => 'your-access-key',
    'qiniu_secret_key' => 'your-secret-key',
    'qiniu_http_protocol' => 'auto', // http, https, auto
]
```

#### 5.3.2 核心功能

```php
// 基本使用
$storage = Storage::instance('qiniu');
$storage->uploadFile('file');

// 获取上传令牌(用于前端直传)
$token = $storage->buildUploadToken('path/to/file.jpg', 3600);

// 获取文件上传地址
$uploadUrl = $storage->upload();
```

### 5.4 FTP驱动 (FtpStorage)

#### 5.4.1 配置

```php
'storage' => [
    'type' => 'ftp',
    'link_type' => 'full',
    'server' => 'ftp.example.com',
    'port' => 21,
    'timeout' => 90,
    'user' => 'ftp-user',
    'password' => 'ftp-password',
    'http_protocol' => 'http://ftp-domain.com',
]
```

#### 5.4.2 核心功能

```php
// 基本使用
$storage = Storage::instance('ftp');
$storage->uploadFile('file');

// FTP驱动自动管理连接
$storage->set('test.txt', 'content');
$content = $storage->get('test.txt');
```

## 6. 高级功能

### 6.1 文件命名规则

Storage组件提供了灵活的文件命名方法，可以自定义文件名称和路径：

```php
// 生成文件名称
$url = 'https://example.com/image.jpg';

// 默认命名: 使用md5对URL进行加密，生成两级目录
$name = Storage::name($url); // 如: a9/4a8fe5ccb19ba61c4c0873d391e9879/test.jpg

// 自定义前缀
$name = Storage::name($url, '', 'images/'); // images/a9/4a8fe5ccb19ba61c4c0873d391e9879/test.jpg

// 自定义命名方法
$name = Storage::name($url, '', 'images/', 'sha1'); // 使用sha1加密
```

### 6.2 MIME类型管理

```php
// 获取文件MIME类型
$mime = Storage::mime('jpg'); // image/jpeg

// 获取多个文件MIME类型
$mime = Storage::mime(['jpg', 'png', 'txt']); // image/jpeg,image/png,text/plain

// 获取所有支持的MIME类型
$mimes = Storage::mimes();
```

### 6.3 自定义存储驱动

Storage组件支持扩展自定义存储驱动，只需继承`Storage`抽象类并实现相应方法：

```php
namespace Storage;

class CustomStorage extends Storage
{
    /**
     * 初始化入口
     */
    protected function initialize(): Storage
    {
        // 初始化配置
        return parent::initialize();
    }

    /**
     * 保存文件
     */
    public function set(string $name, $file, bool $safe = false, string $attname = null): array
    {
        // 实现保存文件逻辑
        return [];
    }

    /**
     * 读取文件
     */
    public function get(string $name, bool $safe = false)
    {
        // 实现读取文件逻辑
        return '';
    }

    // 实现其他抽象方法...
}
```

## 7. 最佳实践与注意事项

### 7.1 性能优化

- **文件缓存**：对于频繁访问的文件，建议使用缓存机制减少I/O操作
- **合理设置文件大小限制**：根据实际业务需求设置文件大小限制，避免过大文件影响性能
- **使用CDN加速**：对于阿里云OSS和七牛云存储，可以开启CDN加速提高文件访问速度

### 7.2 安全建议

- **文件类型验证**：始终验证上传文件的类型和扩展名，避免恶意文件上传
- **安全模式**：敏感文件使用安全模式存储，限制直接访问
- **文件权限**：本地存储时设置合理的文件和目录权限，避免权限过高
- **定期备份**：重要文件定期备份到多个存储位置

### 7.3 常见问题

#### 7.3.1 文件上传失败

- 检查文件大小是否超过配置的最大限制
- 检查文件类型是否在允许列表中
- 检查目录权限是否正确
- 检查上传临时目录是否可写

#### 7.3.2 文件URL访问失败

- 检查存储驱动的域名配置是否正确
- 检查文件路径和命名是否符合规范
- 检查云存储服务的访问权限设置

#### 7.3.3 FTP连接失败

- 检查FTP服务器地址、端口、用户名、密码是否正确
- 检查FTP服务器是否允许被动模式连接
- 检查网络连接和防火墙设置

## 8. 代码示例

### 8.1 完整文件上传示例

```php
<?php

use Storage\Storage;

// 控制器中的文件上传方法
public function upload()
{
    try {
        // 配置上传参数
        $config = [
            'allowType' => ['jpg', 'png', 'gif', 'jpeg'],
            'maxSize' => 5 * 1024 * 1024, // 5MB
            'isRandName' => true,
            'filePath' => date('Ymd'),
        ];

        // 执行上传
        $result = Storage::instance()->uploadFile('file', $config);

        // 处理上传结果
        if (is_array($result) && !empty($result)) {
            $fileInfo = $result[0];
            return json_encode([
                'code' => 200,
                'message' => '上传成功',
                'data' => [
                    'url' => $fileInfo['url'],
                    'name' => $fileInfo['key'],
                    'path' => $fileInfo['file']
                ]
            ]);
        } else {
            return json_encode([
                'code' => 400,
                'message' => '上传失败: ' . Storage::instance()->getErrorMsg()
            ]);
        }
    } catch (Exception $e) {
        return json_encode([
            'code' => 500,
            'message' => '上传异常: ' . $e->getMessage()
        ]);
    }
}
```

### 8.2 多存储驱动切换示例

```php
<?php

use Storage\Storage;

// 根据配置切换不同存储驱动
public function getStorageInstance($type = null)
{
    // 优先使用传入的驱动类型
    if (is_null($type)) {
        // 根据用户角色或业务需求动态选择驱动
        $userRole = $this->getUserRole();
        $type = $userRole == 'admin' ? 'local' : 'alioss';
    }
    
    return Storage::instance($type);
}

// 使用不同驱动上传文件
public function dynamicUpload()
{
    // 获取适合的存储驱动实例
    $storage = $this->getStorageInstance();
    
    // 执行上传
    $result = $storage->uploadFile('file');
    
    // 处理上传结果...
}
```

## 9. API参考

### 9.1 Storage抽象类方法

| 方法名 | 描述 | 参数 | 返回值 |
|--------|------|------|--------|
| `instance()` | 获取存储实例 | `$name`(可选): 驱动名称 | Storage实例 |
| `uploadFile()` | 上传文件 | `$fileField`: 表单字段名<br>`$config`: 配置数组 | 上传结果数组 |
| `set()` | 保存文件内容 | `$name`: 文件路径<br>`$file`: 文件内容<br>`$safe`: 安全模式<br>`$attname`: 下载名称 | 结果数组 |
| `get()` | 读取文件内容 | `$name`: 文件路径<br>`$safe`: 安全模式 | 文件内容 |
| `del()` | 删除文件 | `$name`: 文件路径<br>`$safe`: 安全模式 | 布尔值 |
| `has()` | 检查文件是否存在 | `$name`: 文件路径<br>`$safe`: 安全模式 | 布尔值 |
| `url()` | 获取文件URL | `$name`: 文件路径<br>`$safe`: 安全模式<br>`$attname`: 下载名称 | 文件URL |
| `path()` | 获取文件存储路径 | `$name`: 文件路径<br>`$safe`: 安全模式 | 文件路径 |
| `info()` | 获取文件信息 | `$name`: 文件路径<br>`$safe`: 安全模式<br>`$attname`: 下载名称 | 文件信息数组 |
| `name()` | 生成文件名称 | `$url`: 原URL<br>`$ext`: 文件扩展名<br>`$pre`: 路径前缀<br>`$fun`: 命名方法 | 新文件名 |
| `down()` | 下载文件 | `$url`: 文件URL<br>`$force`: 强制下载<br>`$expire`: 缓存时间 | 下载结果数组 |
| `mime()` | 获取MIME类型 | `$exts`: 文件扩展名<br>`$mime`: 自定义MIME | MIME类型字符串 |
| `mimes()` | 获取所有MIME类型 | 无 | MIME类型数组 |

## 10. 更新日志

- **v1.0.0** (2020-02-17)
  - 初始版本发布
  - 支持本地存储、阿里云OSS、七牛云存储三种驱动
  - 实现基本的文件上传和管理功能

- **v1.1.0** (2020-05-22)
  - 新增FTP存储驱动
  - 优化文件命名和路径管理
  - 增强错误处理和日志记录

- **v1.2.0** (2020-08-15)
  - 新增安全模式功能
  - 优化文件上传性能
  - 修复已知bug