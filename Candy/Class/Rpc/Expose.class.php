<?php
/***
 * Rpc 方法暴露标记
 *
 * 业务说明：标注在服务类方法上，表示允许远程调用；类上若存在任一 Expose，则仅这些方法可调
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:34:53 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 标记可远程调用的方法（非控制器，任意 Rpc 服务类方法均可）
 *
 * 规则优先级见 Dispatcher / Registry：
 * 1. 配置 services[name].methods 白名单（若有）
 * 2. 否则类上存在 #[Expose] 时仅允许带标记的方法
 * 3. 否则允许全部公开实例方法（兼容未标注的旧类）
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Expose
{
}

