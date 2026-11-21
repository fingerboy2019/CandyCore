# Dir 类使用文档

## 简介

Dir 类是一个功能全面的文件系统操作工具类，提供了目录创建、删除、复制、移动、文件列表获取等一系列操作。该类继承自 SplFileInfo，实现了 ArrayAccess 接口，支持数组方式访问目录内容，同时提供了丰富的静态方法，方便直接调用。

## 命名空间

```php
use Candy\Extend\Dir;
```

## 基本使用

### 创建目录

```php
// 创建单个目录
Dir::create('./new/directory');

// 创建多级目录（递归创建）
Dir::create('./new/parent/directory/child');

// 创建目录并指定权限
Dir::create('./new/directory', 0777);
```

### 删除目录

```php
// 删除目录及其所有内容（递归删除）
Dir::delDir('./directory/to/delete');

// 仅删除目录本身（不递归删除子目录）
Dir::delDir('./directory/to/delete', false);
```

### 复制目录

```php
// 复制目录及其所有内容
Dir::copyDir('./source/directory', './destination/directory');
```

### 移动目录

```php
// 移动或重命名目录
Dir::moveDir('./old/path', './new/path');
```

## 获取文件列表

### 递归获取文件列表

```php
// 获取目录下的所有文件（递归）
$files = Dir::listFile('./path/to/directory');

// 获取目录下的所有 PHP 文件并排除 .git 目录
$phpFiles = Dir::listFile('./path/to/directory', '*.php', ['.git']);
```

### 获取文件列表（支持排序和过滤）

```php
// 获取所有文件，默认按文件名降序排列
$files = Dir::getFiles('./path/to/dir');

// 获取 PHP 文件并按大小升序排列
$phpFiles = Dir::getFiles('./path/to/dir', '*.php', 'size', 'ASC');

// 排除特定文件和目录，返回完整信息
$options = [
    './path/to/dir',
    ['files' => ['.gitignore'], 'dirs' => ['.git']],
    true
];
$files = Dir::getFiles($options);
```

## 目录大小计算

```php
// 计算目录总大小
$size = Dir::size('./path/to/directory');
// 返回格式化后的大小字符串，如 "10.23 MB"
```

## 目录对象操作

### 实例化目录对象

```php
// 创建目录对象
$dir = new Dir('./path/to/directory');
```

### 使用迭代器遍历目录

```php
// 获取目录内容迭代器
$iterator = $dir->getIterator();

// 遍历目录内容
foreach ($iterator as $item) {
    echo $item['filename'] . PHP_EOL;
}
```

### 使用数组方式访问目录内容

```php
// 检查索引是否存在
if (isset($dir[0])) {
    echo "索引 0 存在" . PHP_EOL;
}

// 获取索引对应的目录内容
$item = $dir[0];

// 遍历目录内容
for ($i = 0; $i < count($dir); $i++) {
    echo $dir[$i]['filename'] . PHP_EOL;
}
```

## 获取文件属性

### 获取基本属性

```php
// 获取文件名
$filename = $dir->getFilename();

// 获取目录路径
$path = $dir->getPathname();

// 获取目录绝对路径
$realPath = $dir->getRealPath();
```

### 获取时间属性

```php
// 获取最后访问时间
$atime = $dir->getATime();

// 获取创建时间
$ctime = $dir->getCTime();

// 获取最后修改时间
$mtime = $dir->getMTime();
```

### 获取权限和大小

```php
// 获取权限
$perms = $dir->getPerms();

// 获取文件大小（字节）
$size = $dir->getSize();
```

## 最佳实践

1. **目录权限设置**：
   - 在创建目录时，根据实际需求设置合适的权限（默认 0755）。
   - 避免过度宽松的权限设置（如 0777），以提高安全性。

2. **删除操作谨慎**：
   - `delDir()` 方法是不可逆的，删除前应进行确认，避免误删重要数据。
   - 可以在删除前检查目录内容，确保不会删除意外的文件。

3. **文件列表过滤**：
   - 在获取文件列表时，使用 `pattern` 参数过滤不需要的文件类型。
   - 使用 `protectDirs` 参数排除系统目录（如 .git、.svn）和临时目录。

4. **性能优化**：
   - 对于大型目录，避免频繁的递归操作，如 `size()`、`copyDir()` 等。
   - 可以分批处理大型目录的文件列表，减少内存占用。

5. **错误处理**：
   - 对目录操作的结果进行检查，确保操作成功。
   - 在可能出错的地方使用 try-catch 块捕获异常。

## 注意事项

1. **文件系统权限**：
   - 确保 PHP 脚本有足够的文件系统操作权限，特别是在进行创建、删除、复制等操作时。
   - 在 Linux 系统中，需要注意 PHP 运行用户（通常是 www-data 或 apache）与文件所有者的权限关系。

2. **跨设备移动**：
   - `moveDir()` 方法使用 PHP 的 `rename()` 函数实现。
   - 对于跨设备移动（如从一个硬盘移动到另一个硬盘），`rename()` 可能会失败，此时需要使用 `copyDir()` 结合 `delDir()` 实现。

3. **符号链接处理**：
   - Dir 类默认不会处理符号链接，可能会导致意外的递归或文件路径错误。
   - 在处理包含符号链接的目录时，应特别小心。

4. **大型目录处理**：
   - 对于包含大量文件的目录，递归操作可能会消耗大量内存和时间。
   - 考虑使用迭代方式替代递归方式处理大型目录。

5. **操作系统兼容性**：
   - 不同操作系统的文件路径分隔符不同（Windows 使用 \，Unix/Linux 使用 /）。
   - Dir 类内部会处理路径分隔符，但在提供路径参数时，建议使用 `/`，以提高跨平台兼容性。

## 示例代码

### 目录备份示例

```php
/**
 * 备份目录
 * 
 * @param string $source 源目录路径
 * @param string $destination 目标备份路径
 * @return bool 备份成功返回 true，失败返回 false
 */
function backupDirectory(string $source, string $destination): bool
{
    // 确保源目录存在
    if (!is_dir($source)) {
        return false;
    }
    
    // 生成备份文件名（包含时间戳）
    $timestamp = date('YmdHis');
    $backupPath = $destination . '/backup_' . $timestamp;
    
    // 创建备份目录
    if (!Dir::create($backupPath)) {
        return false;
    }
    
    // 复制目录内容
    if (!Dir::copyDir($source, $backupPath)) {
        // 复制失败，清理备份目录
        Dir::delDir($backupPath);
        return false;
    }
    
    return true;
}

// 使用示例
$success = backupDirectory('./data', './backups');
if ($success) {
    echo '目录备份成功！';
} else {
    echo '目录备份失败！';
}
```

### 文件查找示例

```php
/**
 * 查找特定类型的文件
 * 
 * @param string $directory 要搜索的目录
 * @param string $extension 文件扩展名
 * @return array 找到的文件路径数组
 */
function findFilesByExtension(string $directory, string $extension): array
{
    // 构建文件匹配模式
    $pattern = '*.' . ltrim($extension, '.');
    
    // 搜索文件
    $files = Dir::listFile($directory, $pattern);
    
    return $files;
}

// 使用示例
$phpFiles = findFilesByExtension('./app', 'php');
$jsFiles = findFilesByExtension('./public', 'js');
```

## 总结

Dir 类提供了全面的文件系统操作功能，简化了目录和文件的管理。通过静态方法和实例方法的结合，可以方便地进行目录创建、删除、复制、移动等操作，以及获取文件列表和属性信息。在使用过程中，应注意权限设置、错误处理和性能优化，以确保操作的安全性和效率。