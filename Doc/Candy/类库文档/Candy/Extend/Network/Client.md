# Client 客户端信息获取类

## 类简介

`Candy\Extend\Network\Client` 采集访客 IP、浏览器、操作系统、地理位置与语言。

**能力要点：**

- IP：IPv4/IPv6、可信代理链（防伪造 XFF）、请求级缓存（不再写 session）
- 默认 `$adv=true`：仅当 `REMOTE_ADDR` 为私网/配置的可信代理时才解析转发头
- 浏览器：Edge(`Edg`) 优先于 Chrome；微信 / Opera / QQ 等
- 操作系统：iOS→Android→Windows→macOS→Linux 顺序
- 地理：`Http::get` 短超时 + JSON/GBK；配置见 `Source/Inc/Client.inc.php`

## 命名空间

```php
namespace Candy\Extend\Network;
```

## 主要方法

| 方法 | 说明 |
|------|------|
| `getClientIp(int $type=0, bool $adv=true)` | 客户端 IP；type=1 为 IPv4 长整型字符串 |
| `getPreferredLang()` | Accept-Language 中 q 最高标签 |
| `getUserLang()` | Accept-Language 原文（已转义） |
| `getLocation($ip='', $api='')` | 地理位置文案 |
| `getUserBrowser()` / `getUserOS()` | 浏览器 / 操作系统 |
| `getUrlSource()` / `getUserAgent()` | Referer / UA（展示用转义） |
| `isPrivateIp()` / `normalizeIp()` / `ipInRange()` | IP 辅助 |

## 配置

`Source/Inc/Client.inc.php`：

```php
return [
    'trusted_proxies' => [], // 公网 CDN/LB 的 IP 或 CIDR
    'location_api' => 'https://whois.pconline.com.cn/ipJson.jsp?json=true&ip={ip}',
    'location_timeout' => 3,
];
```

## 示例

```php
use Candy\Extend\Network\Client;

$ip = Client::getClientIp();           // 默认可解析可信代理链
$ipOnly = Client::getClientIp(0, false); // 仅 REMOTE_ADDR
$browser = Client::getUserBrowser();
$os = Client::getUserOS();
$lang = Client::getPreferredLang();
$loc = Client::getLocation();
```

## 注意

1. 公网反代请配置 `trusted_proxies`，否则不会采信客户端伪造的 XFF。
2. 地理查询依赖外网，失败返回「未知」；超时默认 3 秒。
3. UA 解析用原始串；`getUserAgent()` 等展示接口才 htmlspecialchars。
