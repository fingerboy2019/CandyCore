---
title: UrlParser 路由劫持与单入口分流
description: /Doc/、/Blog/ 与 MVCS 三轨并行时的请求分流要点。
date: 2026-07-14
tags: UrlParser, 路由, Candy
featured: true
weight: 4
category: 框架使用
cover: https://picsum.photos/seed/candy-url/1200/360
---

# UrlParser 路由劫持

`UrlParser::hijackMarkdownRoutes()` 在 MVCS 分发前拦截 `/Doc/`、`/Blog/` 前缀，转交 `Markdown::start()`。

## 安全

- 拒绝 URI 中出现 `content_dir` 或 `post`
- Web 禁止直访 `Doc/Blog/views/` 与正文目录
