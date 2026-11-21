# CandyCore

> CandyPHP 框架核心源码库 —— 唯一源（single source of truth）

本仓库只存放 **框架核心** 与 **框架文档**，不含任何项目业务代码。

## 目录

```
Candy/          框架核心
├── Core/       内核（Bootstrap / Autoload / Route / Action / Respond / Debug …）
├── Class/      扩展类库（命名空间 Candy\Extend\<模块>）
├── Func/       全局函数库（U/N/T/S/C/G/M/L/I …）
├── Vendor/     内置第三方组件（Workerman 5.2.x、Catlog、Apidoc …）
└── Tpl/        框架页面模板（如 push 监控面板）

Doc/Candy/      框架文档（框架使用 / 类库文档 / 数据库 / 高级应用）
```

## 版本

- CandyPHP **5.2.2**（见 `Candy/Version.php`）
- 运行环境：PHP **8.4+**，支持 FPM / Workerman 常驻 / Swoole 协程三种运行时

## 如何用于一个项目

**直接克隆本仓库**即为一个完整可运行的框架工程（`Candy/` + 文档站 + 两个入口都已随库提供）：

```bash
git clone git@github.com:fingerboy2019/CandyCore.git mysite
cd mysite
# 站点根指向本目录，伪静态交 index.php；首次访问 /?start 初始化
```

| 文件 | 作用 |
|---|---|
| `index.php` | Web 入口（`require Candy/Autoload.php` → `N('App')::run()`） |
| `candy` | CLI 入口（`php candy list` 等） |

> 框架代码由 `Candy/` 与 `Doc/` 组成；**站点级只剩 `.user.ini`（宝塔生成）不入库**，其余全部随库分发。

## 同步约定

- **改框架只在本仓库改**，再分发到各站点；不要在各站点的 `Candy/` 里直接改。
- 分发后必须校验：
  ```bash
  diff -qr CandyCore/Candy /www/wwwroot/<站点>/Candy
  php -l <改动文件>
  ```
- 分发到运行站点后按部署流程清 `Runtime/` 缓存（保留 `Runtime/Data/session` 与 `Runtime/Data/*.php` 除外需单独处理），再回归登录页/首页。

## 版权

© fingerboy. 遵循 Apache-2.0 开源协议，请勿删除源码中的版权信息。
