# File 文件缓存类使用文档

## 简介与基本概念

File类是Candy框架中的文件缓存类，提供高性能的文件缓存机制，支持文件与内存双层缓存策略。主要负责缓存文件的创建、读取、更新和删除，支持缓存过期时间设置与自动清理，在开启shmop扩展模式下可获得更好的性能表现。

### 命名空间
```php
namespace Candy\Extend;
```

### 核心特性
- **文件与内存双层缓存**：提高缓存读取性能
- **支持多种数据类型**：integer, string, array, boolean, double
- **缓存过期控制**：可设置缓存的保存时间
- **自动清理**：支持自动删除过期缓存文件
- **安全防护**：默认使用.php后缀，防止缓存文件被HTTP直接访问

## 快速入门

### 基本用法
```php
<?php
use Candy\Extend\File;

// 创建文件缓存实例
$fileCache = new File(['dir' => './cache', 'ext' => '.php']);

// 设置缓存
$fileCache->set('user_info', ['name' => '张三', 'age' => 25], 3600);

// 获取缓存
$userInfo = $fileCache->get('user_info');

// 删除缓存
$fileCache->delete('user_info');

// 清除所有缓存
$fileCache->flush();

// 自动清理过期缓存
$fileCache->autoDeleteExpiredFile();
```

## 主要功能与用法

### 初始化File实例

```php
<?php
use Candy\Extend\File;

// 使用默认配置创建实例
$fileCache = new File();

// 自定义配置创建实例
$fileCache = new File([
    'dir' => './cache', // 缓存目录
    'ext' => '.php'    // 缓存文件后缀
]);
```

#### 配置参数说明
- `dir`: 缓存目录路径，默认使用RUNTIME/Tmps
- `ext`: 缓存文件后缀，默认使用.php（推荐使用，因为无法直接被HTTP访问）

### 缓存操作方法

#### add() - 添加缓存（仅在键不存在时）
```php
<?php
// 添加缓存，仅在键不存在时成功
$result = $fileCache->add('user_age', 25, 3600);

// 如果键已存在，添加失败
$result = $fileCache->add('user_age', 26, 3600); // 返回false
```

#### set() - 设置/更新缓存
```php
<?php
// 设置缓存，键不存在则添加，存在则更新
$fileCache->set('user_info', ['name' => '张三', 'age' => 25], 3600);

// 永不过期的缓存
$fileCache->set('system_config', ['site_name' => '我的网站'], 0);
```

#### get() - 获取缓存
```php
<?php
// 获取缓存
$userInfo = $fileCache->get('user_info');

// 获取缓存，设置默认值
$userInfo = $fileCache->get('non_existent_key', ['name' => '默认用户']);
```

#### delete() - 删除缓存
```php
<?php
// 删除指定缓存
$fileCache->delete('user_info');
```

#### flush() - 清除所有缓存
```php
<?php
// 清除所有缓存文件（包括未过期的）
$fileCache->flush();
```

#### autoDeleteExpiredFile() - 自动清理过期缓存
```php
<?php
// 仅删除过期的缓存文件
$fileCache->autoDeleteExpiredFile();
```

### 辅助方法

#### cacheConnectError() - 检查缓存连接状态
```php
<?php
// 检查缓存连接是否正常
if (!$fileCache->cacheConnectError()) {
    // 缓存连接正常，可以进行缓存操作
} else {
    // 缓存连接异常
}
```

#### getCacheFileName() - 获取缓存文件名
```php
<?php
// 获取缓存文件路径
$fileName = $fileCache->getCacheFileName('user_info');
echo $fileName; // 输出：./cache/[md5('candy_user_info')].php
```

## 高级用法

### 缓存数据类型支持

File类支持多种数据类型的缓存：

```php
<?php
// 整数缓存
$fileCache->set('count', 123, 3600);

// 字符串缓存
$fileCache->set('message', 'Hello World', 3600);

// 数组缓存
$fileCache->set('user_list', [
    ['id' => 1, 'name' => '张三'],
    ['id' => 2, 'name' => '李四']
], 3600);

// 布尔值缓存
$fileCache->set('is_active', true, 3600);

// 浮点数缓存
$fileCache->set('price', 19.99, 3600);
```

### 缓存过期时间设置

```php
<?php
// 设置1小时过期的缓存
$fileCache->set('temp_data', '临时数据', 3600);

// 设置1天过期的缓存
$fileCache->set('daily_data', '每日数据', 86400);

// 设置永不过期的缓存
$fileCache->set('permanent_data', '永久数据', 0);
```

### 缓存使用场景示例

#### 页面内容缓存
```php
<?php
use Candy\Extend\File;

$fileCache = new File();
$cacheKey = 'page_index_content';
$cacheTime = 3600; // 1小时

// 尝试获取缓存的页面内容
$content = $fileCache->get($cacheKey);

if (!$content) {
    // 如果缓存不存在，生成页面内容
    $content = generatePageContent();
    
    // 将内容存入缓存
    $fileCache->set($cacheKey, $content, $cacheTime);
}

// 输出页面内容
echo $content;
```

#### 数据库查询结果缓存
```php
<?php
use Candy\Extend\File;

$fileCache = new File();
$cacheKey = 'products_list';
$cacheTime = 7200; // 2小时

// 尝试获取缓存的查询结果
$products = $fileCache->get($cacheKey);

if (!$products) {
    // 如果缓存不存在，执行数据库查询
    $products = $db->query('SELECT * FROM products');
    
    // 将查询结果存入缓存
    $fileCache->set($cacheKey, $products, $cacheTime);
}

// 使用查询结果
foreach ($products as $product) {
    // 处理产品数据
}
```

## 最佳实践与注意事项

### 1. 缓存目录权限设置

确保缓存目录具有适当的写入权限：

```bash
# 设置缓存目录权限
chmod 755 ./cache
```

### 2. 缓存文件后缀选择

推荐使用.php作为缓存文件后缀，防止缓存文件被HTTP直接访问：

```php
<?php
// 推荐：使用.php后缀
$fileCache = new File(['ext' => '.php']);

// 不推荐：使用.txt后缀（可被直接访问）
$fileCache = new File(['ext' => '.txt']);
```

### 3. 缓存键命名规范

使用有意义的键名，并考虑添加前缀以避免冲突：

```php
<?php
// 推荐：使用有意义的键名
$fileCache->set('user:1:profile', $userProfile, 3600);
$fileCache->set('product:list:latest', $latestProducts, 3600);

// 不推荐：使用模糊的键名
$fileCache->set('data1', $data, 3600);
$fileCache->set('info', $info, 3600);
```

### 4. 缓存数据大小控制

避免缓存过大的数据，影响性能：

```php
<?php
// 推荐：缓存合理大小的数据
$fileCache->set('user_info', $userInfo, 3600);

// 不推荐：缓存过大的数据集合
$fileCache->set('all_products', $allProducts, 3600);
```

### 5. 合理设置过期时间

根据数据变化频率设置适当的过期时间：

```php
<?php
// 频繁变化的数据，短期缓存
$fileCache->set('online_users', $onlineUsers, 60);

// 变化较少的数据，长期缓存
$fileCache->set('system_config', $systemConfig, 86400);

// 基本不变的数据，永久缓存
$fileCache->set('constants', $constants, 0);
```

### 6. 定期清理过期缓存

定期调用autoDeleteExpiredFile()方法清理过期缓存文件：

```php
<?php
// 在脚本结束时清理过期缓存
register_shutdown_function(function() use ($fileCache) {
    $fileCache->autoDeleteExpiredFile();
});
```

### 7. 错误处理

```php
<?php
try {
    $fileCache = new File(['dir' => '/invalid/path']);
} catch (Exception $e) {
    echo '缓存初始化失败：' . $e->getMessage();
}
```

## 总结

File类是Candy框架中提供的文件缓存解决方案，它提供了简单易用的API来管理文件缓存，支持多种数据类型、过期时间设置和自动清理功能。通过合理使用File类，可以有效提高应用程序的性能，减少数据库查询和复杂计算的次数。

主要优势：
- 提供双层缓存机制，提高读取性能
- 自动处理缓存过期和清理
- 支持多种数据类型
- 具有安全防护措施
- 易于集成和使用

在实际开发中，建议将File类用于缓存频繁访问但不经常变化的数据，如页面内容、数据库查询结果、配置信息等，以提高应用程序的响应速度和性能。