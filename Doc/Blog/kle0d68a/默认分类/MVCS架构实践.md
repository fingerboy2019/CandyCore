---
title: Candy 框架 MVCS 架构实践
description: 从 Controls、Services、Models 到 Views 的分层职责梳理，以及 Workerman 常驻进程下的开发体验与缓存策略。
date: 2026-07-10
tags: PHP, Candy, MVCS, Workerman
featured: true
weight: 5
cover: https://picsum.photos/seed/candy-mvcs/1200/360
---

# Candy 框架 MVCS 架构实践

Candy 采用 **MVCS** 四层：Controls 接收请求、Services 编排业务、Models 访问数据、Views 负责展示。

## 分层职责

| 层 | 职责 |
|----|------|
| Control | HTTP 入口、权限注解、协调 S/M |
| Service | 跨 Model 编排、无单表 CRUD |
| Model | 单表读写、SQL |
| View | 模板展示 |

## Runtime 缓存

修改 Controls/Models/Services 后需清理 `Runtime/`（保留 `init.lock`），否则 Web 进程可能加载旧编译类。
