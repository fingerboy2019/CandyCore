<?php
/***
 * 首页 feed 横向卡片（单条）
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
$imgBase = (string) ($imgBase ?? '/Doc/Blog/Static/img/');

$postUrl = htmlspecialchars((string) ($post['url'] ?? ''), ENT_QUOTES, 'UTF-8');
$postTitle = htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES, 'UTF-8');
$postExcerpt = htmlspecialchars((string) ($post['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8');
$postDate = htmlspecialchars((string) ($post['date'] ?? ''), ENT_QUOTES, 'UTF-8');
$coverUrl = htmlspecialchars(
    \Candy\Extend\Doc\Blog\Index::resolveCoverUrl(
        ['cover' => (string) ($post['cover'] ?? '')],
        (string) ($post['filename'] ?? 'blog'),
        '280/160'
    ),
    ENT_QUOTES,
    'UTF-8'
);
$placeholder = htmlspecialchars($imgBase . 'placeholder.svg', ENT_QUOTES, 'UTF-8');
$reading = (int) ($post['reading_minutes'] ?? 0);
$tags = array_values(array_filter((array) ($post['tags'] ?? [])));
?>
<article class="feed-card feed-card--horiz">
    <a class="feed-card-thumb" href="<?= $postUrl ?>">
        <img src="<?= $coverUrl ?>" alt="<?= $postTitle ?>" loading="lazy"
             onerror="this.onerror=null;this.src='<?= $placeholder ?>';this.classList.add('thumb-fallback');">
    </a>
    <div class="feed-card-body">
        <h3 class="feed-card-title"><a href="<?= $postUrl ?>"><?= $postTitle ?></a></h3>
        <?php if ($postExcerpt !== '') : ?>
        <p class="feed-card-excerpt"><?= $postExcerpt ?></p>
        <?php endif; ?>
        <?php if ($tags !== []) : ?>
        <div class="feed-tags">
            <?php foreach ($tags as $tag) : ?>
            <span class="feed-tag"><?= htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="feed-card-meta">
            <?php if ($postDate !== '') : ?><span class="meta-item"><?= $postDate ?></span><?php endif; ?>
            <?php if ($reading > 0) : ?><span class="meta-item" title="预计阅读时间"><?= $reading ?> 分钟</span><?php endif; ?>
            <?php if (!empty($post['category'])) : ?>
            <span class="meta-item"><?= htmlspecialchars((string) $post['category'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <a class="meta-link" href="<?= $postUrl ?>">查看详细 +</a>
        </div>
    </div>
</article>
