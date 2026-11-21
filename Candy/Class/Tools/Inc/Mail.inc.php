<?php
/**
 * 邮件服务配置（框架默认）
 *
 * 类库：Mail\Server / Mail\Client / Mail\Protocols\{Pop3,Imap}
 * 用途：POP3(110/995) + IMAP(143/993) 邮件服务器，供客户端收信。
 *
 * ⚠️ 端口默认避开 110/143（<1024 需要 root），改用 2110/2143；
 *    要对外提供标准端口，请在项目侧覆盖并把服务以 root 或加端口转发。
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 15:40:00
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

return [
    // POP3 端口（明文）
    'pop3_port' => 2110,
    // POP3 over SSL
    'pop3_ssl_port' => 2995,
    // IMAP 端口（明文）
    'imap_port' => 2143,
    // IMAP over SSL
    'imap_ssl_port' => 2993,
    // 证书（两者都非空才启用 SSL 监听）
    'ssl_cert' => '',
    'ssl_key' => '',
    // 单 worker 最大连接数 / 最大请求数（0 = 不限）
    'max_connections' => 1000,
    'max_requests' => 10000,
    // 每个协议起几个 worker
    'count' => 2,
    // 端口复用（多 worker 抢同一端口减惊群；一般关）
    'reuse_port' => false,
    // 登录账号：[用户名 => 明文密码]
    // ⚠️ 占位值，上线前务必改成自己的
    'users' => [
        'test' => 'test',
    ],
];
