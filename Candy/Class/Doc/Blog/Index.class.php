<?php
/***
 * 博客门户索引
 *
 * 业务说明：扫描正文 Markdown、聚合分类/标签，为首页 feed/轮播/侧栏提供数据
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:40:00 $
 *
 * @package Candy\Extend\Doc\Blog
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Index
{
    /** 门户索引结构版本 */
    private const INDEX_VERSION = '1.1.0';

    /** 标签云色调轮换 */
    private const TAG_TONES = ['blue', 'indigo', 'cyan', 'violet', 'green', 'orange', 'pink', 'teal', 'amber'];

    /**
     * 构建门户首页所需全部动态数据
     *
     * @param string $rootDir 正文根目录（content_dir 绝对路径）
     * @param string $basePath URL 前缀（如 /Blog/）
     * @param array $conf BlogConfig 配置数组
     * @return array{recent:array,featured:array,carousel:array,categories:array,sidebar:array,tags:array,total:int}
     */
    public static function buildPortalData(string $rootDir, string $basePath, array $conf): array
    {
        $limits = array_merge([
            'recent' => 5,
            'featured' => 4,
            'sidebar' => 6,
            'carousel' => 3,
            'category_notes' => 3,
            'tag_cloud' => 24,
        ], (array) ($conf['feed_limits'] ?? []));

        $posts = self::loadPosts($rootDir, $basePath);
        $recent = self::sortByDate($posts, (int) $limits['recent']);
        $featured = self::listFeatured($posts, (int) $limits['featured']);
        $sidebar = self::sortByDate($posts, (int) $limits['sidebar']);

        return [
            'recent' => $recent,
            'featured' => $featured,
            'carousel' => self::buildCarouselSlides($posts, $featured, $conf, (int) $limits['carousel']),
            'categories' => self::listCategories($posts, (int) $limits['category_notes']),
            'sidebar' => $sidebar,
            'tags' => self::aggregateTags($posts, (int) $limits['tag_cloud'], $basePath),
            'total' => count($posts),
        ];
    }

    /**
     * 构建门户顶栏导航项
     *
     * 首页 + 正文一级分类（.order 排序）+ BlogConfig.nav.items；按当前路径标记高亮
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀（如 /Blog/）
     * @param array $conf BlogConfig
     * @param string $currentPath 当前请求路径
     * @return array<int, array<string, mixed>>
     */
    public static function buildMastNav(string $rootDir, string $basePath, array $conf, string $currentPath = '/'): array
    {
        $navConf = (array) ($conf['nav'] ?? []);
        $maxCategories = max(0, (int) ($navConf['max_categories'] ?? 4));
        $showHome = ($navConf['show_home'] ?? true) !== false;
        $items = [];

        if ($showHome) {
            $items[] = [
                'label' => (string) ($navConf['home_label'] ?? '首页'),
                'url' => rtrim($basePath, '/') . '/',
                'type' => 'home',
                'external' => false,
            ];
        }

        $categories = self::listTopCategories($rootDir, $basePath);
        $visible = array_slice($categories, 0, $maxCategories);
        $overflow = array_slice($categories, $maxCategories);

        foreach ($visible as $cat) {
            $items[] = [
                'label' => (string) $cat['name'],
                'url' => (string) $cat['url'],
                'type' => 'category',
                'external' => false,
            ];
        }

        if ($overflow !== []) {
            $children = [];
            foreach ($overflow as $cat) {
                $children[] = [
                    'label' => (string) $cat['name'],
                    'url' => (string) $cat['url'],
                    'type' => 'category',
                    'external' => false,
                ];
            }
            $items[] = [
                'label' => (string) ($navConf['more_label'] ?? '更多'),
                'url' => '#',
                'type' => 'more',
                'external' => false,
                'children' => $children,
            ];
        }

        foreach ((array) ($navConf['items'] ?? []) as $link) {
            if (empty($link['label']) || empty($link['url'])) {
                continue;
            }
            $url = (string) $link['url'];
            $items[] = [
                'label' => (string) $link['label'],
                'url' => $url,
                'type' => 'link',
                'external' => !empty($link['external']) || str_starts_with($url, 'http'),
            ];
        }

        return self::markActiveNavItems($items, $currentPath, $basePath);
    }

    /**
     * 解析友情链接配置
     *
     * 支持新版结构（title / sidebar / footer / items）与旧版纯链接数组
     *
     * @param array $conf BlogConfig
     * @return array{title:string,sidebar:bool,footer:bool,items:array<int,array{name:string,url:string,external:bool,description:string}>}
     */
    public static function normalizeFriendLinks(array $conf): array
    {
        $raw = $conf['friend_links'] ?? [];

        if ($raw !== [] && array_is_list($raw)) {
            return [
                'title' => (string) ($conf['friend_links_title'] ?? '友情链接'),
                'sidebar' => true,
                'footer' => false,
                'items' => self::sanitizeFriendLinkItems($raw),
            ];
        }

        if (!is_array($raw)) {
            return [
                'title' => '友情链接',
                'sidebar' => true,
                'footer' => false,
                'items' => [],
            ];
        }

        $items = (array) ($raw['items'] ?? $raw['links'] ?? []);

        return [
            'title' => (string) ($raw['title'] ?? '友情链接'),
            'sidebar' => ($raw['sidebar'] ?? true) !== false,
            'footer' => !empty($raw['footer']),
            'items' => self::sanitizeFriendLinkItems($items),
        ];
    }

    /**
     * 清洗友链条目
     *
     * @param array<int, mixed> $items 原始配置
     * @return array<int, array{name:string,url:string,external:bool,description:string}>
     */
    private static function sanitizeFriendLinkItems(array $items): array
    {
        $out = [];

        foreach ($items as $link) {
            if (!is_array($link) || empty($link['name']) || empty($link['url'])) {
                continue;
            }

            $url = trim((string) $link['url']);
            if ($url === '' || $url === '#') {
                continue;
            }

            $out[] = [
                'name' => trim((string) $link['name']),
                'url' => $url,
                'external' => isset($link['external'])
                    ? !empty($link['external'])
                    : (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')),
                'description' => trim((string) ($link['description'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * 按标签筛选文章列表
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @param string $tag 标签名（与 Front Matter tags 精确匹配）
     * @return array<int, array<string, mixed>>
     */
    public static function listPostsByTag(string $rootDir, string $basePath, string $tag): array
    {
        $tag = trim($tag);
        if ($tag === '') {
            return [];
        }

        $posts = self::loadPosts($rootDir, $basePath);
        $filtered = array_values(array_filter($posts, static function (array $post) use ($tag): bool {
            foreach ((array) ($post['tags'] ?? []) as $item) {
                if (trim((string) $item) === $tag) {
                    return true;
                }
            }
            return false;
        }));

        return self::sortByDate($filtered, PHP_INT_MAX);
    }

    /**
     * 标签归档页公开 URL
     *
     * @param string $basePath URL 前缀（如 /Blog/）
     * @param string $tag 标签名
     * @return string
     */
    public static function tagUrl(string $basePath, string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return rtrim($basePath, '/') . '/';
        }
        return rtrim($basePath, '/') . '/tag/' . rawurlencode($tag);
    }

    /**
     * 按分类筛选文章列表
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @param string $category 分类名（目录名或 meta.category）
     * @return array<int, array<string, mixed>>
     */
    public static function listPostsByCategory(string $rootDir, string $basePath, string $category): array
    {
        $category = trim($category);
        if ($category === '') {
            return [];
        }

        $posts = self::loadPosts($rootDir, $basePath);
        $filtered = array_values(array_filter($posts, static function (array $post) use ($category): bool {
            return (string) ($post['category'] ?? '') === $category;
        }));

        return self::sortByDate($filtered, PHP_INT_MAX);
    }

    /**
     * 全站文章列表（按日期倒序）
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @return array<int, array<string, mixed>>
     */
    public static function listAllPosts(string $rootDir, string $basePath): array
    {
        return self::sortByDate(self::loadPosts($rootDir, $basePath), PHP_INT_MAX);
    }

    /**
     * 分页获取文章列表（首页时间线 / 分类 / 标签列表共用）
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @param int $offset 跳过条数
     * @param int $limit 本页条数（最大 100）
     * @param string|null $category 分类名，空表示不按分类筛
     * @param string|null $tag 标签名；非空时优先按标签筛选
     * @return array{items:array,total:int,has_more:bool,next_offset:int}
     */
    public static function listPostsPage(
        string $rootDir,
        string $basePath,
        int $offset,
        int $limit,
        ?string $category = null,
        ?string $tag = null
    ): array {
        $offset = max(0, $offset);
        $limit = max(1, min(100, $limit));

        $tag = $tag !== null ? trim($tag) : '';
        $category = $category !== null ? trim($category) : '';
        if ($tag !== '') {
            $posts = self::listPostsByTag($rootDir, $basePath, $tag);
        } elseif ($category !== '') {
            $posts = self::listPostsByCategory($rootDir, $basePath, $category);
        } else {
            $posts = self::sortByDate(self::loadPosts($rootDir, $basePath), PHP_INT_MAX);
        }

        $total = count($posts);
        $items = array_slice($posts, $offset, $limit);
        $nextOffset = $offset + count($items);

        return [
            'items' => $items,
            'total' => $total,
            'has_more' => $nextOffset < $total,
            'next_offset' => $nextOffset,
        ];
    }

    /**
     * 渲染首页横向 feed 卡片 HTML（加载更多追加用）
     *
     * @param array<int, array<string, mixed>> $posts 文章索引项
     * @param string $imgBase 静态图基础路径
     * @return string
     */
    public static function renderHomeFeedCardsHtml(array $posts, string $imgBase): string
    {
        if ($posts === []) {
            return '';
        }

        require_once __DIR__ . '/helpers.php';

        $partial = C('CANDYROOT') . 'Doc/Blog/views/partials/feed-card-horiz.tpl.php';
        if (!is_file($partial)) {
            return '';
        }

        $html = '';
        foreach ($posts as $post) {
            ob_start();
            include $partial;
            $html .= (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * 渲染分类列表卡片 HTML（无限滚动追加用）
     *
     * @param array<int, array<string, mixed>> $posts 文章索引项
     * @param string $imgBase 静态图基础路径
     * @return string
     */
    public static function renderListCardsHtml(array $posts, string $imgBase): string
    {
        if ($posts === []) {
            return '';
        }

        require_once __DIR__ . '/helpers.php';

        $partial = C('CANDYROOT') . 'Doc/Blog/views/partials/list-card.tpl.php';
        if (!is_file($partial)) {
            return '';
        }

        $html = '';
        foreach ($posts as $post) {
            ob_start();
            include $partial;
            $html .= (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * 解析文章封面 URL
     *
     * Front Matter 含 cover/og_image/image 时优先使用；否则按文件名 seed 生成 picsum 占位图
     * 与首页 feed、分类列表卡片共用同一 seed 规则，保证同一文章缩略图一致
     *
     * @param array $meta Front Matter 或含 cover 键的文章索引项
     * @param string $filename 文章文件名（无扩展名），作 picsum seed
     * @param string $size 占位图尺寸，如 320/180
     * @return string
     */
    public static function resolveCoverUrl(array $meta, string $filename = 'blog', string $size = '320/180'): string
    {
        $cover = self::resolveCover($meta);
        if ($cover !== '') {
            return $cover;
        }

        $seed = rawurlencode($filename !== '' ? $filename : 'blog');

        return 'https://picsum.photos/seed/' . $seed . '/' . $size;
    }

    /**
     * 扫描正文根下一级分类目录
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @return array<int, array{name:string,url:string}>
     */
    private static function listTopCategories(string $rootDir, string $basePath): array
    {
        $rootDir = rtrim($rootDir, '/') . '/';
        if (!is_dir($rootDir)) {
            return [];
        }

        $order = self::loadOrderList($rootDir);
        $dirs = [];
        foreach (scandir($rootDir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }
            if (is_dir($rootDir . $item)) {
                $dirs[] = $item;
            }
        }

        $dirs = self::sortByOrderList($dirs, $order);
        $base = rtrim($basePath, '/');
        $categories = [];

        foreach ($dirs as $name) {
            $categories[] = [
                'name' => $name,
                'url' => $base . '/' . trim(str_replace('\\', '/', $name), '/') . '/',
            ];
        }

        return $categories;
    }

    /**
     * 读取 .order 排序文件
     *
     * @param string $dirPath 目录绝对路径
     * @return array<int, string>
     */
    private static function loadOrderList(string $dirPath): array
    {
        $orderFile = rtrim($dirPath, '/') . '/.order';
        if (!is_file($orderFile)) {
            return [];
        }

        $orderList = [];
        $lines = file($orderFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $orderList[] = $line;
            }
        }

        return $orderList;
    }

    /**
     * 按 .order 规则排序目录名
     *
     * @param array<int, string> $items 目录名列表
     * @param array<int, string> $order 排序规则
     * @return array<int, string>
     */
    private static function sortByOrderList(array $items, array $order): array
    {
        if ($order === []) {
            sort($items, SORT_STRING);

            return $items;
        }

        $rank = array_flip($order);
        usort($items, static function (string $a, string $b) use ($rank): int {
            $ra = $rank[$a] ?? PHP_INT_MAX;
            $rb = $rank[$b] ?? PHP_INT_MAX;
            if ($ra === $rb) {
                return strcmp($a, $b);
            }

            return $ra <=> $rb;
        });

        return $items;
    }

    /**
     * 为导航项标记当前高亮
     *
     * @param array<int, array<string, mixed>> $items 导航项
     * @param string $currentPath 当前路径
     * @param string $basePath 博客 URL 前缀
     * @return array<int, array<string, mixed>>
     */
    private static function markActiveNavItems(array $items, string $currentPath, string $basePath): array
    {
        $current = self::normalizeNavPath($currentPath);
        $homeBase = rtrim($basePath, '/');

        foreach ($items as &$item) {
            $item['active'] = false;
            $type = (string) ($item['type'] ?? 'link');

            if ($type === 'home') {
                $item['active'] = $current === $homeBase
                    || $current === $homeBase . '/'
                    || ($homeBase === '/Blog' && $current === '/Blog');
                continue;
            }

            if ($type === 'category' || $type === 'link') {
                $item['active'] = self::isNavUrlActive($current, (string) ($item['url'] ?? ''));
                continue;
            }

            if ($type === 'more' && !empty($item['children']) && is_array($item['children'])) {
                foreach ($item['children'] as &$child) {
                    $child['active'] = self::isNavUrlActive($current, (string) ($child['url'] ?? ''));
                }
                unset($child);
                $item['active'] = !empty(array_filter($item['children'], static fn(array $c): bool => !empty($c['active'])));
            }
        }
        unset($item);

        return $items;
    }

    /**
     * 规范化导航路径比较用字符串
     *
     * @param string $path 请求路径
     * @return string
     */
    private static function normalizeNavPath(string $path): string
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?? $path);
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * 判断导航 URL 是否与当前路径匹配
     *
     * @param string $current 当前路径
     * @param string $url 导航链接
     * @return bool
     */
    private static function isNavUrlActive(string $current, string $url): bool
    {
        if ($url === '' || $url === '#') {
            return false;
        }

        $target = self::normalizeNavPath($url);
        if ($current === $target || $current === $target . '/') {
            return true;
        }

        return str_starts_with($current . '/', $target . '/');
    }

    /**
     * 加载并缓存全部文章索引
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @return array<int, array<string, mixed>>
     */
    private static function loadPosts(string $rootDir, string $basePath): array
    {
        $rootDir = rtrim($rootDir, '/') . '/';
        $cacheFile = C('CANDYROOT') . 'Runtime/Doc/blog-portal-index.json';
        $signature = self::computeSignature($rootDir);

        if (self::shouldUseCache($cacheFile, $signature)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['posts']) && is_array($cached['posts'])) {
                return $cached['posts'];
            }
        }

        $posts = self::scanPosts($rootDir, $basePath);
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        file_put_contents($cacheFile, json_encode([
            'version' => self::INDEX_VERSION,
            'signature' => $signature,
            'built_at' => date('Y-m-d H:i:s'),
            'posts' => $posts,
        ], JSON_UNESCAPED_UNICODE));

        return $posts;
    }

    /**
     * 递归扫描 Markdown 文章
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @return array<int, array<string, mixed>>
     */
    private static function scanPosts(string $rootDir, string $basePath): array
    {
        $posts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                continue;
            }

            if (strcasecmp($file->getFilename(), 'README.md') === 0) {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = ltrim(str_replace($rootDir, '', $absolute), '/');
            $raw = file_get_contents($absolute);
            if ($raw === false) {
                continue;
            }

            $meta = \Candy\Extend\Doc\Markdown\Engine::peekMetaFromText($raw);
            if (!empty($meta['password'])) {
                continue;
            }
            // 草稿不进入公开索引（首页 / RSS / 标签 / 分类 / Sitemap）
            if (self::isDraftMeta($meta)) {
                continue;
            }

            $filename = pathinfo($relative, PATHINFO_FILENAME);
            $title = (string) ($meta['title'] ?? $filename);
            $description = (string) ($meta['description'] ?? '');
            $body = self::stripFrontMatter($raw);
            $plain = self::extractPlainText($body);
            $excerpt = $description !== '' ? $description : self::truncateText($plain, 120);
            $category = self::resolveCategory($relative, $meta);
            $tags = self::parseTags($meta);
            $mtime = (int) $file->getMTime();
            $dateTs = self::parseDate((string) ($meta['date'] ?? ''), $mtime);
            $cover = self::resolveCover($meta);
            $featured = self::isFeatured($meta);
            $urlPath = str_ends_with($relative, '.md') ? substr($relative, 0, -3) : $relative;
            $url = rtrim($basePath, '/') . '/' . trim(str_replace('\\', '/', $urlPath), '/');

            $posts[] = [
                'title' => $title,
                'url' => $url,
                'excerpt' => $excerpt,
                'description' => $description,
                'category' => $category,
                'category_url' => $category !== ''
                    ? rtrim($basePath, '/') . '/' . rawurlencode($category) . '/'
                    : rtrim($basePath, '/') . '/',
                'tags' => $tags,
                'cover' => $cover,
                'featured' => $featured,
                'date' => $dateTs > 0 ? date('Y-m-d', $dateTs) : '',
                'date_ts' => $dateTs,
                'mtime' => $mtime,
                'filename' => $filename,
                // 预计阅读时间：按正文纯文本字符数估算（中文约 400 字/分钟）
                'reading_minutes' => max(1, (int) ceil(mb_strlen($plain) / 400)),
            ];
        }

        return $posts;
    }

    /**
     * 按发布时间倒序取前 N 篇
     *
     * @param array<int, array<string, mixed>> $posts 文章列表
     * @param int $limit 条数
     * @return array<int, array<string, mixed>>
     */
    private static function sortByDate(array $posts, int $limit): array
    {
        $sorted = $posts;
        usort($sorted, static function (array $a, array $b): int {
            return ($b['date_ts'] ?? 0) <=> ($a['date_ts'] ?? 0)
                ?: ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
        });

        return array_slice($sorted, 0, max(0, $limit));
    }

    /**
     * 取推荐/精选文章
     *
     * 优先 meta.featured=true；不足时按日期补齐
     *
     * @param array<int, array<string, mixed>> $posts 文章列表
     * @param int $limit 条数
     * @return array<int, array<string, mixed>>
     */
    private static function listFeatured(array $posts, int $limit): array
    {
        $featured = array_values(array_filter($posts, static fn(array $p): bool => !empty($p['featured'])));
        $featured = self::sortByDate($featured, $limit);

        if (count($featured) >= $limit) {
            return $featured;
        }

        $pickedUrls = array_column($featured, 'url');
        foreach (self::sortByDate($posts, PHP_INT_MAX) as $post) {
            if (in_array($post['url'], $pickedUrls, true)) {
                continue;
            }
            $featured[] = $post;
            if (count($featured) >= $limit) {
                break;
            }
        }

        return array_slice($featured, 0, max(0, $limit));
    }

    /**
     * 按分类聚合笔记 Tab 数据
     *
     * @param array<int, array<string, mixed>> $posts 文章列表
     * @param int $perCategory 每类展示篇数
     * @return array<int, array{slug:string,name:string,url:string,posts:array,cover:string}>
     */
    private static function listCategories(array $posts, int $perCategory): array
    {
        $groups = [];
        foreach ($posts as $post) {
            $name = (string) ($post['category'] ?? '');
            if ($name === '') {
                $name = '未分类';
            }
            $groups[$name][] = $post;
        }

        $categories = [];
        foreach ($groups as $name => $items) {
            $sorted = self::sortByDate($items, $perCategory);
            $slug = self::slugify($name);
            $categories[] = [
                'slug' => $slug,
                'name' => $name,
                'url' => $sorted[0]['category_url'] ?? '/Blog/',
                'posts' => $sorted,
                'cover' => $sorted[0]['cover'] ?? '',
            ];
        }

        usort($categories, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $categories;
    }

    /**
     * 聚合标签并映射词云权重
     *
     * @param array<int, array<string, mixed>> $posts 文章列表
     * @param int $limit 最多标签数
     * @param string $basePath URL 前缀（如 /Blog/）
     * @return array<int, array{name:string,href:string,tone:string,weight:int}>
     */
    private static function aggregateTags(array $posts, int $limit, string $basePath = '/Blog/'): array
    {
        $counts = [];
        foreach ($posts as $post) {
            foreach ((array) ($post['tags'] ?? []) as $tag) {
                $tag = trim((string) $tag);
                if ($tag === '') {
                    continue;
                }
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return [];
        }

        arsort($counts);
        $max = max($counts);
        $cloud = [];
        $i = 0;

        foreach (array_slice($counts, 0, max(1, $limit), true) as $name => $count) {
            $weight = (int) max(1, min(5, (int) round($count / max(1, $max) * 5)));
            $cloud[] = [
                'name' => $name,
                'href' => self::tagUrl($basePath, (string) $name),
                'tone' => self::TAG_TONES[$i % count(self::TAG_TONES)],
                'weight' => $weight,
            ];
            $i++;
        }

        return $cloud;
    }

    /**
     * 清除门户索引缓存（发文后调用）
     *
     * @return void
     * @static
     */
    public static function clearPortalCache(): void
    {
        $cacheFile = C('CANDYROOT') . 'Runtime/Doc/blog-portal-index.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * 构建轮播幻灯数据
     *
     * @param array<int, array<string, mixed>> $posts 全部文章
     * @param array<int, array<string, mixed>> $featured 推荐文章
     * @param array $conf BlogConfig
     * @param int $limit 幻灯数量
     * @return array<int, array{image:string,title:string,subtitle:string,link:string,theme:string}>
     */
    private static function buildCarouselSlides(array $posts, array $featured, array $conf, int $limit): array
    {
        $themes = ['ink', 'blue', 'warm'];
        $imgBase = '/Doc/Blog/Static/img/';
        $candidates = $featured !== [] ? $featured : self::sortByDate($posts, $limit);
        $slides = [];

        foreach (array_slice($candidates, 0, max(1, $limit)) as $i => $post) {
            $slides[] = [
                'image' => (string) ($post['cover'] ?? $imgBase . 'hero.svg'),
                'title' => (string) $post['title'],
                'subtitle' => (string) ($post['excerpt'] ?? $conf['site_description'] ?? ''),
                'link' => (string) $post['url'],
                'theme' => $themes[$i % count($themes)],
            ];
        }

        if ($slides === []) {
            $slides[] = [
                'image' => $imgBase . 'hero.svg',
                'title' => (string) ($conf['site_name'] ?? '博客'),
                'subtitle' => (string) ($conf['site_description'] ?? ''),
                'link' => rtrim((string) ($conf['home_url'] ?? '/Blog/'), '/') . '/',
                'theme' => 'ink',
            ];
        }

        return $slides;
    }

    /**
     * 解析分类名
     *
     * @param string $relative 相对正文根的路径
     * @param array $meta Front Matter
     * @return string
     */
    private static function resolveCategory(string $relative, array $meta): string
    {
        if (!empty($meta['category'])) {
            return trim((string) $meta['category']);
        }

        $dir = trim(str_replace('\\', '/', dirname($relative)), '/.');
        if ($dir === '' || $dir === '.') {
            return '';
        }

        return basename($dir);
    }

    /**
     * 解析标签列表
     *
     * @param array $meta Front Matter
     * @return array<int, string>
     */
    private static function parseTags(array $meta): array
    {
        if (empty($meta['tags'])) {
            return [];
        }

        if (is_array($meta['tags'])) {
            return array_values(array_filter(array_map('trim', $meta['tags'])));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $meta['tags']))));
    }

    /**
     * 解析文章日期
     *
     * @param string $date meta.date 字符串
     * @param int $fallback 文件 mtime
     * @return int Unix 时间戳
     */
    private static function parseDate(string $date, int $fallback): int
    {
        $date = trim($date);
        if ($date !== '') {
            $ts = strtotime($date);
            if ($ts !== false) {
                return $ts;
            }
        }

        return $fallback > 0 ? $fallback : 0;
    }

    /**
     * 判断 Front Matter 是否标记为草稿
     *
     * 支持 draft: true / 1 / yes / on，或 status: draft
     *
     * @param array $meta Front Matter
     * @return bool
     * @static
     */
    public static function isDraftMeta(array $meta): bool
    {
        if (isset($meta['draft'])) {
            $val = strtolower(trim((string) $meta['draft']));
            return in_array($val, ['1', 'true', 'yes', 'on'], true);
        }

        $status = strtolower(trim((string) ($meta['status'] ?? '')));

        return $status === 'draft';
    }

    /**
     * 判断是否精选文章
     *
     * @param array $meta Front Matter
     * @return bool
     */
    private static function isFeatured(array $meta): bool
    {
        if (isset($meta['featured'])) {
            $val = strtolower(trim((string) $meta['featured']));
            return in_array($val, ['1', 'true', 'yes', 'on'], true);
        }

        if (isset($meta['weight']) && is_numeric($meta['weight'])) {
            return (int) $meta['weight'] >= 4;
        }

        return false;
    }

    /**
     * 解析封面图地址
     *
     * @param array $meta Front Matter
     * @return string
     */
    private static function resolveCover(array $meta): string
    {
        foreach (['cover', 'og_image', 'image'] as $key) {
            if (!empty($meta[$key])) {
                return trim((string) $meta[$key]);
            }
        }

        return '';
    }

    /**
     * 分类名转 Tab slug
     *
     * @param string $name 分类名
     * @return string
     */
    private static function slugify(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9\x{4e00}-\x{9fff}]+/iu', '-', $name) ?? $name);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'cat';
    }

    /**
     * 截断摘要文本
     *
     * @param string $text 纯文本
     * @param int $length 最大长度
     * @return string
     */
    private static function truncateText(string $text, int $length): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…';
    }

    /**
     * 是否使用磁盘缓存
     *
     * @param string $cacheFile 缓存路径
     * @param string $signature 目录签名
     * @return bool
     */
    private static function shouldUseCache(string $cacheFile, string $signature): bool
    {
        if (!is_file($cacheFile)) {
            return false;
        }

        if (defined('DEBUG') && DEBUG) {
            return false;
        }

        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (!is_array($cached)) {
            return false;
        }

        return ($cached['version'] ?? '') === self::INDEX_VERSION
            && ($cached['signature'] ?? '') === $signature;
    }

    /**
     * 计算正文目录签名
     *
     * @param string $rootDir 正文根目录
     * @return string
     */
    private static function computeSignature(string $rootDir): string
    {
        $parts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                continue;
            }
            $parts[] = $file->getPathname() . ':' . $file->getMTime();
        }

        sort($parts);

        return md5(implode('|', $parts));
    }

    /**
     * 移除 Front Matter
     *
     * @param string $text 原文
     * @return string
     */
    private static function stripFrontMatter(string $text): string
    {
        if (strpos($text, '---') !== 0) {
            return $text;
        }

        $endPos = strpos($text, '---', 3);
        if ($endPos === false) {
            return $text;
        }

        return ltrim(substr($text, $endPos + 3));
    }

    /**
     * 提取 Markdown 纯文本
     *
     * @param string $markdown 正文
     * @return string
     */
    private static function extractPlainText(string $markdown): string
    {
        $text = $markdown;
        $text = preg_replace('/```[\s\S]*?```/u', ' ', $text) ?? $text;
        $text = preg_replace('/`[^`]+`/u', ' ', $text) ?? $text;
        $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\[[^\]]*\]\([^)]+\)/u', ' ', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/u', ' ', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/[*_~>#|=-]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
