<?php
/***
 * 门户顶栏细条
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="blog-topbar">
    <div class="blog-topbar-inner">
        <div class="blog-topbar-left">
            <span class="blog-topbar-site"><?= blog_esc(blog('name')) ?></span>
            <span class="blog-topbar-sep" aria-hidden="true">|</span>
            <span class="blog-topbar-tagline"><?= blog_esc(blog('description') ?: '欢迎使用 Candy Markdown 博客') ?></span>
        </div>
        <div class="blog-topbar-right">
            <a href="/Doc/">框架文档</a>
            <a href="https://github.com/fingerboy2019/Candy" target="_blank" rel="noopener">GitHub</a>
            <?php if (blog('rss_url')) : ?>
            <a class="blog-rss" href="<?= blog_esc(blog('rss_url')) ?>" target="_blank" rel="noopener" title="RSS 订阅" aria-label="RSS 订阅">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M6.18 17.82a2.18 2.18 0 1 1 0 4.36 2.18 2.18 0 0 1 0-4.36zM4 4.44v3.13c7.02 0 12.43 5.41 12.43 12.43h3.13C19.56 11.32 12.68 4.44 4 4.44zM4 10.1v3.13c3.72 0 6.77 3.05 6.77 6.77h3.13A9.9 9.9 0 0 0 4 10.1z"/>
                </svg>
                RSS
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
