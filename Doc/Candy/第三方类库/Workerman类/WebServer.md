# Candy 内置 WebServer（Workerman）

> 路径：`Candy/Vendor/Workerman/WebServer/`  
> 修订：2026-07-18

## 组成

| 文件 | 角色 |
|------|------|
| `Server.php` | **生产路径**：`extends Workerman\Worker`，由 `php candy worker` 启动 |
| `Swoole.php` | 实验性平行实现，需 Swoole 扩展；默认不走主启动链 |

## 关键行为（优化后）

- **不再** `Coroutine::create` 并发改超全局（Fiber 下串请求风险）；并发靠多 `count` worker
- `onMessage` 拆分为：超全局引导 → 虚拟主机 → PHP / 静态 / 404
- `IF_MODIFIED_SINCE` 拼写已修（兼容旧错误头 `SINEC`）
- `compel` 按**原始 Host** 匹配强制 HTTPS
- 泛域名匹配有深度上限；`additionHeader` 支持字符串/`[name,value]`
- PHP `include` 使用 `ob_*`，脚本 `echo` 可回填空 Response
- `access.log` 使用 `flock`；体积写真实 size
- Keep-Alive：HTTP/1.1 默认保持，显式 `Connection: close` 才断开
- `Route::bindWorkerReload` 同时挂钩 `WorkerRuntime`（DB/Cache 池重置）

## 配置

见 `Source/Inc/Server.inc.php` 的 `webserver`。`addRoot` 现传入整站配置数组（含 `root` / `custom404` / `additionHeader`）。

## 注意

请勿按上游 Workerman 官方 WebServer 文档完全套用；本驱动已深度定制 Response gzip、max-age、PATHINFO 与 Candy Container。
