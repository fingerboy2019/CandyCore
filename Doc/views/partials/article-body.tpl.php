<?php
/***
 * 文档正文区（元信息 + 正文 + 翻页 + 版权）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');
?>
<?= doc('article.meta_html') ?>
<?= doc('article.content') ?>
<?php include __DIR__ . '/pager.tpl.php'; ?>
<?php include __DIR__ . '/copyright.tpl.php'; ?>
