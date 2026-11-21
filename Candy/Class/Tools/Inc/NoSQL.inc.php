<?php
/**
 * NoSQL 服务配置（框架默认）
 *
 * 类库：Candy\Vendor\Workerman\NoSQL（类 Redis 的内存 KV 服务，多用户 / 多库 / 认证 / 限流）
 * 客户端：NoSQL\Client（binary:// 协议）
 *
 * 这份是「装上就能跑」的默认值；要改就在 Source/Inc/NoSQL.inc.php 覆盖同名键。
 *
 * ⚠️ users 必须写明文 user:pass —— 认证协议要求服务端持有明文复算 hash(sha256, 密码+挑战码)，
 *    不支持 password_hash。
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 15:05:00
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

return [
    // 监听地址（0.0.0.0 = 对外，127.0.0.1 = 仅本机）
    'host' => '0.0.0.0',
    // 监听端口
    'port' => 2404,
    // 认证用户：['app' => 'secret']，为空 = 不校验（仅建议在 127.0.0.1 时使用）
    'users' => [
        'default' => 'default',
    ],
    // 落盘目录；空 = Runtime/NoSql/dump
    'dump_dir' => '',
    // 全局限流（每秒请求数）
    'max_rps' => 1000,
    // 单用户限流
    'max_rps_per_user' => 100,
];
