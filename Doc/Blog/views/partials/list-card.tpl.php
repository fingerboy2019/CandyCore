<?php
/***
 * 分类列表文章卡片（单条）
 *
 * 依赖 $post、$imgBase
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:25:00 $
 *
 * @package APP\View\Blog
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$post = is_array($post ?? null) ? $post : [];
?>
<article class="blog-list-card">
    <a class="blog-list-card-thumb" href="<?= blog_esc($post['url'] ?? '') ?>">
        <img src="<?= blog_esc(blog_cover($post, '320/180')) ?>" alt="" loading="lazy">
    </a>
    <div class="blog-list-card-body">
        <h2 class="blog-list-card-title"><a href="<?= blog_esc($post['url'] ?? '') ?>"><?= blog_esc($post['title'] ?? '') ?></a></h2>
        <?php if (!empty($post['excerpt'])) : ?>
        <p class="blog-list-card-excerpt"><?= blog_esc($post['excerpt']) ?></p>
        <?php endif; ?>
        <div class="blog-list-card-meta">
            <?php if (!empty($post['date'])) : ?><time datetime="<?= blog_esc($post['date']) ?>"><?= blog_esc($post['date']) ?></time><?php endif; ?>
            <a class="blog-list-card-more" href="<?= blog_esc($post['url'] ?? '') ?>">阅读全文</a>
        </div>
    </div>
</article>
