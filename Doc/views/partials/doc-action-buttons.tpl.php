<?php
/***
 * 文档工具栏按钮（仅按钮，供 AJAX 更新 innerHTML）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

if (doc('article.password')) {
    return;
}
?>
<?php if (doc('toolbar.show_print')) : ?>
<button id="print-btn" type="button" class="btn btn-sm doc-action-btn" title="打印" aria-label="打印">🖨</button>
<?php endif; ?>
<?php if (doc('toolbar.show_download') && (string) doc('toolbar.download_url') !== '') : ?>
<a id="download-btn" class="btn btn-sm doc-action-btn" href="<?= doc_esc(doc('toolbar.download_url')) ?>" title="下载 Markdown" aria-label="下载 Markdown">⬇</a>
<?php endif; ?>
<button id="theme-toggle" type="button" class="btn btn-sm doc-action-btn" title="切换深浅色" aria-label="切换深浅色"><span id="theme-icon">🌙</span></button>
