<?php
/***
 * 文档站页面 HTML 外壳
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$bodyAttr = '';
if ((string) doc('layout.body_class') !== '') {
    $bodyAttr .= ' class="' . doc_esc(doc('layout.body_class')) . '"';
}
if ((string) doc('layout.doc_theme') !== '') {
    $bodyAttr .= ' data-doc-theme="' . doc_esc(doc('layout.doc_theme')) . '"';
}
?>
<!DOCTYPE html>
<html lang="<?= doc_esc(doc('layout.html_lang')) ?>">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= doc('head.favicon') ?>
    <?= doc('head.title') ?>
    <?= doc('head.keywords') ?>
    <?= doc('head.description') ?>
    <?= doc('head.canonical') ?>
    <?= doc('head.open_graph') ?>
    <?= doc('head.assets') ?>
</head>
<body<?= $bodyAttr ?>>
<?php include $docLayoutBody; ?>
</body>
</html>
