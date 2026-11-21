# Node 类

## 类简介

Node 类是 Candy 框架中的权限节点管理系统核心类，用于管理框架中的权限节点、菜单节点和访问控制，提供节点验证、菜单生成、权限检查等功能。
支持应用 (APP) 和插件 (Addon) 的权限节点统一管理，具备缓存机制提高性能。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
/**
 * Node 类 - Candy 框架权限节点管理系统
 * 
 * 用于管理框架中的权限节点、菜单节点和访问控制，提供节点验证、菜单生成、权限检查等核心功能。
 * 支持应用 (APP) 和插件 (Addon) 的权限节点统一管理，具备缓存机制提高性能。
 * 该类不允许被继承。
 */
final class Node {
    // 类成员
}
```

## 类成员

- **$methods**: 操作方法列表缓存，用于存储系统中所有需要权限验证的节点方法列表

## 主要方法

### full()

```php
/**
 * 获取标准访问节点
 * 
 * 将输入的节点路径标准化，确保节点格式为完整的三段式路径：app/control/action
 * 支持处理三种情况：
 * 1. 空节点：返回当前访问节点
 * 2. 单段节点：自动补全为当前应用和控制器下的方法
 * 3. 两段节点：自动补全为当前应用下的控制器和方法
 * 4. 三段节点：直接处理并返回标准化格式
 * 
 * @param string|null $node 节点路径，可以是 null、单段、两段或三段格式
 * @return string 标准化后的完整节点路径 (app/control/action)
 */
public static function full(?string $node = null): string
```

### current()

```php
/**
 * 获取当前访问节点
 * 
 * 根据当前请求上下文，组合应用名称、控制器和操作方法，生成当前访问的完整节点路径
 * 节点格式为：app/control/action
 * 
 * @return string 当前访问的完整节点路径
 */
public static function current(): string
```

### checkAuth()

```php
/**
 * 强制验证访问权限
 * 
 * 验证指定节点的访问权限，支持多种权限检查机制：
 * 1. 超级管理员 (Admin) 角色直接通过权限验证
 * 2. 检查控制器是否有 closeAuth 或__noAuth 方法 (表示无需权限验证)
 * 3. 检查方法注释中是否包含@Authtrue 标记 (表示需要权限验证)
 * 4. 根据用户拥有的权限节点列表进行验证
 * 
 * 兼容 JWT 模式：
 * - 优先从 JWT Token 获取用户角色和权限
 * - 如果 JWT 不可用，回退到 Session 模式
 * 
 * @param ?string $node 要验证的节点路径，null 表示当前节点
 * @param array $auths 用户权限节点数组，默认为空 (将自动从 JWT/Session 获取)
 * @return array 包含两个元素的数组：[是否需要权限检查，是否通过权限验证]
 */
public static function checkAuth(?string $node = null, array $auths = []): array
```

### getMethodsData()

```php
/**
 * 获取节点方法数据缓存
 * 
 * 优先从 Memcache 获取节点方法数据缓存，如果 Memcache 不可用，则从类静态变量获取
 * 此方法提供统一的缓存访问接口，确保在不同环境下都能正常工作
 * 
 * @return array 节点方法数据数组
 */
public static function getMethodsData(): array
```

### setMethodsData()

```php
/**
 * 设置节点方法数据缓存
 * 
 * 将节点方法数据保存到缓存中，优先使用 Memcache，如果不可用则保存到类静态变量
 * 此方法提供统一的缓存设置接口，确保数据一致性
 * 
 * @param array $data 要缓存的节点方法数据
 * @return void
 */
public static function setMethodsData(array $data): void
```

### saveMethodList()

```php
/**
 * 保存节点权限信息
 * 
 * 将新的节点权限列表合并到现有缓存中，使用数组合并方式保留原有数据并更新新增数据
 * 此方法用于动态添加新的权限节点到系统中
 * 
 * @param array $methodList 要保存的节点权限列表数组
 * @return void
 */
public static function saveMethodList(array $methodList = []): void
```

### getAppMenuList()

```php
/**
 * 获取所有 APP 菜单节点
 * 
 * 获取所有应用中的菜单节点，支持按配置的应用顺序排序
 * 只会返回在 appNode 配置中允许显示在菜单中的应用
 * 结果会被缓存以提高性能
 * 
 * @return array 包含所有 APP 菜单节点的数组，格式为 [appName=>['name'=>应用名称,'controls'=>菜单列表]]
 */
public static function getAppMenuList(): array
```

### getMenuList()

```php
/**
 * 获取菜单节点列表
 * 
 * 获取指定应用的菜单节点列表，支持权限验证过滤
 * 从控制器和方法的注释中解析菜单信息，根据@Menu 属性生成菜单
 * 在非调试模式下会缓存结果以提高性能
 * 
 * @param ?string $appName 应用名称，null 表示当前应用
 * @param bool $force 是否强制执行权限过滤，如果为 true 且用户非管理员，则只返回用户有权限的菜单
 * @return array 菜单节点列表，格式为 [group=>['name'=>分组名称,'menus'=>[节点路径=>菜单标题]]]
 */
public static function getMenuList(?string $appName = null, bool $force = false): array
```

### getAllNodeList()

```php
/**
 * 获取所有有效 APP 节点
 * 
 * 获取系统中所有应用 (APP) 和启用的插件 (Addon) 的节点信息
 * 扫描 Application 目录获取所有应用，并获取每个应用的控制器和方法列表
 * 结果会被缓存以提高性能
 * 
 * @return array 所有应用节点信息数组，格式为 [appName=>['name'=>应用名称,'controls'=>控制器列表]]
 */
public static function getAllNodeList(): array
```

### getAllAuthList()

```php
/**
 * 获取所有验证权限的节点
 * 
 * 获取系统中所有需要权限验证的节点列表，包括所有应用和插件
 * 合并应用权限节点和插件权限节点，提供统一的权限节点访问接口
 * 结果会被缓存以提高性能
 * 
 * @return array 所有权限节点数组，包含应用和插件的权限节点信息
 */
public static function getAllAuthList(): array
```

### takeAuthList()

```php
/**
 * 获取权限节点列表（扁平化）
 * 
 * 获取所有需要权限验证的节点列表，并将其扁平化为一维数组
 * 只返回节点路径，不包含标题信息，便于快速检查权限
 * 在非调试模式下会缓存结果以提高性能
 * 
 * @return array 扁平的权限节点路径数组
 */
public static function takeAuthList(): array
```

### getAllMenuList()

```php
/**
 * 获取所有菜单节点
 * 
 * 获取系统中所有的菜单节点列表，包括所有应用和插件
 * 合并应用菜单节点和插件菜单节点，提供统一的菜单节点访问接口
 * 结果会被缓存以提高性能
 * 
 * @return array 所有菜单节点数组，包含应用和插件的菜单节点信息
 */
public static function getAllMenuList(): array
```

### takeMenuList()

```php
/**
 * 获取菜单节点列表（按组分类）
 * 
 * 获取所有菜单节点，并按控制器分组组织
 * 便于前端按控制器分组展示菜单
 * 在非调试模式下会缓存结果以提高性能
 * 
 * @return array 按控制器分组的菜单节点数组，格式为 [group=>[节点路径=>菜单标题]]
 */
public static function takeMenuList(): array
```

### applyUserAuth()

```php
/**
 * 应用用户权限
 * 
 * 根据用户权限过滤系统节点，确保用户只能访问有权限的节点
 * 支持重置缓存，适用于用户权限变更后刷新权限
 * 超级管理员直接返回所有权限节点
 * 
 * @param array $auths 用户权限节点数组，默认为空 (将从 session 获取)
 * @param bool $force 是否强制重置所有缓存，默认为 false
 * @return array 用户实际拥有的有效权限节点数组
 */
public static function applyUserAuth(array $auths = [], bool $force = false): array
```

## 内部方法

以下是 Node 类中的内部方法（私有方法），仅供类内部使用：

### _getUserRole()

```php
/**
 * 获取当前用户角色（支持配置钩子自定义实现）
 * 
 * 优先级：
 * 1. 配置的钩子函数 getUserRole
 * 2. JWT Token（如果配置了 user_role_field）
 * 3. Session 回退
 * 
 * @return string 用户角色
 */
private static function _getUserRole(): string
```

### _getUserAuths()

```php
/**
 * 获取当前用户权限节点（支持配置钩子自定义实现）
 * 
 * 优先级：
 * 1. 配置的钩子函数 getUserAuths
 * 2. Redis 缓存权限（JWT 模式）
 * 3. Session 回退
 * 
 * @return array 用户权限节点数组
 */
private static function _getUserAuths(): array
```

### getAuthList()

```php
/**
 * 获取授权节点列表
 * 
 * 获取指定应用中需要权限验证的节点列表
 * 从控制器和方法的注释中解析权限信息，根据@Auth 属性生成权限节点
 * 在非调试模式下会缓存结果以提高性能
 * 
 * @param ?string $appName 应用名称，null 表示当前应用
 * @return array 权限节点列表，格式为 [group=>['name'=>分组名称,'auths'=>[节点路径=>权限标题]]]
 */
private static function getAuthList(?string $appName = null): array
```

### getAppAuthList()

```php
/**
 * 获取所有 APP 验证权限的节点
 * 
 * 获取系统中所有应用的权限验证节点列表
 * 只会返回在 appNode 配置中允许进行权限验证的应用
 * 结果会被缓存以提高性能
 * 
 * @return array 所有应用权限节点数组，格式为 [appName=>['name'=>应用名称,'list'=>权限列表]]
 */
private static function getAppAuthList(): array
```

### getAddonAuthList()

```php
/**
 * 获取所有 Addon 验证权限的节点
 * 
 * 获取系统中所有启用的插件 (Addon) 的权限验证节点列表
 * 只处理已经启用的插件，确保安全性
 * 结果会被缓存以提高性能
 * 
 * @return array 所有插件权限节点数组，包含各插件的权限节点信息
 */
private static function getAddonAuthList(): array
```

### getAddonMenuList()

```php
/**
 * 获取所有 Addon 菜单节点
 * 
 * 获取系统中所有启用的插件 (Addon) 的菜单节点列表
 * 只处理已经启用的插件，确保安全性
 * 结果会被缓存以提高性能
 * 
 * @return array 所有插件菜单节点数组，格式为 [appName=>['name'=>插件名称,'controls'=>菜单列表]]
 */
private static function getAddonMenuList(): array
```

## 使用说明

### 基本用法

Node 类主要在框架内部使用，通常不需要手动调用。以下是一些常用场景的使用示例：

1. **权限验证**：
   ```php
   // 检查当前节点权限
   list($needCheck, $passed) = Node::checkAuth();
   if($needCheck && !$passed) {
       // 没有权限访问
   }
   
   // 检查指定节点权限
   list($needCheck, $passed) = Node::checkAuth('admin/user/index');
   ```

2. **获取菜单列表**：
   ```php
   // 获取当前应用的菜单列表
   $menus = Node::getMenuList();
   
   // 获取指定应用的菜单列表，并强制进行权限过滤
   $menus = Node::getMenuList('admin', true);
   
   // 获取按控制器分组的菜单列表
   $groupMenus = Node::takeMenuList();
   ```

3. **获取权限节点**：
   ```php
   // 获取所有权限节点
   $auths = Node::getAllAuthList();
   
   // 获取扁平化的权限节点列表
   $flatAuths = Node::takeAuthList();
   
   // 获取节点方法数据缓存
   $methods = Node::getMethodsData();
   ```

4. **获取节点列表**：
   ```php
   // 获取所有应用节点
   $nodes = Node::getAllNodeList();
   
   // 获取所有菜单节点
   $allMenus = Node::getAllMenuList();
   ```

5. **应用用户权限**：
   ```php
   // 应用用户权限，权限变更后重置缓存
   $userAuths = Node::applyUserAuth($userPerms, true);
   ```

### 控制器权限注释使用

在控制器方法中使用注释来定义权限和菜单属性：

```php
/**
 * 用户列表
 * 
 * @Menu(true, '用户管理', 'admin')
 * @Auth(true)
 */
public function index()
{
    // 方法实现
}
```

## 注意事项

1. Node 类是 final 类，不允许被继承
2. 权限验证会优先从注释的@Auth 标记识别，也支持控制器的 closeAuth 或__noAuth 方法来跳过权限验证
3. 超级管理员 (Admin 角色) 默认拥有所有权限，无需进行权限验证
4. 在非调试模式下，节点数据会被缓存以提高性能，权限变更后需要调用 applyUserAuth(true) 重置缓存
5. 菜单和权限节点的生成依赖于控制器和方法的注释信息
6. 应用权限验证需要在 appNode 配置中指定哪些应用需要进行权限验证
7. 节点路径格式为三段式：app/control/action
8. 支持 APP 和 Addon 的统一权限管理，但只处理已启用的插件
9. 节点方法数据缓存优先使用 Memcache，如果不可用则使用类静态变量缓存

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-12-05 16:03:32