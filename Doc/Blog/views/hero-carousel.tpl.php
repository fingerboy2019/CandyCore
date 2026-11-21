<?php
/***
 * 博客首页轮播横幅
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:50:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$slides = blog_carousel();
if ($slides === []) {
    $slides = [[
        'image' => blog('img_base') . 'hero.svg',
        'title' => '天行健，君子以自强不息',
        'subtitle' => blog('description') ?: '记录开发心得，分享技术实践',
        'link' => blog('home_url'),
        'theme' => 'ink',
    ]];
}
?>
<section class="blog-carousel" data-carousel aria-label="站点轮播" aria-roledescription="carousel">
    <div class="blog-carousel-viewport">
        <div class="blog-carousel-track">
            <?php foreach ($slides as $i => $slide) : ?>
            <div class="blog-carousel-slide<?= $i === 0 ? ' is-active' : '' ?>"
                 data-theme="<?= blog_esc($slide['theme'] ?? 'blue') ?>"
                 role="group" aria-roledescription="slide"
                 aria-label="<?= ($i + 1) . ' / ' . count($slides) ?>"
                 <?= $i === 0 ? '' : ' hidden' ?>>
                <a class="blog-carousel-link" href="<?= blog_esc($slide['link'] ?? blog('home_url')) ?>">
                    <img class="blog-carousel-bg" src="<?= blog_esc($slide['image'] ?? '') ?>" alt="">
                    <div class="blog-carousel-caption">
                        <h2 class="blog-carousel-title"><?= blog_esc($slide['title'] ?? '') ?></h2>
                        <?php if (!empty($slide['subtitle'])) : ?>
                        <p class="blog-carousel-sub"><?= blog_esc($slide['subtitle']) ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="blog-carousel-arrow blog-carousel-prev" aria-label="上一张">‹</button>
        <button type="button" class="blog-carousel-arrow blog-carousel-next" aria-label="下一张">›</button>
    </div>
    <div class="blog-carousel-dots" role="tablist" aria-label="轮播指示器">
        <?php foreach ($slides as $i => $slide) : ?>
        <button type="button" class="blog-carousel-dot<?= $i === 0 ? ' is-active' : '' ?>" data-index="<?= $i ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"></button>
        <?php endforeach; ?>
    </div>
</section>
