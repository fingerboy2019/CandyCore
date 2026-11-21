# Signer 使用文档

HTTP API 硬签与下载链接签名（HMAC / RSA / ECDSA）。**默认不启用、不挂中间件**；执行 `php candy signer:init` 后可用。

命名空间：`Candy\Extend\Signer\*`（框架扩展类库，对齐 JwtToken）。

> **与 Bearer JWT 的关系**：本库管「请求完整性签名」；登录态用 `JwtToken` + `JwtAuthMiddleware`。两套分开写、按路径组合挂载，不要合成一个中间件。
>
> **强密（防抓包明文）**：另见 [PayloadCrypto使用文档](./PayloadCrypto使用文档.md)。可与硬签叠加（先验签密文再解密）；默认关闭。

## 1. 快速开始

```bash
cd backend
php candy signer:init          # 发布 Source/Inc/Signer.inc.php（自动生成 secret / aes_key）
# php candy signer:init -f     # 强制覆盖配置
```

```php
use Candy\Extend\Signer\Api\ApiSigner;
use Candy\Extend\Signer\Download\DownloadSigner;

// 签发：把 headers 挂到 HTTP 请求（标准做法）
$headers = ApiSigner::signHeaders('POST', '/api/order', ['id' => '1'], '{"a":1}');
// 或 $signed = ApiSigner::sign(...); $signed['headers']

// 验签：优先读 Header
$info = ApiSigner::verify('POST', '/api/order', $_GET, $rawBody, $headers);
// 控制器内也可：$info = ApiSigner::verifyRequest();

// 下载临时链接（这类才用 query：_t/_e/_sign）
$url = DownloadSigner::sign('https://cdn.example.com/file.pdf', ['uid' => 1], 600);
DownloadSigner::validate($_GET);
```

未 init 或 `enabled=false` 时调用验签会抛 `SignerException`（`signer_disabled`）。

**不**自动改 `Middleware.inc.php`；需要路径验签时手动挂载（见第 5 节）。

## 2. 目录结构

```
Candy/Class/Signer/
├── Bootstrap / Installer / SignerException / CredentialResolver
├── Signer.inc.php                 # 配置模板
├── Kernel/                        # Canonical + hmac/rsa/ecdsa
├── Credential/                    # ConfigStore（本版）；Database/Chain 预留
├── Api/ApiSigner.class.php
├── Download/DownloadSigner.class.php
└── Nonce/NonceStore.class.php

Console/Command/Signer/Init.class.php
Source/Inc/Signer.inc.php
Source/Middleware/SignerAuthMiddleware.class.php
```

## 3. 配置

| 键 | 说明 |
|----|------|
| `enabled` | 总开关；init 发布为 `true` |
| `mode` | `single`（可不传 AppId）/ `multi`（必须 `X-App-Id`） |
| `allow_default_fallback` | multi 下未知 AppId 是否回退 `default`（生产建议 `false`） |
| `skew` | 时间窗秒数（默认 300） |
| `signature_encoding` | `hex` / `base64` |
| `nonce.enabled` | Cache 防重放；可关 |
| `default` | 默认凭证 |
| `apps` | multi 租户表 |
| `download.*` | `aes_key` / `default_expire` / `path_allow_prefix` |

### single

填 `default.secret`（hmac）即可；请求可不带 `X-App-Id`，规范串末位为 `-`。

### multi

```php
'mode' => 'multi',
'apps' => [
    'tenant_a' => [
        'sign_type' => 'hmac',
        'secret'    => 'tenant-secret',
        'enable'    => true,
    ],
],
```

客户端必须带 `X-App-Id: tenant_a`。

## 4. 协议

### API 规范串

```
METHOD
path
canonicalQuery   ← 不含 _t/_n/_sign/_app 等元数据（已在独立行）
bodySha256
timestamp
nonce
appId|-
```

**标准传参（Header）**：

| Header | 说明 |
|--------|------|
| `X-Timestamp` | Unix 秒 |
| `X-Nonce` | 一次性串 |
| `X-Signature` | 签名 |
| `X-App-Id` | multi 必填；single 可省略 |

验签 **优先 Header**；query（`_t`/`_n`/`_sign`/`_app`）仅兼容，新客户端勿再用。

### 客户端示例

**PHP（服务端代签再转发）**

```php
$headers = ApiSigner::mergeHeaders(
    ['Content-Type' => 'application/json'],
    ApiSigner::signHeaders('POST', '/api/order', [], $body, 'tenant_a')
);
// 交给 curl / Http 客户端
```

**curl**

```bash
TS=$(date +%s); NONCE=$(openssl rand -hex 16)
# 签名请用 SDK/服务端按规范串计算；此处示意 Header 形态
curl -X POST 'https://example.com/api/order' \
  -H "X-Timestamp: $TS" \
  -H "X-Nonce: $NONCE" \
  -H "X-Signature: <hex>" \
  -H "X-App-Id: tenant_a" \
  -H "Content-Type: application/json" \
  -d '{"a":1}'
```

**JavaScript（浏览器/Node，密钥勿放前端！仅示意 Header）**

```js
headers: {
  'X-Timestamp': String(Math.floor(Date.now() / 1000)),
  'X-Nonce': crypto.randomUUID().replace(/-/g, ''),
  'X-Signature': '<服务端签发>',
  'X-App-Id': 'tenant_a',
}
```

> 密钥与规范串计算应在**服务端或可信客户端**完成；浏览器直连开放 API 时，通常由你们的 BFF 代签，或给第三方发独立 secret。

### 下载

query：`_t` `_e` `_sign`；可选 `_app`。校验 `time() <= _t + _e`。

## 5. 中间件（可选）

```php
// Source/Inc/Middleware.inc.php
'Before' => [
    'APP\\Middleware\\JwtAuthMiddleware' => [ /* 认人 */ ],
    'APP\\Middleware\\SignerAuthMiddleware' => [
        'enabled' => true,
        'required_paths' => ['/api/*'],
        'except_paths' => ['/api/public/*'],
    ],
],
```

验签成功：`C('SIGNER_APP')`。与 JWT 按路径分别配置即可。

## 6. 算法与密钥

| sign_type | 说明 |
|-----------|------|
| `hmac` | HMAC-SHA256（默认） |
| `rsa` | RSA-SHA256（PEM） |
| `ecdsa` | **仅 P-256 / prime256v1** + SHA256 |

生成 ECDSA P-256：

```bash
openssl ecparam -name prime256v1 -genkey -noout -out ec-private.pem
openssl ec -in ec-private.pem -pubout -out ec-public.pem
```

生成 RSA（2048）：

```bash
openssl genrsa -out rsa-private.pem 2048
openssl rsa -in rsa-private.pem -pubout -out rsa-public.pem
```

## 7. 注意

- 本版凭证**仅配置**；`DatabaseStore` / `ChainStore` 预留后期「库优先、配置回退」
- Nonce 依赖 Cache；不可用时设 `nonce.enabled => false`
- 不改 Rpc 既有 HMAC；不入库、无 Admin CRUD
- `Authorization: Bearer …` 与本库无关，走 JwtToken
