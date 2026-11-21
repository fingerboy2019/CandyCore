<?php
/**
 * 常驻 Web 服务配置（框架默认值）
 *
 * 这里只是「能让服务启动」的默认值。服务不会被自动启动——只有你在
 * /sys/service 点「开启」、或执行 php candy worker start 时才会跑，
 * 所以不需要额外的 enable 开关（能力前置检查由 Doctor 预检负责）。
 *
 * 要改端口/域名/worker 数，就在项目里建 Source/Inc/Server.inc.php 覆盖同名键：
 *
 *   return ['webserver' => ['server' => [['list' => [
 *       ['domain' => 'okr.kexinlai.com', 'port' => 8008, 'count' => 2],
 *   ]]]]];
 *
 * 服务管理：/sys/service 里的「常驻 Web 服务」；配置：/sys/config 的 Server 项。
 *
 * @package Candy\Extend\Tools\Service
 */
return [
    'webserver' => [
        // Swoole Web 服务单独端口：与 Workerman「常驻 Web 服务」共用同一份 server 列表，
        // 默认错开端口，两者可以同时开启（0 或未配 = 沿用下面 list 里的 port）
        'swoole' => [
            'port' => 8009,
        ],
        'server' => [
            [
                'list' => [
                    [
                        'domain'      => '*',          // 域名（支持泛域名；* = 不限 Host）
                        'root'        => C('CANDYROOT'), // 站点根目录
                        'port'        => 8008,
                        'count'       => 1,            // worker 数（生产建议 CPU 核数 ×1~2）
                        'max_request' => 0,            // 单 worker 累计请求数上限，0 = 不限
                        'reuse_port'  => false,        // 多 worker 监听同端口减惊群
                        'gzip'        => 2,            // 压缩等级 1-9，0 = 不压缩
                        'max-age'     => 3600,         // 静态缓存秒数
                    ],
                ],
            ],
        ],
    ],
    // 'reload' => 1800,   // 定时自动 reload（秒）
];
