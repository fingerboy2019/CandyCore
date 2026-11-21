# Container

## 类简介
Container 类是 Candy 框架的依赖注入容器，实现 PSR 标准和多种接口。提供类的自动加载、实例化和依赖注入功能。支持单例模式、工厂模式和依赖解析。实现了 ArrayAccess 接口，支持数组式访问容器中的对象。同时实现了 Countable 和 IteratorAggregate 接口，支持容器实例的计数和迭代。

### 功能特点
- 自动依赖解析和注入
- 对象实例缓存与单例管理
- 类、闭包、接口实现的绑定
- 反射 API 的封装与优化
- 缓存系统集成，支持容器数据持久化
- 支持多种绑定方式（数组批量绑定、闭包绑定、对象绑定、类名绑定）
- 实例化后回调函数支持

## 使用示例
```php
// 获取容器实例
$container = Container::getInstance();

// 绑定类到容器
$container->bind('app', App::class);

// 获取类的实例
$app = $container->make('app');

// 数组式访问
$container['db'] = new Database();
$db = $container['db'];

// 魔术方法访问
$container->cache = new Cache();
$cache = $container->cache;

// 实例绑定
$container->instance('logger', new Logger());

// 依赖注入示例
class UserService {
    protected Database $db;
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
}

// 自动解析依赖
$userService = $container->make(UserService::class);

// 调用函数并自动注入依赖
$result = $container->invoke(function(Database $db) {
    return $db->query('SELECT * FROM users');
});

// 调用方法并自动注入依赖
$dbService = $container->make(Database::class);
$result = $container->invokeMethod($dbService, 'query', ['SELECT * FROM users']);
```

## 命名空间
```php
namespace Candy\Core;
```

## 类定义
```php
class Container implements \ArrayAccess
{
    // 类主体...
}
```

## 类成员变量

### $bind
```php
/**
 * 容器绑定标识，存储类名/标识与实际实现的映射关系
 * 
 * @var array<string, mixed> 键为类名或标识，值为类名、闭包或对象实例
 * @access protected 保护属性，类内部可访问
 */
protected array $bind = [];
```

### $reflectionbind
```php
/**
 * 反射类缓存，优化性能，避免重复创建反射对象
 * 
 * @var array<string, \ReflectionClass> 键为"Reflection"+ 类名，值为反射类实例
 * @access protected 保护属性，类内部可访问
 */
protected array $reflectionbind = [];
```

### $instance
```php
/**
 * 容器单例实例
 * 
 * @var Container|null 容器单例对象
 * @access protected static 静态保护属性，类内部可访问
 */
protected static ?Container $instance = null;
```

### $instances
```php
/**
 * 容器中已创建的对象实例缓存
 * 
 * @var array<string, object> 键为类名或标识，值为对应的对象实例
 * @access protected 保护属性，类内部可访问
 */
protected array $instances = [];
```

### $parameter
```php
/**
 * 容器全局参数
 * 
 * @var array<string, mixed> 存储容器级别的配置参数
 * @access protected 保护属性，类内部可访问
 */
protected array $parameter = [];
```

### $invokeCallback
```php
/**
 * 容器实例化后的回调函数列表
 * 
 * @var array<string, array<callable>> 键为类名 (支持通配符*)，值为回调函数数组
 * @access protected 保护属性，类内部可访问
 */
protected array $invokeCallback = [];
```

### $cache
```php
/**
 * 缓存对象，用于持久化存储容器数据
 * 
 * @var Cache|false 缓存对象实例或 false(表示未启用缓存)
 * @access protected static 静态保护属性，类内部可访问
 */
protected static Cache|false $cache = false;
```

## 主要方法

### getInstance()
```php
/**
 * 获取容器单例实例
 * 
 * 实现单例模式，确保全局只有一个容器实例。
 * 在非 CLI 模式下，会初始化缓存系统以支持容器数据持久化。
 * 
 * @return Container 容器单例实例
 */
public static function getInstance(): object
```

### make()
```php
/**
 * 创建类的实例，已经存在则直接获取
 * 
 * 根据类名或标识创建或获取对象实例，支持自动依赖注入。
 * 如果指定的实例已存在且不强制创建新实例，则直接返回现有实例。
 * 支持实例化后立即执行指定的方法链。
 * 
 * @param string $abstract 类名或者标识
 * @param array $vars 构造函数参数
 * @param bool $newInstance 是否每次创建新的实例
 * @param array $invokeFunction 实例化后要调用的方法数组，格式为[[方法名，[参数]], ...]
 * @return mixed 创建的对象实例或类名 (静态类)
 */
public function make(string $abstract, array $vars = [], bool $newInstance = false, array $invokeFunction = []): mixed
```

### delete()
```php
/**
 * 从容器中删除指定的实例
 * 
 * 从容器实例缓存中删除指定的对象，同时更新持久化缓存 (如果启用)。
 * 
 * @param string $name 类名或者标识
 * @return void
 */
public function delete(string $name): void
```

### getAlias()
```php
/**
 * 根据别名获取真实类名
 * 
 * 递归解析别名，直到获取到真实的类名。
 * 支持多级别名嵌套，确保最终获取到实际的实现类名。
 * 
 * @param string $abstract 类名或标识别名
 * @return string 解析后的真实类名
 */
public function getAlias(string $abstract): string
```

### instance()
```php
/**
 * 绑定一个类实例到容器
 * 
 * 将对象实例直接存储到容器中，同时更新绑定和实例缓存，供后续使用。
 * 支持链式调用，方便进行多个绑定操作。
 * 
 * @param string $abstract 类名或者标识
 * @param object $instance 类的实例
 * @return Container 当前容器实例，支持链式调用
 */
public function instance(string $abstract, object $instance): Container
```

### invokeFunction()
```php
/**
 * 执行函数或者闭包方法，支持参数调用
 * 
 * 使用反射机制执行函数或闭包，自动处理参数绑定。
 * 支持传入自定义参数，当参数不足时会使用默认值。
 * 
 * @param string|\Closure $function 函数名或者闭包对象
 * @param array $vars 调用参数数组
 * @return mixed 函数执行结果
 * @throws \Exception 当函数不存在时抛出异常
 */
public function invokeFunction(string|\Closure $function, array $vars = []): mixed
```

### invokeMethod()
```php
/**
 * 调用反射执行类的方法，支持参数绑定
 * 
 * 使用反射机制调用类的方法，支持实例方法和静态方法。
 * 自动处理参数绑定，并支持将私有方法设为可访问 (用于私有/保护方法)。
 * 
 * @param mixed $method 方法名或数组格式的 [类，方法名]
 * @param array $vars 调用参数数组
 * @param bool $accessible 是否强制将方法设为可访问 (用于私有/保护方法)
 * @return mixed 方法执行结果
 * @throws \ReflectionException 当方法不存在时抛出异常
 */
public function invokeMethod(mixed $method, array $vars = [], bool $accessible = false): mixed
```

### invoke()
```php
/**
 * 调用反射执行 callable，支持参数绑定
 * 
 * 智能识别 callable 类型并调用相应的执行方法。
 * 支持闭包、普通函数和类方法三种形式的可调用对象。
 * 
 * @param mixed $callable 闭包、函数名或类方法名
 * @param array $vars 调用参数数组
 * @param bool $accessible 是否强制将方法设为可访问 (用于私有/保护方法)
 * @return mixed 执行结果
 */
public function invoke(mixed $callable, array $vars = [], bool $accessible = false): mixed
```

### invokeReflectionClass()
```php
/**
 * 获取反射类
 * 
 * 创建并缓存类的反射对象，避免重复创建提高性能。
 * 如果类不存在则显示错误信息并终止执行。
 * 
 * @param string $class 类名
 * @return \ReflectionClass 反射类实例
 * @throws \ReflectionException 当类不存在时抛出异常
 */
public function invokeReflectionClass(string $class): \ReflectionClass
```

### invokeClass()
```php
/**
 * 调用反射执行类的实例化，支持依赖注入
 * 
 * 使用反射机制创建类的实例，自动处理构造函数参数依赖注入。
 * 支持特殊的__make 静态方法，并在实例化后执行注册的回调函数。
 * 对于应用类还会进行属性注释的权限检查。
 * 
 * @param string $class 类名
 * @param array $vars 构造函数参数
 * @return mixed 创建的对象实例或类名 (静态类)
 */
public function invokeClass(string $class, array $vars = []): mixed
```

### bindParams()
```php
/**
 * 绑定参数
 * 
 * 根据反射信息自动绑定函数或方法的参数。
 * 支持按位置绑定和按名称绑定两种模式。
 * 当参数不足时，会尝试使用参数的默认值。
 * 
 * @param \ReflectionFunctionAbstract $reflect 反射函数/方法对象
 * @param array $vars 参数数组
 * @return array 绑定后的参数数组
 */
public function bindParams(\ReflectionFunctionAbstract $reflect, array $vars = []): array
```

### get()
```php
/**
 * 获取容器中的对象实例，不存在则创建
 * 
 * 检查并获取容器中已存在的对象实例，不存在则创建新实例。
 * 与 make 方法不同，此方法不会检查实例是否已存在。
 * 
 * @param string $abstract 类名或者标识
 * @return mixed 对象实例
 */
public function get(string $abstract): mixed
```

### bind()
```php
/**
 * 绑定一个类、闭包、实例、接口实现到容器
 * 
 * 支持多种绑定方式：
 * - 数组绑定：批量绑定多个类或接口
 * - 闭包绑定：注册可执行的闭包函数
 * - 对象绑定：直接绑定对象实例
 * - 类名绑定：绑定接口到具体实现类
 * 
 * @param string|array $abstract 类标识、接口名或绑定数组
 * @param mixed $concrete 要绑定的类、闭包或者实例
 * @return Container 当前容器实例，支持链式调用
 */
public function bind(string|array $abstract, mixed $concrete = null): Container
```

### setParameter()
```php
/**
 * 设置容器参数
 * 
 * 用于存储和管理容器中的全局配置参数。
 * 会清空现有参数并设置新的参数字典。
 * 
 * @param array $data 参数字典数组
 * @return void
 */
public function setParameter(array $data = []): void
```

### bound()
```php
/**
 * 判断容器中是否存在类及标识
 * 
 * 检查指定的类名或标识是否已在容器中绑定或已有实例。
 * 同时检查绑定缓存和实例缓存。
 * 
 * @param string $abstract 类名或者标识
 * @return bool 是否存在绑定或实例
 */
public function bound(string $abstract): bool
```

### has()
```php
/**
 * 判断容器中是否存在类及标识
 * 
 * has 方法是 bound 方法的别名，用于保持接口一致性。
 * 实现 PSR 容器接口的 has 方法。
 * 
 * @param string $name 类名或者标识
 * @return bool 是否存在绑定或实例
 */
public function has(string $name): bool
```

### exists()
```php
/**
 * 判断容器中是否存在对象实例
 * 
 * 检查指定的类名或标识是否已有实例存在于实例缓存中。
 * 与 bound/has 方法不同，此方法仅检查实例缓存。
 * 
 * @param string $abstract 类名或者标识
 * @return bool 是否存在实例
 */
public function exists(string $abstract): bool
```

## 魔术方法

### __set()
```php
/**
 * 魔术方法：设置容器元素
 * 
 * 当使用赋值操作符设置属性时触发，将绑定操作转换为属性设置语法。
 * 例如：$container->app = new App(); 等效于 $container->bind('app', new App());
 * 
 * @param string $name 类名或标识
 * @param mixed $value 要绑定的值
 * @return void
 */
public function __set(string $name, mixed $value): void
```

### __get()
```php
/**
 * 魔术方法：获取容器元素
 * 
 * 当访问容器属性时触发，将属性访问转换为获取操作。
 * 例如：$app = $container->app; 等效于 $app = $container->get('app');
 * 
 * @param string $name 类名或标识
 * @return mixed 获取的对象或 null
 */
public function __get(string $name): mixed
```

### __isset()
```php
/**
 * 魔术方法：检查元素是否存在
 * 
 * 当使用 isset() 函数检查容器属性时触发，检查实例是否存在。
 * 例如：isset($container->app) 等效于 $container->exists('app');
 * 
 * @param string $name 类名或标识
 * @return bool 是否存在实例
 */
public function __isset(string $name): bool
```

### __unset()
```php
/**
 * 魔术方法：删除容器元素
 * 
 * 当使用 unset() 函数删除容器属性时触发，从实例缓存中删除对象。
 * 例如：unset($container->app) 等效于 $container->delete('app');
 * 
 * @param string $name 类名或标识
 * @return void
 */
public function __unset(string $name): void
```

## ArrayAccess 接口方法

### offsetExists()
```php
/**
 * ArrayAccess 接口：检查偏移量是否存在
 * 
 * 当使用 isset() 函数检查数组访问时触发，实现数组式接口。
 * 例如：isset($container['app']) 等效于 $container->exists('app');
 * 
 * @param mixed $key 键名
 * @return bool 是否存在实例
 */
public function offsetExists(mixed $key): bool
```

### offsetGet()
```php
/**
 * ArrayAccess 接口：获取偏移量的值
 * 
 * 当使用数组访问读取值时触发，实现数组式接口。
 * 例如：$app = $container['app'] 等效于 $app = $container->make('app');
 * 
 * @param mixed $key 键名
 * @return mixed 获取的对象
 */
public function offsetGet(mixed $key): mixed
```

### offsetSet()
```php
/**
 * ArrayAccess 接口：设置偏移量的值
 * 
 * 当使用数组访问设置值时触发，实现数组式接口。
 * 例如：$container['app'] = new App() 等效于 $container->bind('app', new App());
 * 
 * @param mixed $key 键名
 * @param mixed $value 要绑定的值
 * @return void
 */
public function offsetSet(mixed $key, mixed $value): void
```

### offsetUnset()
```php
/**
 * ArrayAccess 接口：删除偏移量
 * 
 * 当使用 unset() 函数删除数组元素时触发，实现数组式接口。
 * 例如：unset($container['app']) 等效于 $container->delete('app');
 * 
 * @param mixed $key 键名
 * @return void
 */
public function offsetUnset(mixed $key): void
```

## Countable 接口方法

### count()
```php
/**
 * Countable 接口：获取容器中实例的数量
 * 
 * 当使用 count() 函数计算容器时触发，实现 Countable 接口。
 * 例如：count($container) 返回容器中实例的数量。
 * 
 * @return int 实例数量
 */
public function count(): int
```

## IteratorAggregate 接口方法

### getIterator()
```php
/**
 * IteratorAggregate 接口：获取迭代器
 * 
 * 允许容器对象被 foreach 遍历，实现 IteratorAggregate 接口。
 * 例如：foreach($container as $key => $instance) {...}
 * 
 * @return \ArrayIterator 数组迭代器实例
 */
public function getIterator(): object
```

## 静态方法

### pull()
```php
/**
 * 静态方法获取容器实例
 * 
 * 静态方法，直接通过容器单例获取或创建对象实例。
 * 提供便捷的静态调用方式，内部调用 make 方法实现。
 * 
 * @param string $abstract 类名或者标识
 * @param array $vars 构造函数参数
 * @param bool $newInstance 是否每次创建新的实例
 * @param array $invokeFunction 实例化后要调用的方法数组
 * @return object 创建的对象实例
 */
public static function pull(string $abstract, array $vars = [], bool $newInstance = false, array $invokeFunction = []): object
```

## 内部方法

### getObjectParam()
```php
/**
 * 获取对象类型的参数值
 * 
 * 从参数数组中获取指定类型的对象，如果不存在则自动创建。
 * 用于在参数绑定时处理对象类型的依赖注入。
 * 
 * @param string $className 类名
 * @param array $vars 参数数组 (引用传递)
 * @return mixed 对象实例
 */
protected function getObjectParam(string $className, array &$vars): mixed
```

### invokeAfter()
```php
/**
 * 执行 invokeClass 回调
 * 
 * 在类实例化后执行注册的回调函数。
 * 支持全局回调 (适用于所有类) 和特定类回调。
 * 
 * @param string $class 对象类名
 * @param object $object 容器对象实例
 * @return void
 */
protected function invokeAfter(string $class, object $object): void
```

### getCache()
```php
/**
 * 获取缓存数据
 * 
 * @param string $key 缓存键名
 * @param mixed $default 默认值
 * @return mixed 缓存数据或默认值
 */
private function getCache(string $key, $default = null): mixed
```

### setCache()
```php
/**
 * 设置缓存数据
 * 
 * @param string $key 缓存键名
 * @param mixed $value 要缓存的值
 * @return bool 操作是否成功
 */
private function setCache(string $key, $value): bool
```

## 使用说明
### 基本用法
```php
// 获取容器实例
$container = Container::getInstance();

// 绑定类到容器
$container->bind('app', App::class);

// 获取类的实例
$app = $container->make('app');

// 数组式访问
$container['db'] = new Database();
$db = $container['db'];

// 魔术方法访问
$container->cache = new Cache();
$cache = $container->cache;

// 实例绑定
$container->instance('logger', new Logger());
```

### 依赖注入示例
```php
class UserService {
    protected Database $db;
    
    public function __construct(Database $db) {
        $this->db = $db;
    }
}

// 自动解析依赖
$userService = $container->make(UserService::class);
```

### 回调函数调用
```php
// 调用函数并自动注入依赖
$result = $container->invoke(function(Database $db) {
    return $db->query('SELECT * FROM users');
});
```

### 批量绑定
```php
// 批量绑定多个类
$container->bind([
    'cache' => Cache::class,
    'logger' => Logger::class,
    'db' => Database::class
]);
```

## 注意事项
1. 容器使用单例模式，确保全局只有一个容器实例
2. 实例默认会被缓存，可以通过设置$newInstance 参数来创建新实例
3. 支持链式调用，方便进行连续操作
4. 实现了多种标准接口，提供了灵活的访问方式
5. 使用反射机制进行依赖解析，提高了代码的灵活性和可维护性
6. 在非 CLI 模式下，会自动初始化缓存系统以支持容器数据持久化
7. 数组访问会自动创建实例，与魔术方法访问行为不同
8. 实例缓存会在 make() 方法中被使用，只有当 newInstance 为 false 时才会缓存

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2023-10-23 10:43:14