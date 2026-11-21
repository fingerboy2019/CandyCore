# Addon 类

## 类简介

Addon类是Candy框架的插件管理核心类，负责框架插件的检查、获取和权限验证等功能。
所有方法均为静态方法，无需实例化即可使用。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Addon类 - 插件管理核心类
 * 
 * 负责框架插件的检查、获取和权限验证等功能
 * 所有方法均为静态方法，无需实例化即可使用
 */
final Class Addon {
    // 类成员
}
```

## 主要方法

### checkAddon()

```php
/**
 * 检查指定插件是否存在且已启用
 * 
 * 判断一个插件是否存在、配置文件是否有效，以及是否已启用
 * 首先检查插件功能是否开启，然后检查插件目录和配置文件是否存在
 * 最后验证插件的启用状态
 * 
 * @param string $addonName 插件名称，对应插件目录名
 * @return bool 插件是否存在且已启用：true表示存在且启用，false表示不存在或未启用
 */
public static function checkAddon(string $addonName): bool
```

### getEnableAddons()

```php
/**
 * 获取所有已启用的插件列表
 * 
 * 获取系统中所有已安装且已启用的插件信息
 * 支持强制刷新缓存，避免重复获取相同数据
 * 返回的插件列表已按优先级排序
 * 
 * @param int $force 是否强制刷新缓存：0表示使用缓存，1表示强制刷新
 * @return array 已启用的插件数组，键为插件名，值为插件配置信息
 */
public static function getEnableAddons(int $force = 0): array
```

### getAddonAuth()

```php
/**
 * 检查插件是否需要认证
 * 
 * 验证指定插件是否配置了需要认证才能访问的权限设置
 * 通过检查插件配置文件中的auth字段来确定
 * 
 * @param string $addonName 插件名称
 * @return bool 插件是否需要认证：true表示需要认证，false表示不需要认证或插件不存在
 */
public static function getAddonAuth(string $addonName): bool
```

## 内部方法

以下是Addon类中的内部方法（私有方法），仅供类内部使用：

### getAddons()

```php
/**
 * 获取所有插件列表
 * 
 * 获取系统中所有可用的插件信息，包括已启用和未启用的插件
 * 支持强制模式，可以忽略配置直接扫描插件目录
 * 返回的插件列表已按rank字段排序
 * 
 * @param int $force 是否强制获取：0表示从配置文件读取，1表示直接扫描目录
 * @return array 所有插件数组，键为插件名，值为插件配置信息
 */
private static function getAddons(int $force = 0): array
```

## 使用说明

### 插件检查

```php
// 检查名为"demo"的插件是否存在且已启用
if (\Candy\Core\Addon::checkAddon('demo')) {
    // 插件已启用，可以使用
    echo '插件已启用';
} else {
    // 插件不存在或未启用
    echo '插件未启用或不存在';
}
```

### 获取插件列表

```php
// 获取所有已启用的插件列表（使用缓存）
$enabledAddons = \Candy\Core\Addon::getEnableAddons();
foreach ($enabledAddons as $addonName => $addonConfig) {
    echo "插件名：$addonName<br>";
    echo "插件配置：" . print_r($addonConfig, true) . "<br>";
}

// 强制刷新缓存获取所有已启用的插件列表
$freshAddons = \Candy\Core\Addon::getEnableAddons(1);
```

### 权限验证

```php
// 检查名为"admin"的插件是否需要认证
if (\Candy\Core\Addon::getAddonAuth('admin')) {
    // 需要先进行用户认证
    if (User::isLogin()) {
        // 已登录，允许访问
    } else {
        // 未登录，跳转到登录页面
        $this->redirect('user/login');
    }
} else {
    // 不需要认证即可访问
}
```

## 插件配置说明

插件通常需要在其目录下创建`conf.php`配置文件，主要配置项包括：

```php
<?php
return [
    'enable' => 1,       // 插件是否启用（0=禁用，1=启用）
    'installed' => 1,    // 插件是否已安装（0=未安装，1=已安装）
    'auth' => 1,         // 插件是否需要认证（0=不需要，1=需要）
    'rank' => 100,       // 插件优先级，用于排序（数值越小优先级越高）
];
```

## 注意事项

1. 使用前需确保插件功能已启用（G('Addons')不为null）
2. 插件目录结构必须符合框架规范
3. 插件配置文件格式必须正确，否则可能导致加载失败
4. 对于性能敏感场景，建议使用缓存模式获取插件列表
5. 插件的优先级（rank）决定了插件的加载顺序

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-08-01 21:01:16
