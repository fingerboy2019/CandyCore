# 应用驼峰路径框架兜底 Implementation Plan

> **For Cursor Agent:** 按任务推进；约定见 `Doc/DEV/AppCamelPathConvention.md`。

**Goal:** 全框架磁盘路径统一「拆段优先、扁平兜底」，逻辑名与磁盘路径分离。

**Architecture:** `AppPathResolver` 为唯一探测实现；Addon / Bootstrap / 模板 / 路由只消费逻辑名 + fsRel。

**Tech Stack:** PHP 8.4、Candy Core

**Design refs:** `backend/Doc/DEV/AppCamelPathConvention.md`

---

### Task 1: 落地 AppPathResolver + 控制器主导锁定（本期）

**Objective:** 按 Controls（含 Hallmark/Tenant）探测并锁定本请求 APP_PATH；同应用 MVS 跟锁。

**Status:** ✅ 已完成（2026-08-04）

**Files:**
- `AppPathResolver.class.php`（probeByControl / lockByControl / lockedRootFor）
- `Bootstrap::resolveAppRoot`、`RouteContextInit`、`AppDispatcher`、`initTplPath`
- Workerman/Swoole 请求末清理锁键
- `AppCamelPathConvention.md`

---

### Task 2: Plugins / Node 扫描渐进（下期）

**Objective:** `RAPPNAME`、Node `getAuthList` 扫盘时对多驼峰应用名调用同一探测。

**Files:** `Hook`、`CompileCache` plugRoot、`Node::getAppAuthList` 相关

---

### Task 3: Generator 默认扁平（下期）

**Objective:** `php candy generate` / Build 默认生成扁平目录；文档说明可选拆段。
