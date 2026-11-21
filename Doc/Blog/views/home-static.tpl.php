<?php
/***
 * 博客首页门户内容
 *
 * 通过 blog_* 函数获取数据，模板内标准 HTML + 循环
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$homeFeedSkip = (int) blog('limits.home_skip');
$homeFeedPageSize = (int) blog('limits.home_stream');
$feedTitle = blog_var('feed_section_title', '最新文章');
$profile = (array) blog('profile');
?>
<main class="blog-feed" role="main">
    <section class="feed-block">
        <div class="feed-block-head">
            <h2 class="feed-block-title"><?= blog_esc($feedTitle) ?></h2>
            <?php if ((int) blog('post_total') > count(blog_recent())) : ?>
            <span class="feed-more">共 <?= (int) blog('post_total') ?> 篇</span>
            <?php endif; ?>
        </div>
        <?php $recentTop = blog_recent(2); ?>
        <?php if ($recentTop === []) : ?>
        <p class="feed-empty">暂无文章，请在正文目录下添加 <code>.md</code> 文件（Front Matter 支持 title、date、tags、featured）。</p>
        <?php else : ?>
        <?php foreach ($recentTop as $post) : ?>
        <article class="feed-card feed-card--horiz">
            <a class="feed-card-thumb" href="<?= blog_esc($post['url']) ?>"><img src="<?= blog_esc(blog_cover($post)) ?>" alt="" loading="lazy"></a>
            <div class="feed-card-body">
                <h3 class="feed-card-title"><a href="<?= blog_esc($post['url']) ?>"><?= blog_esc($post['title']) ?></a></h3>
                <p class="feed-card-excerpt"><?= blog_esc($post['excerpt'] ?? '') ?></p>
                <div class="feed-card-meta">
                    <?php if (!empty($post['date'])) : ?><span class="meta-item"><?= blog_esc($post['date']) ?></span><?php endif; ?>
                    <?php if (!empty($post['category'])) : ?><span class="meta-item"><?= blog_esc($post['category']) ?></span><?php endif; ?>
                    <a class="meta-link" href="<?= blog_esc($post['url']) ?>">查看详细 +</a>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php $categories = blog_categories(); ?>
    <?php if ($categories !== []) : ?>
    <section class="feed-block feed-block--tabs">
        <div class="feed-block-head">
            <h2 class="feed-block-title">学习笔记</h2>
        </div>
        <div class="feed-tabs" data-feed-tabs>
            <div class="feed-tabs-nav" role="tablist">
                <?php foreach ($categories as $i => $cat) : ?>
                <button type="button" class="feed-tab<?= $i === 0 ? ' is-active' : '' ?>" data-tab="<?= blog_esc($cat['slug']) ?>" role="tab"><?= blog_esc($cat['name']) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="feed-tabs-panels">
                <?php foreach ($categories as $i => $cat) : ?>
                <div class="feed-tab-panel<?= $i === 0 ? ' is-active' : '' ?>" data-panel="<?= blog_esc($cat['slug']) ?>">
                    <div class="note-feature">
                        <div class="note-feature-cover"><img src="<?= blog_esc(blog_cover(['cover' => $cat['cover'] ?? '', 'filename' => $cat['slug'] ?? ''], '200/200')) ?>" alt=""></div>
                        <ol class="note-rank">
                            <?php foreach ((array) ($cat['posts'] ?? []) as $j => $note) : ?>
                            <li class="note-rank-item<?= $j === 0 ? ' is-top' : '' ?>"><a href="<?= blog_esc($note['url']) ?>"><?= blog_esc($note['title']) ?></a></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="feed-block">
        <div class="feed-block-head">
            <h2 class="feed-block-title">推荐博文</h2>
        </div>
        <?php $featured = blog_featured(); ?>
        <?php if ($featured === []) : ?>
        <p class="feed-empty">在文章 Front Matter 中设置 <code>featured: true</code> 可进入推荐区。</p>
        <?php else : ?>
        <?php foreach ($featured as $post) : ?>
        <article class="feed-card feed-card--horiz feed-card--lg">
            <a class="feed-card-thumb" href="<?= blog_esc($post['url']) ?>"><img src="<?= blog_esc(blog_cover($post, '320/180')) ?>" alt="" loading="lazy"></a>
            <div class="feed-card-body">
                <h3 class="feed-card-title"><a href="<?= blog_esc($post['url']) ?>"><?= blog_esc($post['title']) ?></a></h3>
                <p class="feed-card-excerpt"><?= blog_esc($post['excerpt'] ?? '') ?></p>
                <div class="feed-card-meta">
                    <?php if (!empty($post['date'])) : ?><span class="meta-item"><?= blog_esc($post['date']) ?></span><?php endif; ?>
                    <a class="meta-link" href="<?= blog_esc($post['url']) ?>">查看详细 +</a>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if ((int) blog('post_total') > $homeFeedSkip) : ?>
    <section class="feed-block feed-block--stream" aria-label="更多文章">
        <div class="feed-stream-list" data-feed-stream aria-live="polite"></div>
        <button type="button" class="feed-load-more" data-load-more data-scope="home"
                data-offset="<?= $homeFeedSkip ?>" data-limit="<?= $homeFeedPageSize ?>"
                data-total="<?= (int) blog('post_total') ?>">加载更多</button>
    </section>
    <?php endif; ?>
</main>

<aside class="blog-side" aria-label="侧边栏">
    <div class="side-widget side-profile">
        <div class="side-profile-avatar"><img src="<?= blog_esc($profile['avatar'] ?? blog('img_base') . 'avatar.svg') ?>" alt="博主头像"></div>
        <h3 class="side-profile-name"><?= blog_esc($profile['name'] ?? 'Candy 开发者') ?></h3>
        <p class="side-profile-bio"><?= blog_esc($profile['bio'] ?? '') ?></p>
        <?php if (!empty($profile['more_url']) && $profile['more_url'] !== '#') : ?>
        <a class="side-profile-more" href="<?= blog_esc($profile['more_url']) ?>">阅读更多</a>
        <?php endif; ?>
    </div>

    <div class="side-widget">
        <h4 class="side-widget-title">微信二维码</h4>
        <div class="side-qr"><img src="<?= blog_esc($profile['wechat_qr'] ?? blog('img_base') . 'qr-placeholder.svg') ?>" alt="微信二维码"></div>
    </div>

    <div class="side-widget">
        <h4 class="side-widget-title">QQ 二维码</h4>
        <div class="side-qr"><img src="<?= blog_esc($profile['qq_qr'] ?? blog('img_base') . 'qr-placeholder.svg') ?>" alt="QQ 二维码"></div>
    </div>

    <blockquote class="side-widget side-quote">
        <p><?= blog_esc(blog('quote')) ?></p>
    </blockquote>

    <div class="side-widget">
        <h4 class="side-widget-title">文章推荐</h4>
        <?php $sidebarPosts = blog_sidebar_posts(); ?>
        <?php if ($sidebarPosts === []) : ?>
        <p class="feed-empty">暂无推荐</p>
        <?php else : ?>
        <ol class="side-num-list">
            <?php foreach ($sidebarPosts as $post) : ?>
            <li><a href="<?= blog_esc($post['url']) ?>"><?= blog_esc($post['title']) ?></a></li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>

    <?php if (blog_friend_links('sidebar') !== []) :
        $friendLinksVariant = 'sidebar';
        include __DIR__ . '/partials/friend-links.tpl.php';
    endif; ?>

    <?php include __DIR__ . '/hot-tags.tpl.php'; ?>
</aside>
