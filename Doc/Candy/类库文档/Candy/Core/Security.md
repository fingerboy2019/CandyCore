# Security 类

## 类简介

`Security` 是 Candy 框架的安全防护**门面类**，对外统一暴露 CSRF、输入过滤、IP 访问控制与中间件调度等能力。
实现上通过组合四个 Trait 子模块完成，自身仅保留 `run()` 入口，并作为**中间件基类**供项目扩展（如 `JwtAuthMiddleware`）。

| 子模块 | 文件 | 命名空间 | 职责 |
|--------|------|----------|------|
| **CsrfGuard** | `Candy/Core/Security/CsrfGuard.class.php` | `Candy\Core\Security` | CSRF 令牌、后台免验 Cookie |
| **InputFilter** | `Candy/Core/Security/InputFilter.class.php` | `Candy\Core\Security` | XSS/注入过滤、Referer 校验、攻击日志 |
| **IpAccess** | `Candy/Core/Security/IpAccess.class.php` | `Candy\Core\Security` | IP 黑白名单读写与校验 |
| **MiddlewareRunner** | `Candy/Core/Security/MiddlewareRunner.class.php` | `Candy\Core\Security` | 中间件加载、调度、请求上下文 |

```
Candy/Core/Security.class.php          ← 门面 + 中间件基类
Candy/Core/Security/
├── CsrfGuard.class.php
├── InputFilter.class.php
├── IpAccess.class.php
└── MiddlewareRunner.class.php
```

业务代码**始终通过** `Candy\Core\Security` 调用，无需直接引用子模块 Trait。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
use Candy\Core\Security\CsrfGuard;
use Candy\Core\Security\InputFilter;
use Candy\Core\Security\IpAccess;
use Candy\Core\Security\MiddlewareRunner;

class Security
{
    use CsrfGuard;
    use InputFilter;
    use IpAccess;
    use MiddlewareRunner;

    public static function run(): void
    {
        self::loadMiddleware();
        self::initCsrfProtection();
    }
}
```

> **说明**：`Security` 不是 final 类。项目中间件应 `extends Security` 并实现 `getName()`、`getAliases()`、`handle()`；框架通过 `is_subclass_of($class, Security::class)` 注册中间件。

## 主要方法

以下 API 均挂载在 `Candy\Core\Security` 上，按子模块分组说明。

### run() — 门面

```php
/**
 * 安全系统入口函数
 * 
 * 该方法是 Security 类的主入口，负责初始化安全系统，包括：
 * - 加载并执行所有注册的中间件
 * - 初始化 CSRF 防护机制
 * 
 * @return void
 * @example
 * \```php
 * Security::run();
 * \```
 */
public static function run(): void
```

### CsrfGuard

#### generateCsrfToken()

```php
/**
 * 生成 CSRF 令牌
 * 
 * 该方法用于生成或获取当前会话的 CSRF 令牌，用于表单提交和 AJAX 请求的安全验证。
 * 如果会话中不存在有效的 CSRF 令牌，将自动创建一个新的令牌。
 * 
 * @return string 生成的 CSRF 令牌
 * @example
 * \```php
 * // 在 HTML 表单中添加隐藏字段
 * <input type="hidden" name="csrf_token" value="<?php echo Security::generateCsrfToken(); ?>">
 * 
 * // 或在 AJAX 请求中设置请求头
 * $.ajax({
 *     headers: {
 *         'X-CSRF-TOKEN': '<?php echo Security::generateCsrfToken(); ?>'
 *     }
 * });
 * \```
 */
public static function generateCsrfToken(): string
```

#### verifyCsrfToken()

```php
/**
 * 验证 CSRF 令牌
 * 
 * 该方法用于验证提交的 CSRF 令牌是否与会话中存储的令牌匹配且未过期。
 * 令牌验证失败时，将终止请求并阻止潜在的 CSRF 攻击。
 * 
 * @param string $token 待验证的 CSRF 令牌
 * @return bool 验证结果，true 表示验证成功
 * @example
 * \```php
 * // 验证表单提交的 CSRF 令牌
 * if (Security::verifyCsrfToken($_POST['csrf_token'])) {
 *     // 处理表单请求
 * }
 * 
 * // 验证 AJAX 请求头中的 CSRF 令牌
 * if (Security::verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'])) {
 *     // 处理 AJAX 请求
 * }
 * \```
 */
public static function verifyCsrfToken(string $token): bool
```

#### _setTokenName()

```php
/**
 * 设置验证令牌 Cookie
 *
 * 设置特殊令牌 Cookie，使后续请求可跳过 POST 严格验证。
 * 通常在后台登录成功后调用。
 *
 * @return void
 */
public static function _setTokenName(): void
```

#### _delTokenName()

```php
/**
 * 删除验证令牌 Cookie
 *
 * 强制下一次请求对 POST 数据执行完整安全验证。
 *
 * @return void
 */
public static function _delTokenName(): void
```

### MiddlewareRunner

#### runMiddlewares()

```php
/**
 * 运行指定类型的中间件
 * 
 * 该方法用于执行已注册的指定类型的中间件，不同类型的中间件会有不同的执行行为：
 * - 'Over'类型：中间件会按顺序处理内容并返回处理后的结果
 * - 其他类型：中间件仅执行 handle 方法，不处理返回值
 * 
 * @param string $type 中间件类型，默认为'Init'
 * @param string $content 待处理的内容，用于'Over'类型的中间件，默认为空字符串
 * @return mixed 当$type 为'Over'时返回处理后的内容，其他情况下无返回值
 * @example
 * \```php
 * // 运行初始化中间件
 * Security::runMiddlewares('Init');
 * 
 * // 运行内容处理中间件
 * $processedContent = Security::runMiddlewares('Over', $originalContent);
 * 
 * // 运行请求后中间件
 * Security::runMiddlewares('After');
 * \```
 */
public static function runMiddlewares(string $type = 'Init', string $content = '')
```

> `After` 类型在非调试模式下会触发 `InputFilter::_getInfo()` 执行请求参数过滤。

#### getRequestContext()

```php
/**
 * 获取当前请求上下文（供中间件子类使用）
 *
 * @return array 包含 path、method、is_write、get/post/cookie/server 等
 */
protected static function getRequestContext(): array
```

### IpAccess

#### add()

```php
/**
 * 添加 IP 到黑白名单
 * 
 * 该方法用于将指定 IP 或 IP 范围添加到黑名单或白名单中，并可设置过期时间。
 * 如未指定 IP，将使用当前客户端 IP。
 * 
 * @param array $data 配置参数数组
 * @param string $data['type'] 添加类型，'b'表示黑名单，'w'表示白名单
 * @param string $data['ip_start'] 起始 IP 地址，可选
 * @param string $data['ip_end'] 结束 IP 地址，可选，不指定则与起始 IP 相同
 * @param int $data['exp_time'] 过期时间戳，可选，不指定则永不过期
 * @return bool 添加成功返回 true，失败返回 false
 * @example
 * \```php
 * // 将当前 IP 添加到黑名单，有效期 1 小时
 * Security::add(['type' => 'b', 'exp_time' => time() + 3600]);
 * 
 * // 添加指定 IP 范围到白名单
 * Security::add([
 *     'type' => 'w',
 *     'ip_start' => '192.168.1.1',
 *     'ip_end' => '192.168.1.100'
 * ]);
 * \```
 */
public static function add(array $data = []): bool
```

#### delete()

```php
/**
 * 从黑白名单中删除 IP
 * 
 * 该方法用于从黑名单或白名单中删除指定的 IP 或 IP 范围。
 * 如未指定 IP，将使用当前客户端 IP。
 * 
 * @param array $data 配置参数数组
 * @param string $data['type'] 删除类型，'b'表示黑名单，'w'表示白名单
 * @param string $data['ip_start'] 起始 IP 地址，可选
 * @param string $data['ip_end'] 结束 IP 地址，可选，不指定则与起始 IP 相同
 * @return bool 删除成功返回 true，失败返回 false
 * @example
 * \```php
 * // 从黑名单中删除当前 IP
 * Security::delete(['type' => 'b']);
 * 
 * // 从白名单中删除指定 IP 范围
 * Security::delete([
 *     'type' => 'w',
 *     'ip_start' => '192.168.1.1',
 *     'ip_end' => '192.168.1.100'
 * ]);
 * \```
 */
public static function delete(array $data = []): bool
```

#### check()

```php
/**
 * 检查 IP 是否在指定名单中
 * 
 * 该方法用于检查指定 IP 是否在黑名单或白名单中。
 * 会自动处理过期的 IP 记录。
 * 
 * @param string $type 检查类型，'b'表示黑名单，'w'表示白名单，默认为'b'
 * @param string $ip 要检查的 IP 地址，可选，不指定则使用当前客户端 IP
 * @return bool IP 在名单中返回 true，不在名单中返回 false
 * @example
 * \```php
 * // 检查当前 IP 是否在黑名单中
 * if (Security::check()) {
 *     die('您的 IP 已被限制访问');
 * }
 * 
 * // 检查指定 IP 是否在白名单中
 * if (Security::check('w', '192.168.1.10')) {
 *     // 允许访问特权功能
 * }
 * \```
 */
public static function check(string $type = 'b', string $ip = ''): bool
```

#### getAll()

```php
/**
 * 获取指定类型的所有 IP 名单
 * 
 * 该方法用于获取黑名单或白名单中的所有 IP 记录。
 * 返回的数组包含 IP 范围和过期时间信息。
 * 
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单，默认为'b'
 * @return array IP 名单数组，每条记录格式为 [起始 IP, 结束 IP, 过期时间戳]
 * @example
 * \```php
 * // 获取所有黑名单记录
 * $blacklist = Security::getAll();
 * 
 * // 获取所有白名单记录
 * $whitelist = Security::getAll('w');
 * 
 * // 遍历黑名单记录
 * foreach ($blacklist as $item) {
 *     echo "IP 范围：{$item[0]} - {$item[1]}, 过期时间：" . ($item[2] ? date('Y-m-d H:i:s', $item[2]) : '永不过期') . "\n";
 * }
 * \```
 */
public static function getAll(string $type = 'b'): array
```

#### find()

```php
/**
 * 查找指定 IP 是否在特定名单中
 * 
 * 该方法用于精确查找指定 IP 是否存在于黑名单或白名单中。
 * 支持 IP 范围匹配，会自动过滤过期记录。
 * 
 * @param string $ip 要查找的 IP 地址
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单，默认为'b'
 * @return bool IP 存在于名单中返回 true，不存在返回 false
 * @example
 * \```php
 * // 检查 IP 是否在黑名单中
 * if (Security::find('192.168.1.100')) {
 *     echo '该 IP 已被列入黑名单';
 * }
 * 
 * // 检查 IP 是否在白名单中
 * if (Security::find('192.168.1.200', 'w')) {
 *     echo '该 IP 在白名单中，可信任';
 * }
 * \```
 */
public static function find(string $ip, string $type = 'b'): bool
```

#### clean()

```php
/**
 * 清理或重置 IP 名单
 * 
 * 该方法用于清理黑名单或白名单中已过期的 IP 记录，
 * 或根据需要重置整个名单。清理过期记录可确保名单保持最新状态，
 * 重置功能则用于完全清空指定类型的名单。
 * 
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单，留空表示清理所有类型
 * @param bool $reset 是否重置名单，true 表示清空名单，false 表示仅清理过期记录
 * @return void
 * @example
 * \```php
 * // 清理黑名单中所有过期记录
 * Security::clean('b');
 * 
 * // 清空白名单中所有记录
 * Security::clean('w', true);
 * 
 * // 清理所有类型名单的过期记录
 * Security::clean();
 * \```
 */
public static function clean(string $type = '', $reset = false): void
```

#### IPList()

```php
/**
 * 初始化 IP 黑白名单数据
 * 
 * 该方法用于从日志文件中加载 IP 黑白名单数据到内存中，避免频繁的文件读取操作。
 * 只有当黑白名单为空时才会执行加载操作。
 * 
 * @return void
 * @example
 * \```php
 * // 确保 IP 名单已加载
 * Security::IPList();
 * 
 * // 通常在系统初始化时调用，也会在其他 IP 相关方法中自动调用
 * \```
 */
public static function IPList(): void
```

### InputFilter

#### _removeXss()

```php
/**
 * 清理 HTML 中的 XSS 潜在威胁
 * 
 * 该方法通过多种正则表达式过滤，移除 HTML 内容中的 XSS 攻击风险，包括：
 * - 移除不可见的控制字符
 * - 过滤危险标签（meta、link、script、style、iframe）
 * - 移除标签上的事件处理器（onclick、onload 等）
 * - 过滤 A 标签中以 javascript: 开头的 href 属性
 * 
 * @param string|string[] $string 需要清理的 HTML 字符串或数组
 * @return string|string[] 清理后的安全 HTML 字符串或数组
 * @example
 * \```php
 * // 清理用户输入的 HTML 内容
 * $safeContent = Security::_removeXss($userInput);
 * 
 * // 在保存前清理富文本内容
 * $article['content'] = Security::_removeXss($article['content']);
 * \```
 */
public static function _removeXss($string): string
```

## 内部方法

以下为各子模块内部方法，经 Trait 组合后挂载在 `Security` 上；`array_walk` 等回调仍使用 `Candy\Core\Security::_checkGet` 等形式。

### CsrfGuard

#### initCsrfProtection()

```php
/**
 * 初始化 CSRF 防护
 * 
 * 内部方法，用于初始化 CSRF 令牌的会话存储。
 * 如果会话中不存在 CSRF 令牌，将创建一个新的令牌并设置过期时间。
 * 
 * @return void
 */
protected static function initCsrfProtection(): void
```

### MiddlewareRunner

#### loadMiddleware()

```php
/**
 * 加载中间件
 * 
 * 内部方法，从配置中加载所有注册的中间件。
 * 
 * @return void
 */
protected static function loadMiddleware(): void
```

#### addMiddlewares()

```php
/**
 * 添加中间件
 * 
 * 内部方法，将中间件添加到中间件集合中。
 * 支持三种配置格式：
 * - 简单类名：'APP\Middleware\JwtAuthMiddleware'
 * - 带配置：'APP\Middleware\JwtAuthMiddleware' => ['enabled' => true, ...]
 * - 数组格式：['class' => '类名', 'config' => [...]]
 * 
 * @param array $middlewares 中间件配置数组
 * @return void
 */
protected static function addMiddlewares(array $middlewares): void
```

#### addMiddleware()

```php
/**
 * 添加一个指令
 * 
 * 内部方法，添加单个中间件到中间件集合。
 * 支持字符串类名和已实例化的对象。
 * 
 * @param mixed $middleware 指令对象或者指令类名
 * @param string $name 指令名，留空则自动获取
 * @param string $type 中间件类型
 * @param array $config 中间件配置参数
 * @return void
 */
protected static function addMiddleware($middleware, string $name = '', string $type = '', array $config = []): void
```

### InputFilter

#### _getInfo()

```php
/**
 * 参数过滤
 * 
 * 内部方法，处理和过滤请求参数，包括 GET、POST 等数据。
 * 根据安全级别执行不同程度的过滤操作。
 * 
 * @return void
 */
private static function _getInfo(): void
```

#### _misMatch()

```php
/**
 * 验证请求来源是否匹配
 * 
 * 内部方法，验证 HTTP 请求的来源是否匹配当前域名，防止 CSRF 攻击。
 * 检查 Referer 和 Origin 头信息，确保请求来自合法来源。
 * 
 * @return bool 如果来源匹配返回 true，不匹配返回 false
 */
private static function _misMatch(): bool
```

#### _recursive()

```php
/**
 * 数据递归处理
 * 
 * 内部方法，递归遍历并处理数据中的所有元素，对字符串类型的值进行安全处理。
 * 用于过滤多层嵌套数组中的潜在危险内容。
 * 
 * @param mixed $varPara 要处理的数据，可以是数组或字符串
 * @return mixed 处理后的结果
 */
private static function _recursive($varPara)
```

#### _unSetPost()

```php
/**
 * 卸载并清空 POST 数据
 * 
 * 内部方法，完全清空$_POST 全局变量，防止未经过滤的 POST 数据被使用。
 * 在检测到潜在威胁时调用，确保安全。
 * 
 * @return void
 */
private static function _unSetPost(): void
```

#### _strToHtml()

```php
/**
 * 将字符串转换为 HTML 实体
 * 
 * 内部方法，将特殊字符转换为 HTML 实体，防止 XSS 攻击。
 * 使用 htmlspecialchars 函数处理字符串，确保输出安全。
 * 
 * @param string $str 要处理的字符串
 * @return bool 如果包含危险代码返回 true，否则返回 false
 */
private static function _strToHtml(string $str): bool
```

#### _pregCode()

```php
/**
 * 检测字符串中是否包含代码注入攻击
 * 
 * 内部方法，使用正则表达式检查字符串中是否包含 PHP、JavaScript 或 SQL 代码特征，
 * 用于预防代码注入攻击。
 * 
 * @param string $hexStr 要检测的字符串
 * @return bool 包含可疑代码返回 true，否则返回 false
 */
private static function _pregCode($hexStr): bool
```

#### _evenNumber()

```php
/**
 * 判断正则前段是否是偶数个 16 进制
 * 
 * 该方法用于判断正则表达式匹配结果中第一段内容是否为偶数个 16 进制字符，
 * 用于避免误报合法输入中的特殊字符。
 * 
 * @param array $pregRevArr 正则表达式匹配结果数组
 * @return bool 匹配结果不为空返回 false，为空返回 true
 */
private static function _evenNumber(array $pregRevArr): bool
```

#### _murlDecodeEx()

```php
/**
 * 增强版 URL 解码处理
 * 
 * 该方法对字符串进行增强版的 URL 解码处理，支持多次编码的情况，
 * 确保所有 URL 编码的字符都能被正确解码，防止利用编码绕过安全检测。
 * 
 * @param string $str 要解码的字符串
 * @return string 解码后的字符串
 */
private static function _murlDecodeEx(string $str): string
```

#### _preProcess()

```php
/**
 * 预处理用户输入数据
 * 
 * 该方法对用户输入的字符串进行预处理，移除危险字符和空白字符，
 * 并转换 HTML 实体，为后续的安全检测做准备。
 * 
 * @param string $str 要预处理的字符串
 * @return string 预处理后的字符串
 */
private static function _preProcess(string $str): string
```

#### _checkGet()

```php
/**
 * 处理和检查 GET 参数中的安全风险
 * 
 * 该方法递归处理 GET 参数，过滤危险字符并进行 XSS 防护，
 * 确保 GET 请求数据的安全性。对于数组类型的参数会递归处理。
 * 
 * @param mixed $get GET 参数值，可以是字符串或数组
 * @return void
 */
private static function _checkGet($get)
```

#### _checkPost()

```php
/**
 * 处理和检查 POST 参数中的安全风险
 * 
 * 该方法递归处理 POST 参数，过滤危险字符、检查代码注入并进行 XSS 防护，
 * 确保 POST 请求数据的安全性。对于数组类型的参数会递归处理。
 * 
 * @param mixed $post POST 参数值，可以是字符串或数组
 * @return void
 */
private static function _checkPost($post)
```

#### _saveAttackLog()

```php
/**
 * 保存攻击日志到文件
 * 
 * 内部方法，用于记录检测到的安全攻击日志，包括攻击类型、攻击内容、
 * 攻击者 IP 和时间戳等信息，便于后期安全审计和分析。
 * 
 * @param string $type 攻击类型标识
 * @param string $val 攻击内容字符串
 * @return void
 */
private static function _saveAttackLog(string $type, string $val): void
```

#### _stripSlashesCheck()

```php
/**
 * stripslashes
 * 
 * @return mixed
 */
protected static function _stripSlashesCheck(string $string): mixed
```

### IpAccess

#### textToArray()

```php
/**
 * 将文本格式的 IP 列表转换为数组
 * 
 * 该方法用于将存储在文本文件中的 IP 列表数据转换为数组格式，
 * 便于程序内部处理和使用。支持 IP 范围和过期时间的解析。
 * 
 * @param string $text 文本格式的 IP 列表数据
 * @return array 转换后的 IP 列表数组，每条记录格式为 [起始 IP, 结束 IP, 过期时间戳]
 */
private static function textToArray(string $str): array
```

#### arrayToText()

```php
/**
 * 数组转文本
 * 
 * 该方法用于将 IP 列表数组转换为文本格式存储，支持 IP 范围的处理。
 * 
 * @param array $array IP 列表数组
 * @return string 转换后的文本字符串
 */
private static function arrayToText(array $array): string
```

#### ipCheck()

```php
/**
 * 验证 IP 地址格式是否有效
 * 
 * 检查给定字符串是否符合有效的 IP 地址格式，支持完整 IP 和通配符 IP 格式。
 * 支持 IPv4 标准格式和部分通配符表示法（如 192.168.*.*）。
 * 
 * @param string $str 要验证的 IP 地址字符串
 * @return bool IP 地址格式有效返回 true，否则返回 false
 */
private static function ipCheck(string $str): bool
```

#### transForm()

```php
/**
 * IP 地址格式转换工具
 * 
 * 提供 IP 地址在不同格式之间的转换功能：
 * - 数字格式 IP 转换为点分十进制格式
 * - 处理通配符 IP 地址的转换
 * - 将 IP 地址转换为无符号整数格式，用于比较操作
 * 
 * @param string $str 要转换的 IP 地址字符串或数字
 * @param bool $start 是否作为起始 IP 处理，true 时将通配符替换为 0，false 时替换为 255
 * @return bool|string|int 转换后的 IP 表示，失败返回 false
 */
private static function transForm(string $str, bool $start = true)
```

#### remove()

```php
/**
 * 移除
 * 
 * 该方法用于从 IP 黑白名单中移除指定的 IP 范围。
 * 
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单
 * @param int $startIPNumber 起始 IP 数字
 * @param int $endIPNumber 结束 IP 数字
 * @return bool 移除成功返回 true
 */
private static function remove($type, $startIPNumber, $endIPNumber): bool
```

#### save()

```php
/**
 * 写入文件
 * 
 * 该方法用于将 IP 黑白名单数组转换为文本格式并写入文件。
 * 
 * @param string $type 名单类型，'b'表示黑名单，'w'表示白名单
 * @return void
 */
private static function save(string $type = ''): void
```

## 类属性

属性按子模块分布在对应 Trait 中，组合后均属于 `Security` 类。

### MiddlewareRunner — $Middlewares

```php
/**
 * 中间件集合，按类型分组存储
 * @var array<string, array<string, Security>>
 */
private static $Middlewares;
```

### IpAccess — $blackList

```php
/**
 * IP 黑名单列表
 * @var array<array<int, mixed>> 格式：[IP 起始数值，IP 结束数值，过期时间]
 */
private static $blackList;
```

#### $whiteList

```php
/**
 * IP 白名单列表
 * @var array<array<int, mixed>> 格式：[IP 起始数值，IP 结束数值，过期时间]
 */
private static $whiteList;
```

### CsrfGuard — $csrfTokenName

```php
/**
 * CSRF 令牌名称
 * @var string
 */
private static $csrfTokenName = 'csrf_token';
```

#### $csrfTokenLength

```php
/**
 * CSRF 令牌长度
 * @var int
 */
private static $csrfTokenLength = 32;
```

#### $csrfTokenExpire

```php
/**
 * CSRF 令牌过期时间（秒）
 * @var int
 */
private static $csrfTokenExpire = 3600;
```

#### $Initializekey

```php
/**
 * 后台免验 Cookie 键名（InputFilter 严格过滤时读取同一属性）
 * @var string
 */
private static $Initializekey = 'fHoi[moOij8s9fdsifrewikdsaf';
```

### InputFilter — $rank

```php
/**
 * 安全等级，用于正则表达式匹配
 * @var string
 */
private static $rank = '';
```

#### $preProcess

```php
/**
 * 原始文件预处理 strtolower，bin2hex
 * @var array
 */
private static $preProcess = ['"."', "'.'"];
```

#### $codePreg

```php
/**
 * PHP 代码特征规则
 * @var array
 */
private static $codePreg;  // PHP/JS/SQL/LDAP/XXE/SSRF 等注入特征规则
```

## 中间件扩展

在 `Source/Inc/Middleware.inc.php` 中注册，类须 `extends Security`：

```php
namespace APP\Middleware;

use Candy\Core\Security;

class JwtAuthMiddleware extends Security
{
    protected string $name = 'JwtAuth';
    protected array $aliases = ['auth', 'jwt'];

    public function getName(): string { return $this->name; }
    public function getAliases(): array { return $this->aliases; }

    public function handle(string $content = '')
    {
        $context = self::getRequestContext();
        // ...
        return $content;
    }
}
```

配置示例：

```php
// Source/Inc/Middleware.inc.php
return [
    'Before' => [
        'APP\Middleware\JwtAuthMiddleware' => [
            'enabled' => true,
            'except_paths' => ['/admin/login'],
        ],
    ],
];
```

## 使用说明

### 基本用法

安全类的初始化和核心功能调用：

```php
// 在应用入口处初始化安全系统
Security::run();

// 生成 CSRF 令牌并在表单中使用
$csrfToken = Security::generateCsrfToken();
// 在表单中添加
<input type="hidden" name="csrf_token" value="$csrfToken">

// 验证 CSRF 令牌
if (Security::verifyCsrfToken($_POST['csrf_token'])) {
    // 处理表单请求
} else {
    // 验证失败处理
}
```

### IP 黑名单管理

```php
// 将恶意 IP 添加到黑名单，有效期 1 小时
Security::add([
    'type' => 'b',
    'ip_start' => '192.168.1.100',
    'exp_time' => time() + 3600
]);

// 检查当前 IP 是否在黑名单中
if (Security::check()) {
    die('您的 IP 已被限制访问');
}

// 清理过期的黑名单记录
Security::clean('b');
```

### XSS 防护

```php
// 清理用户输入的 HTML 内容
$userInput = '<script>alert("XSS 攻击")</script>';
$safeInput = Security::_removeXss($userInput);
// $safeInput 现在是安全的 HTML 内容
```

### 后台访问控制

```php
// 后台登录成功后，设置验证令牌以简化后续操作
if (Auth::login($username, $password)) {
    Security::_setTokenName();
    redirect('/admin/dashboard');
}

// 退出后台时，删除验证令牌
function logout() {
    Security::_delTokenName();
    Auth::logout();
    redirect('/admin/login');
}
```

## 注意事项

1. `Security` 为门面类，**可继承**用于自定义中间件；子模块 Trait 位于 `Candy\Core\Security\`，一般无需直接引用
2. 系统在非调试模式下，`runMiddlewares('After')` 会自动执行输入过滤（`_getInfo`）
3. 白名单中的 IP 不会进行任何过滤处理，应谨慎使用
4. CSRF 令牌的默认过期时间为 3600 秒（1 小时），可在类中配置
5. 攻击日志默认存储在 LOGPATH 目录下，文件名格式为 attack-日期.log
6. IP 黑白名单存储在 LOGPATH 目录下，文件名为 ip-blackList.log 和 ip-whiteList.log
7. 安全类提供的中间件机制支持不同类型的中间件，包括 Init、Over 和 After 等
8. 对于频繁受到攻击的 IP，系统会自动将其添加到黑名单中
9. XSS 防护支持配置安全等级（securityRank），可根据需要调整过滤强度
10. 攻击日志中会记录攻击者 IP、URL、时间等信息，便于安全审计

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-07-27 11:31:47