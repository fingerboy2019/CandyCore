<?php
/***
 * 框架内置端点：存活探测（最小代价，不查库）
 *
 * 业务说明：仅供负载均衡 / 进程守护做「进程还活着」判断；
 * 需要业务健康度（DB / 磁盘）请用 health 端点。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-13 17:30:00 $
 *
 * @package Candy\Extend\Tools\Endpoint
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Ping
{
    /**
     * 返回固定响应
     *
     * @return string JSON
     */
    public static function check(): string
    {
        Registry::authorize('sys/ping');

        return (string) json_encode([
            'pong' => true,
            'time' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
