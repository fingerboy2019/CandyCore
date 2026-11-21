<?php
/***
 * 文章阅读页布局
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

include dirname(__DIR__) . '/partials/topbar.tpl.php';
include dirname(__DIR__) . '/partials/masthead.tpl.php';
?>
<div class="blog-overlay blog-overlay--portal" aria-hidden="true"></div>
<div class="blog-wrap blog-wrap--article">
    <div class="blog-grid blog-grid--article">
        <?php include dirname(__DIR__) . '/partials/article-aside.tpl.php'; ?>
        <main class="blog-main blog-main--article" role="main">
            <div class="blog-article-panel">
                <?= blog('article.meta_html') ?>
                <article class="blog-article markdown-body">
                    <?= blog('article.content') ?>
                </article>
                <?php if (blog_echo('article_footer_tip') !== '') : ?>
                <p class="blog-article-tip"><?= blog_echo('article_footer_tip') ?></p>
                <?php endif; ?>
                <?php include dirname(__DIR__) . '/partials/article-pager.tpl.php'; ?>
                <?php include dirname(__DIR__) . '/partials/article-copyright.tpl.php'; ?>
                <?php include dirname(__DIR__) . '/partials/comments.tpl.php'; ?>
            </div>
        </main>
    </div>
</div>
<?php include dirname(__DIR__) . '/partials/footer.tpl.php'; ?>
