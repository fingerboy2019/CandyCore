<?php
/***
 * Markdown 文档模式页面引导
 *
 * 组装 $doc 上下文并载入标准 HTML 布局；无大段字符串拼接
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 *
 * @package Candy\Extend\Doc
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$doc = $doc ?? \Candy\Extend\Doc\Site\Context::viewData();
$viewsRoot = C('CANDYROOT') . 'Doc/views/';

if (I('server.HTTP_X_REQUESTED_WITH', '', '') === 'XMLHttpRequest') {
    ob_start();
    include $viewsRoot . 'partials/doc-action-buttons.tpl.php';
    $docActionsHtml = ob_get_clean();

    ob_start();
    include $viewsRoot . 'partials/article-body.tpl.php';
    $bodyHtml = ob_get_clean();

    $res = [
        'title' => doc('article.title'),
        'header' => doc('head.favicon') . doc('head.open_graph') . doc('head.keywords')
            . doc('head.description') . doc('head.title') . doc('head.assets'),
        'nav' => doc_nav(),
        'body' => $bodyHtml,
        'docActions' => $docActionsHtml,
    ];
} else {
    $docLayoutBody = $viewsRoot . 'layouts/page.tpl.php';
    ob_start();
    include $viewsRoot . 'layouts/shell.tpl.php';
    $content = ob_get_clean();
}
