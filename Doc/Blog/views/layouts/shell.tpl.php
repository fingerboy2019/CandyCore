<?php
/***
 * 博客页面 HTML 外壳
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$bodyAttr = ' class="' . blog_esc(blog('layout.body_class')) . '"';
if ((string) blog('layout.doc_theme') !== '') {
    $bodyAttr .= ' data-doc-theme="' . blog_esc(blog('layout.doc_theme')) . '"';
}
$bodyAttr .= ' data-doc-base="' . blog_esc(blog('home_url')) . '"';
?>
<!DOCTYPE html>
<html lang="<?= blog_esc(blog('layout.html_lang')) ?>">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= blog('head.favicon') ?>
    <?= blog('head.title') ?>
    <?= blog('head.keywords') ?>
    <?= blog('head.description') ?>
    <?= blog('head.open_graph') ?>
    <?= blog('head.assets') ?>
</head>
<body<?= $bodyAttr ?>>
<div class="blog-shell">
<?php include $blogLayoutBody; ?>
</div>
</body>
</html>
