<?php
/***
 * 博客模板全局辅助函数
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:48:00 $
 */
defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Doc\Blog\Context;

if (!function_exists('blog')) {
    function blog(string $key, mixed $default = null): mixed
    {
        return Context::get($key, $default);
    }
}

if (!function_exists('blog_esc')) {
    function blog_esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('blog_posts')) {
    function blog_posts(?string $category = null, int $limit = 0, int $offset = 0): array
    {
        return Context::instance()->posts($category, $limit, $offset);
    }
}

if (!function_exists('blog_posts_by_tag')) {
    /**
     * 按标签取文章列表
     *
     * @param string $tag 标签名
     * @param int $limit 条数，0 不限制
     * @param int $offset 跳过条数
     * @return array<int, array<string, mixed>>
     */
    function blog_posts_by_tag(string $tag, int $limit = 0, int $offset = 0): array
    {
        return Context::instance()->postsByTag($tag, $limit, $offset);
    }
}

if (!function_exists('blog_post_count')) {
    function blog_post_count(?string $category = null): int
    {
        return Context::instance()->postCount($category);
    }
}

if (!function_exists('blog_nav')) {
    function blog_nav(): array
    {
        return Context::instance()->nav();
    }
}

if (!function_exists('blog_friend_links')) {
    function blog_friend_links(?string $scope = null): array
    {
        return Context::instance()->friendLinks($scope);
    }
}

if (!function_exists('blog_cover')) {
    function blog_cover(array $post, string $size = '320/180'): string
    {
        return Context::instance()->cover($post, $size);
    }
}

if (!function_exists('blog_featured')) {
    function blog_featured(int $limit = 0): array
    {
        return Context::instance()->featured($limit);
    }
}

if (!function_exists('blog_recent')) {
    function blog_recent(int $limit = 0): array
    {
        return Context::instance()->recent($limit);
    }
}

if (!function_exists('blog_categories')) {
    function blog_categories(): array
    {
        return Context::instance()->categories();
    }
}

if (!function_exists('blog_tags')) {
    function blog_tags(): array
    {
        return Context::instance()->tags();
    }
}

if (!function_exists('blog_carousel')) {
    function blog_carousel(): array
    {
        return Context::instance()->carousel();
    }
}

if (!function_exists('blog_sidebar_posts')) {
    function blog_sidebar_posts(int $limit = 0): array
    {
        return Context::instance()->sidebarPosts($limit);
    }
}
