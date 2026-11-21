<?php
/***
 * 门户主导航顶栏
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$showAsideToggle = (blog('tpl') === 'post') && blog('layout.sidebar_enabled');
?>
<header class="blog-masthead">
    <div class="blog-masthead-inner">
        <a href="<?= blog_esc(blog('home_url')) ?>" class="blog-brand blog-brand--portal">
            <span class="blog-brand-mark" aria-hidden="true">C</span>
            <span class="blog-brand-text"><?= blog_esc(blog('name')) ?></span>
        </a>
        <nav class="blog-mastnav" aria-label="站点导航">
            <?php $mastnavVariant = 'desktop'; include __DIR__ . '/mastnav.tpl.php'; ?>
        </nav>
        <div class="blog-masthead-actions">
            <?php if ($showAsideToggle) : ?>
            <button type="button" class="blog-aside-toggle" aria-label="打开目录" aria-expanded="false">目录</button>
            <?php endif; ?>
            <?php include __DIR__ . '/search-box.tpl.php'; ?>
            <div class="blog-masthead-tools">
                <?php include __DIR__ . '/toolbar-tools.tpl.php'; ?>
            </div>
        </div>
        <button type="button" class="blog-mastnav-toggle" aria-label="打开菜单" aria-expanded="false">☰</button>
    </div>
    <div class="blog-mastnav-drawer" hidden>
        <?php $mastnavVariant = 'drawer'; include __DIR__ . '/mastnav.tpl.php'; ?>
    </div>
</header>
