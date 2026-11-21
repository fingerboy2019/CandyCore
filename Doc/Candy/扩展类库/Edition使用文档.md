# Edition 使用文档

## 文档概述

Edition类是Candy框架的版本控制与代码安全管理类，**属于核心类库的补充类库**。该类提供了框架版本包创建、文件压缩和代码加密等功能，主要用于框架内部的版本控制和代码安全管理。

## 快速入门

### 基本用法

```php
// 导入Edition类
use Extend\Edition;

// 1. 创建框架版本包
$result = Edition::build();
if ($result['status']) {
    echo '版本包创建成功，文件路径：' . $result['file'];
} else {
    echo '版本包创建失败：' . $result['message'];
}

// 2. 压缩文件
$success = Edition::zip('/path/to/source', '/path/to/output.zip');
if ($success) {
    echo '文件压缩成功';
} else {
    echo '文件压缩失败';
}

// 3. 加密文件
$success = Edition::encrypt('/path/to/source.php', '/path/to/encrypted.php');
if ($success) {
    echo '文件加密成功';
} else {
    echo '文件加密失败';
}
```

## 核心功能详解

### 创建框架版本包

使用`build()`方法可以创建完整的框架版本包。

#### `build()`方法

```php
/**
 * 创建框架版本包
 * 
 * 负责将框架文件打包，并可选择性地进行加密处理，生成完整的框架版本包。
 * 该方法是框架版本控制的核心，用于分发和部署框架。
 *
 * @return array [status, message, file] 返回包含状态、消息和文件路径的数组
 */
public static function build()
```

**功能特点**：
- 将框架文件打包生成完整的版本包
- 可选择性地对代码进行加密处理
- 自动处理需要保护的文件和目录
- 返回详细的执行结果信息

**返回格式**：

```php
[
    'status' => true, // 执行状态，true表示成功，false表示失败
    'message' => '版本包创建成功', // 执行结果消息
    'file' => '/path/to/framework.zip' // 生成的版本包文件路径
]
```

**使用示例**：

```php
$result = Edition::build();

if ($result['status']) {
    echo "✅ 版本包创建成功！\n";
    echo "📦 文件路径：" . $result['file'] . "\n";
    echo "📁 文件大小：" . round(filesize($result['file']) / 1024 / 1024, 2) . " MB\n";
} else {
    echo "❌ 版本包创建失败！\n";
    echo "💬 错误信息：" . $result['message'] . "\n";
}
```

### 文件压缩

使用`zip()`方法可以将指定目录下的所有文件和子目录压缩为ZIP格式文件。

#### `zip()`方法

```php
/**
 * 压缩文件
 * 
 * 将指定目录下的所有文件和子目录压缩为ZIP格式文件。
 * 用于框架打包和资源压缩等场景。
 *
 * @param string $srcDir 源目录路径
 * @param string $zipFile 压缩文件路径
 * @return bool 是否压缩成功
 */
public static function zip($srcDir, $zipFile)
```

**功能特点**：
- 递归压缩指定目录下的所有文件和子目录
- 保持原始目录结构
- 支持大文件压缩
- 自动处理文件权限

**使用示例**：

```php
// 压缩整个应用目录
$sourceDir = '/path/to/your/application';
$zipFilePath = '/path/to/output/application.zip';

if (Edition::zip($sourceDir, $zipFilePath)) {
    echo "✅ 目录压缩成功！\n";
    echo "📦 压缩文件：" . $zipFilePath . "\n";
} else {
    echo "❌ 目录压缩失败！\n";
}

// 压缩特定文件类型
// 注意：Edition::zip方法会压缩目录下所有文件，如果需要过滤文件类型
// 可以先创建临时目录，复制需要的文件，再进行压缩
$tempDir = '/path/to/temp';
// 复制.php文件到临时目录
// ...
$zipFilePath = '/path/to/output/php_files.zip';
if (Edition::zip($tempDir, $zipFilePath)) {
    echo "✅ PHP文件压缩成功！\n";
    // 清理临时目录
    // ...
}
```

### 代码加密

使用`encrypt()`方法可以对指定的PHP文件进行加密处理，增强代码安全性。

#### `encrypt()`方法

```php
/**
 * 加密文件
 * 
 * 对指定的PHP文件进行加密处理，增强代码安全性。
 * 加密后的文件需要在支持的环境中运行。
 *
 * @param string $file 文件路径
 * @param string $outputFile 输出文件路径
 * @return bool 是否加密成功
 */
public static function encrypt($file, $outputFile)
```

**功能特点**：
- 对PHP文件进行加密处理，保护源代码
- 保持加密后文件的可执行性
- 支持单个文件加密
- 自动处理加密过程中的错误

**使用示例**：

```php
// 加密单个PHP文件
$sourceFile = '/path/to/source.php';
$encryptedFile = '/path/to/encrypted.php';

if (Edition::encrypt($sourceFile, $encryptedFile)) {
    echo "✅ 文件加密成功！\n";
    echo "🔒 加密前大小：" . filesize($sourceFile) . " bytes\n";
    echo "🔒 加密后大小：" . filesize($encryptedFile) . " bytes\n";
} else {
    echo "❌ 文件加密失败！\n";
}

// 批量加密PHP文件
$files = [
    '/path/to/file1.php',
    '/path/to/file2.php',
    '/path/to/file3.php'
];

$successCount = 0;
$failedCount = 0;

foreach ($files as $file) {
    $encryptedFile = dirname($file) . '/encrypted_' . basename($file);
    if (Edition::encrypt($file, $encryptedFile)) {
        $successCount++;
    } else {
        $failedCount++;
    }
}

echo "✅ 批量加密完成！\n";
echo "成功：" . $successCount . " 个文件\n";
echo "失败：" . $failedCount . " 个文件\n";
```

## 高级用法

### 自定义版本包构建

```php
use Extend\Edition;

// 创建自定义版本包
// 注意：Edition类内部会处理保护的文件和目录
$result = Edition::build();

if ($result['status']) {
    // 版本包创建成功，可以进行后续处理
    $zipFile = $result['file'];
    
    // 计算文件哈希值
    $hash = md5_file($zipFile);
    echo "版本包MD5: " . $hash . "\n";
    
    // 移动到发布目录
    $releaseDir = '/path/to/releases';
    if (!is_dir($releaseDir)) {
        mkdir($releaseDir, 0755, true);
    }
    
    $releaseFile = $releaseDir . '/framework_' . date('Ymd_His') . '.zip';
    if (rename($zipFile, $releaseFile)) {
        echo "版本包已移动到发布目录：" . $releaseFile . "\n";
    }
} else {
    echo "版本包创建失败：" . $result['message'] . "\n";
    // 记录错误日志
    error_log('Edition build failed: ' . $result['message']);
}
```

### 安全的文件分发流程

```php
use Extend\Edition;

// 1. 加密核心文件
$coreFiles = [
    '/path/to/framework/Core/Controller.php',
    '/path/to/framework/Core/Model.php',
    '/path/to/framework/Core/View.php'
];

foreach ($coreFiles as $file) {
    $encryptedFile = $file . '.encrypted';
    if (Edition::encrypt($file, $encryptedFile)) {
        // 替换原文件
        unlink($file);
        rename($encryptedFile, $file);
        echo "已加密：" . basename($file) . "\n";
    }
}

// 2. 创建版本包
$result = Edition::build();

if ($result['status']) {
    echo "\n✅ 安全版本包创建成功！\n";
    echo "📦 文件：" . $result['file'] . "\n";
    echo "🔒 包含加密的核心文件\n";
}
```

## 注意事项

1. **主要用途**：Edition类主要用于框架内部的版本控制和代码安全管理，**一般情况下不建议直接调用**。

2. **环境要求**：
   - 加密后的文件需要在支持的环境中运行，否则可能无法正常执行
   - 需要PHP的zip扩展支持文件压缩功能
   - 需要足够的磁盘空间和文件系统权限

3. **权限问题**：
   - 创建版本包时请确保有足够的磁盘空间和写入权限
   - 加密文件时需要源文件的读取权限和目标位置的写入权限

4. **文件保护**：
   - Edition类内部维护了需要保护的文件和目录列表
   - 这些保护列表可以根据需要进行调整

5. **性能考量**：
   - 加密大量文件可能会消耗较多系统资源
   - 压缩大目录时建议在后台执行

## 常见问题

### Q: 为什么加密后的文件无法运行？

A: 加密后的文件需要在支持的环境中运行。请确保：
- 服务器环境支持Candy框架的加密文件执行
- 加密文件的路径和权限设置正确
- 没有修改加密后的文件内容

### Q: 如何自定义需要保护的文件和目录？

A: Edition类内部使用了`$protectFiles`和`$protectDirs`私有静态属性来存储需要保护的文件和目录。由于这些是私有属性，不建议直接修改。如果需要自定义保护列表，建议通过扩展Edition类或联系框架开发者。

### Q: 版本包创建失败怎么办？

A: 版本包创建失败可能有多种原因：
- 磁盘空间不足
- 文件系统权限不足
- 源文件损坏
- 系统资源限制

请检查`build()`方法返回的错误消息，根据具体情况进行排查。

### Q: 可以加密非PHP文件吗？

A: Edition::encrypt()方法主要设计用于加密PHP文件，对其他类型文件的支持可能有限。如果需要加密非PHP文件，建议使用专门的加密工具。

### Q: 如何验证加密文件的完整性？

A: 可以通过以下方式验证加密文件的完整性：
- 检查加密后的文件大小是否合理
- 尝试在支持的环境中运行加密文件
- 比较加密前后文件的功能是否一致

## 最佳实践

1. **仅用于框架管理**：Edition类主要用于框架内部的版本控制和安全管理，避免在应用程序中直接使用。

2. **定期备份**：在使用加密功能前，务必备份原始文件，以防加密失败导致文件丢失。

3. **测试环境验证**：在生产环境中使用加密功能前，务必在测试环境中充分验证加密文件的可执行性。

4. **合理使用加密**：仅对核心代码进行加密，避免过度加密影响性能和可维护性。

5. **版本控制**：对加密前的源代码进行版本控制，便于后续维护和更新。

通过遵循这些最佳实践，您可以安全有效地使用Edition类进行框架版本控制和代码安全管理。