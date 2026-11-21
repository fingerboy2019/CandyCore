<?php
/***
 * 全站搜索独立结果页布局
 *
 * 从 Context::get('page.search.*') 读取结果；复用博客顶栏/页脚与视觉
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 14:20:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

$search = \Candy\Extend\Doc\Blog\Context::get('search', []);
$keyword = (string) ($search['keyword'] ?? '');
$total = (int) ($search['total'] ?? 0);
$results = is_array($search['results'] ?? null) ? $search['results'] : [];

include dirname(__DIR__) . '/partials/topbar.tpl.php';
include dirname(__DIR__) . '/partials/masthead.tpl.php';
?>
<div class="blog-wrap blog-wrap--search">
    <div class="blog-list-page-inner">
        <header class="blog-list-hero">
            <nav class="blog-breadcrumb" aria-label="面包屑">
                <a href="<?= blog_esc(blog('home_url')) ?>">首页</a>
                <span class="blog-breadcrumb-sep">/</span>
                <span class="blog-breadcrumb-current">搜索</span>
            </nav>
            <h1 class="blog-list-title"><?= $keyword !== '' ? '搜索「' . blog_esc($keyword) . '」' : '全站搜索' ?></h1>
            <?php if ($keyword !== '') : ?>
            <p class="blog-list-count">共 <?= (int) $total ?> 篇相关文章</p>
            <?php endif; ?>
        </header>

        <?php if ($keyword === '') : ?>
        <div class="blog-list-empty">
            <p>请输入关键词开始搜索，例如：<?= blog_esc((string) ($GLOBALS['blog_search_tip'] ?? 'MVCS')) ?></p>
        </div>
        <?php elseif ($results === []) : ?>
        <div class="blog-list-empty">
            <p>未找到与「<?= blog_esc($keyword) ?>」相关的文章，换个关键词试试？</p>
        </div>
        <?php else : ?>
        <div class="blog-list-grid blog-search-page-grid">
            <?php foreach ($results as $item) :
                $url = (string) ($item['url'] ?? '');
                $title = (string) ($item['title'] ?? '');
                $category = (string) ($item['category'] ?? '');
                $snippet = (string) ($item['snippet'] ?? '');
            ?>
            <article class="blog-card blog-search-page-card">
                <div class="blog-card-body">
                    <h2 class="blog-card-title">
                        <a href="<?= blog_esc($url) ?>"<?= $keyword !== '' ? ' data-search-link data-q="' . blog_esc($keyword) . '"' : '' ?>>
                            <?= blog_esc($title) ?>
                        </a>
                    </h2>
                    <?php if ($category !== '') : ?>
                    <div class="blog-card-meta">
                        <span class="blog-card-category"><?= blog_esc($category) ?></span>
                    </div>
                    <?php endif; ?>
                    <p class="blog-card-excerpt"><?= $snippet ?></p>
                    <div class="blog-card-link">
                        <a href="<?= blog_esc($url) ?>"<?= $keyword !== '' ? ' data-search-link data-q="' . blog_esc($keyword) . '"' : '' ?>>
                            阅读全文 →
                        </a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include dirname(__DIR__) . '/partials/footer.tpl.php'; ?>
