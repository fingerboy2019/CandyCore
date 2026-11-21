<?php
/***
 * 文章页左侧目录
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

if (!blog('layout.sidebar_enabled') || blog('tpl') !== 'post') {
    return;
}
?>
<aside class="blog-aside blog-aside--toc" aria-label="文章目录">
    <p class="blog-aside-label">本页目录</p>
    <nav class="blog-nav">
        <ul>
            <?= blog('article.sidebar_nav_html') ?>
        </ul>
    </nav>
    <div class="blog-aside-foot">Powered by <a href="https://github.com/fingerboy2019/Candy" target="_blank" rel="noopener noreferrer">Candy</a></div>
</aside>
