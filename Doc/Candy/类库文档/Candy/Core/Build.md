# Build 类

## 类简介

Build 类是 Candy 框架的应用构建类，负责创建项目结构、应用程序、扩展插件等系统组件。提供自动化的目录创建、文件生成、项目初始化功能，是框架初始化和应用创建的核心组件。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Candy 框架 应用构建类
 * 
 * 系统项目生成器核心类，负责创建项目结构、应用程序、扩展插件等系统组件
 * 提供自动化的目录创建、文件生成、项目初始化功能
 * 
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-07-27 10:00:00
 * @final 该类设计为最终类，不允许继承
 */
final class Build {
    // 类成员
}
```

## 主要方法

### touch()

```php
/**
 * 创建或写入文件
 * 
 * 该方法用于在指定路径创建文件并写入内容，如果文件已存在则跳过操作
 * 自动创建文件所在的目录结构，并设置适当的文件权限
 * 
 * @param string $fileName 需要创建的文件完整路径
 * @param string $str 需要写入文件的内容字符串
 * @return void
 * @access public
 * @static
 */
public static function touch(string $fileName, string $str): void
```

**功能说明：**
- 如果文件不存在，则创建目录结构并写入内容
- 如果文件已存在，则跳过操作
- 创建的文件会自动设置权限为 0644
- 记录操作信息到 `$mess` 数组

### start()

```php
/**
 * 开始创建项目或初始化系统
 * 
 * 该方法是框架初始化的核心入口，负责创建整个项目的目录结构、配置文件和基础类文件
 * 执行流程：
 * 1. 检查初始化锁文件，避免重复初始化
 * 2. 处理 MD 相关功能
 * 3. 创建运行时目录
 * 4. 根据请求参数创建不同类型的应用（主应用、扩展或插件）
 * 5. 生成各种配置文件和基础类文件
 * 6. 记录初始化日志
 * 
 * @return void
 * @access public
 * @static
 */
public static function start(): void
```

**功能说明：**
- 检查初始化锁文件，避免重复初始化
- 如果锁文件不存在但 Home 目录已创建，说明是手动清理了 Runtime 缓存，此时只重建锁文件
- 根据 GET 参数决定是否创建项目
- 支持通过 URL 参数触发创建（如 `?start`、`?add`、`?name` 等）
- 创建框架配置文件、配置初始化文件、全局路由、初始化文件等

### blogInit()

```php
/**
 * 创建 MD-Blog 的配置文件和基本结构
 * 
 * 该方法用于初始化 Markdown 博客系统所需的基础文件和目录结构
 * 包括以下组件：
 * 1. 博客配置文件
 * 2. 默认首页文件
 * 3. 默认文章分类目录和文件
 * 4. 各类视图模板文件（首页、文章、分页、404）
 * 
 * @return bool 是否初始化成功，成功返回 true
 * @access public
 * @static
 */
public static function blogInit(): bool
```

**功能说明：**
- 创建博客配置文件 `Source/Inc/BlogConfig.inc.php`
- 创建默认首页 `Doc/Blog/post/README.md`
- 创建默认分类目录 `Doc/Blog/post/默认分类/README.md`
- 创建样式模板文件（`views/index.tpl.php`、`views/post.tpl.php`、`views/list.tpl.php`、`views/404.tpl.php`）

## 内部方法

### mkdir()

```php
/**
 * 创建目录结构
 * 
 * 该方法用于批量创建目录，确保每个指定的目录都存在
 * 自动为创建的目录设置适当的访问权限（0755）
 * 如果目录已存在则跳过操作
 * 
 * @param array $dirs 要创建的目录路径数组
 * @return void
 * @access private
 * @static
 */
private static function mkdir(array $dirs): void
```

**功能说明：**
- 批量创建目录结构
- 使用 `\Candy\Extend\Dir::create()` 创建目录
- 新创建的目录自动设置权限为 0755
- 记录成功创建的目录信息到 `$mess` 数组

### runtime()

```php
/**
 * 创建系统运行时的目录和文件
 * 
 * 该方法负责创建框架运行所必需的临时文件和缓存目录
 * 包括缓存目录、模板编译目录、控制器缓存、模型缓存等
 * 根据当前环境（普通应用或插件应用）创建不同的目录结构
 * 
 * @return void
 * @access private
 * @static
 */
private static function runtime(): void
```

**功能说明：**
- 创建系统运行时必需的目录：
  - `Runtime/` - 系统缓存目录
  - `Runtime/Cache/` - 系统缓存目录
  - `Runtime/Comps/` - 模板组合文件目录
  - `Runtime/Data/cache/` - 文件缓存数据目录
  - `Runtime/Controls/` - 控制器缓存目录
  - `Runtime/Models/` - 模型缓存目录
  - `Runtime/Logs/` - 日志目录
  - `Runtime/Tmps/` - 临时文件目录
- 如果是普通应用，还会创建：
  - `Runtime/Cache/{TMPPATH}/` - 应用级缓存目录
  - `Runtime/Comps/{TMPPATH}/{TPLSTYLE}/` - 应用级模板组合文件
  - `Runtime/Controls/{TMPPATH}/` - 应用级控制器缓存
  - `Runtime/Models/{TMPPATH}/` - 应用级模型缓存
- 如果是插件应用，还会创建：
  - `Runtime/Cache/Addons/{APPNAME}/` - 插件缓存目录
  - `Runtime/Comps/Addons/{APPNAME}/` - 插件模板组合文件
  - `Runtime/Controls/Addons/{APPNAME}/` - 插件控制器缓存
  - `Runtime/Models/Addons/{APPNAME}/` - 插件模型缓存

### bulidSpaces()

```php
/**
 * 创建相应空间应用
 * 
 * 该方法用于创建不同类型的应用空间，包括主应用、插件和扩展
 * 根据传入的类型参数，创建相应的目录结构和基础文件
 * 支持三种类型：
 * - 空字符串：创建主应用
 * - 'addon'：创建扩展应用
 * - 'plug'：创建插件
 * 
 * @param string $name 应用名称，将转换为首字母大写的格式
 * @param string $type 应用类型，可选值：''(主应用)、'addon'(扩展)、'plug'(插件)
 * @return bool 是否创建成功
 * @access public
 * @static
 * @throws 当应用名称为空或应用已存在时，会输出错误信息并返回 false
 */
public static function bulidSpaces(string $name, string $type = ''): bool
```

**功能说明：**

#### 创建扩展（addon）：
- 检查项目是否开启了 Addon 功能
- 验证扩展名称不能为空
- 检查扩展是否已存在
- 创建扩展目录结构并复制 demo 示例
- 替换命名空间中的 Demo 为实际扩展名称

#### 创建插件（plug）：
- 验证插件名称不能为空
- 检查插件是否已存在
- 创建插件目录结构（Controls/Home、Languages/Home、Models/Home、Views/Home/{TPLSTYLE}/Resource/*）
- 复制 Hook 示例和配置文件

#### 创建主应用：
- 定义模板后缀和分离符（CLI 模式或 Web 模式）
- 创建应用目录结构（Application/{APPNAME}/Models/、Vendor/、Controls/、Languages/zh-cn/）
- 创建扩展目录（PLUGPATH/{APPNAME}/Models/、Controls/、Languages/zh-cn/、Views/{TPLSTYLE}/Resource/*）
- 创建模板目录（Views/{TPLSTYLE}/Resource/css/、js/、images/）
- 如果分离符为 '/'，还会创建 Public/ 和 Index/ 目录
- 创建首页模板（Index/index.tpl.php）
- 创建控制器基类（Controls/Common.class.php）
- 创建控制器示例（Controls/Index.class.php）
- 创建语言文件（Common.lang.php、Index.lang.php）
- 创建路由配置文件（{APPNAME}Route.inc.php）
- 生成初始化锁文件

## 类成员变量

### mess

```php
/**
 * 提示消息数组，用于记录构建过程中的各种操作信息
 * 
 * @var array
 * @access private
 * @static
 */
private static $mess = [];
```

## 工作原理

Build 类的工作流程主要包括以下几个部分：

1. **目录结构创建**：通过 `mkdir()` 方法批量创建所需的目录结构，确保项目各组件有正确的文件组织
2. **文件生成**：使用 `touch()` 方法创建各种配置文件和基础类文件，并写入初始化内容
3. **运行时环境准备**：`runtime()` 方法创建缓存目录、临时文件目录等运行时必需的环境
4. **应用初始化**：`start()` 方法作为核心入口，协调整个初始化过程，根据不同参数创建不同类型的应用
5. **MD-Blog 初始化**：`blogInit()` 方法专门用于 Markdown 博客系统的初始化

## 使用场景

Build 类主要在以下场景中使用：

- 框架首次运行时自动初始化项目结构
- 通过 URL 参数触发创建新的应用或扩展
- 生成项目所需的配置文件和基础类文件
- 创建插件、扩展和主应用的空间结构
- MD-Blog 博客系统的初始化

## 注意事项

- Build 类被设计为最终类（final），不允许继承
- 通常不需要手动调用此类，框架会在适当的时候自动使用
- 初始化操作会生成锁文件，避免重复初始化
- 创建的文件和目录会自动设置适当的权限
- 如果应用名称为空或应用已存在，会输出错误信息并返回 false
- 扩展功能需要项目开启 Addon 功能才能使用

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-07-27 10:00:00