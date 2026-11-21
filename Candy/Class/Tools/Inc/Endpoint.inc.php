<?php
/**
 * 框架端点配置（框架默认值）
 *
 * 这里的默认值只为「端点能正常工作」准备；项目改过的值请写在 Source/Inc/Endpoint.inc.php，
 * 会与这里深合并（以本文件的键为范围，超出范围的键会被丢弃）。
 *
 * @package Candy\Extend\Tools\Endpoint
 */
return [
    // 额外启用的端点（sys/* 都是框架级端点，恒注册；这里只列应用扩展端点）
    'enable'    => [],
    'token'     => '',
    'tokens'    => [],
    'endpoints' => [],

];
