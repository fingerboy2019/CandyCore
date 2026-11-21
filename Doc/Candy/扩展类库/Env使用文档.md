# Env 环境变量配置文件管理类使用文档

## 简介与基本概念

Env类是Candy框架中的环境变量配置文件管理工具，提供对.env配置文件的读取、修改、删除等操作。该类支持分组配置管理、键名大小写转换，并能处理特殊值类型（如null、true、false），适用于动态管理应用程序配置。

### 命名空间
```php
namespace Candy\Extend;
```

### 核心特性
- **环境变量管理**：提供读取、修改、删除.env配置文件的功能
- **分组支持**：允许使用点号分隔的键名表示分组配置（如 `database.host`）
- **批量操作**：支持单个或批量设置环境变量
- **类型转换**：自动将字符串值（如 'null'、'true'、'false'）转换为对应的PHP值
- **文件自动保存**：修改环境变量后自动保存到.env文件

## 快速入门

### 基本用法
```php
<?php
use Candy\Extend\Env;

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

## 主要功能与用法

### 初始化Env实例

```php
<?php
use Candy\Extend\Env;

// 创建默认配置的Env实例
$env = new Env();
```

### 环境变量的读取

#### 获取单个环境变量
```php
<?php
// 获取环境变量
$appName = $env->get('APP_NAME');

// 获取环境变量并设置默认值
$dbPort = $env->get('DB_PORT', 3306);
```

#### 获取所有环境变量
```php
<?php
// 获取所有环境变量
$allEnv = $env->get();

// 遍历所有环境变量
foreach ($allEnv as $key => $value) {
    echo "{$key}: {$value}<br>";
}
```

#### 获取分组环境变量
```php
<?php
// 获取分组环境变量
$dbHost = $env->get('database.host');
$dbPort = $env->get('database.port');
$dbUser = $env->get('database.username');
$dbPass = $env->get('database.password');
```

### 环境变量的设置

#### 设置单个环境变量
```php
<?php
// 设置字符串类型的环境变量
$env->set('APP_NAME', 'MyApplication');

// 设置布尔类型的环境变量
$env->set('DEBUG', true);

// 设置数字类型的环境变量
$env->set('MAX_UPLOAD_SIZE', 5242880); // 5MB

// 设置null值
$env->set('API_KEY', null);
```

#### 设置分组环境变量
```php
<?php
// 设置数据库相关的分组环境变量
$env->set('database.host', 'localhost');
$env->set('database.port', 3306);
$env->set('database.name', 'my_database');
$env->set('database.username', 'root');
$env->set('database.password', 'password');

// 设置缓存相关的分组环境变量
$env->set('cache.driver', 'redis');
$env->set('cache.host', 'localhost');
$env->set('cache.port', 6379);
$env->set('cache.prefix', 'myapp_');
```

#### 批量设置环境变量
```php
<?php
// 批量设置应用基本配置
$env->set([
    'APP_NAME' => 'MyApplication',
    'APP_ENV' => 'production',
    'DEBUG' => false,
    'TIMEZONE' => 'Asia/Shanghai',
    'LOCALE' => 'zh_CN'
]);

// 批量设置数据库配置
$env->set([
    'database.host' => 'localhost',
    'database.port' => 3306,
    'database.name' => 'my_database',
    'database.username' => 'root',
    'database.password' => 'password'
]);
```

### 环境变量的删除

#### 删除单个环境变量
```php
<?php
// 删除单个环境变量
$env->del('TEMP_VAR');
```

#### 批量删除环境变量
```php
<?php
// 批量删除多个环境变量
$env->del(['TEMP_VAR1', 'TEMP_VAR2', 'TEMP_VAR3']);
```

#### 删除分组环境变量
```php
<?php
// 删除分组环境变量（默认会删除空分组）
$env->del('database.password');

// 删除分组环境变量但保留空分组
$env->del('database.port', false);
```

## 高级用法

### 类型转换处理

Env类会自动处理特殊值的类型转换：

```php
<?php
// 设置特殊值
$env->set([
    'IS_TRUE' => true,
    'IS_FALSE' => false,
    'IS_NULL' => null,
    'NUMBER' => 123,
    'STRING' => 'hello world'
]);

// 获取并检查类型
var_dump($env->get('IS_TRUE'));    // bool(true)
var_dump($env->get('IS_FALSE'));   // bool(false)
var_dump($env->get('IS_NULL'));    // NULL
var_dump($env->get('NUMBER'));     // int(123)
var_dump($env->get('STRING'));     // string(11) "hello world"
```

### 与框架配置的集成

在Candy框架中，可以将Env类与全局配置结合使用：

```php
<?php
use Candy\Extend\Env;

// 初始化Env实例
$env = new Env();

// 获取应用名称并设置到全局配置
G('app_name', $env->get('APP_NAME', 'DefaultApp'));

// 获取数据库配置并设置到全局配置
G('database', [
    'host' => $env->get('DB_HOST', 'localhost'),
    'port' => $env->get('DB_PORT', 3306),
    'name' => $env->get('DB_NAME', 'default_db'),
    'username' => $env->get('DB_USER', 'root'),
    'password' => $env->get('DB_PASSWORD', '')
]);
```

### 配置文件示例

.env文件通常采用键值对格式，支持分组配置：

```ini
# 应用基本配置
APP_NAME=MyApplication
APP_ENV=development
DEBUG=true
TIMEZONE=Asia/Shanghai

# 数据库配置
[database]
host=localhost
port=3306
name=my_database
username=root
password=password

# 缓存配置
[cache]
driver=redis
host=localhost
port=6379
prefix=myapp_
```

## 最佳实践与注意事项

### 1. 敏感信息管理

环境变量是管理敏感信息的理想方式，避免将密码、密钥等直接硬编码在代码中：

```php
<?php
// 推荐：从环境变量获取敏感信息
$dbPassword = $env->get('DB_PASSWORD');
$apiKey = $env->get('API_KEY');

// 不推荐：直接硬编码敏感信息
$dbPassword = 'secret_password';
$apiKey = 'my_secret_api_key';
```

### 2. 配置文件权限

确保.env文件具有适当的权限设置，防止未授权访问：

```bash
# 设置适当的文件权限
chmod 600 .env
```

### 3. 开发与生产环境分离

使用不同的.env文件管理开发环境和生产环境的配置：

```php
<?php
// 开发环境.env
APP_NAME=MyApp (Development)
DEBUG=true
DB_HOST=localhost

// 生产环境.env
APP_NAME=MyApp
DEBUG=false
DB_HOST=production-db.example.com
```

### 4. 默认值设置

在获取环境变量时，始终设置合理的默认值，以提高代码的健壮性：

```php
<?php
// 推荐：设置默认值
$appName = $env->get('APP_NAME', 'MyApp');
$dbPort = $env->get('DB_PORT', 3306);
$debugMode = $env->get('DEBUG', false);

// 不推荐：不设置默认值，可能导致意外错误
$appName = $env->get('APP_NAME'); // 当APP_NAME不存在时返回空字符串
$dbPort = $env->get('DB_PORT'); // 当DB_PORT不存在时返回空字符串
```

### 5. 批量操作效率

对于大量环境变量的设置，使用批量操作更高效：

```php
<?php
// 推荐：批量设置
$env->set([
    'APP_NAME' => 'MyApp',
    'DEBUG' => true,
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_USER' => 'root'
]);

// 不推荐：逐个设置
$env->set('APP_NAME', 'MyApp');
$env->set('DEBUG', true);
$env->set('DB_HOST', 'localhost');
$env->set('DB_PORT', 3306);
$env->set('DB_USER', 'root');
```

### 6. 避免频繁修改环境变量

环境变量通常用于配置应用程序的基本参数，应避免在运行时频繁修改：

```php
<?php
// 推荐：在应用启动时读取配置
$env = new Env();
$appConfig = [
    'name' => $env->get('APP_NAME'),
    'debug' => $env->get('DEBUG'),
    'database' => [
        'host' => $env->get('DB_HOST'),
        'port' => $env->get('DB_PORT')
    ]
];

// 不推荐：在运行时频繁修改环境变量
function processRequest() {
    $env = new Env();
    $env->set('REQUEST_ID', uniqid());
    // 处理请求...
}
```

## 总结

Env类是Candy框架中用于管理环境变量配置的强大工具，它提供了简洁的API来读取、修改和删除.env配置文件中的环境变量。通过使用Env类，开发者可以更灵活地管理应用程序的配置，提高代码的可移植性和安全性。

主要优势：
- 提供了统一的接口来管理环境变量
- 支持分组配置，使配置结构更加清晰
- 自动处理类型转换，提高开发效率
- 支持批量操作，提高性能
- 与框架配置系统无缝集成

在实际开发中，建议将敏感信息和环境相关的配置放在.env文件中，使用Env类来管理，这样可以提高代码的安全性和可维护性。