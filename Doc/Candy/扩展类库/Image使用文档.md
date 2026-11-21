# Image使用文档

## 概述

Image类是Candy框架中的图像处理工具类，用于处理各种图像操作，支持图像缩略图生成、水印添加和图像裁剪等功能。基于PHP的GD库实现，支持GIF、JPEG和PNG等常见图像格式。

> 注意：Image类是Candy框架核心类库的补充类库，提供图像处理功能。

### 主要功能特性

- **图像缩放**：按比例缩放图像，生成新的缩略图文件
- **水印添加**：在原图上添加指定的水印图片，支持多种水印位置
- **图像裁剪**：从原图中裁剪指定区域的图片，生成新的裁剪后图片文件
- **支持多种格式**：基于PHP的GD库实现，支持GIF、JPEG和PNG等常见图像格式

## 快速入门

### 命名空间

```php
use Extend\Image;
```

### 基本使用示例

```php
// 创建Image实例，指定图片处理路径
$image = new Image('/path/to/images');

// 生成缩略图（宽度200px，高度150px）
$thumbnail = $image->thumb('original.jpg', 200, 150, 'th_');

// 添加水印（中央位置）
$watermarked = $image->waterMark('original.jpg', 'watermark.png', 5, 'wa_');

// 裁剪图像（从左上角(0,0)开始，宽300px，高200px）
$cropped = $image->cut('original.jpg', 0, 0, 300, 200, 'cu_');

// 输出处理后的文件名
echo "缩略图文件名: {$thumbnail}<br>";
echo "水印图像文件名: {$watermarked}<br>";
echo "裁剪图像文件名: {$cropped}<br>";
```

## 核心功能详解

### 构造函数

```php
/**
 * 构造方法
 *
 * 初始化Image类的实例，设置图片处理的基础路径。
 *
 * @param string $path 图片处理的基础路径，如果不提供则使用默认的上传目录
 */
public function __construct(string $path = '')
```

#### 示例

```php
// 使用默认上传目录
$image1 = new Image();

// 指定自定义图片处理路径
$image2 = new Image('/var/www/uploads/images');
```

### 图像缩放（创建缩略图）

```php
/**
 * 图像缩放（创建缩略图）
 *
 * 将原图按指定尺寸缩放，生成新的缩略图文件。缩放过程保持图像比例，
 * 并将缩放后的图像居中放置在指定尺寸的画布中，多余部分透明或填充白色。
 *
 * @param string $name 原始图像文件名，相对于$path路径
 * @param int $width 生成缩略图的宽度
 * @param int $height 生成缩略图的高度
 * @param string $qz 缩略图文件名前缀，默认为'th_'
 * @return string 生成的缩略图文件名，包含前缀
 * @throws Exception 当图像文件不存在或格式不支持时抛出异常
 */
public function thumb($name, int $width, int $height, string $qz = 'th_'): string
```

#### 示例

```php
// 生成不同尺寸的缩略图
$thumb1 = $image->thumb('photo.jpg', 100, 100, 'small_');   // 100x100小图
$thumb2 = $image->thumb('photo.jpg', 200, 150, 'medium_');  // 200x150中图
$thumb3 = $image->thumb('photo.jpg', 800, 600, 'large_');   // 800x600大图
```

### 添加图片水印

```php
/**
 * 添加图片水印
 *
 * 在原图上添加指定的水印图片，可以设置水印位置。支持多种水印位置定位方式，
 * 水印图片会被适当调整大小以确保在原图中显示协调。
 *
 * @param string $groundName 原始图像文件名，相对于$path路径
 * @param string $waterName 水印图像文件名，相对于$path路径
 * @param int $waterPos 水印位置，1-9的数字，默认为0（随机位置）
 * @param string $qz 生成的水印图片文件名前缀，默认为'wa_'
 * @return string 生成的带水印图片的文件名，包含前缀
 * @throws Exception 当图像文件不存在或格式不支持时抛出异常
 */
public function waterMark(string $groundName, string $waterName, int $waterPos = 0, string $qz = 'wa_'): mixed
```

#### 水印位置说明

| 参数值 | 位置描述 |
|--------|----------|
| 0 | 随机位置（默认） |
| 1 | 左上角 |
| 2 | 中上 |
| 3 | 右上角 |
| 4 | 左中 |
| 5 | 中央（最常用） |
| 6 | 右中 |
| 7 | 左下角 |
| 8 | 中下 |
| 9 | 右下角 |

#### 示例

```php
// 中央位置添加水印
$watermarked = $image->waterMark('photo.jpg', 'logo.png', 5, 'wa_');

// 右下角添加水印
$watermarked = $image->waterMark('photo.jpg', 'logo.png', 9, 'wa_');

// 随机位置添加水印（默认）
$watermarked = $image->waterMark('photo.jpg', 'logo.png', 0, 'wa_');
```

### 图像裁剪

```php
/**
 * 裁剪图片
 *
 * 从原图中裁剪指定区域的图片，生成新的裁剪后图片文件。可以通过指定起始坐标
 * 和裁剪尺寸来精确裁剪原图的特定区域。
 *
 * @param string $name 原始图像文件名，相对于$path路径
 * @param int $x 裁剪起始点的x坐标（左上角为原点）
 * @param int $y 裁剪起始点的y坐标（左上角为原点）
 * @param int $width 裁剪区域的宽度
 * @param int $height 裁剪区域的高度
 * @param string $qz 裁剪后图片文件名前缀，默认为'cu_'
 * @return string 生成的裁剪后图片文件名，包含前缀
 * @throws Exception 当图像文件不存在、裁剪区域超出原图范围或格式不支持时抛出异常
 */
public function cut($name, int $x, int $y, int $width, int $height, string $qz = 'cu_'): mixed
```

#### 示例

```php
// 从左上角(0,0)开始，裁剪300x200的区域
$cropped1 = $image->cut('photo.jpg', 0, 0, 300, 200, 'cu_');

// 从中间位置(100,100)开始，裁剪400x300的区域
$cropped2 = $image->cut('photo.jpg', 100, 100, 400, 300, 'cu_');
```

## 高级用法

### 1. 链式图像处理

虽然Image类没有直接支持链式调用，但可以通过以下方式实现类似效果：

```php
$image = new Image('/path/to/images');

// 先裁剪，再添加水印
$cropped = $image->cut('original.jpg', 0, 0, 800, 600, 'cu_');
$final = $image->waterMark($cropped, 'logo.png', 5, 'final_');
```

### 2. 批量图像处理

```php
$image = new Image('/path/to/images');
$photos = ['photo1.jpg', 'photo2.jpg', 'photo3.jpg'];

foreach ($photos as $photo) {
    try {
        // 为每个图片生成缩略图和水印版本
        $thumb = $image->thumb($photo, 200, 150, 'th_');
        $watermarked = $image->waterMark($photo, 'logo.png', 9, 'wa_');
        
        echo "处理完成: {$photo}<br>";
        echo "  缩略图: {$thumb}<br>";
        echo "  水印图: {$watermarked}<br>";
    } catch (Exception $e) {
        echo "处理失败 {$photo}: {$e->getMessage()}<br>";
    }
}
```

### 3. 动态调整水印大小

虽然Image类没有直接提供调整水印大小的方法，但可以先使用thumb()方法调整水印图片大小，然后再添加：

```php
$image = new Image('/path/to/images');

// 先将大水印缩小
$smallWatermark = $image->thumb('large_watermark.png', 50, 50, 'small_');

// 使用缩小后的水印
$watermarked = $image->waterMark('photo.jpg', $smallWatermark, 5, 'wa_');
```

## 异常处理

Image类在处理过程中可能会抛出异常，建议使用try-catch块进行异常处理：

```php
try {
    // 尝试处理图像
    $thumbnail = $image->thumb('non_existent.jpg', 200, 150);
} catch (Exception $e) {
    // 处理异常
    echo '图像处理失败: ' . $e->getMessage();
}
```

## 注意事项

1. **GD库依赖**：使用此类前，请确保服务器已启用PHP的GD库扩展

2. **性能考虑**：
   - 处理大图片时可能需要调整PHP的内存限制（memory_limit）
   - 对于大量图像处理任务，建议设置较长的执行时间（max_execution_time）

3. **水印处理**：
   - 添加水印时，确保水印图片尺寸不大于原图，否则可能会返回false
   - 水印图片建议使用透明背景的PNG格式，效果更佳

4. **裁剪操作**：
   - 裁剪图像时，确保裁剪区域完全在原图范围内，否则会抛出异常
   - 裁剪坐标从左上角(0,0)开始计算

5. **文件路径**：
   - 所有文件操作都是相对于构造函数中设置的$path路径
   - 确保PHP进程对该路径有读写权限

6. **图像格式**：
   - 支持的图像格式为GIF、JPEG和PNG，其他格式可能无法正常处理
   - 处理后的图像会保持原有的图像格式

7. **文件名前缀**：
   - 每个操作都会生成新文件，并添加指定前缀
   - 避免使用过长或特殊字符的前缀，可能会导致文件名问题

## 应用场景

1. **网站缩略图**：为文章、商品图片生成不同尺寸的缩略图
2. **图片水印保护**：为原创图片添加水印，防止未经授权的使用
3. **图片裁剪**：裁剪用户上传的头像，确保统一尺寸
4. **批量图像处理**：对大量图片进行统一处理，如批量添加水印或生成缩略图
5. **内容管理系统**：在CMS中集成图像处理功能，方便用户管理图片

## 版本信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2024-09-14 01:28:06
- **所属框架**: Candy框架核心类库的补充类库
- **依赖**: PHP GD库

## 相关链接

- [PHP GD库文档](http://php.net/manual/zh/book.image.php) - PHP官方GD库文档
- [Candy框架文档](https://github.com/fingerboy/candy)