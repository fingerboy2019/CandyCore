# Firebase JWT 使用文档

Firebase PHP-JWT（`Candy/Vendor/Firebase/JWT`，当前对齐上游 **v7.1.0**）是底层编解码库。  
**业务代码请只通过 `Candy\Extend\JwtToken` 使用**，不要直接 `JWT::encode/decode`（除非写测试或特殊工具）。

登录态 = **Bearer Token**（Cookie 或 `Authorization: Bearer …`），与开放接口的 **Signer 硬签**（`X-Signature` 等）是两套机制，分开挂中间件。

## 1. 框架内调用链

```
请求 → JwtAuthMiddleware → JwtToken → Firebase\JWT（JWT / Key）
                 ↑
         Source/Inc/Jwt.inc.php
```

| 层 | 职责 |
|----|------|
| `JwtAuthMiddleware` | 路径鉴权、CSRF（写操作） |
| `JwtToken` | 登录/刷新/注销、单设备、黑名单、Cookie↔Header |
| `Firebase\JWT` | RFC 7519 编解码与验签 |

## 2. 推荐用法（JwtToken）

```php
use Candy\Extend\JwtToken;

// 登录（写 Cookie；并按配置生成 CSRF）
JwtToken::login($user, 'WEB', 0, 0, $remember);

// 鉴权（统一按 token_mode 读 Cookie 或 Bearer）
$result = JwtToken::auth(true);
// $result['authenticated'] / user_id / user

// 刷新（data 含 access_token，Header 模式客户端可续用）
$ref = JwtToken::refresh();

// 验签（失败返回 false）
$payload = JwtToken::verify();

// 区分过期 / 坏签（排障用）
$detail = JwtToken::verifyDetailed();
// $detail['ok'] / error: expired|bad_signature|...

JwtToken::logout();
```

中间件配置见 `Source/Inc/Middleware.inc.php` 中的 `JwtAuthMiddleware`。

## 3. 配置要点（Jwt.inc.php）

| 项 | 说明 |
|----|------|
| `algorithms` | `HS256`/`HS384`/`HS512`/`RS256`/`RS384`/`RS512`；未知算法直接拒绝 |
| `access_secret_key` / `refresh_secret_key` | HMAC 密钥；**php-jwt 7+ 要求足够长**（HS256≥32 字节）。过短时 JwtToken 会哈希派生以兼容，生产请换成长随机串 |
| `token_mode` | `cookie`（后台默认）或 `header`（`Authorization: Bearer`） |
| `is_single_device` | 单设备登录（Cache 存当前 Token） |
| `leeway` | 时钟偏差秒数（配置加载时写入一次 `JWT::$leeway`） |

⚠️ 默认示例密钥仅供开发；生产务必更换，并避免提交真实密钥到公开仓库。

## 4. 与 Signer 的分工

| | JwtToken（Bearer） | Signer（硬签） |
|--|-------------------|----------------|
| Header | `Authorization: Bearer …` | `X-Timestamp` / `X-Nonce` / `X-Signature` |
| 证明 | 你是谁（会话） | 这次请求完整/未重放 |
| 启用 | 登录后即用 | `php candy signer:init` |

可同时挂两个中间件，按 `required_paths` 组合。

## 5. 底层 Firebase API（仅参考）

一般无需直接调用；升级 Vendor 时用于冒烟：

```php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$key = str_repeat('x', 32); // HS256 至少 32 字节
$jwt = JWT::encode(['exp' => time() + 60], $key, 'HS256');
$payload = JWT::decode($jwt, new Key($key, 'HS256'));
```

异常：`ExpiredException` / `BeforeValidException` / `SignatureInvalidException`。  
`JWK` / `CachedKeySet` 已随 Vendor 提供，本框架 JwtToken **未接入** OIDC/JWKS。

## 6. Vendor 位置与升级

```
Candy/Vendor/Firebase/JWT/   ← 与 github.com/firebase/php-jwt src/ 对齐
```

自动加载走框架 `Candy\Vendor\…` 规则；勿与项目 Composer `vendor/` 混用两套版本。

## 7. 升级注意（v7）

- HMAC 密钥长度校验更严；短密钥由 JwtToken 派生兼容，**旧 Token 可能需重新登录**
- 业务仍只依赖 JwtToken 公共 API；`verify()` 行为保持「失败返回 false」
