<?php
/***
 * Markdown 博客模式页面引导
 *
 * 组装 $blog 上下文并载入标准 HTML 布局；无大段字符串拼接
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 *
 * @package Candy\Extend\Doc
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$blog = $blog ?? \Candy\Extend\Doc\Blog\Context::viewData();
$viewsRoot = C('CANDYROOT') . 'Doc/Blog/views/';

if (I('server.HTTP_X_REQUESTED_WITH', '', '') === 'XMLHttpRequest') {
  ob_start();
  include $viewsRoot . 'partials/toolbar-tools.tpl.php';
  $toolbarToolsHtml = ob_get_clean();

  if ($blog['tpl'] === 'index') {
      $res = ['reload' => true, 'url' => blog('home_url')];
  } elseif ($blog['tpl'] === 'list') {
      $catSlug = trim(str_replace('\\', '/', (string) blog('category.name')), '/');
      $listUrl = rtrim(blog('home_url'), '/') . '/' . ($catSlug !== '' ? rawurlencode($catSlug) . '/' : '');
      $res = ['reload' => true, 'url' => $listUrl];
  } else {
      $articlePanelHtml = blog('article.meta_html')
          . '<article class="blog-article markdown-body">' . blog('article.content') . '</article>';
      if (blog_echo('article_footer_tip') !== '') {
          $articlePanelHtml .= '<p class="blog-article-tip">' . blog_echo('article_footer_tip') . '</p>';
      }
      ob_start();
      include $viewsRoot . 'partials/article-pager.tpl.php';
      include $viewsRoot . 'partials/article-copyright.tpl.php';
      $articlePanelHtml .= ob_get_clean();

      $res = [
          'title' => blog('article.title'),
          'header' => blog('head.favicon') . blog('head.open_graph') . blog('head.keywords')
              . blog('head.description') . blog('head.title') . blog('head.assets'),
          'nav' => blog('article.sidebar_nav_html'),
          'body' => blog('article.meta_html') . blog('article.content'),
          'panel' => $articlePanelHtml,
          'docActions' => $toolbarToolsHtml,
      ];
  }
} else {
  $layoutMap = [
      'index' => 'layouts/portal.tpl.php',
      'list' => 'layouts/list.tpl.php',
      'post' => 'layouts/article.tpl.php',
      'search' => 'layouts/search.tpl.php',
  ];
  $blogLayoutBody = $viewsRoot . ($layoutMap[$blog['tpl']] ?? 'layouts/classic.tpl.php');
  ob_start();
  include $viewsRoot . 'layouts/shell.tpl.php';
  $content = ob_get_clean();
}
