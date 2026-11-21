# 第三方与 Vendor 集成

框架在 `Candy/Vendor/` 下集成的第三方能力使用说明。

## 通用

| 文档 | 说明 |
|------|------|
| [Console使用文档](Console使用文档.md) | `php candy` 命令行 |
| [Apidoc使用文档](Apidoc使用文档.md) | API 文档生成 |
| [Check使用文档](Check使用文档.md) | 敏感词检测 |
| [QL使用文档](QL使用文档.md) | QueryList 采集 |
| [Storage存储引擎使用文档](Storage存储引擎使用文档.md) | 对象存储抽象 |
| [Firebase JWT使用文档](Firebase JWT使用文档.md) | JWT 库 |
| [Revolt 事件循环库使用文档](Revolt 事件循环库使用文档.md) | Revolt EventLoop |
| [Catlog使用文档](Catlog使用文档.md) | 性能分析（Xhprof） |

## Workerman 生态

目录：[Workerman类/](Workerman类/Workerman.md)

含 WebServer、GatewayWorker、Crontab、RedisQueue、GlobalData、MQTT、Socket.IO，以及 Candy 定制入站层 [Http](Workerman类/Http使用文档.md)（`Workerman\Http\Http`）等组件文档。框架级远程调用见 `Candy\Extend\Rpc`（`rpc()` / `Source/Inc/Rpc.inc.php`）。
