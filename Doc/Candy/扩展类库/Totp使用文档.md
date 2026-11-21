# Totp 使用文档

命名空间：`Candy\Extend\Totp`（框架扩展类库，对齐 JwtToken）。

> Admin 双因素业务在 `S('Totp')` / 登录流程中接线；本类只做 RFC 6238 算法与恢复码工具，**无数据库依赖**。

## 1. 能力

| 方法 | 说明 |
|------|------|
| `generateSecret()` | 随机 Base32 密钥 |
| `getProvisioningUri($secret, $label, $issuer)` | `otpauth://totp/...` |
| `getCode($secret, $time = null)` | 当前 6 位码 |
| `verify($secret, $code, $window = 1)` | 校验（时间窗 ±1） |
| `generateRecoveryCodes(10)` | 明文恢复码列表 |
| `hashRecoveryCode` / `verifyRecoveryCode` | 恢复码哈希与校验 |

约定：SHA1、30 秒、6 位，兼容 Google Authenticator。

## 2. 示例

```php
use Candy\Extend\Totp;

$secret = Totp::generateSecret();
$uri = Totp::getProvisioningUri($secret, 'admin', 'CANDY Admin');
$ok = Totp::verify($secret, $_POST['code'] ?? '');
```

## 3. Admin 接线（摘要）

- 绑定：右上角「双因素认证」→ `Index/totp`
- 登录：密码通过且已启用 2FA → 输入验证码/恢复码；可勾选信任本机
- 运维：用户管理「重置2FA」

详见 `docs/superpowers/specs/2026-07-19-admin-totp-design.md`。
