# DEBUG 安全提醒（`/sys/debug`）

> 站点以 DEBUG 模式运行时，页面顶部自动出现提示条，提醒你「当前对外暴露了调试信息」，
> 并给两个动作：**关闭 DEBUG** / **静默一天**。

## 为什么需要

`DEBUG=1` 时框架会展示报错细节、SQL、文件路径——**这在生产环境是信息泄露**。
开发时天天 DEBUG 很正常，问题往往出在「换到另一个环境后忘了关」。
这个提醒就是为那个时刻准备的。

## 触发条件（三条同时满足）

1. `isDebug()` 为真（`DEBUG==1 && $GLOBALS['debug']===1`）
2. 本浏览器没有有效的静默 cookie（`candy_debug_ack`）
3. **请求不是来自内网 / 本机**（`skip_intranet` 默认开启）

> 第 3 条是刻意的：本地开发每次刷新都弹提醒会让人直接无视它；
> 真正危险的是**公网 / 新机器**访问，那才是需要提醒的场景。

## 两个动作

| 动作 | 端点 | 效果 |
|---|---|---|
| 静默 24 小时 | `GET /sys/debug?do=ack` | 写 `candy_debug_ack=<到期时间戳>`，`max-age` 由 `silent_hours` 决定；**免 token**（只影响本浏览器） |
| 关闭 DEBUG | `GET /sys/debug?do=off` | 写 `Source/Inc/Sys.inc.php` 的 `runtime.debug=false`；**需要 token**（改的是站点配置） |

还有 `GET /sys/debug?do=status` 返回当前状态（调试用）。

## 配置（`Candy/Class/Tools/Inc/Sys.inc.php` → `debug_notice`）

```php
'debug_notice' => [
    'enable'        => true,   // 总开关
    'silent_hours'  => 24,     // 静默时长
    'skip_intranet' => true,   // 内网/本机不提醒
    'block'         => false,  // 预留：true 时用全屏遮罩强制处理
    'log'           => true,   // 记 Runtime/Logs/security-debug-*.log
],
```

项目覆盖写 `Source/Inc/Sys.inc.php` 即可（深合并）。

## 实现要点

| 点 | 说明 |
|---|---|
| 注入位置 | `Respond::filter('candyDebugNoticeInject', ['html'], 100)` —— **只处理 HTML**，JSON/XML 自动跳过 |
| 注册时机 | `Bootstrap::route()` 在 `UrlParser::parseUrl()` 之后调用 `DebugNotice::boot()` |
| 全局函数 | `Candy/Func/security.func.php` 里的 `candyDebugNoticeInject()`，已在 `global.func.php` 的 Tier 2a 注册（HookPipeline 对字符串回调只认 `function_exists`） |
| 日志 | `Runtime/Logs/security-debug-YYYY-MM.log`，记录 IP / UA / URI，便于事后追溯 |

## 注意

**这是「人因提醒」，不是安全边界。** cookie 可以伪造或清除，绕过它很容易。
真正的安全仍然是：生产环境 `APP_DEBUG=0` / `runtime.debug=false`。
它的价值只有一个——**在你忘了关的那一刻提醒你**。
