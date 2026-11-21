---
title: Hook 钩子系统：从 tags.php 到行为类
description: 梳理 Candy 事件标签注册、执行顺序与 ApplySiteBrandLang 等典型行为。
date: 2026-07-08
tags: Hook, PHP, Candy
featured: true
---

# Hook 钩子系统

在 `Application/tags.php` 或应用级 `tags.php` 注册行为类，框架在请求生命周期关键节点触发。

## 典型标签

- `app_init`：应用初始化
- `view_filter`：模板输出前处理

行为类放在 `Source/Class/Behaviors/`，实现约定接口即可接入。
