# Zip使用文档

## 概述

Zip类是Candy框架中提供ZIP文件创建、文件压缩和流式打包下载功能的工具类。支持多文件压缩、流式输出下载、中文文件名处理等特性，适用于各种文件打包和下载场景。

> 注意：Zip类是Candy框架核心类库的补充类库，提供ZIP文件处理功能。

## 快速入门

### 命名空间

```php
use Extend\Zip;
```

### 基本使用示例

**创建ZIP文件并下载**：

```php
// 创建ZIP文件并下载
$zip = new Zip([
    'filename' => 'backup',
    'doWrite' => true,
    'filelist' => [
        ['type' => 'file', 'pathname' => '/data/file1.txt'],
        ['type' => 'file', 'pathname' => '/data/file2.txt']
    ],
    'comments' => '系统备份文件'
]);
```

**批量文件下载**：

```php
// 准备文件列表
$files = [
    ['path' => '/uploads/photo1.jpg'],
    ['path' => '/uploads/photo2.jpg'],
    ['path' => '/uploads/document.pdf']
];

// 转换为Zip类需要的文件列表格式
$filelist = [];
foreach ($files as $file) {
    $filelist[] = ['type' => 'file', 'pathname' => $file['path']];
}

// 创建ZIP并下载
$zip = new Zip([
    'filename' => '批量下载_' . date('Ymd'),
    'doWrite' => true,
    'filelist' => $filelist
]);
```

## 核心功能详解

### 构造函数

```php
/**
 * 构造函数
 *
 * 初始化Zip类并根据选项配置ZIP文件处理
 * 支持设置文件名、开启流式下载、添加文件列表和设置注释
 *
 * @param  array  $options 配置选项数组
 * @param  string $options['filename'] 下载文件名，默认为当前时间戳
 * @param  bool   $options['doWrite'] 是否开启流式压缩，默认为false
 * @param  array  $options['filelist'] 文件列表数组，每项包含type和pathname字段
 * @param  string $options['comments'] ZIP文件注释信息
 * @return void
 */
public function __construct(array $options = [])
```

#### 配置选项详解

| 选项名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| filename | string | 当前时间戳 | 下载的ZIP文件名（不含.zip后缀） |
| doWrite | bool | false | 是否开启流式下载模式 |
| filelist | array | [] | 要添加到ZIP的文件列表 |
| comments | string | '' | ZIP文件的注释信息 |

#### 文件列表格式

文件列表数组中的每个元素必须包含以下字段：

```php
[
    'type' => 'file',     // 文件类型，目前只支持'file'
    'pathname' => '/path/to/file.txt'  // 文件的完整路径
]
```

### 内部方法（了解即可）

虽然这些方法是私有的，但了解它们的功能可以帮助更好地使用Zip类：

1. **setDoWrite()**：开启流式打包模式
2. **setComments()**：设置ZIP文件注释信息
3. **unix2DosTime()**：将Unix时间戳转换为ZIP文件规范中使用的DOS时间格式
4. **addFile()**：向ZIP包中添加文件
5. **file()**：生成完整的ZIP文件数据

## 高级用法

### 1. 带有注释的ZIP文件

```php
// 创建带有注释的ZIP文件
$zip = new Zip([
    'filename' => 'documentation',
    'doWrite' => true,
    'filelist' => [
        ['type' => 'file', 'pathname' => '/docs/manual.pdf'],
        ['type' => 'file', 'pathname' => '/docs/tutorial.txt']
    ],
    'comments' => '项目文档包 - 生成日期：' . date('Y-m-d H:i:s')
]);
```

### 2. 动态生成文件列表

```php
// 从数据库查询文件列表
$files = DB::table('attachments')
            ->where('user_id', 123)
            ->get(['file_path']);

// 转换为Zip类需要的格式
$filelist = [];
foreach ($files as $file) {
    $filelist[] = ['type' => 'file', 'pathname' => $file->file_path];
}

// 下载用户的所有附件
$zip = new Zip([
    'filename' => '用户附件_' . date('Ymd'),
    'doWrite' => true,
    'filelist' => $filelist
]);
```

### 3. 根据日期批量下载

```php
// 获取当天上传的文件
$today = date('Y-m-d');
$files = glob('/uploads/' . $today . '/*.{jpg,png,pdf}', GLOB_BRACE);

// 转换为文件列表
$filelist = [];
foreach ($files as $file) {
    $filelist[] = ['type' => 'file', 'pathname' => $file];
}

if (!empty($filelist)) {
    // 创建ZIP并下载
    $zip = new Zip([
        'filename' => '今日上传_' . $today,
        'doWrite' => true,
        'filelist' => $filelist
    ]);
} else {
    echo '今日没有上传文件';
}
```

## 注意事项

1. **输出内容**：当使用流式下载时（doWrite=true），确保在创建Zip实例前没有输出任何内容，包括空格、换行或HTML标签，否则会导致下载失败。

2. **中文支持**：文件路径和文件名支持中文，但会自动转换为GBK编码以保证兼容性。

3. **内存管理**：对于大文件或大量文件的打包，推荐使用流式下载模式（doWrite=true），以减少内存占用。

4. **文件权限**：在添加文件列表时，确保所有文件都存在且PHP进程有读取权限。

5. **文件大小**：由于PHP的内存限制，单个ZIP文件的大小可能受到限制。对于超大文件的打包，建议考虑使用系统级的zip命令或分卷压缩。

6. **编码转换**：ZIP文件的注释信息也会自动进行编码转换，支持中文注释。

7. **文件名处理**：文件名中的特殊字符可能会被自动处理，以确保兼容性。

## 应用场景

1. **文件批量下载**：允许用户一键下载多个文件
2. **数据备份**：将系统数据或用户数据打包备份
3. **文档分发**：将多个文档打包成一个文件分发
4. **图片打包**：相册或图片集的打包下载
5. **日志下载**：将系统日志文件打包下载用于分析

## 版本信息

- **所属框架**: Candy框架核心类库的补充类库
- **主要功能**: ZIP文件创建、文件压缩和流式打包下载
- **支持特性**: 多文件压缩、流式输出下载、中文文件名处理

## 相关链接

- [PHP ZipArchive类文档](http://php.net/manual/zh/class.ziparchive.php) - PHP官方提供的ZIP处理类
- [Candy框架文档](https://github.com/fingerboy/candy)