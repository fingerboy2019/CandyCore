<?php
/***
 * 经典 Markdown 页布局（回退）
 *
 * 用于未映射到 portal/list/article 的页面类型
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:05:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

include dirname(__DIR__) . '/partials/topbar.tpl.php';
include dirname(__DIR__) . '/partials/masthead.tpl.php';
?>
<div class="blog-wrap blog-wrap--classic">
    <main class="blog-main" role="main">
        <?php if ((string) blog('article.content') !== '') : ?>
        <div class="blog-article-panel">
            <?= blog('article.meta_html') ?>
            <article class="blog-article markdown-body">
                <?= blog('article.content') ?>
            </article>
        </div>
        <?php else : ?>
        <div class="blog-list-empty">
            <p>页面不存在或内容为空。</p>
            <p><a href="<?= blog_esc(blog('home_url')) ?>">返回首页</a></p>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include dirname(__DIR__) . '/partials/footer.tpl.php'; ?>
