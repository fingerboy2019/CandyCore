# 全局函数库

## 类库简介

全局函数库是Candy框架的核心工具集合，提供了各种实用的全局函数，用于框架内部以及应用程序开发中的常用操作。这些函数覆盖了控制器调用、配置管理、缓存操作、权限验证、安全处理、请求/响应处理等多个功能领域。

全局函数库的设计理念是提供简单易用的接口，简化常见操作的代码编写，提高开发效率。所有函数都定义在全局命名空间中，可以在代码的任何位置直接调用。

## 功能分类

全局函数库按照功能可以分为以下几个主要类别：

- **控制器调用与实例化**：提供控制器的实例化和调用功能
- **配置管理**：用于获取、设置系统配置参数
- **缓存操作**：提供缓存的读写和管理功能
- **权限验证**：用于用户权限的检查和管理
- **安全处理**：包含IP过滤、安全验证等功能
- **请求/响应处理**：处理HTTP请求和响应相关操作
- **实用工具函数**：提供各种常用的辅助函数

## 主要函数

### A() - 异步调用控制器类

```php
/**
 * 异步调用控制器类
 *
 * 该函数用于异步实例化并调用控制器类，支持跨应用调用、插件调用，
 * 可在不同应用间或插件间进行无缝切换和调用。
 *
 * @param string|null $className 类名或调用路径，格式可为：控制器/方法 或 应用://控制器/方法
 * @return void
 * @throws Exception 当类不存在或参数无效时可能抛出异常
 */
function A(?string $className = null):void
```

**使用示例**：
- 同应用调用: `A('index/index')` - 调用当前应用的index控制器的index方法
- 跨应用调用: `A('admin://user/login')` - 调用admin应用的user控制器的login方法
- 插件调用: `A('Addon://plugin/action')` - 调用插件的action方法

### C() - 配置参数管理

```php
/**
 * 获取和设置配置参数，支持批量定义和多维数组操作
 *
 * 该函数是框架的核心配置管理函数，用于获取、设置或批量设置配置参数。
 * 支持简单配置获取、设置，以及多维数组配置的获取和设置。
 *
 * @param string|array|null $name 配置变量名、多维配置路径或配置数组
 * @param mixed $value 配置值，当$name为字符串时有效
 * @param mixed $default 默认值，当配置不存在时返回的默认值
 * @return mixed 当获取配置时返回配置值，设置配置时返回null
 */
function C(string|array|null $name=null,mixed $value='',mixed $default=null):mixed
```

**使用示例**：
- 获取配置: `C('DB_HOST')` - 获取DB_HOST配置值
- 设置配置: `C('DB_HOST', 'localhost')` - 设置DB_HOST配置值
- 多维数组: `C('DB.HOST')` - 获取DB数组中的HOST值
- 批量设置: `C(['DB_HOST' => 'localhost', 'DB_PORT' => 3306])` - 批量设置配置

### D() - 数据库操作对象

```php
/**
 * 创建数据库操作对象
 *
 * 该函数是框架的核心数据库操作入口，用于获取或创建数据库连接对象。支持跨项目调用
 * 数据库操作，可根据指定的表名和配置信息初始化数据库驱动实例。
 *
 * @param string $tableName 表名，支持跨项目格式'项目://表名'，特殊值'_dbDriver'仅返回驱动实例
 * @param string|array|null $config 数据库配置信息，支持字符串或数组形式
 * @return object 数据库连接对象实例
 */
function D(string $tableName='_dbDriver',string|array|null $config = []):object
```

**使用示例**：
- 基本使用: `D('User')` - 获取User表的数据库操作对象
- 跨项目调用: `D('Admin://User')` - 获取Admin项目中User表的数据库操作对象
- 自定义配置: `D('User', ['host' => 'localhost', 'username' => 'root'])` - 使用自定义配置
- 仅获取数据库驱动: `D('_dbDriver')` - 仅获取数据库驱动实例，不指定表名

### G() - 全局变量管理

```php
/**
 * 获取和设置配置参数 支持批量定义 S函数扩展 workerman模式进程间共享变量
 *
 * 该函数是C函数的扩展版本，主要增加了进程间共享变量的支持，特别适用于workerman等
 * 多进程环境下的配置共享。在无缓存类支持时会降级使用C函数的缓存机制。
 *
 * @param mixed $name 配置变量名、二维配置路径或配置数组，为null时返回所有配置
 * @param mixed $value 配置值，当$name为字符串时有效
 * @param mixed $default 默认值，当配置不存在时返回的默认值
 */
function G(mixed $name = null, mixed $value = '', mixed $default = null): mixed
```

**使用示例**：
- 获取全局变量: `G('version')` - 获取version全局变量值
- 设置全局变量: `G('version', '1.0.0')` - 设置version全局变量值
- 多维数组: `G('app.version')` - 获取app数组中的version值

### getCache() - 缓存实例获取

```php
/**
 * 获取缓存实例
 * 
 * 该函数用于获取缓存实例，支持自动选择或手动指定缓存驱动，实现了单例模式以避免重复初始化。
 * 自动选择模式下会按照以下优先级尝试：GlobalData > Memory > APCu
 * 
 * @return bool|object 缓存实例对象或false（当驱动不存在时）
 * @see \Candy\Core\Cache 缓存基类
 */
function getCache(): bool|object
```

**功能说明**：
- 该函数实现了缓存实例的单例模式，确保在同一请求生命周期内只创建一次缓存对象
- 支持两种缓存驱动选择模式：
  - **自动模式**：系统会按照配置的优先级自动选择可用的缓存驱动
  - **手动模式**：通过配置文件指定具体的缓存驱动
- 在自动模式下，系统会优先尝试GlobalData（分布式内存共享）、其次是内存缓存，最后是APCu缓存

**参数说明**：
- 该函数无参数

**返回值**：
- 成功：返回缓存驱动实例对象
- 失败：返回false

**使用示例**：
```php
// 获取缓存实例
$cache = getCache();
// 使用缓存实例
$cache->set('key', 'value');
$value = $cache->get('key');
```

**使用场景**：
- 全局缓存操作：在应用的各个部分获取统一的缓存实例
- 跨模块数据共享：通过缓存实现模块间的数据交换
- 性能优化：缓存热点数据以减少数据库查询或复杂计算

**注意事项**：
- 在使用Redis或Memcached等需要连接参数的缓存驱动时，必须在配置文件中正确设置options参数
- 缓存驱动选择会影响应用性能，建议根据应用规模和部署环境选择合适的驱动
- 该函数会优先使用配置文件中指定的缓存驱动，如未指定则自动选择



### F() - 文件缓存操作

```php
/**
 * 快速文件缓存操作
 * 
 * 提供简单高效的文件缓存读写功能，支持字符串和数组类型数据的快速存取，
 * 包含设置缓存、获取缓存和删除缓存的完整功能，并支持设置缓存过期时间。
 * 
 * @param string $name 缓存名称，用于标识缓存数据的唯一键
 * @param mixed $value 缓存值，为空字符串时表示获取缓存，为null时表示删除缓存，其他值表示设置缓存
 * @param int $expires_time 缓存过期时间（秒），0表示永不过期，默认值为0
 * @return mixed 设置缓存时返回null，获取缓存时返回缓存值或null，删除缓存时返回布尔值
 */
function F(string $name,mixed $value = '',int $expires_time = 0):mixed
```

**使用示例**：
- 设置缓存（永不过期）: `F('config', ['db' => 'mysql', 'host' => 'localhost'])`
- 设置带过期时间的缓存（3600秒后过期）: `F('user_info', ['id' => 1, 'name' => 'admin'], 3600)`
- 获取缓存: `$config = F('config')`
- 删除缓存: `F('user_info', null)`

### fileRead() - 文件内容读取

```php
/**
 * 文件内容读取函数
 * 
 * 提供安全可靠的文件内容读取功能，支持二进制模式读取，自动处理文件编码，并可选择性地
 * 对文件内容进行格式化处理，如统一换行符和添加HTML段落标签。函数会对文件进行存在性和可读性检查。
 * 
 * @param string $filename 要读取的文件路径
 * @param bool $deal 是否对读取的内容进行格式化处理，默认为false（不处理）
 * @return string 读取的文件内容，如果文件不存在或不可读则返回false
 */
function fileRead(string $filename,bool $deal = false): string
```

**使用示例**：
- 基本文件读取: `$content = fileRead('./config.php')`
- 读取并格式化内容（添加HTML段落标签）: `$formattedContent = fileRead('./README.md', true)`
- 二进制文件读取: `$binaryData = fileRead('./image.jpg')`

### fileWrite() - 文件内容写入

```php
/**
 * 文件内容写入函数
 * 
 * 提供安全可靠的文件写入功能，自动创建目标文件的父目录，并处理可能的文件权限问题。
 * 函数使用二进制模式写入，确保数据的完整性，并在写入失败时尝试使用临时文件作为备选方案。
 * 
 * @param string $filename 要写入的文件路径
 * @param string $contents 要写入的文件内容
 * @return bool 写入成功返回true，失败返回false
 */
function fileWrite(string $filename,string $contents): bool
```

**使用示例**：
- 基本文件写入: `$success = fileWrite('./logs/error.log', '错误信息记录')`
- 写入配置文件: `$config = '<?php return [\'db_host\' => \'localhost\'];'; fileWrite('./config/database.php', $config)`
- 写入二进制数据: `fileWrite('./uploads/image.jpg', $binaryData)`

### checkAuth() - 权限验证

```php
/**
 * 检查用户是否具有指定节点的访问权限
 *
 * 该函数用于验证当前用户是否拥有对指定节点（如菜单、操作）的访问权限，
 * 支持自定义权限数组验证。
 * 主要用于权限控制和访问验证场景。
 *
 * @param string|null $node 要检查的节点标识，为null时检查当前节点，默认为null
 * @param array $auths 自定义权限数组，为空时使用当前用户权限，默认为空数组
 * @return bool 如果用户拥有访问权限返回true，否则返回false
 */
function checkAuth(string|null $node = null,array $auths = []): bool
```

**使用示例**：
- 检查当前节点权限: `checkAuth()`
- 检查特定节点权限: `checkAuth('admin/user')`
- 使用自定义权限检查: `checkAuth('admin/user', ['admin', 'editor'])`

### dispatch() - 事件调度

```php
/**
 * 执行事件方法
 *
 * 该函数是框架事件系统的调度入口，用于触发指定名称的事件并传递相关数据。
 * 事件系统采用观察者模式，允许多个监听器响应同一个事件。
 *
 * @param string $eventName 事件名称，用于标识要触发的事件
 * @param mixed $data 传递给事件监听器的数据，可以是任意类型
 * @return array|null 事件监听器的返回结果数组，无监听器时返回null
 */
function dispatch(string $eventName,mixed $data): array|null
```

**使用示例**：
- 触发用户登录事件并传递用户数据: `$result = dispatch('user_login', ['user_id' => 1, 'username' => 'admin'])`
- 触发系统初始化事件: `dispatch('system_init', [])`

### cookie() - Cookie操作

```php
/**
 * Cookie操作函数，支持获取、设置和删除Cookie
 *
 * 该函数是对PHP原生Cookie操作的封装，提供了更便捷的接口，
 * 同时支持CLI模式下的特殊处理。可用于获取单个Cookie值、所有Cookie值，
 * 设置Cookie，以及删除单个或所有Cookie。
 *
 * @param string $name Cookie名称，空字符串时操作所有Cookie
 * @param mixed $value Cookie值，null表示删除，空字符串表示获取
 * @param int $expire 过期时间（秒），默认3600秒
 * @param string $path 有效路径，默认为根路径
 * @param string $domain 域名，默认为当前主机名
 * @return mixed 获取Cookie时返回Cookie值或Cookie数组，设置/删除时无返回值
 */
function cookie(string $name = '',mixed $value = '',int $expire = 0,string $path = '/',string $domain = ''):mixed
```

**使用示例**：
- 获取Cookie: `cookie('username')` - 获取username的值
- 设置Cookie: `cookie('username', 'admin', 3600)` - 设置username值为admin，有效期1小时
- 删除Cookie: `cookie('username', null)` - 删除username Cookie
- 获取所有Cookie: `cookie()` - 获取所有Cookie值
- 删除所有Cookie: `cookie('', null)` - 删除所有Cookie（保留会话ID）

### createUuidV4() - UUID生成

```php
/**
 * 生成UUID v4格式的唯一标识符
 *
 * 该函数生成符合RFC4122规范的UUID v4格式标识符，使用伪随机数生成
 * 128位的唯一值。UUID适用于需要全局唯一标识符的场景，如数据库主键、
 * 会话标识等。
 *
 * @return string 格式为xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx的UUID字符串
 */
function createUuidV4(): string
```

**使用示例**：
- 生成UUID: `$uuid = createUuidV4()` - 生成一个新的UUID
- 作为数据库主键: `$data['id'] = createUuidV4()` - 将UUID用作数据ID

### debug() - 调试信息输出

```php
/**
 * 调试信息输出函数
 *
 * 该函数用于在开发环境中输出调试信息，支持多种输出模式，包括数组
 * 格式化、变量类型和内存占用分析等。函数会自动判断当前环境，在Web环境下
 * 使用HTML格式化输出，在CLI环境下使用纯文本输出。
 *
 * @param mixed $var 要输出的变量，可以是任意类型
 * @param bool $echo 是否直接输出，默认为true
 * @return string|null 当$echo为false时返回格式化后的字符串，否则无返回值
 */
function debug(mixed $var,bool $echo = true): string|null
```

**使用示例**：
- 直接输出调试信息: `debug($userInfo)` - 格式化输出用户信息数组
- 获取格式化的调试字符串: `$debugStr = debug($data, false)` - 获取但不输出调试字符串

### dirs() - 目录管理

```php
/**
 * 获取、创建、删除目录的统一接口函数
 *
 * 该函数提供了对目录的全面操作支持，包括获取目录列表、创建目录、删除目录等。
 * 支持深度遍历和递归创建目录，适用于项目中的目录管理需求。
 *
 * @param string $path 目录路径
 * @param string $action 操作类型：get(获取列表)、create(创建)、delete(删除)
 * @param bool $depth 是否深度遍历或递归创建，默认为false
 * @param array $except 排除的文件或目录，默认为空数组
 * @return mixed 根据操作类型返回不同结果，获取列表返回数组，创建/删除返回布尔值
 */
function dirs(string $path,string $action = 'get',bool $depth = false,array $except = []): mixed
```

**使用示例**：
- 获取目录列表: `$dirs = dirs('./upload')` - 获取upload目录下的文件和目录
- 创建目录: `dirs('./temp', 'create')` - 创建temp目录
- 递归创建多级目录: `dirs('./logs/2023/01', 'create', true)` - 创建多级目录
- 删除目录: `dirs('./temp', 'delete')` - 删除temp目录

### doDefine() - 常量定义

```php
/**
 * 安全定义常量
 *
 * 该函数用于安全地定义常量，只有在常量未被定义时才会执行定义操作，
 * 避免重复定义导致的错误。支持同时定义多个常量。
 *
 * @param mixed $name 常量名或常量数组
 * @param mixed $value 常量值，当$name为字符串时有效
 * @return bool 定义成功返回true，常量已存在返回false
 */
function doDefine(mixed $name,mixed $value = null): bool
```

**使用示例**：
- 定义单个常量: `doDefine('SITE_NAME', 'Candy Framework')` - 定义站点名称常量
- 批量定义常量: `doDefine(['MAX_SIZE' => 1024, 'DEBUG_MODE' => true])` - 批量定义多个常量
- 安全定义: `doDefine('PI', 3.14159)` - 如果PI已定义，则不执行任何操作

### editInc() - 文件编辑

```php
/**
 * 编辑文件内容中的特定部分
 *
 * 该函数用于在文件中查找并替换特定内容，支持正则表达式匹配。
 * 适用于配置文件修改、模板文件编辑等场景。
 *
 * @param string $fileName 文件路径
 * @param string $find 要查找的内容或正则表达式
 * @param string $replace 替换的内容
 * @param bool $isRegex 是否使用正则表达式匹配，默认为false
 * @return bool 编辑成功返回true，失败返回false
 */
function editInc(string $fileName,string $find,string $replace,bool $isRegex = false): bool
```

**使用示例**：
- 简单文本替换: `editInc('./config.php', 'define("DEBUG", false)', 'define("DEBUG", true)')` - 修改配置值
- 使用正则表达式: `editInc('./config.php', '/define\("DEBUG", [^)]*\)/', 'define("DEBUG", true)', true)` - 使用正则替换

### emit() - 事件触发

```php
/**
 * 触发指定事件并获取所有监听器的返回值
 *
 * 该函数用于触发已注册的事件，并收集所有监听器的返回值。
 * 是框架事件系统的核心函数之一，支持传递事件数据和获取处理结果。
 *
 * @param string $eventName 事件名称
 * @param mixed $data 传递给事件监听器的数据
 * @return array 所有事件监听器的返回值数组
 */
function emit(string $eventName,mixed $data): array
```

**使用示例**：
- 触发用户注册事件: `$results = emit('user_register', ['user_id' => 1, 'email' => 'user@example.com'])` - 触发事件并获取结果
- 触发系统日志事件: `emit('system_log', ['level' => 'info', 'message' => '系统启动'])` - 触发日志记录事件

### envDel() - 环境变量删除

```php
/**
 * 删除环境变量
 *
 * 该函数用于删除指定的环境变量，支持从$_SERVER、$_ENV等超全局数组中移除变量。
 * 适用于需要清理特定环境变量的场景。
 *
 * @param string $name 环境变量名
 * @return bool 删除成功返回true，变量不存在返回false
 */
function envDel(string $name): bool
```

**使用示例**：
- 删除环境变量: `envDel('SECRET_KEY')` - 从环境中移除SECRET_KEY变量

### envGet() - 环境变量获取

```php
/**
 * 获取环境变量
 *
 * 该函数用于从多种来源（$_SERVER、$_ENV、getenv()）获取环境变量的值，
 * 提供统一的接口和默认值支持。
 *
 * @param string $name 环境变量名
 * @param mixed $default 默认值，当变量不存在时返回
 * @return mixed 环境变量的值或默认值
 */
function envGet(string $name,mixed $default = null): mixed
```

**使用示例**：
- 获取环境变量: `$dbHost = envGet('DB_HOST', 'localhost')` - 获取数据库主机，默认localhost
- 获取服务器信息: `$serverName = envGet('SERVER_NAME')` - 获取服务器名称

### envSet() - 环境变量设置

```php
/**
 * 设置环境变量
 *
 * 该函数用于设置环境变量，同时更新$_SERVER和$_ENV数组。
 * 适用于需要在运行时动态设置环境变量的场景。
 *
 * @param string $name 环境变量名
 * @param mixed $value 环境变量值
 * @return bool 设置成功返回true
 */
function envSet(string $name,mixed $value): bool
```

**使用示例**：
- 设置环境变量: `envSet('APP_ENV', 'production')` - 设置应用环境为生产环境
- 设置临时配置: `envSet('DEBUG_MODE', 'on')` - 开启调试模式

### fileDownload() - 文件下载

```php
/**
 * 文件下载函数
 *
 * 该函数用于向浏览器发送文件下载响应，支持各种类型的文件下载，
 * 自动处理文件类型检测、内容长度设置和缓存控制。
 *
 * @param string $filename 文件路径
 * @param string $showname 显示给用户的文件名，默认为原始文件名
 * @return void 无返回值，直接输出文件内容
 */
function fileDownload(string $filename,string $showname = ''): void
```

**使用示例**：
- 下载文件: `fileDownload('./uploads/report.pdf')` - 下载报告文件
- 自定义文件名: `fileDownload('./uploads/invoice.pdf', '我的发票.pdf')` - 下载并显示自定义文件名

### fileExistsCase() - 区分大小写的文件存在检查

```php
/**
 * 区分大小写的文件存在检查
 *
 * 该函数用于检查文件是否存在，并且考虑文件名的大小写。在大小写不敏感的文件系统
 * （如Windows）上，PHP原生的file_exists()不区分大小写，此函数可以解决这个问题。
 *
 * @param string $filename 文件路径
 * @return bool 文件存在且名称匹配返回true，否则返回false
 */
function fileExistsCase(string $filename): bool
```

**使用示例**：
- 检查文件是否存在: `if (fileExistsCase('./Config.php')) { /* 处理 */ }` - 检查Config.php是否存在（区分大小写）

### functionDump() - 函数信息分析

```php
/**
 * 函数信息分析函数
 *
 * 该函数用于获取PHP函数的详细信息，包括函数参数、返回类型、文档注释等。
 * 是一个强大的调试和文档生成工具。
 *
 * @param string $functionName 函数名
 * @return array 函数信息数组，包含函数的详细信息
 */
function functionDump(string $functionName): array
```

**使用示例**：
- 获取函数信息: `$info = functionDump('C')` - 获取C函数的详细信息
- 生成API文档: `$doc = functionDump('fileWrite')` - 获取fileWrite函数的文档信息

### getAppPath() - 应用路径获取

```php
/**
 * 获取应用路径
 *
 * 该函数用于获取当前应用或指定应用的物理路径，支持跨应用路径获取。
 * 适用于需要在不同应用间进行文件操作的场景。
 *
 * @param string $name 应用名称，为空时获取当前应用路径
 * @return string 应用的物理路径
 */
function getAppPath(string $name = ''): string
```

**使用示例**：
- 获取当前应用路径: `$path = getAppPath()` - 获取当前应用的物理路径
- 获取指定应用路径: `$adminPath = getAppPath('Admin')` - 获取Admin应用的物理路径

### getIPList() - IP白名单获取

```php
/**
 * 获取IP白名单列表
 *
 * 该函数用于获取系统配置的IP白名单，支持从缓存中获取，提高访问速度。
 * 适用于需要进行IP访问控制的场景。
 *
 * @return array IP白名单数组
 */
function getIPList(): array
```

**使用示例**：
- 获取IP白名单: `$ipList = getIPList()` - 获取系统IP白名单
- 检查IP是否在白名单中: `if (in_array($userIP, getIPList())) { /* 允许访问 */ }`

### getLocalIP() - 本地IP获取

```php
/**
 * 获取本地IP地址
 *
 * 该函数用于获取服务器的本地IP地址，支持在多种网络环境下正确识别
 * 服务器的真实IP，适用于分布式系统和多网卡环境。
 *
 * @return string 本地IP地址
 */
function getLocalIP(): string
```

**使用示例**：
- 获取本地IP: `$localIP = getLocalIP()` - 获取服务器的本地IP地址
- 记录服务器信息: `log('Server IP: ' . getLocalIP())` - 记录服务器IP信息

### getMirContents() - 镜像内容获取

```php
/**
 * 获取镜像内容
 *
 * 该函数用于从本地缓存中获取网络资源的镜像内容，支持检查缓存有效性
 * 和自动加载过期资源。适用于需要缓存远程内容的场景。
 *
 * @param string $url 资源URL
 * @param array $config 配置参数，包括缓存目录、过期时间等
 * @return array|false 包含content_type和content的数组，失败返回false
 */
function getMirContents(string $url,array $config): array|false
```

**使用示例**：
- 获取镜像内容: `$content = getMirContents('https://example.com/page.html', ['dir' => 'example_com'])` - 获取缓存的页面内容

### getOperate() - 用户操作获取

```php
/**
 * 获取用户操作信息
 *
 * 该函数用于获取当前用户的操作信息，包括操作类型、模块、控制器等，
 * 支持从URL或请求参数中解析操作信息。
 *
 * @return array 用户操作信息数组
 */
function getOperate(): array
```

**使用示例**：
- 获取当前操作: `$operate = getOperate()` - 获取用户当前操作信息
- 记录操作日志: `log('User operation: ' . json_encode(getOperate()))` - 记录用户操作

### getPinyin() - 中文转拼音

```php
/**
 * 中文转拼音函数
 *
 * 该函数用于将中文字符串转换为拼音，支持首字母和全拼，适用于
 * 中文搜索、排序等场景。
 *
 * @param string $string 中文字符串
 * @param string $type 转换类型：first(首字母)、full(全拼)
 * @return string 拼音字符串
 */
function getPinyin(string $string,string $type = 'first'): string
```

**使用示例**：
- 获取中文首字母: `$pinyin = getPinyin('中国', 'first')` - 返回'ZG'
- 获取中文全拼: `$pinyin = getPinyin('中国', 'full')` - 返回'zhongguo'

### getRandomStr() - 随机字符串生成

```php
/**
 * 生成随机字符串
 *
 * 该函数用于生成指定长度的随机字符串，支持多种字符集选项，
 * 适用于生成验证码、临时密码、密钥等场景。
 *
 * @param int $length 字符串长度，默认为6
 * @param int $type 字符类型：0(纯数字)、1(纯字母)、2(数字字母混合)
 * @return string 生成的随机字符串
 */
function getRandomStr(int $length = 6,int $type = 0): string
```

**使用示例**：
- 生成数字验证码: `$code = getRandomStr(6, 0)` - 生成6位数字验证码
- 生成混合字符串: `$token = getRandomStr(16, 2)` - 生成16位混合字符

### gmTime() - GMT时间获取

```php
/**
 * 获取GMT时间戳
 *
 * 该函数用于获取当前的GMT(格林威治标准时间)时间戳，支持时区转换，
 * 适用于需要使用标准时间的场景。
 *
 * @param string $time 时间字符串，为空时获取当前时间
 * @return int GMT时间戳
 */
function gmTime(string $time = ''): int
```

**使用示例**：
- 获取当前GMT时间: `$timestamp = gmTime()` - 获取当前GMT时间戳
- 转换指定时间: `$gmtTime = gmTime('2023-01-01 12:00:00')` - 转换指定时间为GMT时间

### halt() - 程序终止

```php
/**
 * 程序终止函数
 *
 * 该函数用于立即终止程序执行，并输出指定的错误信息。在调试和错误处理
 * 场景中非常有用，可以快速定位问题。
 *
 * @param string $message 终止消息
 * @return void 无返回值
 */
function halt(string $message): void
```

**使用示例**：
- 遇到错误时终止: `if ($error) halt('发生错误，程序终止')` - 发生错误时立即终止
- 调试断点: `halt('Debug point: ' . __LINE__)` - 在指定行设置调试断点

### headerLoading() - 响应头设置

```php
/**
 * 设置HTTP响应头
 *
 * 该函数用于设置HTTP响应头，在CLI模式下会自动跳过，避免错误。
 * 支持各种常见的HTTP头设置，如Content-Type、Cache-Control等。
 *
 * @param string $header HTTP头信息
 * @return void 无返回值
 */
function headerLoading(string $header): void
```

**使用示例**：
- 设置内容类型: `headerLoading('Content-Type: application/json')` - 设置JSON响应头
- 禁用缓存: `headerLoading('Cache-Control: no-cache, no-store')` - 禁用浏览器缓存

### html2txt() - HTML转文本

```php
/**
 * HTML转文本函数
 *
 * 该函数用于将HTML格式的文本转换为纯文本，移除所有HTML标签和特殊字符，
 * 适用于从HTML内容中提取文本内容的场景。
 *
 * @param string $string HTML字符串
 * @return string 纯文本字符串
 */
function html2txt(string $string): string
```

**使用示例**：
- 提取HTML文本: `$text = html2txt('<p>Hello <strong>World</strong></p>')` - 返回'Hello World'
- 处理富文本: `$content = html2txt($richText)` - 将富文本转换为纯文本

### hex2str() - 十六进制转字符串

```php
/**
 * 十六进制转字符串函数
 *
 * 该函数用于将十六进制格式的字符串转换回原始字符串，与str2hex()函数配对使用，
 * 适用于数据编码和解码场景。
 *
 * @param string $hex 十六进制字符串
 * @return string 转换后的原始字符串
 */
function hex2str(string $hex): string
```

**使用示例**：
- 解码十六进制: `$str = hex2str('48656c6c6f')` - 返回'Hello'
- 编码解码配对: `$original = 'test'; $encoded = str2hex($original); $decoded = hex2str($encoded)` - 完整的编码解码过程

### I() - 输入参数获取

```php
/**
 * 获取输入参数，支持过滤和默认值
 *
 * 该函数是框架的核心输入处理函数，用于从多种来源（GET、POST、PUT、DELETE等）
 * 获取输入参数，并支持类型过滤和默认值。是安全获取用户输入的标准方式。
 *
 * @param string|null $name 参数名，为空时获取所有参数
 * @param mixed $default 默认值，当参数不存在时返回
 * @param string $filter 过滤类型，默认为空（不过滤）
 * @return mixed 获取的参数值或默认值
 */
function I(string|null $name = null,mixed $default = null,string $filter = ''): mixed
```

**使用示例**：
- 获取GET参数: `$id = I('get.id', 0)` - 获取id参数，默认0
- 获取POST参数: `$username = I('post.username')` - 获取用户名
- 过滤输入: `$email = I('post.email', '', 'email')` - 获取并验证邮箱格式
- 获取所有参数: `$params = I()` - 获取所有请求参数

### id_decode() - ID解密

```php
/**
 * ID解密函数
 *
 * 该函数用于解密由id_encode()加密的ID值，适用于URL参数加密、
 * 敏感ID保护等场景。
 *
 * @param string $string 加密的ID字符串
 * @return int 解密后的ID值
 */
function id_decode(string $string): int
```

**使用示例**：
- 解密URL参数: `$realId = id_decode($_GET['id'])` - 解密URL中的ID参数

### id_encode() - ID加密

```php
/**
 * ID加密函数
 *
 * 该函数用于加密ID值，生成一个看起来像随机字符串的编码，
 * 适用于URL参数加密、防止ID遍历等场景。
 *
 * @param int $string 原始ID值
 * @return string 加密后的ID字符串
 */
function id_encode(int $string): string
```

**使用示例**：
- 加密URL参数: `$url = '/user/edit?id=' . id_encode(123)` - 加密ID后生成URL

### import() - 文件导入

```php
/**
 * 动态导入文件
 *
 * 该函数用于动态导入PHP文件，支持按需加载和路径别名，
 * 适用于需要根据条件加载不同文件的场景。
 *
 * @param string $path 文件路径
 * @return bool 导入成功返回true，失败返回false
 */
function import(string $path): bool
```

**使用示例**：
- 导入配置文件: `import('./config.php')` - 导入配置文件
- 导入功能模块: `if ($needAdvanced) import('./advanced.php')` - 条件导入

### importLang() - 语言文件导入

```php
/**
 * 导入语言文件
 *
 * 该函数用于导入指定的语言文件，并将语言变量加载到系统中，
 * 支持多语言切换和动态语言加载。
 *
 * @param string $file 语言文件名
 * @param string $range 语言包作用域
 * @return array 语言变量数组
 */
function importLang(string $file,string $range = 'common'): array
```

**使用示例**：
- 导入通用语言: `importLang('common')` - 导入通用语言文件
- 导入模块语言: `importLang('user', 'admin')` - 导入admin模块的user语言文件

### includeCache() - 缓存包含

```php
/**
 * 缓存包含文件
 *
 * 该函数用于包含文件并缓存结果，提高性能，避免重复解析文件内容。
 * 适用于频繁使用的配置文件和数据文件。
 *
 * @param string $filename 文件路径
 * @param bool $once 是否只包含一次，默认为true
 * @return mixed 文件返回值
 */
function includeCache(string $filename,bool $once = true): mixed
```

**使用示例**：
- 缓存包含配置: `$config = includeCache('./config.php')` - 缓存包含配置文件
- 只包含一次: `includeCache('./helper.php')` - 确保helper.php只被包含一次

### initTplPath() - 模板路径初始化

```php
/**
 * 初始化模板路径
 *
 * 该函数用于初始化系统模板路径，支持多种模板路径配置，
 * 适用于多主题、多语言场景下的模板管理。
 *
 * @return array 模板路径数组
 */
function initTplPath(): array
```

**使用示例**：
- 初始化模板: `$tplPaths = initTplPath()` - 获取初始化后的模板路径

### isDebug() - 调试模式检查

```php
/**
 * 检查是否处于调试模式
 *
 * 该函数用于检查系统是否处于调试模式，根据配置文件中的DEBUG设置
 * 和开发环境判断。适用于条件性地执行调试代码。
 *
 * @return bool 调试模式返回true，否则返回false
 */
function isDebug(): bool
```

**使用示例**：
- 条件调试: `if (isDebug()) { debug($data); }` - 仅在调试模式下输出调试信息
- 性能监控: `if (isDebug()) { $startTime = microtime(true); }` - 调试模式下启动性能监控

### isHavePlugs() - 插件检查

```php
/**
 * 检查是否存在指定插件
 *
 * 该函数用于检查系统中是否存在指定的插件，支持通过插件名称或路径检查。
 * 适用于插件管理和按需加载场景。
 *
 * @param string $name 插件名称
 * @return bool 插件存在返回true，否则返回false
 */
function isHavePlugs(string $name): bool
```

**使用示例**：
- 检查插件: `if (isHavePlugs('Payment')) { /* 使用支付插件 */ }` - 检查支付插件是否存在

### isWebServer() - Web环境检查

```php
/**
 * 检查是否在Web服务器环境下运行
 *
 * 该函数用于检查当前脚本是否在Web服务器环境下运行（如Apache、Nginx等），
 * 还是在CLI模式下运行。适用于根据运行环境执行不同的逻辑。
 *
 * @return bool Web服务器环境返回true，CLI模式返回false
 */
function isWebServer(): bool
```

**使用示例**：
- 环境适配: `if (isWebServer()) { header('Content-Type: text/html'); } else { echo 'CLI mode'; }` - 根据环境执行不同操作

### isMobile() - 移动设备检测

```php
/**
 * 检测是否为移动设备访问
 *
 * 该函数用于检测当前访问是否来自移动设备（手机、平板等），
 * 通过分析User-Agent字符串判断。适用于响应式网站开发。
 *
 * @return bool 移动设备返回true，桌面设备返回false
 */
function isMobile(): bool
```

**使用示例**：
- 响应式适配: `if (isMobile()) { $template = 'mobile'; } else { $template = 'desktop'; }` - 根据设备选择不同模板

### isSsl() - SSL检测

```php
/**
 * 检测是否使用SSL连接
 *
 * 该函数用于检测当前是否使用HTTPS/SSL连接，通过多种方式判断
 * 以确保在各种环境下的准确性。
 *
 * @return bool 使用SSL连接返回true，否则返回false
 */
function isSsl(): bool
```

**使用示例**：
- 安全链接: `$protocol = isSsl() ? 'https://' : 'http://'` - 根据SSL状态选择协议
- 安全操作: `if (isSsl()) { /* 执行安全操作 */ }` - 仅在SSL连接下执行敏感操作

### isWinOS() - Windows系统检测

```php
/**
 * 检测是否为Windows操作系统
 *
 * 该函数用于检测当前运行环境是否为Windows操作系统，
 * 适用于需要根据操作系统执行不同逻辑的场景。
 *
 * @return bool Windows系统返回true，其他系统返回false
 */
function isWinOS(): bool
```

**使用示例**：
- 路径适配: `$pathSep = isWinOS() ? '\\' : '/'` - 根据系统选择路径分隔符
- 命令适配: `$command = isWinOS() ? 'dir' : 'ls -la'` - 根据系统选择命令

### json() - JSON数据处理

```php
/**
 * JSON数据处理函数
 *
 * 该函数用于JSON数据的编码、解码和响应输出，提供了统一的接口
 * 处理JSON数据，适用于API开发和数据交换场景。
 *
 * @param mixed $data 要处理的数据
 * @param bool $exit 是否在输出后终止程序，默认为true
 * @return string|mixed JSON字符串或解码后的数据
 */
function json(mixed $data,bool $exit = true): string|mixed
```

**使用示例**：
- 输出JSON响应: `json(['code' => 0, 'message' => 'success', 'data' => $user])` - 输出JSON并终止
- 仅JSON编码: `$jsonStr = json($data, false)` - 仅返回JSON字符串不输出
- JSON解码: `$array = json($jsonStr)` - 解码JSON字符串

### jsonRpc() - JSON-RPC调用

```php
/**
 * JSON-RPC远程调用函数
 *
 * 该函数用于执行JSON-RPC远程调用，支持HTTP/HTTPS协议，
 * 适用于分布式系统和微服务架构中的服务间通信。
 *
 * @param string $url 远程服务URL
 * @param string $method 调用的方法名
 * @param array $params 方法参数数组
 * @return mixed 远程调用的返回结果
 */
function jsonRpc(string $url,string $method,array $params = []): mixed
```

**使用示例**：
- 远程API调用: `$result = jsonRpc('https://api.example.com', 'user.getInfo', ['id' => 1])` - 调用远程API

### L() - 语言变量管理

```php
/**
 * 获取和设置语言变量
 *
 * 该函数用于获取和设置系统语言变量，支持多语言切换和动态语言内容管理，
 * 是框架国际化支持的核心函数。
 *
 * @param string|null $name 语言变量名，为空时返回所有语言变量
 * @param mixed $value 语言变量值，用于设置模式
 * @return mixed 获取模式返回语言变量值，设置模式返回true
 */
function L(string|null $name = null,mixed $value = null): mixed
```

**使用示例**：
- 获取语言变量: `$welcome = L('welcome')` - 获取welcome语言变量
- 设置语言变量: `L('welcome', '欢迎使用Candy')` - 设置welcome语言变量
- 获取所有语言: `$allLang = L()` - 获取所有加载的语言变量

### listen() - 事件监听

```php
/**
 * 注册事件监听器
 *
 * 该函数用于注册事件监听器，当指定的事件被触发时，执行注册的回调函数。
 * 是框架事件系统的核心函数，支持观察者模式的事件处理机制。
 *
 * @param string $eventName 事件名称
 * @param callable|null $callback 回调函数，为空时仅触发事件
 * @return mixed 注册监听器时返回监听器ID，触发事件时返回事件结果
 */
function listen(string $eventName,callable|null $callback = null): mixed
```

**使用示例**：
- 注册事件监听: `listen('user_login', function($data) { log('User logged in: ' . $data['user_id']); })` - 监听用户登录事件
- 触发事件: `listen('app_stop')` - 触发应用停止事件

### loadConfig() - 配置加载

```php
/**
 * 加载配置文件
 *
 * 该函数用于加载指定的配置文件，并将配置合并到系统配置中，
 * 支持多种格式的配置文件和路径别名。
 *
 * @param string $file 配置文件名
 * @param string $type 配置类型
 * @return array 加载的配置数组
 */
function loadConfig(string $file,string $type = ''): array
```

**使用示例**：
- 加载数据库配置: `$dbConfig = loadConfig('database')` - 加载数据库配置文件
- 加载自定义配置: `loadConfig('myconfig', 'mytype')` - 加载自定义类型配置

### M() - 模型实例化

```php
/**
 * 实例化模型类
 *
 * 该函数用于实例化框架的模型类，支持跨应用调用模型，
 * 是框架ORM功能的入口函数。
 *
 * @param string $name 模型名称，支持跨应用格式'应用://模型'
 * @param string $tablePrefix 表前缀，默认为空
 * @param string $connection 数据库连接，默认为空
 * @return object 模型实例
 */
function M(string $name = '',string $tablePrefix = '',string $connection = ''): object
```

**使用示例**：
- 实例化模型: `$user = M('User')` - 实例化User模型
- 跨应用模型: `$adminUser = M('Admin://User')` - 实例化Admin应用的User模型
- 自定义表前缀: `$order = M('Order', 'tbl_')` - 使用自定义表前缀

### makeMirForSave() - 镜像生成

```php
/**
 * 生成镜像内容用于保存
 *
 * 该函数用于生成网络资源的镜像内容，支持获取远程文件内容并进行预处理，
 * 适用于内容镜像和缓存系统。
 *
 * @param string $url 资源URL
 * @return array|false 包含content_type和content的数组，失败返回false
 */
function makeMirForSave(string $url): array|false
```

**使用示例**：
- 生成镜像: `$mirror = makeMirForSave('https://example.com/image.jpg')` - 获取远程图片的镜像内容

### mConvtEncod() - 字符编码转换

```php
/**
 * 字符编码转换函数
 *
 * 该函数用于进行字符编码的转换，支持数组和字符串的编码转换，
 * 适用于多语言环境和文件编码处理。
 *
 * @param mixed $str 要转换的字符串或数组
 * @param string $toEncoding 目标编码
 * @param string $fromEncoding 源编码
 * @return mixed 转换后的字符串或数组
 */
function mConvtEncod(mixed $str,string $toEncoding = 'UTF-8',string $fromEncoding = ''): mixed
```

**使用示例**：
- 转换字符串编码: `$utf8Str = mConvtEncod($gbkStr, 'UTF-8', 'GBK')` - GBK转UTF-8
- 转换数组编码: `$utf8Array = mConvtEncod($gbkArray, 'UTF-8', 'GBK')` - 递归转换数组中所有字符串

### mkZip() - ZIP压缩

```php
/**
 * 创建ZIP压缩文件
 *
 * 该函数用于创建ZIP格式的压缩文件，支持添加多个文件和目录，
 * 适用于文件打包、备份等场景。
 *
 * @param array|string $files 要压缩的文件路径或文件数组
 * @param string $zipName 输出的ZIP文件名
 * @param bool $root 是否保持原文件路径结构，默认为false
 * @return bool 压缩成功返回true，失败返回false
 */
function mkZip(array|string $files,string $zipName,bool $root = false): bool
```

**使用示例**：
- 压缩单个文件: `mkZip('./data.txt', './data.zip')` - 压缩单个文件
- 压缩多个文件: `mkZip(['./file1.txt', './file2.txt'], './files.zip')` - 压缩多个文件

### mSubStr() - 多字节字符串截取

```php
/**
 * 多字节字符串截取函数
 *
 * 该函数用于安全地截取多字节字符串（如中文），避免出现乱码，
 * 支持指定编码和追加省略号。
 *
 * @param string $string 要截取的字符串
 * @param int $length 截取长度
 * @param int $start 起始位置，默认为0
 * @param string $charset 字符编码，默认为UTF-8
 * @param bool $suffix 是否添加省略号，默认为false
 * @return string 截取后的字符串
 */
function mSubStr(string $string,int $length,int $start = 0,string $charset = 'UTF-8',bool $suffix = false): string
```

**使用示例**：
- 截取中文字符串: `$short = mSubStr('这是一段很长的中文字符串', 10)` - 截取前10个字符
- 添加省略号: `$preview = mSubStr($content, 100, 0, 'UTF-8', true)` - 截取并添加省略号

### N() - 服务实例化

```php
/**
 * 实例化服务类
 *
 * 该函数用于实例化框架内置的服务类，如缓存、日志等，
 * 支持服务单例模式和依赖注入。
 *
 * @param string $name 服务名称
 * @param array $options 服务选项
 * @return object 服务实例
 */
function N(string $name,array $options = []): object
```

**使用示例**：
- 实例化缓存服务: `$cache = N('Cache')` - 实例化缓存服务
- 实例化日志服务: `$log = N('Log', ['type' => 'file'])` - 实例化日志服务

### objToArray() - 对象转数组

```php
/**
 * 对象转数组函数
 *
 * 该函数用于将PHP对象转换为关联数组，支持多层嵌套对象的递归转换，
 * 适用于对象数据的序列化和处理。
 *
 * @param object $obj 要转换的对象
 * @return array 转换后的关联数组
 */
function objToArray(object $obj): array
```

**使用示例**：
- 对象转数组: `$array = objToArray($userObject)` - 将用户对象转换为数组
- JSON序列化: `$json = json_encode(objToArray($complexObject))` - 对象转数组后JSON编码

### P() - 打印调试

```php
/**
 * 打印调试函数
 *
 * 该函数用于格式化打印变量内容，支持HTML和CLI两种环境，
 * 提供了比var_dump更友好的输出格式。
 *
 * @param mixed $var 要打印的变量
 * @param bool $exit 是否在打印后终止程序，默认为false
 * @return void 无返回值
 */
function P(mixed $var,bool $exit = false): void
```

**使用示例**：
- 打印调试: `P($userInfo)` - 格式化打印用户信息
- 打印并终止: `P($error, true)` - 打印错误信息并终止程序

### readInc() - 文件读取

```php
/**
 * 读取文件内容
 *
 * 该函数提供了一个简单的接口读取文件内容，支持自动处理文件编码，
 * 适用于配置文件、模板文件等的读取。
 *
 * @param string $filename 文件路径
 * @return string|false 文件内容或false（失败时）
 */
function readInc(string $filename): string|false
```

**使用示例**：
- 读取配置: `$config = readInc('./config.php')` - 读取配置文件内容
- 读取模板: `$template = readInc('./template.html')` - 读取模板文件

### readLog() - 日志读取

```php
/**
 * 读取日志文件
 *
 * 该函数用于读取系统日志文件，支持按日期范围和日志级别过滤，
 * 适用于日志分析和调试。
 *
 * @param string $type 日志类型
 * @param int $start 起始位置
 * @param int $limit 限制数量
 * @return array 日志记录数组
 */
function readLog(string $type = '',int $start = 0,int $limit = 100): array
```

**使用示例**：
- 读取错误日志: `$errors = readLog('error')` - 读取错误日志
- 分页读取: `$logs = readLog('', 20, 10)` - 读取第20-30条日志

### replenishNamespace() - 命名空间补充

```php
/**
 * 补充类的命名空间
 *
 * 该函数用于自动补充类的命名空间，简化类名的使用，
 * 适用于框架的类自动加载机制。
 *
 * @param string $className 类名
 * @return string 完整的类名（包含命名空间）
 */
function replenishNamespace(string $className): string
```

**使用示例**：
- 补充命名空间: `$fullClass = replenishNamespace('UserModel')` - 获取完整类名

### requireCache() - 缓存引用

```php
/**
 * 缓存require文件
 *
 * 该函数用于缓存require文件的结果，避免重复加载文件，
 * 提高系统性能，适用于频繁使用的类库文件。
 *
 * @param string $filename 文件路径
 * @return bool 加载成功返回true，失败返回false
 */
function requireCache(string $filename): bool
```

**使用示例**：
- 缓存加载类库: `requireCache('./lib/helper.php')` - 缓存加载helper类库

### safeReplace() - 安全替换

```php
/**
 * 安全字符串替换函数
 *
 * 该函数用于安全地替换字符串中的特定内容，支持HTML实体转换，
 * 适用于防止XSS攻击和安全内容处理。
 *
 * @param string $string 原始字符串
 * @param array|string $search 要查找的内容
 * @param array|string $replace 替换的内容
 * @param int $flags 替换标志
 * @return string 替换后的字符串
 */
function safeReplace(string $string,array|string $search,array|string $replace,int $flags = 0): string
```

**使用示例**：
- 安全替换: `$safeHtml = safeReplace($html, ['<script', '</script>'], ['&lt;script', '&lt;/script&gt;'])` - 移除脚本标签

### session() - 会话管理

```php
/**
 * 会话管理函数
 *
 * 该函数提供了会话数据的获取、设置和删除功能，支持数组和单个值操作，
 * 是对PHP原生session功能的封装和扩展。
 *
 * @param string $key 会话键名，为空时获取所有会话数据
 * @param mixed $value 会话值，null表示删除，空字符串表示获取
 * @return mixed 获取会话时返回会话值或会话数组，设置/删除时无返回值
 */
function session(string $key = '',mixed $value = ''): mixed
```

**使用示例**：
- 获取会话: `$user = session('user')` - 获取用户会话数据
- 设置会话: `session('user', ['id' => 1, 'name' => 'admin'])` - 设置用户会话
- 删除会话: `session('user', null)` - 删除用户会话
- 获取所有会话: `$allSession = session()` - 获取所有会话数据

### sessionId() - 会话ID获取

```php
/**
 * 获取当前会话的Session ID
 *
 * 该函数用于获取当前会话的Session ID，优先从Request对象获取，
 * 不存在时使用原生session_id()，确保在不同环境下都能正确获取。
 *
 * @return string 当前会话的Session ID
 */
function sessionId(): string
```

**使用示例**：
- 获取会话ID: `$sessionId = sessionId()` - 获取当前会话ID
- 会话跟踪: `log('User action with session: ' . sessionId())` - 记录会话ID

### setMirContents() - 镜像内容设置

```php
/**
 * 保存镜像内容到本地缓存
 *
 * 该函数用于将网络资源的内容保存到本地缓存系统，支持根据内容类型选择
 * 不同的缓存策略，适用于内容镜像和资源缓存。
 *
 * @param string $url 资源URL
 * @param array $content 要保存的内容数组，包含content_type和content字段
 * @param array $config 配置参数，包括缓存目录、过期时间等
 * @return void 无返回值
 */
function setMirContents(string $url,array $content,array $config): void
```

**使用示例**：
- 保存HTML镜像: `setMirContents('https://example.com', ['content_type' => 'text/html', 'content' => $html], ['dir' => 'example'])` - 保存网页镜像

### showDebugMsg() - 调试信息展示

```php
/**
 * 展示框架调试信息
 *
 * 该函数用于在开发环境中显示框架的调试信息，包括执行时间、内存使用、
 * SQL查询等，是框架调试模式的核心功能。
 *
 * @return void 无返回值
 */
function showDebugMsg(): void
```

**使用示例**：
- 显示调试信息: `showDebugMsg()` - 显示当前程序的调试信息

### showTplMsg() - 模板信息展示

```php
/**
 * 显示模板化信息
 *
 * 该函数用于显示框架预定义的模板化信息，支持CLI模式和Web模式，
 * 可用于显示调试信息、错误信息等。
 *
 * @param string $name 模板名称，默认为'debug'
 * @param string|null $msg 提示信息
 * @param mixed $detailed 详细信息
 * @param string $type 信息类型
 * @param bool $die 是否在显示后终止程序
 * @return void 无返回值
 */
function showTplMsg(string $name = 'debug', ?string $msg = '', $detailed = '', $type = '',bool $die = false): void
```

**使用示例**：
- 显示错误信息: `showTplMsg('error', '操作失败', ['code' => 500], 'danger', true)` - 显示错误信息并终止
- 显示成功信息: `showTplMsg('success', '操作成功', [], 'success')` - 显示成功信息

### sizeCount() - 文件尺寸转换

```php
/**
 * 文件尺寸转换函数
 *
 * 该函数用于将字节数转换为人类可读的文件大小格式，自动选择合适的单位，
 * 适用于文件大小显示、内存使用统计等场景。
 *
 * @param int $bytes 字节数
 * @return string 格式化后的大小字符串，如"1.5 MB"
 */
function sizeCount(int $bytes): string
```

**使用示例**：
- 格式化文件大小: `echo sizeCount(filesize('./largefile.zip'))` - 显示文件大小
- 格式化内存使用: `echo 'Memory used: ' . sizeCount(memory_get_usage())` - 显示内存使用情况

### stop() - 程序终止

```php
/**
 * 程序终止函数
 *
 * 该函数提供了更加灵活的程序终止机制，会触发应用程序停止事件监听，
 * 并根据运行环境采用不同的终止策略。
 *
 * @param string $msg 退出消息，仅在CLI环境下有效
 * @return void 无返回值
 */
function stop(string $msg = ' '): void
```

**使用示例**：
- 终止程序: `stop()` - 终止程序执行
- 带消息终止: `stop('程序执行完毕')` - 带消息终止（仅CLI环境有效）

### str2hex() - 字符串转十六进制

```php
/**
 * 字符串转十六进制函数
 *
 * 该函数用于将普通字符串转换为十六进制表示形式，适用于数据加密、
 * 二进制数据传输和数据隐藏等场景。
 *
 * @param string $string 要转换的字符串
 * @return string 转换后的十六进制字符串
 */
function str2hex(string $string): string
```

**使用示例**：
- 字符串转十六进制: `$hex = str2hex('Hello World')` - 转换为十六进制
- 数据加密: `$encoded = str2hex($sensitiveData)` - 简单加密敏感数据

### stripslashesDeep() - 递归去除反斜杠

```php
/**
 * 递归去除字符串中的反斜杠函数
 *
 * 该函数能够递归处理数组或单个值，对其中的字符串去除反斜杠转义，
 * 适用于处理从数据库或POST/GET获取的数据。
 *
 * @param mixed $value 要处理的值
 * @return mixed 处理后的值
 */
function stripslashesDeep(mixed $value): mixed
```

**使用示例**：
- 清理转义: `$clean = stripslashesDeep($userInput)` - 清理用户输入中的转义字符
- 处理数组: `$cleanArray = stripslashesDeep($_POST)` - 递归处理POST数组

### stripWhitespace() - 空白和注释去除

```php
/**
 * PHP代码空白和注释去除函数
 *
 * 该函数用于去除PHP代码中的注释和多余空白，同时保持代码的语法正确性，
 * 适用于代码压缩和优化。
 *
 * @param string $content PHP源代码字符串
 * @return string 压缩后的PHP代码
 */
function stripWhitespace(string $content): string
```

**使用示例**：
- 压缩代码: `$compressed = stripWhitespace($phpCode)` - 压缩PHP代码
- 移除注释: `$codeWithoutComments = stripWhitespace(file_get_contents('./source.php'))` - 移除代码注释

### T() - 请求级数据作用域

```php
/**
 * 获取和设置请求级数据
 *
 * WebServer 按 REDIRECT_REQUEST_UUID 隔离数据；PHP-FPM 和普通 CLI 无 UUID 时降级到 C()。
 * 长驻服务在请求结束时删除当前 UUID 的整个数据桶。
 *
 * @param string|array|null $name 数据键、多维键路径、批量数据或null
 * @param mixed $value 设置值；空字符串表示读取
 * @param mixed $default 获取模式下，键不存在时返回的默认值
 * @param string $uuid 显式请求UUID，为空时自动读取WebServer注入值
 * @return mixed
 */
function T(mixed $name=null,mixed $value='',mixed $default=null,string $uuid = ''):mixed
```

**使用示例**：
- 获取当前请求数据: `$allData = T()`
- 获取单个请求项: `$site = T('CMS_SITE')`
- 设置请求项: `T('CMS_SITE', $site)`
- 使用默认值: `$timeout = T('TIMEOUT', '', 30)`
- 清理当前UUID桶: `T(null, null)`（通常由WebServer在 `finally` 中执行）

### takeToken() - CSRF令牌生成

```php
/**
 * 生成并输出CSRF令牌函数
 *
 * 该函数用于生成CSRF（跨站请求伪造）保护令牌，并可选择直接输出为隐藏表单字段
 * 或仅输出生成的令牌值，适用于Web表单提交安全保护。
 *
 * @param bool $input 是否以隐藏表单字段形式输出
 * @param string $name token字段的名称
 * @return void 无返回值
 */
function takeToken(bool $input = true,string $name = 'token'): void
```

**使用示例**：
- 生成隐藏表单字段: `takeToken()` - 输出隐藏token表单字段
- 仅获取token值: `takeToken(false)` - 仅输出生成的token值（用于AJAX）
- 自定义字段名: `takeToken(true, 'csrf_token')` - 使用自定义字段名

### U() - URL组装

```php
/**
 * URL组装函数
 *
 * 该函数是框架的核心URL生成器，用于根据URL表达式和参数组装生成各种格式的URL，
 * 支持PATHINFO、REWRITE、GET等多种URL模式。
 *
 * @param mixed ...$params 可变参数列表
 * @return string 组装好的URL字符串
 */
function U(...$params): string
```

**使用示例**：
- 基本URL: `$url = U('Index/index')` - 生成控制器/操作的URL
- 带参数: `$url = U('User/detail', ['id' => 1])` - 生成带参数的URL
- 跨应用: `$url = U('Admin://User/index')` - 生成跨应用的URL
- 带锚点: `$url = U('Article/read#section1')` - 生成带锚点的URL

### usageMemory() - 内存使用计算

```php
/**
 * 内存使用计算函数
 *
 * 该函数用于计算并格式化当前PHP脚本的内存使用情况，使用sizeCount()函数将字节数格式化为人类可读的大小。
 *
 * @param int $startMemory 起始内存值
 * @return string 格式化后的内存使用情况字符串
 */
function usageMemory(int $startMemory = 0): string
```

**使用示例**：
- 计算内存使用: `$memory = usageMemory()` - 获取脚本内存使用情况
- 测量代码块: `$start = memory_get_usage(); /* 代码块 */ echo usageMemory($start)` - 测量特定代码块的内存消耗

### unZip() - ZIP解压

```php
/**
 * 解压ZIP压缩文件
 *
 * 该函数用于解压ZIP格式的压缩文件到指定目录，支持自动创建目标目录，
 * 适用于文件解压和安装场景。
 *
 * @param string $path ZIP文件路径
 * @param string $root 解压目标目录
 * @return bool 解压成功返回true，失败返回false
 */
function unZip(string $path, string $root = ''): bool
```

**使用示例**：
- 解压文件: `$success = unZip('./archive.zip', './extract/')` - 解压到指定目录
- 默认解压: `$success = unZip('./archive.zip')` - 解压到同名目录

### view() - 模板输出

```php
/**
 * 模板输出函数
 *
 * 该函数用于创建并返回视图对象，支持设置模板、变量和HTTP状态码，
 * 是控制器中渲染视图的标准方式。
 *
 * @param string|null $template 模板名称
 * @param mixed $vars 模板变量数组
 * @param string $data 额外数据
 * @param int $code HTTP状态码
 * @return \Candy\Core\View 视图对象
 */
function view(?string $template = null, $vars = [],$data = '', $code = 200): \Candy\Core\View
```

**使用示例**：
- 渲染默认模板: `return view()` - 渲染默认模板
- 带变量渲染: `return view('user/index', ['user' => $userInfo])` - 传递变量并渲染
- 错误页面: `return view('error/404', [], '', 404)` - 渲染404错误页面

### vendor() - 类库引入

```php
/**
 * 引入非规则类库
 *
 * 该函数提供了一种灵活的方式来引入项目中的类库文件，支持使用点分隔符指定路径，
 * 并允许自定义起始目录和文件后缀。
 *
 * @param string $path 类库路径
 * @param string $startpath 起始目录路径
 * @param string $suffix 文件后缀
 * @return bool 引入成功返回true，失败返回false
 */
function vendor(string $path,string $startpath = '',string $suffix = '.php'): bool
```

**使用示例**：
- 引入第三方类库: `vendor('Util.Helper')` - 引入Vendor/Util/Helper.php
- 自定义路径: `vendor('Custom.Library', '/path/to/libs/')` - 从自定义路径引入

### verificationCode() - 验证码生成

```php
/**
 * 验证码生成函数
 *
 * 该函数用于生成图形验证码，支持自定义验证码内容、尺寸和干扰元素，
 * 适用于用户登录、注册、表单提交等需要人机验证的场景。
 *
 * @param string $code 验证码内容
 * @param string $key 验证码存储键名
 * @param int $height 验证码图片高度
 * @param int $width 验证码图片宽度
 * @return void 无返回值
 */
function verificationCode(string $code = '',string $key = '',int $height=27,int $width=80): void
```

**使用示例**：
- 生成默认验证码: `verificationCode()` - 生成默认4位验证码
- 自定义验证码: `verificationCode('ABCD', 'verify')` - 使用自定义内容
- 自定义尺寸: `verificationCode('', 'verify', 50, 150)` - 自定义验证码尺寸

### writeLog() - 日志记录

```php
/**
 * 日志记录函数
 *
 * 该函数提供了一个简单的接口用于记录系统日志信息，支持自定义日志类型和存储路径，
 * 适用于记录系统错误、用户操作、调试信息等。
 *
 * @param string $message 日志消息
 * @param int $type 日志类型
 * @param string $path 日志文件路径
 * @return void 无返回值
 */
function writeLog(string $message,int $type=0,string $path = ''): void
```

**使用示例**：
- 记录操作日志: `writeLog('用户登录成功: ' . $username)` - 记录用户操作
- 记录错误日志: `writeLog('数据库连接失败', 1)` - 记录错误信息

### __destructor() - 框架析构函数

```php
/**
 * 框架析构函数
 *
 * 该函数是框架的全局析构函数，在脚本执行结束时自动调用，负责完成清理和收尾工作。
 *
 * @return void 无返回值
 */
function __destructor(): void
```

**使用示例**：
- 框架内部使用，通常不需要手动调用

### checkUserAuth() - 用户权限检查

```php
/**
 * 用户权限检查函数（默认实现）
 *
 * 该函数是URL生成过程中的权限检查钩子，用于在生成URL时进行权限验证，
 * 可以被应用层重写以提供自定义的权限检查逻辑。
 *
 * @param string $actions 操作权限标识
 * @param string $url 生成的URL链接
 * @param string $content 格式化的URL内容块
 * @return string 处理后的URL
 */
function checkUserAuth(string $actions, string $url, string $content): string
```

**使用示例**：
- 框架内部使用，可在应用中重写此函数实现自定义权限检查

### addLogs() - 日志记录（默认实现）

```php
/**
 * 日志记录函数（默认实现）
 *
 * 该函数是框架的日志记录接口，用于记录系统运行过程中的各类日志信息，
 * 可以被应用层重写以提供自定义的日志记录逻辑。
 *
 * @return void 无返回值
 */
function addLogs(): void
```

**使用示例**：
- 框架内部使用，可在应用中重写此函数实现自定义日志记录

## 使用说明

### 基本使用方式

全局函数库中的所有函数都定义在全局命名空间中，可以在代码的任何位置直接调用，无需引入额外的命名空间或类。

```php
// 直接调用全局函数
$config = C('DB_HOST');
$userModel = D('User');
$cache = F('system_config');
```

### 配置和初始化

全局函数库会在框架初始化时自动加载，无需手动初始化。但某些功能（如缓存）可能需要在配置文件中进行相应的设置。

### 常见操作示例

#### 配置管理

```php
// 设置数据库配置
C('DB_HOST', 'localhost');
C('DB_USER', 'root');
C('DB_PASS', 'password');
C('DB_NAME', 'test');

// 批量设置配置
C([
    'DB_HOST' => 'localhost',
    'DB_USER' => 'root',
    'DB_PASS' => 'password',
    'DB_NAME' => 'test'
]);

// 获取配置
$host = C('DB_HOST');
```

#### 数据库操作

```php
// 创建数据库模型
$user = D('User');

// 查询数据
$userInfo = $user->where(['id' => 1])->find();

// 插入数据
$user->insert(['username' => 'test', 'password' => md5('123456')]);
```

#### 缓存操作

```php
// 设置缓存
F('user_1', ['id' => 1, 'username' => 'admin'], 3600);

// 获取缓存
$userInfo = F('user_1');

// 删除缓存
F('user_1', null);
```

#### 文件操作

```php
// 读取文件内容
$content = fileRead('./config.php');

// 写入文件
fileWrite('./logs/access.log', '访问日志内容');

// 文件下载
fileDownload('./uploads/report.pdf');
```

## 注意事项

1. **性能考虑**：对于频繁调用的函数，建议使用局部变量缓存结果，避免重复计算
2. **安全使用**：文件操作函数在处理用户输入路径时需要进行安全过滤，避免目录遍历攻击
3. **缓存管理**：使用缓存函数时需要合理设置过期时间，避免数据过期导致的问题
4. **错误处理**：大多数函数都有返回值用于判断操作是否成功，建议在关键操作中检查返回值
5. **命名冲突**：由于是全局函数，在自定义函数时需要注意避免函数名冲突

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2020-02-03 11:31:47