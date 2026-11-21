# Apidoc使用文档

## 定位（Candy）

| 层 | 路径 | 说明 |
|----|------|------|
| 注解 | `Candy\Extend\Attribute\Annotations` | **框架能力**（`#[Title]` / `@Query` 等） |
| 引擎 | `Candy/Vendor/Apidoc` | 文档站解析与 UI，**可选启用** |
| 配置模板 | `Candy/Vendor/Apidoc/Apidoc.inc.php` | 默认留在包内，不进 Inc |
| 项目配置 | `Source/Inc/Apidoc.inc.php` | **`apidoc:init` 发布后**才存在 |
| 挂载 | `\Apidoc\Bootstrap`（包内） | 按开关合并 `route_start` Hook |
| 安装 | `\Apidoc\Installer`（包内） | `php candy apidoc:init` 薄命令调用 |
| 适配 | `Apidoc\providers\CandyService` | 唯一框架适配 Service（已删其它框架 stub） |

与 Node 的 `#[Annotation]` 并存：文档注解归 Apidoc，菜单权限归 Node。

**接口标注规范（项目约定）**：[`raw/doc/ApidocAnnotationConvention.md`](../../../raw/doc/ApidocAnnotationConvention.md)（扫描规则、必写项、Validate/Schema、分组标签作者、检查清单）。

## 启用步骤

```bash
cd backend
php candy apidoc:init
# 覆盖已有配置/前端：php candy apidoc:init --force
```

命令会：

1. 复制配置模板 → `Source/Inc/Apidoc.inc.php`（并设 `enable=true`）
2. 解压 `Candy/Vendor/Apidoc/apidoc.zip` → 站点根目录 `Apidoc/`（前端展示；zip 顶层目录名须为 `Apidoc`）
3. 将 `Application/tags.php` 改为 `Bootstrap::mergeTags([...])`（注册 CandyService）

然后访问 `/Apidoc/`，并在 Inc 配置里改 `auth.password`。

**不要**默认打开 `auto_register_routes`（会把业务注解 URL 扫进全局路由）。

> 前端静态资源不进 Git（见根目录 `.gitignore` 的 `/backend/Apidoc/`）。改 UI 后需重新打包 zip 覆盖 `Candy/Vendor/Apidoc/apidoc.zip`，再 `php candy apidoc:init --force` 解压。

## 快速开始

### 配置文件

- **模板（仓库内）**：`Candy/Vendor/Apidoc/Apidoc.inc.php`
- **运行时（init 后）**：`Source/Inc/Apidoc.inc.php`（`loadConfig('Apidoc')`）

```php
return [
    'enable' => true,
    'title'  => 'Candy API',
    'apps'   => [
        [
            'title' => '管理中心',
            'path'  => 'Application/Admin/Controls',
            'host'  => '',
            'key'   => 'admin',
        ],
    ],
    'route_prefix' => '/Apidoc',
    'auto_register_routes' => false,
    'auth' => [
        'enable'   => true,
        'password' => 'change-me',
    ],
];
```

### 基本使用

注解定义在框架 `Candy\Extend\Attribute\Annotations`（`@Title` / `#[Title]` 等），与 Node 的 `#[Annotation]` 可同方法并存。范例见 `Application/Admin/Controls/Login.class.php`：

```php
/**
 * 管理员登录
 * @Title("管理员登录")
 * @Desc("管理员账号登录认证")
 * @Query("username", "string", true, "用户名")
 * @Query("password", "string", true, "密码")
 * @ResponseSuccess("code", "int", "状态码，0 表示成功", true)
 * @ResponseSuccess("message", "string", "提示信息", false)
 */
#[Annotation(title: '管理员登录')]
function dologin() { /* ... */ }
```

访问文档前：执行 `php candy apidoc:init`（或 `--force`），解压到站点根 `Apidoc/`。默认地址：

```
http://localhost/Apidoc/
```

## 核心功能详情

### 1. 注解系统

Candy 使用框架 Attribute 注解（非旧版 `@ApiTitle` 前缀）。常用标签：

| 注解 | 说明 | 示例 |
|------|------|------|
| `@Title` / `#[Title]` | 接口标题 | `@Title("管理员登录")` |
| `@Desc` | 摘要说明 | `@Desc("管理员账号登录认证")` |
| `@Method` | 请求方法 | `@Method("POST")` |
| `@Url` | 接口 URL | `@Url("/Admin/Login/dologin.html")` |
| `@Query` | Query/表单参数 | `@Query("username", "string", true, "用户名")` |
| `@Param` | Body 参数 | `@Param("id", "int", true, "ID")` |
| `@ResponseSuccess` | 成功响应字段 | `@ResponseSuccess("code", "int", "状态码", true)` |

完整注解类见 `Candy/Class/Attribute/Annotations/`。

### 1.1 Validate / Schema 自动补全（`ApiContract`）

启用后（`Apidoc.inc.php` → `contract.enable`，默认 true），Apidoc 会从 Validate / Schema 补全字段；**同名字段以手写注解为准**。

**绑定（混合）**

1. 方法上 `#[ApiContract(...)]` 显式指定  
2. 否则约定：控制器短名 → `{Name}Validate` / `{Name}Schema`，scene 由方法名映射（`add`→`add`，`edit`→`mod` 等）

```php
use Candy\Extend\Attribute\Annotations\ApiContract;

// 控制器为 Auth，校验/响应用 Group（显式绑定）
#[ApiContract(validate: 'Group', scene: 'add', schema: 'Group')]
function add() { /* ... */ }

#[ApiContract(schema: 'Group', dataType: 'page')]
function list() { /* ... */ }
```

样板：`Application/Admin/Controls/Auth.class.php`（`add` / `edit` / `list`）。  
详见 `Doc/Candy/框架使用/自动验证.md`、`响应Schema.md`。

### 1.2 OpenAPI 导出（CLI / HTTP）

与上文扫描、契约补全同源，产出 OpenAPI 3.0.3：

```bash
php candy openapi:export --app=admin -o Runtime/Tmps/openapi-admin.json
```

HTTP：`GET /Apidoc/openapi?appKey=admin`（鉴权与文档站一致）。  
用法见 [`raw/doc/OpenApiExport.md`](../../../raw/doc/OpenApiExport.md)。

### 2. 多应用管理

Apidoc支持管理多个应用的API文档：

```php
'apps' => [
    [
        'title' => '前端API',
        'path' => 'Application/Api/Controls',
        'host' => 'http://api.example.com',
        'key' => 'front_api',
    ],
    [
        'title' => '后端管理API',
        'path' => 'Application/Admin/Controls',
        'host' => 'http://admin.example.com',
        'key' => 'admin_api',
    ]
]
```

### 3. 权限控制

Apidoc提供密码认证机制保护API文档：

```php
'auth' => [
    // 启用认证
    'enable' => true,
    // 访问密码
    'password' => 'your_password',
    // 加密盐
    'secret_key' => 'your_secret_key',
    // 有效期（秒）
    'expire' => 24*60*60
]
```

### 4. 缓存机制

启用缓存提升文档加载性能：

```php
'cache' => [
    // 启用缓存
    'enable' => true,
    // 缓存文件夹
    'folder' => 'apidoc_cache',
    // 缓存有效期（秒）
    'expire' => 3600
]
```

### 5. 自动路由注册

Apidoc可以自动注册API路由：

```php
// 启用自动路由注册
'auto_register_routes' => true,
```

### 6. Markdown文档集成

Apidoc支持集成Markdown文档：

```php
'docs' => [
    ['title' => 'API使用指南', 'path' => './docs/ApiGuide.md'],
    ['title' => '接口变更记录', 'path' => './docs/ChangeLog.md']
]
```

## 高级用法

### 1. 全局参数配置

定义全局请求参数：

```php
'params' => [
    // 全局请求Header
    'header' => [
        ['name' => 'Authorization', 'type' => 'string', 'required' => true, 'desc' => '身份令牌'],
        ['name' => 'Content-Type', 'type' => 'string', 'required' => true, 'desc' => '内容类型']
    ],
    // 全局请求Query
    'query' => [
        ['name' => 'app_id', 'type' => 'string', 'required' => true, 'desc' => '应用ID']
    ]
]
```

### 2. 全局响应体配置

定义全局响应体结构：

```php
'responses' => [
    // 成功响应体
    'success' => [
        ['name' => 'code', 'type' => 'int', 'required' => 1, 'desc' => '业务代码'],
        ['name' => 'message', 'type' => 'string', 'required' => 1, 'desc' => '业务信息'],
        ['name' => 'data', 'type' => 'object', 'required' => 1, 'desc' => '业务数据', 'main' => true]
    ],
    // 异常响应体
    'error' => [
        ['name' => 'code', 'type' => 'int', 'required' => 1, 'desc' => '错误代码'],
        ['name' => 'message', 'type' => 'string', 'required' => 1, 'desc' => '错误信息']
    ]
]
```

### 3. 接口分享功能

Apidoc支持接口分享功能，可以生成分享链接：

```php
// 生成分享链接
public function addApiShare()
{
    $params = [
        'name' => '分享名称',
        'type' => 'api', // 'app'或'api'
        'appKeys' => ['api'],
        'apiKeys' => ['/api/user/:id']
    ];
    $share = (new ApiShare())->addApiShare($params);
    return $share;
}
```

### 4. 自定义忽略注解

配置忽略特定的注解标签：

```php
// 忽略 Node 权限注解等，避免被文档解析
'ignored_annitation' => ['Auth', 'Menu', 'Log', 'Annotation', 'RateLimiter']
```

### 5. 代码生成器

Apidoc提供代码生成功能：

```php
// 生成API测试代码
public function renderCodeTemplate()
{
    $params = [
        'template' => 'curl',
        'apiData' => $apiDetail
    ];
    $code = (new ParseCodeTemplate($config))->renderCode($params);
    return $code;
}
```

## 注意事项

### 1. 注解格式规范

- 注解必须放在方法的文档注释块中
- 每个注解独占一行
- 复杂参数使用正确的格式定义

### 2. 性能优化

- 生产环境建议启用缓存
- 大型项目可分批生成缓存
- 定期清理过期缓存

### 3. 安全配置

- 生产环境启用权限认证
- 设置强密码和安全密钥
- 限制API文档的访问IP

### 4. 跨域访问

如果需要支持跨域访问，在配置中启用：

```php
// 启用跨域访问
'allowCrossDomain' => true
```

## 常见问题

### 1. 文档不显示 / 路由未注册

- 先执行 `php candy apidoc:init`
- 确认 `Source/Inc/Apidoc.inc.php` 中 `'enable' => true`
- 确认 `Application/tags.php` 使用了 `Bootstrap::mergeTags()`
- 检查站点根目录是否存在前端 `Apidoc/`（`php candy apidoc:init --force`）
- 检查 `apps.path` 是否指向 `Application/{App}/Controls`
- 确保方法有完整的 `@Title` 等文档注解

### 2. 权限认证失败

- 检查密码是否正确
- 确认 `secret_key` 配置一致
- 检查 token 是否过期

### 3. 注解解析错误

- 检查注解格式是否正确（框架 `@Title` / `@Query`，非旧版 `@ApiTitle`）
- 确认 `ignored_annitation` 已包含 `Annotation`、`RateLimiter` 等 Node 注解

## 架构边界（Candy）

| 做 | 不做 |
|----|------|
| 注解与双注释规范在框架 Attribute | 把整包 `Apidoc\` 迁入 `Candy\Core` / `Extend` |
| Bootstrap/Installer **留在 Vendor/Apidoc** | 在 `Candy\Class` 建框架级安装类 |
| 配置模板留 Vendor，`apidoc:init` 再发布到 Inc | 仓库默认提交 `Source/Inc/Apidoc.inc.php` |
| 仅保留 `CandyService` 适配 | 保留其它框架 stub Service |
| `Bootstrap` 按开关合并 Hook | 自动扫描 `Vendor/*/tags.php` |

## 更新日志

- **v1.0.0**：初始版本，支持基本的API文档生成功能
- **v1.1.0**：添加多应用支持和接口分享功能
- **v1.2.0**：增强Markdown文档集成和代码生成功能
- **v1.3.0**：优化性能和缓存机制
- **Candy**：配置迁入 `Source/Inc/Apidoc.inc.php`；`Bootstrap` 可选启用；`CandyService` 对齐 MVCS / `D()` / `L()`
