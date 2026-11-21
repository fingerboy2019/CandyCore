# Autoload 类

## 类简介

Autoload 类负责 Candy 框架的自动加载机制，采用 `final` 修饰禁止继承。
实现了 PHP 的 `spl_autoload_register` 接口，支持多种命名空间的类文件自动加载。
包含缓存机制以提升加载性能，兼容 Composer 自动加载。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Candy 框架 自动加载类
 * 
 * 负责自动加载框架内的所有类文件，支持命名空间映射和文件路径解析
 * 实现了 PHP 的 spl_autoload_register 接口
 * 包含缓存机制以提升加载性能，兼容 Composer 自动加载
 * 
 * @final 禁止继承此类
 * @author 刘森
 * @date 2019-07-25 10:00:00
 * @package Candy\Core
 */
final class Autoload
{
    // 类成员
}
```

## 主要方法

### loading()

```php
/**
 * 初始化自动加载配置
 * 
 * 注册框架的自动加载函数到 spl_autoload_register
 * 同时检查并集成 Composer 自动加载机制
 * 该方法应在应用程序启动时调用
 * 
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function loading(): void
```

**功能说明**：初始化自动加载机制，注册类文件自动加载处理函数，并检测是否存在 Composer 的自动加载文件。

**使用示例**：
```php
// 初始化自动加载
Autoload::loading();
```

### importClass()

```php
/**
 * 自动导入类文件
 * 
 * 处理类的自动加载，支持多种命名空间格式
 * 搜索顺序包括项目内置扩展、框架内置扩展、外置扩展等
 * 实现了缓存机制以减少 IO 操作，提升性能
 * 
 * @param string $className 要加载的类名（包含命名空间）
 * @return void 无返回值
 * @static 静态方法，可通过类名直接调用
 * @access public 公开方法
 */
public static function importClass(string $className): void
```

**功能说明**：根据类名（包含命名空间）解析对应的文件路径，支持多种命名空间规则，包括 Candy 核心类、APP 项目类、外部类库等，并实现类文件缓存机制提高性能。

**参数说明**：
- `$className`：要加载的类名，包含完整的命名空间路径

**使用示例**：
```php
// 自动被 PHP 调用，不需要手动调用
// 当使用未加载的类时，PHP 会自动调用此方法
$instance = new Some\Namespace\Class();
```

**支持的命名空间规则**：
- `Candy/Extend/XXX/XXX`：框架核心类库
- `Candy/APP/XXX/XXX/XXX`：项目类库（控制类、模型类等）
- 单斜杠或无斜杠：项目内置扩展类、框架内置扩展类、外置扩展类
- 特殊类库路径映射（通过 `$specialClass` 配置）

**缓存机制**：
- 使用静态数组 `$_autoLoadFiles` 记录已加载的类，避免重复加载
- 支持 `requireCache()` 函数加载缓存的类文件

## 作者信息

- **作者**: 刘森
- **创建日期**: 2019-07-25 10:00:00