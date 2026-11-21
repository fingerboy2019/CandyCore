<?php
/***
 * 文档站模板全局辅助函数
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Doc\Site\Context;

if (!function_exists('doc')) {
    function doc(string $key, mixed $default = null): mixed
    {
        return Context::get($key, $default);
    }
}

if (!function_exists('doc_esc')) {
    function doc_esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('doc_nav')) {
    /**
     * 侧栏导航 inner HTML（&lt;ul&gt; 内的 li 列表）
     *
     * @return string
     */
    function doc_nav(): string
    {
        return (string) doc('sidebar.nav_html', '');
    }
}
