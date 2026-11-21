# Firebase PHP-JWT 旁路说明见上级；本目录为 PHP QR Code 编码内核

# PHP QR Code（phpqrcode）

- Upstream: https://github.com/t0k4rt/phpqrcode（SourceForge phpqrcode）
- 许可: **LGPL-3.0**
- 文件: 拆分为 `QRcode/*.class.php`，由 Candy 自动加载器按 `QRcode\\类名` 加载；禁用 cache。
- 框架封装: `Candy\Extend\Qr`（业务请走封装；由封装用 GD 渲染 PNG）
- 文档: `Doc/Candy/扩展类库/Qr使用文档.md`

**说明**：选「纯 GD / 无 Composer endroid」方案时，编码算法仍需可靠实现，故 Vendor 本内核；渲染由 `Qr` 自行 GD 绘制，不调用其带 PHP 8.4 形参告警的 `QRimage::png`。
