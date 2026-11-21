---
title: Admin 后台 i18n 语言包同步
description: zh-cn 维护、sync-admin-langs 与多语言 MENU 注解对齐实践。
date: 2026-07-05
tags: i18n, Admin, 多语言
---

# Admin 后台 i18n

开发期只维护 `zh-cn` 语言包，其他语言通过 `sync-admin-langs.php` 批量补齐键名。

```bash
php raw/doc/sync-admin-langs.php
php raw/doc/translate-admin-langs.php
```

控制器菜单名默认读 `#[Annotation(title:)]`，非 zh-cn 可维护 `MENU_*` 项。
