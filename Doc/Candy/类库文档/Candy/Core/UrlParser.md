# UrlParser 类

## 类简介

负责 URL 路由模式解析、参数获取、镜像代理与 API 后缀识别。
PATH_INFO 环境初始化委托 `Candy\Core\Url\PathInfoResolver`，静态资源拦截委托 `Candy\Core\Url\StaticAssetGuard`。

## 命名空间

```php
namespace Candy\Core;
```

## 类定义

```php
final class UrlParser {
    public function __construct(?PathInfoResolver $pathInfoResolver = null);
    public static function parseUrl(): void;  // 框架入口
    public function parse(): void;            // 实例解析
}
```

## 协作类（`Candy\Core\Url\`）

| 类 | 职责 |
|----|------|
| `PathInfoResolver` | Workerman PATH_INFO、请求方法标记、路径规范化 |
| `StaticAssetGuard` | 静态资源扩展名拦截并 404 |

## 内部方法

| 方法 | 说明 |
|------|------|
| `parsePathInfoToGet()` | 将 PATH_INFO 解析为 `$_GET` 参数 |

## 项目钩子

在 `Source/Func/global.func.php` 中可定义：

```php
function setUrlParserConfig(): array
{
    return [
        // 合并到应用路由配置
    ];
}
```

## 调用位置

`Bootstrap::route()` → `UrlParser::parseUrl()`

## 作者信息

- **作者**: 刘森 (fingerboy@qq.com)
- **创建日期**: 2019-07-26
