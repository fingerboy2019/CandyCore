<?php
/***
 * 推送网关配置（框架默认值）
 *
 * 只放「不因站点而异」的骨架；**应用注册表（apps，含 app_key）属于站点数据**，
 * 仍写在本站点的 Source/Inc/Push.inc.php 里 —— 密钥不能进框架目录，否则同步到其他站点会泄露。
 *
 * 可配置项（留空即用 Candy\Extend\Tools\Push\Config::defaults()）：
 *   gateway.listen   默认 websocket://0.0.0.0:4020
 *   gateway.ssl      enable / cert / key / verify_peer
 *   register.listen  默认 text://127.0.0.1:1236
 *   offline          enable / ttl / max / persist
 *   limits           conns_per_ip / frames_per_second
 *   audit            enable / days
 *
 * @package Candy\Extend\Tools\Push
 */
return [];
