---
title: Markdown 博客模式与安全目录
description: 随机 content_dir、路由劫持与 Nginx 拦截，避免正文被直接 URL 猜测下载。
date: 2026-07-12
tags: Markdown, 博客, 安全, UrlParser
featured: true
weight: 4
cover: https://picsum.photos/seed/candy-blog-md/1200/360
---

# Markdown 博客模式与安全目录

博客正文存放在 `Doc/Blog/{content_dir}/` 下，公开 URL **不含** 目录名与 `post` 段。

## 访问示例

- 首页：`/Blog/`
- 分类：`/Blog/默认分类/`
- 文章：`/Blog/默认分类/Markdown博客模式`

## Front Matter

首页 feed、轮播、标签云均从文章元数据聚合，常用字段：`title`、`date`、`tags`、`featured`、`cover`。
