<?php
/***
 * 博客 404 页（使用博客主题样式）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-30 00:00:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$content = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>404 · 页面未找到</title>'
    . '<link rel="stylesheet" href="/Doc/Blog/Static/css/blog.css">'
    . '</head><body class="blog blog-dark-body">'
    . '<div class="blog-shell"><div class="blog-404">'
    . '<p class="blog-404-code">404</p>'
    . '<h1 class="blog-404-title">页面不存在或已移动</h1>'
    . '<p class="blog-404-desc">你访问的页面可能已被删除、改名或暂时不可用</p>'
    . '<div class="blog-404-actions">'
    . '<a class="blog-404-btn blog-404-btn--primary" href="/">返回首页</a>'
    . '<a class="blog-404-btn blog-404-btn--ghost" href="javascript:history.back()">返回上一页</a>'
    . '</div></div></div></body></html>';
