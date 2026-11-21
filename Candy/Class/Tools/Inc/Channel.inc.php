<?php
/**
 * Channel 服务配置（框架默认）
 *
 * 类库：Candy\Vendor\Workerman\Channel（跨进程 pub/sub + 队列，frame:// 协议）
 * 客户端：Channel\Client
 *
 * 与 GlobalData 的分工：
 *   GlobalData = 共享 KV（读写单个键）
 *   Channel    = 广播 / 发布订阅（一条消息推给所有订阅者）+ 队列
 *
 * @package Candy\Extend\Tools\Service
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 15:05:00
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

return [
    // 监听地址：默认只给本机用（跨进程通信），要跨机器才改 0.0.0.0
    'host' => '127.0.0.1',
    // 监听端口
    'port' => 2206,
];
