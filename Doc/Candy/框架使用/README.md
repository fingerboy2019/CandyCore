# 框架使用指南

面向 **Application 层** 开发的专题文档。分层与命名约定以 **[raw/doc/MVCSCallConvention.md](../../../raw/doc/MVCSCallConvention.md)** 为准。

## 入门

| 文档 | 说明 |
|------|------|
| [项目模式](项目模式.md) | 单项目 / 多项目（ALLINONE） |
| [项目构建](项目构建.md) | `?start` 初始化、`?add` 创建应用 |
| [配置管理](配置管理.md) | `Source/Inc/*.inc.php` |

## MVCS 分层

| 层 | 目录 | 文档 |
|----|------|------|
| Control | `Application/{App}/Controls/` | [控制器使用](控制器使用.md) |
| Model | `Application/{App}/Models/` | [模型使用](模型使用.md) |
| Service | `Application/{App}/Services/` | 见 raw/doc/MVCSCallConvention（控制器协调 S/M，业务编排在 Service） |
| Validate | `Application/{App}/Validates/` | [自动验证](自动验证.md)（类规则，非 XML） |
| Schema | `Application/{App}/Schemas/` | [响应 Schema](响应Schema.md) |
| View | `Views/` 或 `Template/` | [模板使用](模板使用.md) · [模板/模板系统学习文档](模板/模板系统学习文档.md) |

运行时类名：源码 `Index` → `IndexAction`；`Order` Model → `OrderModel`；`Order` Service → `OrderService`。

## 请求与数据

| 文档 | 说明 |
|------|------|
| [路由使用](路由使用.md) | 静态/动态路由、PATHINFO |
| [中间件使用](中间件使用.md) · [中间件/中间件开发规范](中间件/中间件开发规范.md) | 中间件 |
| [缓存使用](缓存使用.md) | 缓存驱动与页面缓存 |
| [多数据库切换功能说明](多数据库切换功能说明.md) | 多库连接 |
| [自动验证](自动验证.md) | Validate 入参校验（类规则） |
| [响应 Schema](响应Schema.md) | Schema 出参 data 形状 |

## 安全与权限

| 文档 | 说明 |
|------|------|
| [安全防护](安全防护.md) | Security、过滤 |
| [权限节点](权限节点.md) | Node、`#[Annotation]` |
| [认证/登录功能说明](认证/登录功能说明.md) | Admin 登录 |
| [认证/登录规范](认证/登录规范.md) | 登录开发规范 |
| [认证/JwtToken使用指南](认证/JwtToken使用指南.md) | JWT |
| [认证/混合认证使用指南](认证/混合认证使用指南.md) | Cookie + JWT |

## 其他能力

| 文档 | 说明 |
|------|------|
| [语言功能](语言功能.md) | 多语言（规范见 [raw/doc/LangConvention.md](../../../raw/doc/LangConvention.md)） |
| [事件功能](事件功能.md) | Event |
| [日志使用](日志使用.md) | Log |
| [调试使用](调试使用.md) | Debug |
| [类库使用](类库使用.md) | 类库引入 |
| [扩展使用](扩展使用.md) | `Source/Class` 扩展 |
| [插件使用](插件使用.md) | Plugins 静态钩子与类扩展 |
| [Hook 钩子系统](Hook钩子系统.md) | `Hook` 类、双轨机制、扩展选型与开发指引 |
| [Hook 标签清单](Hook标签清单.md) | `listen()` 内置标签速查与 `tags.php` 注册 |
| [TAG功能](TAG功能.md) | 模板标签 |
| [博客模式](博客模式.md) | Markdown 博客 |
| [函数替代](函数替代.md) | 框架函数替代原生 |
| [PHP8.4 新特性支持分析](PHP8.4 新特性支持分析.md) | PHP 8.4 |

## 常见过时写法（请勿照抄旧示例）

| 错误 | 正确 |
|------|------|
| `Application/Controller/` | `Application/{App}/Controls/` |
| `namespace App\Controller` | `namespace APP\Control\{App}` |
| `config/database.php` | `Source/Inc/Config.inc.php` |
| `php build.php` / `php run.php` | `php index.php` / `php candy` |
| 控制器方法名 `init()` 作业务接口 | 避免与 `Action::working()` 生命周期钩子冲突 |

返回总索引：[../README.md](../README.md)
