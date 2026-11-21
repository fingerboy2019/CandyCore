<?php
/***
 * 工具栏按钮（打印 / 下载 / 主题）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$toolbar = (array) blog('toolbar');
?>
<?php if (!empty($toolbar['show_print']) && !blog('article.password')) : ?>
<button id="print-btn" type="button" class="blog-tool-btn" title="打印" aria-label="打印">⎙</button>
<?php endif; ?>
<?php if (!empty($toolbar['show_download']) && ($toolbar['download_url'] ?? '') !== '' && !blog('article.password')) : ?>
<a id="download-btn" class="blog-tool-btn" href="<?= blog_esc($toolbar['download_url']) ?>" title="下载 Markdown" aria-label="下载">↓</a>
<?php endif; ?>
<button id="theme-toggle" type="button" class="blog-tool-btn" title="切换主题" aria-label="切换主题"><span id="theme-icon">☾</span></button>
