<?php
/***
 * 分类 / 标签文章列表区块
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 17:00:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$listScope = (string) blog('list.scope', 'category');
$isTag = $listScope === 'tag';
$listPageSize = (int) blog('limits.list_page');
$categoryIntro = trim((string) blog('category.intro'));

if ($isTag) {
    $tagName = (string) blog('tag.name');
    $categoryPosts = $tagName !== '' ? blog_posts_by_tag($tagName, $listPageSize, 0) : [];
    $postTotal = (int) blog('tag.total');
    $catTitle = $tagName !== '' ? ('标签：' . $tagName) : '标签归档';
    $emptyHint = blog_echo('tag_empty_hint') ?: '该标签下暂无文章。';
    $scopeAttr = 'tag';
    $filterAttr = 'data-tag="' . blog_esc($tagName) . '"';
} else {
    $categoryName = (string) blog('category.name');
    $categoryKey = $categoryName !== '' ? basename(str_replace('\\', '/', $categoryName)) : '';
    $categoryPosts = $categoryKey !== '' ? blog_posts($categoryKey, $listPageSize, 0) : [];
    $postTotal = $categoryKey !== '' ? (int) blog('category.total') : 0;
    $catTitle = $categoryName !== '' ? $categoryName : '文章归档';
    $emptyHint = blog_echo('list_empty_hint') ?: '该分类下暂无文章，可在对应目录添加 <code>.md</code> 文件。';
    $scopeAttr = 'category';
    $filterAttr = 'data-category="' . blog_esc($categoryKey) . '"';
}
$listOffset = count($categoryPosts);
?>
<div class="blog-list-page-inner">
    <header class="blog-list-hero">
        <nav class="blog-breadcrumb" aria-label="面包屑">
            <a href="<?= blog_esc(blog('home_url')) ?>">首页</a>
            <span class="blog-breadcrumb-sep">/</span>
            <span class="blog-breadcrumb-current"><?= blog_esc($catTitle) ?></span>
        </nav>
        <h1 class="blog-list-title"><?= blog_esc($catTitle) ?></h1>
        <?php if ($categoryIntro !== '') : ?>
        <p class="blog-list-desc"><?= blog_esc($categoryIntro) ?></p>
        <?php endif; ?>
        <p class="blog-list-count">共 <?= (int) $postTotal ?> 篇文章</p>
    </header>

    <?php if ($categoryPosts === []) : ?>
    <div class="blog-list-empty">
        <p><?= $emptyHint ?></p>
    </div>
    <?php else : ?>
    <div class="blog-list-grid"
         data-infinite-scroll
         data-scope="<?= blog_esc($scopeAttr) ?>"
         <?= $filterAttr ?>
         data-offset="<?= (int) $listOffset ?>"
         data-limit="<?= (int) $listPageSize ?>"
         data-total="<?= (int) $postTotal ?>">
        <?php foreach ($categoryPosts as $post) :
            include __DIR__ . '/list-card.tpl.php';
        endforeach; ?>
    </div>
    <?php if ($postTotal > $listOffset) : ?>
    <div class="blog-list-sentinel" data-infinite-sentinel aria-hidden="true"></div>
    <?php endif; ?>
    <?php endif; ?>
</div>
