<?php
/***
 * 博客模板上下文
 *
 * 业务说明：为视图提供 $blog 数组与数据查询（导航、文章、友链等）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:48:00 $
 *
 * @package Candy\Extend\Doc\Blog
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

require_once __DIR__ . '/helpers.php';

final class Context
{
    private static ?self $instance = null;

    private string $filesDir = '';

    private string $basePath = '/Blog/';

    /** @var array<string, mixed> */
    private array $conf = [];

    private string $tpl = 'post';

    private string $currentPath = '/';

    /** @var array<string, mixed> */
    private array $page = [];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $allPostsCache = null;

    /** @var array<string, mixed>|null */
    private ?array $portalCache = null;

    /**
     * 初始化当前请求的博客上下文
     *
     * @param array<string, mixed> $payload
     * @return void
     */
    public static function boot(array $payload): void
    {
        self::$instance = new self();
        self::$instance->hydrate($payload);
    }

    /**
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 读取 $blog 视图变量（支持 category.name 点分键）
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::instance()->read($key, $default);
    }

    /**
     * 导出 $blog 数组
     *
     * @return array<string, mixed>
     */
    public static function viewData(): array
    {
        return self::instance()->buildViewData();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrate(array $payload): void
    {
        $this->filesDir = rtrim((string) ($payload['files_dir'] ?? ''), '/') . '/';
        $this->basePath = (string) ($payload['base_path'] ?? '/Blog/');
        $this->conf = is_array($payload['conf'] ?? null) ? $payload['conf'] : [];
        $this->tpl = (string) ($payload['tpl'] ?? 'post');
        $this->currentPath = (string) ($payload['current_path'] ?? '/');
        $this->page = is_array($payload['page'] ?? null) ? $payload['page'] : [];
    }

    private function read(string $key, mixed $default = null): mixed
    {
        $data = $this->buildViewData();
        $cursor = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(): array
    {
        $limits = array_merge([
            'recent' => 5,
            'featured' => 4,
            'sidebar' => 6,
            'carousel' => 3,
            'category_notes' => 3,
            'tag_cloud' => 24,
            'home_skip' => 2,
            'home_stream' => 6,
            'list_page' => 3,
        ], (array) ($this->conf['feed_limits'] ?? []));

        $portal = $this->portal();
        $friendConf = Index::normalizeFriendLinks($this->conf);

        return [
            'name' => (string) ($this->conf['site_name'] ?? '博客'),
            'description' => (string) ($this->conf['site_description'] ?? ''),
            'home_url' => rtrim($this->basePath, '/') . '/',
            'static_base' => '/Doc/Blog/Static/',
            'img_base' => '/Doc/Blog/Static/img/',
            'tpl' => $this->tpl,
            'year' => (int) date('Y'),
            'quote' => (string) ($this->conf['quote'] ?? ''),
            'profile' => (array) ($this->conf['profile'] ?? []),
            'limits' => $limits,
            'nav' => $this->nav(),
            'friend_links' => $friendConf,
            'portal' => $portal,
            'post_total' => (int) ($portal['total'] ?? 0),
            'category' => [
                'name' => (string) ($this->page['category_name'] ?? ''),
                'intro' => (string) ($this->page['category_intro'] ?? ''),
                'total' => (int) ($this->page['category_total'] ?? 0),
            ],
            'tag' => [
                'name' => (string) ($this->page['tag_name'] ?? ''),
                'total' => (int) ($this->page['tag_total'] ?? 0),
            ],
            'list' => [
                'scope' => (string) ($this->page['list_scope'] ?? 'category'),
            ],
            'rss_url' => rtrim($this->basePath, '/') . '/rss.xml',
            'article' => [
                'title' => (string) ($this->page['plain_title'] ?? ''),
                'content' => (string) ($this->page['content'] ?? ''),
                'meta_html' => (string) ($this->page['doc_meta'] ?? ''),
                'sidebar_nav_html' => (string) ($this->page['nav_html'] ?? ''),
                'prev' => is_array($this->page['prev_page'] ?? null) ? $this->page['prev_page'] : [],
                'next' => is_array($this->page['next_page'] ?? null) ? $this->page['next_page'] : [],
                'password' => !empty($this->page['mark']['password'] ?? false),
                'post_rel' => (string) ($this->page['post_rel'] ?? ''),
            ],
            'comments' => [
                'enabled' => !empty($this->page['comment_enabled']),
                'captcha' => !empty($this->page['comment_captcha']),
                'total' => (int) ($this->page['comment_total'] ?? 0),
                'html' => (string) ($this->page['comment_html'] ?? ''),
                'emojis' => is_array($this->page['comment_emojis'] ?? null) ? $this->page['comment_emojis'] : [],
            ],
            'layout' => [
                'body_class' => implode(' ', $this->bodyClassList()),
                'doc_theme' => (string) ($this->page['doc_theme'] ?? ''),
                'sidebar_enabled' => ($this->page['sidebar_enabled'] ?? true) !== false,
                'html_lang' => (string) ($this->page['html_lang'] ?? 'zh-CN'),
            ],
            'head' => [
                'title' => (string) ($this->page['title_tag'] ?? ''),
                'keywords' => (string) ($this->page['keywords'] ?? ''),
                'description' => (string) ($this->page['description_tag'] ?? ''),
                'favicon' => (string) ($this->page['favicon'] ?? ''),
                'open_graph' => (string) ($this->page['open_graph'] ?? ''),
                'assets' => (string) ($this->page['head_assets'] ?? ''),
            ],
            'toolbar' => [
                'show_print' => !empty($this->page['show_print']),
                'show_download' => !empty($this->page['show_download']),
                'download_url' => (string) ($this->page['download_url'] ?? ''),
            ],
            'search' => is_array($this->page['search'] ?? null) ? $this->page['search'] : [],
        ];
    }

    /** @return array<int, string> */
    private function bodyClassList(): array
    {
        $classes = ['blog'];
        if ($this->tpl === 'index') {
            $classes[] = 'blog-portal';
        } elseif ($this->tpl === 'list') {
            $classes[] = 'blog-list-page';
        } elseif ($this->tpl === 'post') {
            $classes[] = 'blog-article-page';
        }
        if (($this->page['sidebar_enabled'] ?? true) === false && $this->tpl !== 'index') {
            $classes[] = 'no-aside';
        }

        return $classes;
    }

    /** @return array<int, array<string, mixed>> */
    public function nav(): array
    {
        if (isset($this->page['mast_nav']) && is_array($this->page['mast_nav'])) {
            return $this->page['mast_nav'];
        }
        if ($this->filesDir === '/' || !is_dir($this->filesDir)) {
            return [];
        }

        return Index::buildMastNav($this->filesDir, $this->basePath, $this->conf, $this->currentPath);
    }

    public function sidebarNavHtml(): string
    {
        return (string) ($this->page['nav_html'] ?? '');
    }

    /** @return array<int, array<string, mixed>> */
    public function posts(?string $category = null, int $limit = 0, int $offset = 0): array
    {
        if ($this->filesDir === '/' || !is_dir($this->filesDir)) {
            return [];
        }

        $category = $category !== null ? trim($category) : '';
        $posts = $category !== ''
            ? Index::listPostsByCategory($this->filesDir, $this->basePath, $category)
            : $this->allPosts();

        if ($offset > 0) {
            $posts = array_slice($posts, $offset);
        }
        if ($limit > 0) {
            $posts = array_slice($posts, 0, $limit);
        }

        return $posts;
    }

    /**
     * 按标签取文章列表（可选分页切片）
     *
     * @param string $tag 标签名
     * @param int $limit 条数，0 表示不限制
     * @param int $offset 跳过条数
     * @return array<int, array<string, mixed>>
     */
    public function postsByTag(string $tag, int $limit = 0, int $offset = 0): array
    {
        if ($this->filesDir === '/' || !is_dir($this->filesDir)) {
            return [];
        }

        $posts = Index::listPostsByTag($this->filesDir, $this->basePath, $tag);
        if ($offset > 0) {
            $posts = array_slice($posts, $offset);
        }
        if ($limit > 0) {
            $posts = array_slice($posts, 0, $limit);
        }

        return $posts;
    }

    public function postCount(?string $category = null): int
    {
        return count($this->posts($category, 0, 0));
    }

    /** @return array<int, array<string, mixed>> */
    public function featured(int $limit = 0): array
    {
        $items = (array) ($this->portal()['featured'] ?? []);

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    /** @return array<int, array<string, mixed>> */
    public function categories(): array
    {
        return (array) ($this->portal()['categories'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function tags(): array
    {
        return (array) ($this->portal()['tags'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function carousel(): array
    {
        return (array) ($this->portal()['carousel'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function sidebarPosts(int $limit = 0): array
    {
        $items = (array) ($this->portal()['sidebar'] ?? []);

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 0): array
    {
        $items = (array) ($this->portal()['recent'] ?? []);

        return $limit > 0 ? array_slice($items, 0, $limit) : $items;
    }

    /** @return array<int, array<string, mixed>>|array<string, mixed> */
    public function friendLinks(?string $scope = null): array
    {
        $conf = Index::normalizeFriendLinks($this->conf);
        if ($scope === 'sidebar') {
            return !empty($conf['sidebar']) ? (array) $conf['items'] : [];
        }
        if ($scope === 'footer') {
            return !empty($conf['footer']) ? (array) $conf['items'] : [];
        }

        return $conf;
    }

    public function cover(array $post, string $size = '320/180'): string
    {
        return Index::resolveCoverUrl(
            ['cover' => (string) ($post['cover'] ?? '')],
            (string) ($post['filename'] ?? 'blog'),
            $size
        );
    }

    /** @return array<string, mixed> */
    private function portal(): array
    {
        if ($this->portalCache !== null) {
            return $this->portalCache;
        }
        if (isset($this->page['portal']) && is_array($this->page['portal'])) {
            $this->portalCache = $this->page['portal'];

            return $this->portalCache;
        }
        if ($this->filesDir === '/' || !is_dir($this->filesDir)) {
            $this->portalCache = [];

            return $this->portalCache;
        }
        $this->portalCache = Index::buildPortalData($this->filesDir, $this->basePath, $this->conf);

        return $this->portalCache;
    }

    /** @return array<int, array<string, mixed>> */
    private function allPosts(): array
    {
        if ($this->allPostsCache !== null) {
            return $this->allPostsCache;
        }
        $this->allPostsCache = Index::listAllPosts($this->filesDir, $this->basePath);

        return $this->allPostsCache;
    }
}
