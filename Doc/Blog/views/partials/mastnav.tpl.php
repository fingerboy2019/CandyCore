<?php
/***
 * 博客门户顶栏导航链接
 *
 * 通过 blog_nav() 获取导航数据；$mastnavVariant 为 desktop 或 drawer
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:05:00 $
 *
 * @package APP\View\Blog
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$mastnavVariant = $mastnavVariant ?? 'desktop';
$isDrawer = $mastnavVariant === 'drawer';

foreach (blog_nav() as $item) :
    $type = (string) ($item['type'] ?? 'link');

    if ($type === 'more') :
        if ($isDrawer) :
            foreach ((array) ($item['children'] ?? []) as $child) : ?>
    <a href="<?= blog_esc($child['url'] ?? '#') ?>"<?= !empty($child['active']) ? ' class="is-active"' : '' ?><?= !empty($child['external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= blog_esc($child['label'] ?? '') ?></a>
            <?php endforeach;
            continue;
        endif;
        ?>
    <div class="blog-mastnav-more<?= !empty($item['active']) ? ' is-active' : '' ?>">
        <button type="button" class="blog-mastnav-more-btn" aria-haspopup="true" aria-expanded="false">
            <?= blog_esc($item['label'] ?? '更多') ?>
        </button>
        <div class="blog-mastnav-more-menu" role="menu">
            <?php foreach ((array) ($item['children'] ?? []) as $child) : ?>
            <a href="<?= blog_esc($child['url'] ?? '#') ?>" class="blog-mastnav-more-link<?= !empty($child['active']) ? ' is-active' : '' ?>" role="menuitem"><?= blog_esc($child['label'] ?? '') ?></a>
            <?php endforeach; ?>
        </div>
    </div>
        <?php
        continue;
    endif;
    ?>
    <a href="<?= blog_esc($item['url'] ?? '#') ?>"<?= !empty($item['active']) ? ' class="is-active"' : '' ?><?= !empty($item['external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= blog_esc($item['label'] ?? '') ?></a>
    <?php
endforeach;
