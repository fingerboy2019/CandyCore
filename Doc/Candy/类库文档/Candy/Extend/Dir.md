# Dir 类

## 简介

Dir类是一个文件夹操作类，提供了全面的目录和文件操作功能，包括目录创建、删除、复制、移动、文件列表获取等。该类继承自SplFileInfo，能够获取文件和目录的各种属性信息，同时实现了迭代器接口，支持对目录内容进行遍历操作。

## 命名空间

```php
namespace Candy\Extend;
```

## 类定义

```php
/**
 * 文件夹操作类
 * 
 * 提供目录创建、删除、复制、移动等功能，支持文件列表获取和过滤。
 * 继承SplFileInfo类，实现ArrayAccess接口，可直接通过数组方式获取目录内容。
 * 
 * @package Candy\Extend
 */
final class Dir extends \SplFileInfo implements \ArrayAccess
```

## 主要方法

### __construct()

```php
/**
 * 构造函数，初始化目录对象
 * 
 * @param string $directory 目录路径
 * @example
 * \```php
 * $dir = new Dir('./path/to/directory');
 * \```
 */
public function __construct(string $directory)
```

### create()

```php
/**
 * 创建目录
 * 
 * 创建指定路径的目录，支持递归创建多级目录。
 * 
 * @param string $dir 需要创建的目录路径
 * @param int $mode 目录权限，默认0755
 * @return bool 创建成功返回true，失败返回false
 * @example
 * \```php
 * // 创建目录
 * Dir::create('./new/directory');
 * 
 * // 创建目录并指定权限
 * Dir::create('./new/directory', 0777);
 * \```
 */
public static function create(string $dir, int $mode = 0755): bool
```

### listFile()

```php
/**
 * 获取目录下的所有文件列表（递归）
 * 
 * 递归扫描指定目录及其所有子目录，获取所有符合条件的文件信息。
 * 支持文件过滤模式和排除特定子目录。
 * 
 * @param string $directory 要扫描的目录路径
 * @param string $pattern 文件匹配模式，默认'*'（所有文件）
 * @param array $protectDirs 要排除的目录列表
 * @return array 返回文件信息数组
 * @example
 * \```php
 * // 获取所有文件
 * $files = Dir::listFile('./path/to/dir');
 * 
 * // 获取PHP文件并排除.git目录
 * $phpFiles = Dir::listFile('./path/to/dir', '*.php', ['.git']);
 * \```
 */
public static function listFile(string $directory, string $pattern = '*', array $protectDirs = []): array
```

### getFiles()

```php
/**
 * 获取目录中的文件列表
 * 
 * 获取指定目录下符合条件的所有文件列表，并可按指定字段排序。
 * 支持排除指定文件和目录，以及返回完整的文件信息或仅文件路径。
 * 使用静态缓存提高性能，避免重复扫描相同的目录。
 * 
 * @param string|array $directory 目录路径，或包含[目录路径, 保护设置, 是否返回完整信息]的数组
 * @param string $pattern 文件匹配模式，默认为'*'（匹配所有文件）
 * @param string $type 排序字段，可选值：name（文件名）、size（文件大小）、time（访问时间）
 * @param string $sort 排序方向，可选值：DESC（降序）、ASC（升序）
 * @return array 返回文件路径数组或包含文件详细信息的数组
 * @example
 * \```php
 * // 获取所有文件，默认按文件名降序排列
 * $files = Dir::getFiles('./path/to/dir');
 * 
 * // 获取PHP文件并按大小排序
 * $phpFiles = Dir::getFiles('./path/to/dir', '*.php', 'size', 'ASC');
 * 
 * // 排除特定文件和目录，返回完整信息
 * $options = [
 *     './path/to/dir',
 *     ['files' => ['.gitignore'], 'dirs' => ['.git']],
 *     true
 * ];
 * $files = Dir::getFiles($options);
 * \```
 */
public static function getFiles($directory, string $pattern = '*', string $type = 'name', string $sort = 'DESC'): array
```

### delDir()

```php
/**
 * 递归删除目录及其所有内容
 * 
 * 删除指定目录及其所有子目录和文件。
 * 这个操作不可逆，请谨慎使用，确保不会删除重要数据。
 * 
 * @param string $directory 要删除的目录路径
 * @param bool $subdir 是否递归删除子目录，默认为true
 * @return bool 操作成功返回true，目录不存在返回false
 * @example
 * \```php
 * // 删除目录及其所有内容
 * Dir::delDir('./path/to/delete');
 * \```
 */
public static function delDir(string $directory, bool $subdir = true)
```

### copyDir()

```php
/**
 * 递归复制目录及其所有内容
 * 
 * 将源目录及其所有子目录和文件复制到目标位置。
 * 支持排除特定文件和目录，以及保留文件权限。
 * 目标目录不存在时会自动创建。
 * 
 * @param string $source 源目录路径
 * @param string $destination 目标目录路径
 * @return bool 复制成功返回true，失败返回false
 * @example
 * \```php
 * // 基本复制
 * Dir::copyDir('./source/dir', './destination/dir');
 * \```
 */
public static function copyDir(string $source, string $destination): bool
```

### moveDir()

```php
/**
 * 移动或重命名目录
 * 
 * 将源目录移动到目标位置，相当于重命名目录。
 * 如果目标目录不存在，将进行重命名；如果目标目录存在且为空，则会尝试移动。
 * 此方法使用PHP的rename()函数，对于跨设备移动可能会失败。
 * 
 * @param string $source 源目录路径
 * @param string $destination 目标目录路径
 * @return bool 移动成功返回true，失败返回false
 * @example
 * \```php
 * // 移动目录
 * Dir::moveDir('./old/path', './new/path');
 * \```
 */
public static function moveDir(string $source, string $destination): bool
```

### size()

```php
/**
 * 计算目录总大小
 * 
 * 递归计算目录及其所有子目录和文件的总大小，并返回格式化后的大小字符串。
 * 此方法可能会消耗大量资源，特别是对于包含大量文件的大型目录。
 * 
 * @param string $path 要计算大小的目录路径
 * @param int $dept 递归层数，通常无需手动指定
 * @return string 返回格式化后的目录大小字符串
 * @example
 * \```php
 * // 计算目录大小
 * $size = Dir::size('./path/to/dir');
 * \```
 */
public static function size(string $path, int $dept = 0): string
```

### getIterator()

```php
/**
 * 创建并返回当前目录内容的ArrayObject迭代器，用于遍历目录内容。
 * 可以使用标准的Iterator接口方法来遍历目录中的文件和子目录。
 * 
 * @return \ArrayObject 返回目录内容的数组迭代器对象
 * @example
 * \```php
 * // 使用迭代器遍历目录
 * $dir = new Dir('./path/to/dir');
 * $iterator = $dir->getIterator();
 * foreach ($iterator as $item) {
 *     echo $item['filename'] . PHP_EOL;
 * }
 * \```
 */
public function getIterator(): mixed
```

## 接口实现方法

### offsetExists()

```php
/**
 * 检查指定索引是否存在于目录内容中
 * 
 * 实现ArrayAccess接口的offsetExists方法
 * 
 * @param mixed $offset 索引值
 * @return bool 存在返回true，不存在返回false
 */
public function offsetExists(mixed $offset): bool
```

### offsetGet()

```php
/**
 * 获取指定索引的目录内容
 * 
 * 实现ArrayAccess接口的offsetGet方法
 * 
 * @param mixed $offset 索引值
 * @return mixed 返回对应的值
 */
public function offsetGet(mixed $offset): mixed
```

### offsetSet()

```php
/**
 * 设置指定索引的目录内容（不支持修改目录内容）
 * 
 * 实现ArrayAccess接口的offsetSet方法
 * 
 * @param mixed $offset 索引值
 * @param mixed $value 要设置的值
 */
public function offsetSet(mixed $offset, mixed $value): void
```

### offsetUnset()

```php
/**
 * 删除指定索引的目录内容（不支持修改目录内容）
 * 
 * 实现ArrayAccess接口的offsetUnset方法
 * 
 * @param mixed $offset 索引值
 */
public function offsetUnset(mixed $offset): void
```

## 属性获取方法

### getATime()

```php
/**
 * 获取文件的最后访问时间
 * 
 * @return int 返回Unix时间戳
 */
public function getATime(): int
```

### getCTime()

```php
/**
 * 获取文件的创建时间
 * 
 * @return int 返回Unix时间戳
 */
public function getCTime(): int
```

### getMTime()

```php
/**
 * 获取文件的最后修改时间
 * 
 * @return int 返回Unix时间戳
 */
public function getMTime(): int
```

### getSize()

```php
/**
 * 获取文件大小
 * 
 * @return int 返回文件大小（字节）
 */
public function getSize(): int
```

### getPerms()

```php
/**
 * 获取文件权限
 * 
 * @return int 返回文件权限的整数表示
 */
public function getPerms(): int
```

## 使用说明

1. **创建目录**：使用`create()`方法可以创建单个或多级目录。
2. **获取文件列表**：使用`getFiles()`方法可以获取符合条件的文件列表，并支持排序和过滤。
3. **目录操作**：提供`delDir()`、`copyDir()`、`moveDir()`等方法进行目录的删除、复制和移动操作。
4. **属性获取**：继承自SplFileInfo，可以获取文件和目录的各种属性信息。
5. **遍历目录**：实现了迭代器接口，可以使用标准的遍历方式访问目录内容。

## 注意事项

1. **文件操作权限**：使用目录操作方法时，请确保PHP脚本有相应的文件系统操作权限。
2. **删除操作谨慎**：`delDir()`和`del()`方法是不可逆的，请谨慎使用，确保不会删除重要数据。
3. **性能考虑**：对于大型目录，递归操作（如`size()`、`copyDir()`等）可能会消耗大量资源。
4. **跨设备移动**：`moveDir()`方法使用PHP的rename()函数，对于跨设备移动可能会失败。

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-04-12 13:45:33