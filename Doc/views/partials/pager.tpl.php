<?php
/***
 * 文档上一篇 / 下一篇
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

if (doc('article.password')) {
    return;
}

$prev = (array) doc('article.prev');
$next = (array) doc('article.next');
?>
<div class="article-navigation" aria-label="文档导航">
    <?php if (!empty($prev['url'])) : ?>
    <div class="nav-prev">
        <div class="nav-label"><a href="<?= doc_esc($prev['url']) ?>"> Prev: <?= doc_esc($prev['title'] ?? '') ?></a></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($next['url'])) : ?>
    <div class="nav-next">
        <div class="nav-label"><a href="<?= doc_esc($next['url']) ?>"> Next: <?= doc_esc($next['title'] ?? '') ?></a></div>
    </div>
    <?php endif; ?>
</div>
