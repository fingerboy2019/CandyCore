> 本文档最后修订：**2026-07-15**

# Candy 框架使用说明

Candy 是基于 **Workerman 5.1** 的 **PHP 8.4+ MVCS** 框架，支持 Web（Nginx/Apache + PATHINFO）与 CLI（`php candy`）双模式。

**当前文档范围**：`backend/Doc/Candy/` — 框架能力、类库 API、扩展与第三方集成。  
**项目开发约定**（Admin MVCS、语言包、权限、脚手架等）见同级目录 **[`../../raw/doc/`](../../raw/doc/)**。

---

## 文档地图

| 分类 | 目录 | 说明 |
|------|------|------|
| 入门 | [安装指南](安装指南.md) · [快速入门](快速入门.md) | 环境、配置、首个应用 |
| 框架使用 | [框架使用/](框架使用/README.md) | 控制器、模型、服务、模板、路由、缓存、权限等 |
| 全局函数 | [函数库/](函数库/README.md) | `C()` / `D()` / `M()` / `S()` / `A()` / `L()` 等 |
| 数据库 | [数据库/](数据库/简述.md) | DBServer、查询、Where、多库 |
| 扩展类库 | [扩展类库/](扩展类库/索引.md) | `Candy\Vendor\Extend` 工具类使用说明 |
| 第三方集成 | [第三方类库/](第三方类库/README.md) | Workerman 组件、Console、QL、Storage 等 |
| API 参考 | [类库文档/](类库文档/README.md) | 与 `backend/Candy/` 源码对应的类/方法文档 |
| 进阶 | [高级应用/](高级应用/README.md) | CLI、Markdown/Mermaid、Bootstrap 等 |
| 变更记录 | [框架更新记录](框架更新记录.md) | 版本历史 |

### 与 `raw/doc/` 的分工

| 文档 | 用途 |
|------|------|
| [MVCSCallConvention.md](../../raw/doc/MVCSCallConvention.md) | **Controls / Models / Services / Views** 分层与调用规范 |
| [LangConvention.md](../../raw/doc/LangConvention.md) | 多语言包维护（取代已删除的「语言规范」） |
| [AuthConvention.md](../../raw/doc/AuthConvention.md) | 权限、Node、`#[Annotation]` |
| [MVCSArchitecture.md](../../raw/doc/MVCSArchitecture.md) | 架构总览 |
| [sql/README.md](../../raw/doc/sql/README.md) | 开发库脚手架 SQL 与 `scaffold:init` |

仓库根目录 [`AGENTS.md`](../../AGENTS.md) 为 AI/开发速查，路径与约定以该文件与 **DEV** 为准。

---

## 核心目录（项目初始化后）

```
backend/
├── Candy/              # 框架核心（勿随意改）
├── Application/        # 应用 MVCS
│   └── {App}/
│       ├── Controls/   # 控制器（编译为 *Action）
│       ├── Models/     # 数据层（*Model）
│       ├── Services/   # 业务层（*Service）
│       ├── Views/      # 视图（可选，Home 可用 Template/）
│       └── Languages/
├── Source/
│   ├── Inc/            # Init.inc.php、Config.inc.php、Route.inc.php …
│   ├── Class/          # APP\Extend 项目扩展类
│   └── Common/         # 各应用 Base
├── Template/           # Home 默认模板（System.inc.php 中 $tplpath）
├── Runtime/            # 编译与缓存（保留 init.lock）
├── Plugins/ · Addons/
├── index.php           # Web 入口
└── candy               # CLI 入口
```

---

## 入口与 URL

| 模式 | 入口 | 示例 |
|------|------|------|
| Web | `index.php` | `https://domain/Admin/Login/index.shtml` |
| CLI | `php candy <cmd>` | `php candy scaffold:init` |

- 默认应用 **Home** 可省略应用名：`/Index/index.html`
- Admin 伪静态后缀多为 **`.shtml`**（见 `Source/Inc/Route.inc.php`）
- 调试模式：`Source/Inc/Init.inc.php` 中 `DEBUG = 1`

---

## 配置要点

- 主配置：`Source/Inc/Init.inc.php`（DEBUG、模板路径）
- 数据库：`Source/Inc/Config.inc.php` → `DB.default`（**不是** `config/database.php`）
- 路由：`Source/Inc/Route.inc.php`、`HomeRoute.inc.php`
- CLI 服务：`Source/Inc/Server.inc.php`

常量请使用 `defined('X') OR define('X', …)`，避免进程模式下重复定义。

---

## Workerman 定制说明

内置 Workerman 已针对 Candy 修改（Autoloader、Worker CLI、Http 缓存键、Response gzip/max-age、Session 与框架缓存驱动等）。**请勿按上游 Workerman 文档直接套用全部行为**。详见上文各子文档及 [框架更新记录](框架更新记录.md)。

---

## 缓存与排错

修改 Controls / Models / Services / Views 或模板后若未生效，清理 `Runtime/`（**保留** `init.lock`）：

```bash
find backend/Runtime -mindepth 1 ! -name 'init.lock' -exec rm -rf {} +
```

修改 `#[Annotation]` 菜单/权限后执行：`php candy clear --node` 或 `php candy deploy:post`。

---

## 版权声明

本框架遵循 Apache-2.0 开源协议，请勿删除源码中的版权信息。
