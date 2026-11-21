# PayloadCrypto 使用文档

HTTP / Rpc **业务载荷** AES-256-GCM 加解密（强密备选）。与 Signer（硬签）分层：**默认不启用**；`php candy payload-crypto:init` 后按需打开。

命名空间：`Candy\Extend\PayloadCrypto\*`（框架扩展类库）。

> **与 Signer**：硬签管完整性；本库管机密性。可只开加密，或「先签密文、再解密」。  
> **与 JwtToken**：登录态仍用 JWT，互不替代。  
> **与 Rpc apply_auth / remote_lib**：不动；强密解开后再走原有 Apply 验签。

## 1. 快速开始

```bash
cd backend
php candy payload-crypto:init       # 发布 Source/Inc/PayloadCrypto.inc.php（自动生成 32 字节 key）
# php candy payload-crypto:init -f  # 强制覆盖
```

两端（HTTP 客户端 SDK / Rpc 对端）使用**同一** `default.key`。

```php
use Candy\Extend\PayloadCrypto\PayloadCrypto;

// 通用封包
$wire = PayloadCrypto::encryptBytes($plain, $aad);
$plain = PayloadCrypto::decryptBytes($wire, $aad);

// JSON
$wire = PayloadCrypto::encryptJson(['a' => 1], $aad);
$data = PayloadCrypto::decryptJson($wire, $aad);

// 控制器读 body（中间件解密后）
$body = PayloadCrypto::requestBody();
```

未 init 或 `enabled=false` 时：HTTP 中间件透传；Rpc **不**改包。调用加解密 API 会抛 `payload_crypto_disabled`（若先 `assertEnabled`）或按 KeyResolver 失败。

## 2. 目录结构

```
Candy/Class/PayloadCrypto/
├── Bootstrap / Installer / PayloadCryptoException / KeyResolver
├── PayloadCrypto.class.php          # 门面
├── PayloadCrypto.inc.php            # 配置模板
└── Kernel/ AesGcmEngine + Envelope

Console/Command/PayloadCrypto/Init.class.php
Source/Inc/PayloadCrypto.inc.php
Source/Middleware/PayloadCryptoMiddleware.class.php
```

## 3. 配置

| 键 | 说明 |
|----|------|
| `enabled` | 总开关；init 发布为 `true` |
| `mode` | `single` / `multi`（按 AppId 选 key） |
| `allow_default_fallback` | multi 未知 App 是否回退 default |
| `default.key` | 32 字节：hex(64) 或 base64 |
| `apps` | multi 租户密钥表 |
| `encoding` | `base64`（默认）/ `raw` |
| `aad.enabled` | 是否绑定 AAD（建议 true） |

## 4. 信封

```
version(1)=0x01 | nonce(12) | ciphertext | tag(16)
```

标准 Base64 传输（`encoding=base64`）。

### HTTP AAD

- 请求：`{METHOD}\n{path}\n{ts}\n{nonce}\n{appId|-}`
- 响应：`RES\n{METHOD}\n{path}\n{appId|-}`

头：`X-Payload-Crypto: 1`；无 Signer 时用 `X-Payload-Ts` / `X-Payload-Nonce`（有 Signer 则复用 `X-Timestamp` / `X-Nonce`）。

### Rpc

请求：`crypto=1`，`params` 为密文字符串（原数组 JSON 再 seal）。  
响应：`crypto=1`，`payload` 解密后为 `{result, error}`。  
AAD：`rpc\n{class}\n{method}\n{id}\nreq|res`。  
`ping`/`pong`、协议层明文 `error` 不加密。

## 5. HTTP 中间件

**不**自动改 `Middleware.inc.php`。示例：

```php
'After' => [
    // 可选：先验签（bodyHash=密文）
    'APP\\Middleware\\SignerAuthMiddleware' => [
        'enabled' => true,
        'required_paths' => ['/api/*'],
    ],
    'APP\\Middleware\\PayloadCryptoMiddleware' => [
        'enabled' => true,
        'strict' => true,
        'encrypt_response' => true,
        'required_paths' => ['/api/*'],
    ],
],
'Over' => [
    'APP\\Middleware\\PayloadCryptoMiddleware' => [
        'enabled' => true,
        'encrypt_response' => true,
        'required_paths' => ['/api/*'],
    ],
],
```

挂载顺序：Signer → PayloadCrypto。同一类可分别挂 After（解密）与 Over（加密响应）。

## 6. Rpc

`enabled=true` 时 Client / Server **自动**双边加解密，无需改业务服务类。两端配置与 key 必须一致。

| 错误码 | 含义 |
|--------|------|
| `-32020` | 要求密文却未带 |
| `-32021` | 解密/AAD 失败 |
| `-32022` | 对端未启用或密钥未配置 |

## 7. 错误码（HTTP JSON）

| error | HTTP |
|-------|------|
| `payload_crypto_required` | 400 |
| `payload_crypto_invalid` | 400 |
| `payload_crypto_misconfigured` | 500 |

## 8. 相关文档

- [Signer使用文档](./Signer使用文档.md)
- 设计规格：`docs/superpowers/specs/2026-07-19-payload-crypto-design.md`
