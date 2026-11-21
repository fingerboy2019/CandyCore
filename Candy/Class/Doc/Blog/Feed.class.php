<?php
/***
 * 博客 RSS / Sitemap
 *
 * 业务说明：动态输出与静态落盘（Doc/Blog/Static/feed/）；空配置兜底；MD 变更后可重建
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 19:05:39 $
 *
 * @package Candy\Extend\Doc\Blog
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Feed
{
    /** 静态文件目录（相对 CANDYROOT） */
    private const STATIC_REL = 'Doc/Blog/Static/feed';

    /**
     * 空配置兜底（生成 RSS / Sitemap 前调用）
     *
     * @param array<string, mixed> $conf
     * @return array<string, mixed>
     */
    public static function normalizeConf(array $conf): array
    {
        $name = trim((string) ($conf['site_name'] ?? ''));
        $conf['site_name'] = $name !== '' ? $name : 'Candy 博客';

        $conf['site_description'] = trim((string) ($conf['site_description'] ?? ''));
        $conf['default_author'] = trim((string) ($conf['default_author'] ?? ''));

        $siteUrl = trim((string) ($conf['site_url'] ?? ''));
        if ($siteUrl === '') {
            $domain = Config::normalizeDomain((string) ($conf['blog_domain'] ?? ''));
            if ($domain !== '') {
                $siteUrl = 'https://' . $domain;
            }
        }
        $conf['site_url'] = rtrim($siteUrl, '/');

        $limits = is_array($conf['feed_limits'] ?? null) ? $conf['feed_limits'] : [];
        $rss = (int) ($limits['rss_items'] ?? 0);
        $limits['rss_items'] = $rss > 0 ? max(1, min(200, $rss)) : 20;
        $map = (int) ($limits['sitemap_items'] ?? 0);
        $limits['sitemap_items'] = $map > 0 ? max(1, min(5000, $map)) : 1000;
        $conf['feed_limits'] = $limits;

        return $conf;
    }

    /**
     * 构建站点绝对根 URL（无尾斜杠）
     *
     * @param array<string, mixed> $conf BlogConfig
     * @return string
     */
    public static function siteOrigin(array $conf): string
    {
        $conf = self::normalizeConf($conf);
        $configured = trim((string) ($conf['site_url'] ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $server = I('server.', [], '');
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || ((int) ($server['SERVER_PORT'] ?? 0) === 443)
            || (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = trim((string) ($server['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }

        return $scheme . '://' . $host;
    }

    /**
     * 前台 URL 前缀（路径模式 /Blog/，独立域名 /）
     *
     * @param array<string, mixed> $conf
     * @return string
     */
    public static function publicBasePath(array $conf): string
    {
        return Config::publicBasePath($conf);
    }

    /**
     * RSS 公开路径（含 basePath 前缀）
     *
     * @param string $basePath 如 /Blog/ 或 /
     * @return string
     */
    public static function feedPath(string $basePath): string
    {
        return rtrim($basePath, '/') . '/rss.xml';
    }

    /**
     * Sitemap 公开路径
     *
     * @param string $basePath
     * @return string
     */
    public static function sitemapPath(string $basePath): string
    {
        return rtrim($basePath, '/') . '/sitemap.xml';
    }

    /**
     * 静态 feed 目录绝对路径
     *
     * @return string
     */
    public static function staticDir(): string
    {
        return rtrim(C('CANDYROOT'), '/\\') . '/' . self::STATIC_REL;
    }

    /**
     * 静态 RSS / Sitemap 文件路径
     *
     * @param string $which rss|sitemap
     * @return string
     */
    public static function staticFile(string $which): string
    {
        $name = $which === 'sitemap' ? 'sitemap.xml' : 'rss.xml';

        return self::staticDir() . '/' . $name;
    }

    /**
     * 静态文件对外 URL（Nginx 直出）
     *
     * @param string $which
     * @return string
     */
    public static function staticPublicUrl(string $which): string
    {
        $name = $which === 'sitemap' ? 'sitemap.xml' : 'rss.xml';

        return '/Doc/Blog/Static/feed/' . $name;
    }

    /**
     * 生成 RSS 2.0 XML
     *
     * @param string $rootDir 正文根目录
     * @param string $basePath URL 前缀
     * @param array<string, mixed> $conf BlogConfig
     * @return string
     */
    public static function buildRssXml(string $rootDir, string $basePath, array $conf): string
    {
        $conf = self::normalizeConf($conf);
        $limit = (int) $conf['feed_limits']['rss_items'];
        $posts = Index::listPostsPage($rootDir, $basePath, 0, $limit)['items'];
        $origin = self::siteOrigin($conf);
        $channelLink = $origin . rtrim($basePath, '/') . '/';
        $feedLink = $origin . self::feedPath($basePath);
        $siteName = (string) $conf['site_name'];
        $siteDesc = (string) $conf['site_description'];
        $author = (string) $conf['default_author'];

        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $itemsXml = '';
        foreach ($posts as $post) {
            $title = (string) ($post['title'] ?? '');
            $link = $origin . (string) ($post['url'] ?? '');
            $desc = (string) ($post['excerpt'] ?? $post['description'] ?? '');
            $dateTs = (int) ($post['date_ts'] ?? $post['mtime'] ?? time());
            $pubDate = gmdate('D, d M Y H:i:s', $dateTs > 0 ? $dateTs : time()) . ' GMT';
            $guid = $link;
            $cat = (string) ($post['category'] ?? '');
            $itemsXml .= "\n    <item>\n"
                . '      <title>' . $esc($title) . "</title>\n"
                . '      <link>' . $esc($link) . "</link>\n"
                . '      <guid isPermaLink="true">' . $esc($guid) . "</guid>\n"
                . '      <pubDate>' . $esc($pubDate) . "</pubDate>\n"
                . '      <description>' . $esc($desc) . "</description>\n";
            if ($cat !== '') {
                $itemsXml .= '      <category>' . $esc($cat) . "</category>\n";
            }
            if ($author !== '') {
                $itemsXml .= '      <author>' . $esc($author) . "</author>\n";
            }
            $itemsXml .= "    </item>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "  <channel>\n"
            . '    <title>' . $esc($siteName) . "</title>\n"
            . '    <link>' . $esc($channelLink) . "</link>\n"
            . '    <description>' . $esc($siteDesc) . "</description>\n"
            . "    <language>zh-cn</language>\n"
            . '    <lastBuildDate>' . $esc(gmdate('D, d M Y H:i:s') . ' GMT') . "</lastBuildDate>\n"
            . '    <atom:link href="' . $esc($feedLink) . '" rel="self" type="application/rss+xml" />'
            . $itemsXml . "\n"
            . "  </channel>\n"
            . "</rss>\n";
    }

    /**
     * 生成 sitemap.xml
     *
     * @param string $rootDir
     * @param string $basePath
     * @param array<string, mixed> $conf
     * @return string
     */
    public static function buildSitemapXml(string $rootDir, string $basePath, array $conf): string
    {
        $conf = self::normalizeConf($conf);
        $origin = self::siteOrigin($conf);
        $home = $origin . rtrim($basePath, '/') . '/';
        $maxPosts = (int) $conf['feed_limits']['sitemap_items'];
        $posts = array_slice(Index::listAllPosts($rootDir, $basePath), 0, $maxPosts);
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $urls = [];
        $urls[$home] = [
            'loc' => $home,
            'lastmod' => gmdate('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];

        $categories = [];
        $tags = [];
        $latestTs = 0;
        foreach ($posts as $post) {
            $url = $origin . (string) ($post['url'] ?? '');
            if ($url === $origin) {
                continue;
            }
            $ts = (int) ($post['date_ts'] ?? $post['mtime'] ?? 0);
            if ($ts > $latestTs) {
                $latestTs = $ts;
            }
            $lastmod = $ts > 0 ? gmdate('Y-m-d', $ts) : gmdate('Y-m-d');
            $urls[$url] = [
                'loc' => $url,
                'lastmod' => $lastmod,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];

            $cat = trim((string) ($post['category'] ?? ''));
            if ($cat !== '') {
                $categories[$cat] = true;
            }
            foreach ((array) ($post['tags'] ?? []) as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        if ($latestTs > 0) {
            $urls[$home]['lastmod'] = gmdate('Y-m-d', $latestTs);
        }

        foreach (array_keys($categories) as $cat) {
            $loc = $origin . rtrim($basePath, '/') . '/' . rawurlencode((string) $cat) . '/';
            $urls[$loc] = [
                'loc' => $loc,
                'lastmod' => $urls[$home]['lastmod'],
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ];
        }

        foreach (array_keys($tags) as $tag) {
            $loc = $origin . Index::tagUrl($basePath, (string) $tag);
            $urls[$loc] = [
                'loc' => $loc,
                'lastmod' => $urls[$home]['lastmod'],
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];
        }

        $body = '';
        foreach ($urls as $row) {
            $body .= "  <url>\n"
                . '    <loc>' . $esc((string) $row['loc']) . "</loc>\n"
                . '    <lastmod>' . $esc((string) $row['lastmod']) . "</lastmod>\n"
                . '    <changefreq>' . $esc((string) $row['changefreq']) . "</changefreq>\n"
                . '    <priority>' . $esc((string) $row['priority']) . "</priority>\n"
                . "  </url>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $body
            . "</urlset>\n";
    }

    /**
     * 写入静态 RSS
     *
     * @param array<string, mixed>|null $conf
     * @return array{code:int,message:string,path?:string,url?:string,count?:int}
     */
    public static function writeStaticRss(?array $conf = null): array
    {
        return self::writeStatic('rss', $conf);
    }

    /**
     * 写入静态 Sitemap
     *
     * @param array<string, mixed>|null $conf
     * @return array{code:int,message:string,path?:string,url?:string,count?:int}
     */
    public static function writeStaticSitemap(?array $conf = null): array
    {
        return self::writeStatic('sitemap', $conf);
    }

    /**
     * 同时重建 RSS + Sitemap（MD 变更后调用）
     *
     * @param array<string, mixed>|null $conf
     * @return array{code:int,message:string,data?:array}
     */
    public static function regenerateStatic(?array $conf = null): array
    {
        Index::clearPortalCache();
        $rss = self::writeStaticRss($conf);
        $map = self::writeStaticSitemap($conf);
        if (($rss['code'] ?? 1) !== 0) {
            return $rss;
        }
        if (($map['code'] ?? 1) !== 0) {
            return $map;
        }

        return [
            'code' => 0,
            'message' => 'RSS 与站点地图已更新',
            'data' => [
                'rss' => $rss,
                'sitemap' => $map,
            ],
        ];
    }

    /**
     * Markdown 文件变更后尝试更新静态订阅文件（失败静默，不阻断主流程）
     *
     * @param string $path 相对或绝对路径 / 文件名
     * @return void
     */
    public static function afterMarkdownChanged(string $path = ''): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path !== '' && !str_ends_with(strtolower($path), '.md')) {
            return;
        }
        try {
            self::regenerateStatic();
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * @param string $which rss|sitemap
     * @param array<string, mixed>|null $conf
     * @return array{code:int,message:string,path?:string,url?:string,count?:int}
     */
    private static function writeStatic(string $which, ?array $conf = null): array
    {
        $conf = self::normalizeConf($conf ?? Config::load());
        $root = Config::contentRoot($conf);
        if ($root === '' || !is_dir($root)) {
            return ['code' => 1, 'message' => '正文目录不存在，无法生成'];
        }

        $basePath = self::publicBasePath($conf);
        Index::clearPortalCache();

        if ($which === 'sitemap') {
            $xml = self::buildSitemapXml($root, $basePath, $conf);
            $label = '站点地图';
            $count = (int) $conf['feed_limits']['sitemap_items'];
        } else {
            $xml = self::buildRssXml($root, $basePath, $conf);
            $label = 'RSS';
            $count = (int) $conf['feed_limits']['rss_items'];
        }

        $dir = self::staticDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['code' => 1, 'message' => '无法创建目录：' . self::STATIC_REL];
        }

        $file = self::staticFile($which);
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (file_put_contents($tmp, $xml, LOCK_EX) === false) {
            return ['code' => 1, 'message' => $label . '写入失败'];
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return ['code' => 1, 'message' => $label . '落盘失败'];
        }
        @chmod($file, 0644);

        return [
            'code' => 0,
            'message' => $label . '已生成',
            'path' => $file,
            'url' => self::staticPublicUrl($which),
            'live' => $which === 'sitemap'
                ? self::sitemapPath($basePath)
                : self::feedPath($basePath),
            'count' => $count,
            'bytes' => strlen($xml),
        ];
    }
}
