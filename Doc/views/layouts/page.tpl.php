<?php
/***
 * 文档阅读页布局
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<div class="container-fluid">
    <?php if (doc('sidebar.enabled')) :
        include dirname(__DIR__) . '/partials/sidebar.tpl.php';
        include dirname(__DIR__) . '/partials/sidebar-toggle.tpl.php';
    endif; ?>
    <?php include dirname(__DIR__) . '/partials/doc-actions.tpl.php'; ?>
    <?php include dirname(__DIR__) . '/partials/init-guide.tpl.php'; ?>
    <div class="markdown-body">
        <?php include dirname(__DIR__) . '/partials/article-body.tpl.php'; ?>
    </div>
</div>
