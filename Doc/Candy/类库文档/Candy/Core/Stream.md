# Stream 类

## 类简介

Stream 类是 Candy 框架的数据流处理核心类，实现了 PHP 流包装器接口（Stream Wrapper），
提供了强大的数据流处理功能。主要用于处理文件内容的读取、写入和加密解密操作，
支持通过 candy://协议进行内容处理，以及对加密内容的自动解密和缓存管理。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Stream 类 - 数据流处理核心类
 * 
 * 实现了 PHP 流包装器接口（Stream Wrapper），提供强大的数据流处理能力。
 * 主要用于处理文件内容的读取、写入和加密解密操作，
 * 支持通过 candy://协议进行内容处理，以及对加密内容的自动解密和缓存管理。
 * 
 * 主要功能：
 * - 自定义流协议处理（candy://）
 * - 文件内容的缓存管理
 * - 支持加密内容的解密处理
 * - 文件内容的读写和压缩（gzip 压缩）
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-26 11:31:47
 */
class Stream {
    /**
     * 流上下文资源
     * @var resource
     */
    public $context;
    
    /**
     * 单例实例
     * @var Stream|null
     */
    private static $instance;
    
    /**
     * 当前处理的字符串内容
     * @var string
     */
    private $string;
    
    /**
     * 当前读取位置
     * @var int
     */
    private $position;
    
    // ... 其他方法
}
```

## 主要方法

### getInstance()

```php
/**
 * 获取 Stream 类的单例实例
 * 
 * 实现了单例模式，确保在应用中只存在一个 Stream 实例，避免重复创建对象
 * 
 * 参数：无
 * 
 * 返回值：Stream 返回 Stream 类的单例实例
 * 
 * @access public
 * @static
 */
public static function getInstance(): Stream
```

### stream_open()

```php
/**
 * 打开自定义流，实现 PHP 流包装器接口
 * 
 * 处理 candy://协议的流打开操作，支持文件内容读取、缓存和加密内容处理。
 * 
 * 处理流程：
 * 1. 读取文件内容
 * 2. 检查是否以'gzcp'结尾，如果是则进行 gzip 解压
 * 3. 检查是否包含加密标记，如果是则进行解密
 * 4. 保存字符串内容并重置读取位置
 * 
 * 参数：
 * - $path: 要打开的流路径，支持 candy://协议
 * - $mode: 打开模式（与 fopen 兼容）
 * - $options: 流选项
 * - &$opened_path: 引用参数，返回实际打开的路径
 * 
 * 返回值：操作是否成功
 *   - true: 打开成功
 *   - false: 打开失败
 * 
 * @param string $path 要打开的流路径
 * @param string $mode 打开模式
 * @param int $options 流选项
 * @param string &$opened_path 引用参数，返回实际打开的路径
 * @return bool 操作是否成功
 * @access public
 */
public function stream_open(string $path, $mode, $options, &$opened_path): bool
```

### stream_read()

```php
/**
 * 从当前位置读取指定字节数的内容
 * 
 * 实现 PHP 流包装器的读取接口，从内部字符串缓冲区读取数据。
 * 
 * 参数：
 * - $count: 要读取的字节数
 * 
 * 返回值：读取的内容
 * 
 * @param int $count 要读取的字节数
 * @return string 读取的内容
 * @access public
 */
public function stream_read(int $count): string
```

### fileGetContents()

```php
/**
 * 获取文件内容并自动解密加密内容
 * 
 * 读取指定文件的内容，并自动检测和处理加密内容（以 -----BEGIN CIPHERTEXT----- 标记）。
 * 
 * 处理流程：
 * 1. 读取文件内容
 * 2. 检查是否包含加密标记
 * 3. 如果包含加密标记，调用 transCode() 方法解密
 * 4. 返回解密后的内容
 * 
 * 参数：
 * - $file: 文件路径
 * 
 * 返回值：文件内容，加密内容会被自动解密
 * 
 * @param string $file 文件路径
 * @return string 文件内容，加密内容会被自动解密
 * @access public
 */
public function fileGetContents(string $file): string
```

### filePutContents()

```php
/**
 * 将内容写入文件并进行压缩加密
 * 
 * 将指定内容写入文件，并自动对内容进行 gzip 压缩加密处理。
 * 
 * 处理流程：
 * 1. 对内容进行 gzip 压缩
 * 2. 进行 base64 编码
 * 3. 添加'gzcp'标记
 * 4. 写入文件
 * 
 * 参数：
 * - $file: 文件路径
 * - $contents: 要写入的文件内容
 * 
 * 返回值：无（void）
 * 
 * @param string $file 文件路径
 * @param string $contents 要写入的文件内容
 * @return void
 * @access public
 */
public function filePutContents(string $file, string $contents): void
```

### stream_set_option()

```php
/**
 * 实现流包装器的 stream_set_option 接口
 * 
 * 该方法目前返回 false，未实现具体功能
 * 
 * 参数：
 * - $option: 选项类型
 * - $arg1: 第一个参数
 * - $arg2: 第二个参数
 * 
 * 返回值：false
 * 
 * @param int $option 选项类型
 * @param int $arg1 第一个参数
 * @param int $arg2 第二个参数
 * @return bool 返回 false
 * @access public
 */
public function stream_set_option(int $option, int $arg1, int $arg2): bool
```

### stream_eof()

```php
/**
 * 检测流是否到达文件末尾
 * 
 * 检查当前读取位置是否已到达字符串末尾
 * 
 * 参数：无
 * 
 * 返回值：是否到达文件末尾
 *   - true: 已到达文件末尾
 *   - false: 未到达文件末尾
 * 
 * @return bool 是否到达文件末尾
 * @access public
 */
public function stream_eof(): bool
```

### stream_stat()

```php
/**
 * 获取流的统计信息
 * 
 * 该方法目前返回 false，未实现具体功能
 * 
 * 参数：无
 * 
 * 返回值：流的统计信息数组或 false
 * 
 * @return array|false 流的统计信息数组
 * @access public
 */
public function stream_stat(): array|false
```

### stream_cast()

```php
/**
 * 转换流为特定类型
 * 
 * 该方法目前返回 false，未实现具体功能
 * 
 * 参数：
 * - $cast_as: 转换目标类型
 * 
 * 返回值：资源或 false
 * 
 * @param int $cast_as 转换目标类型
 * @return resource|false 转换后的资源或 false
 * @access public
 */
public function stream_cast(int $cast_as): resource|false
```

### url_stat()

```php
/**
 * 获取 URL 的统计信息
 * 
 * 该方法目前返回 false，未实现具体功能
 * 
 * 参数：
 * - $path: URL 路径
 * - $flags: 标志位
 * 
 * 返回值：URL 的统计信息数组或 false
 * 
 * @param string $path URL 路径
 * @param int $flags 标志位
 * @return array|false URL 的统计信息数组
 * @access public
 */
public function url_stat(string $path, int $flags): array|false
```

## 内部方法

### transCode()

```php
/**
 * 解密加密的文件内容
 * 
 * 解析包含密文标记的内容，提取加密部分并进行解密处理，
 * 支持多层加密和动态密钥处理。
 * 
 * 处理流程：
 * 1. 延迟加载 Mcrypt 类库
 * 2. 解析内容，提取解释性注释和密文
 * 3. 解密外层密文
 * 4. 查找并解密内层密文（//CIPHER 标记）
 * 5. 返回解密后的内容
 * 
 * 参数：
 * - $content: 包含可能加密内容的字符串
 * 
 * 返回值：解密后的内容
 * 
 * @param string $content 包含可能加密内容的字符串
 * @return string 解密后的内容
 * @access private
 */
private function transCode(string $content): string
```

### getDecode()

```php
/**
 * 生成解密所需的密钥数组
 * 
 * 根据授权码、起始位置和数量生成用于解密的密钥数组，
 * 支持基于服务器信息和时间的动态密钥生成。
 * 
 * 处理流程：
 * 1. 解析授权码，获取动态密钥部分
 * 2. 基于服务器主机名和 IP 地址生成密钥
 * 3. 根据起始位置和数量生成密钥数组
 * 
 * 参数：
 * - $code: 授权码字符串
 * - $start: 起始位置偏移量
 * - $number: 需要生成的密钥数量
 * 
 * 返回值：生成的密钥数组
 * 
 * @param string $code 授权码字符串
 * @param int $start 起始位置偏移量
 * @param int $number 需要生成的密钥数量
 * @return array 生成的密钥数组
 * @access private
 */
private function getDecode(string $code, int $start, int $number): array
```

## 使用说明

### 基本用法

在框架中使用 Stream 类处理数据流：

```php
use Candy\Core\Stream;

// 获取 Stream 实例
$stream = Stream::getInstance();

// 读取文件内容（支持自动解密）
$content = $stream->fileGetContents('path/to/file.php');

// 写入文件内容（自动压缩加密）
$stream->filePutContents('path/to/encrypted_file.php', $phpCode);

// 使用自定义流协议
$handle = fopen('candy://path/to/file.php', 'r');
$content = stream_get_contents($handle);
fclose($handle);
```

### 自定义流协议使用

使用 candy://协议读取内容和处理加密文件：

```php
// 直接读取字符串内容
$handle = fopen('candy://echo "Hello World"', 'r');
$content = stream_get_contents($handle);

// 读取文件内容并处理
$handle = fopen('candy://#$#read#$#path/to/file.php', 'r');
$content = stream_get_contents($handle);
```

### 加密文件处理

创建和处理加密文件：

```php
// 写入加密内容
$stream = Stream::getInstance();
$stream->filePutContents('path/to/protected_file.php', '<?php echo "This is protected content"; ?>');

// 读取解密（自动处理）
$content = $stream->fileGetContents('path/to/protected_file.php');
```

### 流读写示例

```php
$stream = Stream::getInstance();

// 打开流
$stream->stream_open('path/to/file.php', 'r', 0, $opened_path);

// 读取内容
$chunk = $stream->stream_read(1024);
echo $chunk;

// 检查是否到达末尾
if ($stream->stream_eof()) {
    echo "已读取完整个文件";
}

// 关闭流（需要手动调用 fclose）
fclose($stream->context);
```

## 加密文件格式

加密文件采用以下格式：

```php
/*{3}
-----BEGIN CIPHERTEXT-----
加密后的密文
-----END CIPHERTEXT-----
*{/}
```

### 多层加密格式

```php
/*{3}
//CIPHER 外层密文
//CIPHER 内层密文
-----BEGIN CIPHERTEXT-----
外层加密的密文
-----END CIPHERTEXT-----
*{/}
```

## 注意事项

1. **流包装器注册**：使用 candy://协议前需要确保流包装器已正确注册
2. **缓存机制**：
   - 缓存仅在非开发模式下启用
   - 内存存储模式不支持文件缓存
3. **加密内容格式**：加密内容必须包含特定标记 `-----BEGIN CIPHERTEXT-----` 和 `-----END CIPHERTEXT-----`
4. **性能考虑**：大量文件处理时，建议在非开发环境中启用缓存以提高性能
5. **依赖关系**：加密解密功能依赖 Mcrypt 类库，会自动延迟加载
6. **授权码配置**：多层加密时需要正确配置 AUTHCODE 常量
7. **服务器信息依赖**：部分加密机制依赖服务器主机名和 IP 地址信息
8. **压缩标记**：使用 filePutContents 写入的文件末尾会添加'gzcp'标记，用于自动解压
9. **gzip 解压**：读取文件时，如果以'gzcp'结尾，会自动进行 gzip 解压
10. **单例模式**：Stream 采用单例模式，确保全局只有一个实例

## 依赖类库

- **Mcrypt**: 用于加密解密操作，位于 `Candy/Class/Str/Mcrypt.class.php`
- **Cache**: 用于缓存管理（可选）

## 性能优化建议

1. **启用缓存**：生产环境建议启用缓存，避免重复读取和解密
2. **批量操作**：对于大量文件处理，考虑使用流式读取而非一次性加载
3. **压缩加密**：对于敏感文件，使用 filePutContents 自动压缩加密
4. **授权码配置**：合理配置授权码，提高安全性

## 安全注意事项

1. **授权码保护**：AUTHCODE 常量应妥善保管，避免泄露
2. **动态密钥**：支持基于服务器信息的动态密钥生成，增强安全性
3. **多层加密**：支持多层加密，提高敏感数据的保护级别
4. **服务器信息**：加密密钥会用到服务器主机名和 IP，生产环境需注意

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-07-26 11:31:47
- **最后更新**: 2024 年