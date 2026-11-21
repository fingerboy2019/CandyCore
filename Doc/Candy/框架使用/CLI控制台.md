# 命令控制台（`/sys/cli`）

> 在浏览器里执行 `php candy <命令>`，不必随时开远程终端。
> 一个页面覆盖全部可安全执行的命令，不给每个命令单独做面板。

## 三个动作

| 请求 | 作用 |
|---|---|
| `GET /sys/cli` | 页面：命令输入框 + 快捷按钮（doctor / version / migrate:status / seed:status / queue:status / schedule:list）+ 输出区 |
| `GET /sys/cli?do=exec&cmd=<命令>&args=<参数>&confirm=<确认词>` | 执行一条命令，返回 JSON（`code/code/time/output/command`） |
| `GET /sys/cli?do=cmds` | 命令白名单 JSON（safe / danger / forbidden） |

未认证时页面显示令牌输入框（保存到 cookie `candy_endpoint_token`）；`do=exec` / `do=cmds` 未认证直接 403 JSON。

## 安全边界（按此实现，不要放宽）

1. **只执行 `php <candy 入口> <cmd> <args>`** —— `proc_open` 传数组、不经 shell，参数逐个追加，天然无注入；
2. `cmd` 必须命中白名单，且匹配 `^[a-z][a-z0-9:_-]{0,48}$`；
3. **三档白名单**：
   - `SAFE`：只读（`doctor` `version` `migrate:status` `seed:status` `queue:status` `schedule:list` `lifecycle:status/preview` `im:event-status` `mcp:list` …），随时可用；
   - `DANGER`：写文件/写库（`clear` `migrate:run/rollback/make` `seed:run/make` `*:init` `make:*` `generate:*` `lifecycle:run/freeze/thaw` …），**必须 `confirm` 等于命令名，且仅 DEBUG 模式**；
   - `FORBIDDEN`：长驻/交互（`composer` `process` `web` `ws` `swoole` `run` `dns` `ftp` `nosql` `webdav` `worker`），直接 403，请到服务器或 `/sys/service`。
4. **超时 20s**（子进程 killed，exit=124），输出去 ANSI 色码、截断 64KB；
5. **审计**：`Runtime/Logs/cli-YYYY-MM.log` 记 `时间 / ip / cmd / args / code / cost`。

## 为什么不做「每条命令一个面板」

60+ 命令，逐个做页面维护成本高且容易漂移。控制台 = 一个执行器 + 一份白名单，新增命令只需登记分类。

## 已知限制

- **生产 FPM 禁了 `proc_open`** → 控制台只能返回「环境禁用」提示；只读命令也受影响。要么放开 `proc_open`，要么继续用终端。
- 长驻命令（`queue:work` 等）不在此页执行 —— 用 `/sys/service` 或后台任务 + 日志轮询。
- `composer`/`build:phar` 属交互/重构建，保留 CLI。

## 文件

```
Candy/Class/Tools/Cli/Console.class.php     执行器（白名单 / proc_open 数组 / 超时 / 审计）
Candy/Class/Tools/Endpoint/Cli.class.php    /sys/cli 端点（page / exec / cmds + token 鉴权）
```
