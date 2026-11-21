# Env 类

## 类简介

Env类是Candy框架中的环境变量配置文件管理类，提供对.env配置文件的读取、修改、删除等操作。该类支持分组配置管理、键名大小写转换，并能处理特殊值类型（如null、true、false），适用于动态管理应用程序配置。

## 命名空间

```php
namespace Candy\Extend;
```

## 类定义

```php
/**
 * Candy框架 .env配置文件管理类
 * 
 * 提供对.env配置文件的读取、修改、删除等操作，支持分组配置管理和键名大小写转换
 * 可用于动态管理应用程序配置，支持单个或批量操作环境变量
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2024-07-17 13:45:33
 * @final 该类不允许被继承
 */
final class Env
```

## 主要方法

### __construct()

```php
/**
 * 构造函数
 * 
 * 初始化Env类实例，设置配置文件路径、键名大小写类型，并读取.env文件内容
 * 
 * @uses G() 全局函数，用于获取配置信息
 * @uses [] 内部方法，用于读取.env文件内容到内存
 * @return void
 * @example
 * \```php
 * $env = new Env();
 * \```
 */
public function __construct()
```

### set()

```php
/**
 * 设置环境变量
 * 
 * 支持单个设置或批量设置环境变量，自动处理键名大小写转换和特殊值类型
 * 可以设置分组环境变量（使用点号分隔，如 "database.host"）
 * 
 * @param mixed $key 环境变量键名或键值对数组
 * @param mixed $val 环境变量值，当$key为数组时忽略此参数
 * @return int 更新的环境变量数量
 * @example
 * \```php
 * // 单个设置
 * $env->set('APP_NAME', 'MyApp');
 * // 批量设置
 * $env->set(['DEBUG' => true, 'CACHE' => false]);
 * // 设置分组环境变量
 * $env->set('database.host', 'localhost');
 * \```
 */
public function set(mixed $key, mixed $val = ""): int
```

### get()

```php
/**
 * 获取环境变量
 * 
 * 根据键名获取环境变量值，支持获取分组环境变量和设置默认值
 * 如果不指定键名，则返回所有环境变量数组
 * 
 * @param mixed $key 环境变量键名，为null时返回所有环境变量
 * @param mixed $default 当环境变量不存在时的默认值
 * @return mixed 环境变量值或默认值，当不指定键名时返回所有环境变量数组
 * @example
 * \```php
 * // 获取单个环境变量
 * $appName = $env->get('APP_NAME');
 * // 获取分组环境变量
 * $dbHost = $env->get('database.host');
 * // 获取所有环境变量
 * $allEnv = $env->get();
 * // 获取环境变量并设置默认值
 * $port = $env->get('DB_PORT', 3306);
 * \```
 */
public function get(mixed $key = null, mixed $default = ""): mixed
```

### del()

```php
/**
 * 删除环境变量
 * 
 * 支持单个删除或批量删除环境变量，可选择是否删除空分组
 * 
 * @param mixed $key 环境变量键名或键名数组
 * @param bool $delGroup 当分组为空时是否删除分组，默认为true
 * @param int $i 内部参数，用于递归计数
 * @return int 删除的环境变量数量
 * @example
 * \```php
 * // 删除单个环境变量
 * $env->del('APP_DEBUG');
 * // 批量删除环境变量
 * $env->del(['DB_HOST', 'DB_USER']);
 * // 删除分组环境变量但保留空分组
 * $env->del('database.host', false);
 * \```
 */
public function del(mixed $key, bool $delGroup = true, int $i = 0): int
```

## 内部方法

以下是Env类中的内部方法（受保护方法），通常不直接调用：

### save()

```php
/**
 * 保存环境变量到文件
 * 
 * 将内存中的环境变量数组写入到.env文件中，自动处理分组和特殊值
 * 
 * @param int $i 要保存的环境变量数量
 * @param string $ini 分组配置的字符串内容
 * @return int 保存的环境变量数量
 * @internal 通常由set()和del()方法内部调用，不建议直接使用
 */
protected function save(int $i = 0, string $ini = ""): int
```

### array()

```php
/**
 * 读取.env文件内容到内存
 * 
 * 从配置文件中读取环境变量并解析到内存数组中，支持分组和特殊值类型转换
 * 
 * @param bool $onGroup 是否启用分组解析，默认为true
 * @param bool $readType 读取方式，true使用parse_ini_file，false使用parse_ini_string，默认为true
 * @return static 返回当前实例，支持链式调用
 * @internal 通常在构造函数中调用，不建议直接使用
 */
protected function array(bool $onGroup = true, bool $readType = true): static
```

### convert()

```php
/**
 * 转换键名大小写
 * 
 * 根据keyType设置，将键名转换为大写、小写或保持原样
 * 
 * @param string|int $key 要转换的键名
 * @return string|int 转换后的键名
 * @internal 内部方法，用于统一处理键名大小写格式
 */
protected function convert(string|int $key): string|int
```

## 使用说明

### 基本用法

```php
// 初始化Env实例
$env = new Env();

// 读取环境变量
$appName = $env->get('APP_NAME');

// 设置环境变量
$env->set('DEBUG', true);

// 批量设置环境变量
$env->set([
    'DB_HOST' => 'localhost',
    'DB_USER' => 'root',
    'DB_PASSWORD' => 'password'
]);

// 删除环境变量
$env->del('TEMP_VAR');
```

### 分组配置使用

```php
// 设置分组环境变量
env->set('database.host', 'localhost');
env->set('database.port', 3306);

// 获取分组环境变量
$dbHost = $env->get('database.host');
```

## 注意事项

1. **配置文件路径**：默认读取当前目录下的.env文件，可通过全局配置修改路径
2. **键名大小写**：默认情况下不转换键名大小写，可通过全局配置修改此行为
3. **特殊值处理**：自动将字符串'null'、'true'、'false'转换为对应的PHP值
4. **分组管理**：支持使用点号分隔的键名表示分组，如'database.host'
5. **文件保存**：修改环境变量后会自动保存到.env文件，操作是立即生效的
6. **文件权限**：确保PHP脚本有足够的权限读取和写入.env文件

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2024-07-17 13:45:33