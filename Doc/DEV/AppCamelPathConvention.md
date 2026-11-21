# 应用驼峰路径探测约定（App Camel Path）

> 状态：控制器主导锁定（方案 A）已落地  
> 日期：2026-08-04

## 问题

多驼峰应用名（如 `CreatorHub`）存在两套磁盘布局：

- **拆段**：`Creator/Hub`（历史 `TMPPATH`）
- **扁平**：`CreatorHub`（现代默认）

若只按「应用根是否存在」探测，可能根对了但控制器在另一布局；M/V/S 也可能跟错根。

## 硬性约定

| 概念 | 规则 | 示例 |
|------|------|------|
| **逻辑名** | URL、`APPNAME`、FQCN、Node、Runtime 键 | `CreatorHub` |
| **磁盘根** | 本请求由**控制器探测**锁定 | `…/Addons/Creator/Hub/` |
| **探测顺序（根）** | ① 拆段 ② 扁平 ③ 大小写不敏感 | 先 `Creator/Hub` |
| **探测成功（方案 A）** | 该根下命中控制器源文件任一 | 见下 |
| **命名空间 / Node** | 一律扁平逻辑名 | `APP\Addon\CreatorHub` |

### 控制器命中条件（任一即可锁定该根）

在候选 `{root}` 下：

1. `Controls/{Control}.class.php`
2. `Controls/Hallmark/{HALLMARK}/{Control}.class.php`（有 HALLMARK）
3. `Controls/Tenant/{TENANT_ID}/{Control}.class.php`（有租户）

Addon 额外要求 `{root}/conf.php` 存在。

### 锁定后本请求

写入（请求作用域 `C()`）：

- `APP_PATH_LOCKED` = true  
- `APP_FS_REL` / `APP_FS_ROOT` / `APP_PATH` = 锁定根  
- `APPNAME` = 逻辑名  

此后 **同应用** 的 M / V / S / Validate / Schema / 语言包 / 模板均经 `Bootstrap::resolveAppRoot()` 返回锁定根。  
层内覆写顺序不变：**Hallmark → Tenant → 公共**（均相对锁定根）。

### 跨应用

`M('Admin://…')` / `S('Portal://…')` 目标应用 ≠ 当前 `APPNAME` 时**不使用**本请求锁，各自独立探测（或目标应用无控制器上下文时回退根目录探测）。

## 统一入口

```text
AppPathResolver::probeByControl($kind, $app, $control)
AppPathResolver::lockByControl(...)   → 写 C() 锁
AppPathResolver::lockedRootFor($app)  → 当前请求锁
AppPathResolver::clearRequestLock()   → Workerman/Swoole 请求末清理

Bootstrap::resolveAppRoot($app)
  → 若 lockedRootFor($app) 有值则直接返回
  → 否则 Addon / Application 根探测（兼容无控制器场景：CLI、bindApp）
```

**禁止**业务代码手写驼峰拆路径拼磁盘。

## Runtime 缓存键

仍用**逻辑名**（扁平）：`Runtime/Comps/{Logical}/…`、`Runtime/Comps/Addons/{Logical}/…`。

## 验收

- `/CreatorHub/Monitor/index.html`：锁定 `Addons/CreatorHub`（或仅有拆段时的 `Addons/Creator/Hub`），模板与 Model 同源根  
- 租户 `Controls/Tenant/{id}/Monitor.class.php` 可单独带活该布局根  
- 跨应用 `S('Admin://…')` 不串用 CreatorHub 锁  
