<?php
/***
 * 安全相关全局函数
 *
 * 响应过滤器只接受「函数名字符串」（HookPipeline 用 function_exists 判定），
 * 所以这里为 DebugNotice 提供一个全局函数入口。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-21 21:06:00 $
 *
 * @package Candy\Func
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

if (!function_exists('candyDebugNoticeInject')) {
    /**
     * DEBUG 提醒注入器（响应级过滤器入口）
     *
     * @param string $html 响应内容
     * @param array $context 响应上下文（HookPipeline 传入）
     * @return string
     */
    function candyDebugNoticeInject(string $html, array $context = []): string
    {
        return \Candy\Extend\Security\DebugNotice::inject($html, $context);
    }
}
