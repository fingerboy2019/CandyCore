<?php
/***
 * 文档侧栏
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="sidebar">
    <div class="sidebar-header">
        <?php if ((string) doc('sidebar.logo_html') !== '') : ?>
        <a href="<?= doc_esc(doc('site.home_url')) ?>" class="title doc-title-with-logo">
            <?= doc('sidebar.logo_html') ?><?= doc_esc(doc('site.manual_title')) ?>
        </a>
        <?php else : ?>
        <a href="<?= doc_esc(doc('site.home_url')) ?>" class="title"><?= doc_esc(doc('site.manual_title')) ?></a>
        <?php endif; ?>
        <?php include __DIR__ . '/search-box.tpl.php'; ?>
    </div>
    <ul>
        <?= doc_nav() ?>
    </ul>
    <div class="doc-theme-switcher" id="doc-theme-switcher"></div>
    <div class="sidebar-copyright">
        本文档使用 <a href="https://github.com/fingerboy2019/Candy" target="_blank" rel="noopener noreferrer">Candy</a> 构建
    </div>
</div>
