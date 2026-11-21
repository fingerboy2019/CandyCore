<?php
/***
 * 文章上一篇 / 下一篇
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

if (blog('article.password')) {
    return;
}

$prev = (array) blog('article.prev');
$next = (array) blog('article.next');
?>
<nav class="blog-pager article-navigation" aria-label="文章导航">
    <div class="blog-pager-prev nav-prev">
        <?php if (!empty($prev['url'])) : ?>
        <span class="blog-pager-label nav-label">上一篇</span>
        <a href="<?= blog_esc($prev['url']) ?>"><?= blog_esc($prev['title'] ?? '') ?></a>
        <?php endif; ?>
    </div>
    <div class="blog-pager-next nav-next">
        <?php if (!empty($next['url'])) : ?>
        <span class="blog-pager-label nav-label">下一篇</span>
        <a href="<?= blog_esc($next['url']) ?>"><?= blog_esc($next['title'] ?? '') ?></a>
        <?php endif; ?>
    </div>
</nav>
