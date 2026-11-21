# 框架 Rpc（替代旧 Workerman JsonRpc）

旧文档描述的 `JsonRpc\RpcClient` / `JsonNL` 已移除。

请使用：

| 项 | 说明 |
|----|------|
| 客户端 | `rpc('ServiceName', ['tcp://127.0.0.1:2020'])` |
| 服务端 | `Candy\Extend\Rpc\Server`，在 `Process.inc.php` 注册 |
| 配置 | `Source/Inc/Rpc.inc.php` |
| 服务类 | `Application/{App}/Rpc/*.class.php` 或配置 `services` 白名单 |
| 协议 | 长度前缀帧；`json` 调试 / `bin` 上线 / `auto` 按 DEBUG；服务端自动识别 |

调度模型：注册对象 → 调公开方法 → **返回值** 写入 `result`（不再跑 Control + 输出缓冲）。

### 强密备选（PayloadCrypto）

开启 `Source/Inc/PayloadCrypto.inc.php` 的 `enabled` 后，框架 Rpc Client/Server **自动**加密 `params` 与响应 `payload`（AES-256-GCM）。详见 [PayloadCrypto使用文档](../../扩展类库/PayloadCrypto使用文档.md)。两端须使用相同密钥；与 `apply_auth` / `remote_lib` 独立。
