# Annotation 类

## 类简介

用于在Candy框架中定义和处理注解（Annotations），支持路由定义、权限验证、视图指定等多种功能。该类实现了PHP的Attribute特性，可重复应用于类、方法、属性等元素。

**主要功能：**
- 支持定义路由路径、请求方法和中间件
- 提供权限验证标识
- 支持视图模板指定
- 可配置路由名称和日志记录
- 支持菜单定义

## 命名空间

规范实现：

```php
namespace Candy\Extend\Attribute\Annotations;
```

全局桥接（应用层 `#[Annotation]` 无 `use` 时自动加载）：

```php
// Candy/Class/Vendor/Annotation.class.php
class_alias(
    \Candy\Extend\Attribute\Annotations\Annotation::class,
    'Annotation'
);
```

## 类定义

```php
#[\Attribute(\Attribute::TARGET_ALL | \Attribute::IS_REPEATABLE)]
class Annotation
{
    // 类实现...
}
```

## 主要属性

### $args

```php
/**
 * 存储注解参数的私有数组
 * 
 * 用于保存通过构造函数传入的所有注解参数，键值对格式存储原始参数。
 * 在get()方法中会被处理成键名首字母大写的格式返回。
 * 
 * @var array 注解参数数组，如['title' => 'Index', 'auth' => true, 'menu' => true]
 */
private $args = [];
```

## 主要方法

### __construct(...$params)

```php
/**
 * 构造函数 - 初始化注解参数
 * 
 * 接受多个命名参数，用于配置注解的各种属性。参数以键值对形式传入。
 * 
 * @param array $params 注解参数数组，支持以下常用参数：
 *                      - string $title: 接口或路由标题描述
 *                      - string $path: 路由路径，如'/api/user'
 *                      - string|array $request: 请求方法，如'GET', 'POST'或['GET', 'POST']
 *                      - string|array $middleware: 路由中间件，可以是单个中间件名称或数组
 *                      - bool|string $auth: 权限验证标识，true表示需要验证，也可指定具体权限名
 *                      - string $view: 视图模板标识
 *                      - string $name: 路由名称，用于反向生成URL
 *                      - bool $menu: 是否在菜单中显示
 *                      - bool $log: 是否记录操作日志
 * 
 * @example
 * \```php
 * // 基本路由定义
 * #[Annotation(title: '获取用户列表', path: '/api/users', request: 'GET')]
 * 
 * // 完整配置示例
 * #[Annotation(
 *     title: '创建用户',
 *     path: '/api/users',
 *     request: 'POST',
 *     middleware: ['Auth', 'Log'],
 *     auth: 'user.create',
 *     name: 'user.create'
 * )]
 * \```
 */
public function __construct(...$params) {
    $keys = array_keys($params);
    $values = array_values($params);
    foreach($keys as $i=>$key){
        $this->args[$key] = $values[$i];
    }
}
```

### get()

```php
/**
 * 获取注解数据
 * 
 * 返回格式化后的注解参数数组。将注解参数的键名首字母大写，并确保关键标志参数
 * （Auth、Menu、Log）始终存在且为布尔类型。
 * 
 * @return array 格式化后的注解数据数组，包含以下特性：
 *              - 所有键名首字母大写
 *              - 始终包含Auth、Menu、Log键，值为布尔类型
 *              - 保留原始传递的所有其他参数，但键名已首字母大写
 * 
 * @example
 * \```php
 * $annotation = new Annotation(title: 'Test', auth: true, menu: false);
 * $data = $annotation->get();
 * // $data结构为:
 * // [
 * //     'Title' => 'Test',
 * //     'Auth' => true,
 * //     'Menu' => false,
 * //     'Log' => false
 * // ]
 * \```
 */
public function get(): array
{
    $tmpArgs = [];
    foreach($this->args as $key=>$value){
        $tmpArgs[ucfirst($key)] = $value;
    }
    
    //保留参数
    foreach(['Auth','Menu','Log'] as $key){
        $tmpArgs[$key] = isset($this->args[$key]) ? $this->args[$key] === true : false;
    }
    
    return $tmpArgs;
}
```

### data()

```php
/**
 * 获取应用中所有注解数据
 * 
 * 静态方法，用于获取整个应用中所有带有Annotation注解的方法的数据。
 * 该方法内部调用Node::getMethodsData()来收集和整理所有注解信息。
 * 
 * @return array 应用中所有注解数据的数组，包含所有控制器和方法的注解信息
 *              数据结构由Node类的getMethodsData()方法定义
 * 
 * @see \Candy\Core\Node::getMethodsData()
 * 
 * @example
 * \```php
 * // 获取所有注解数据，用于路由注册或文档生成
 * $allAnnotations = Annotation::data();
 * 
 * // 遍历所有注解数据
 * foreach ($allAnnotations as $controller => $methods) {
 *     foreach ($methods as $method => $annotation) {
 *         // 处理每个方法的注解数据
 *         echo "控制器: $controller, 方法: $method, 路由: {$annotation['Path']}\n";
 *     }
 * }
 * \```
 */
public static function data(): array
{
    return \Candy\Core\Node::getMethodsData();
}
```

## 内部方法

此类没有内部方法。

## 使用说明

### 基本用法

**1. 在控制器方法上使用注解**

```php
#[Annotation(title: '用户列表', path: '/user/list', method: 'GET', auth: true, menu: true)]
public function list() {
    // 方法实现...
}
```

**2. 自定义路由 - 带参数的复杂路由**

```php
#[Annotation(title: '用户详情', path: '/user/{id}', method: 'GET', auth: true)]
public function detail($id) {
    // 方法实现...
}
```

**3. 表单提交 - POST请求处理**

```php
#[Annotation(title: '添加用户', path: '/user/add', method: 'POST', auth: true)]
public function add(Request $request) {
    // 方法实现...
}
```

**4. 获取注解数据 - 在框架内部使用**

```php
$annotation = new Annotation(['title' => '测试', 'path' => '/test']);
$data = $annotation->get();
```

**5. 获取所有注解数据 - 用于路由注册**

```php
$allAnnotations = Annotation::data();
foreach ($allAnnotations as $controller => $methods) {
    foreach ($methods as $method => $annotation) {
        // 注册路由
        app()->router->addRoute(
            $annotation['Method'],
            $annotation['Path'],
            [$controller, $method],
            $annotation
        );
    }
}
```

## 注意事项

1. **参数命名规范**: 使用小驼峰命名法（如title, path, auth）
2. **必填参数**: 对于API接口，title和path是必填的
3. **权限控制**: 敏感操作请设置auth=true
4. **菜单展示**: 需要在后台菜单显示的功能设置menu=true
5. **日志记录**: 重要操作设置log=true便于审计
6. **路由格式**: 使用RESTful风格的路由格式
7. **方法规范**: 建议在方法前添加访问控制和参数验证

## 作者信息

作者：Candy框架开发团队  
日期：2024-04-17