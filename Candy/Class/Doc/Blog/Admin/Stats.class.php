<?php
/***
 * 博客管理后台 Dashboard 统计
 *
 * 业务说明：聚合文章/评论/最近更新等概览数据，供管理首页展示
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 00:00:00 $
 *
 * @package Candy\Extend\Doc\Blog\Admin
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog\Admin;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Stats
{
    /**
     * 聚合管理概览统计
     *
     * @param array $conf 博客配置
     * @return array<string, mixed>
     */
    public static function overview(array $conf): array
    {
        $contentRoot = rtrim((string) (self::contentRoot($conf)), '/\\');
        $stats = [
            'article_total' => 0,
            'category_total' => 0,
            'comment_total' => 0,
            'comment_pending' => 0,
            'recent_files' => [],
            'storage_size' => 0,
        ];

        if (!is_dir($contentRoot)) {
            return $stats;
        }

        // 遍历 Markdown 文章
        $mdFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($contentRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $mdFiles[] = [
                    'path' => substr(str_replace('\\', '/', $file->getPathname()), strlen($contentRoot) + 1),
                    'mtime' => $file->getMTime(),
                ];
            }
        }

        $stats['article_total'] = count($mdFiles);
        // 分类 = 顶级非隐藏目录（与前台 listTopCategories 对齐，排除 .comments 等）
        $stats['category_total'] = self::countTopCategories($contentRoot);

        // 字数统计（正文，剔除 front matter）
        $words = array_map(static function (array $f): int {
            return self::countWords($f['path']);
        }, $mdFiles);
        $stats['words_total'] = array_sum($words);
        $stats['words_avg'] = count($words) > 0 ? (int) round(array_sum($words) / count($words)) : 0;

        // 最近修改
        usort($mdFiles, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $stats['recent_files'] = array_slice(array_map(static function (array $f): array {
            return ['path' => $f['path'], 'mtime' => date('Y-m-d H:i', $f['mtime'])];
        }, $mdFiles), 0, 8);

        // 评论统计 + 趋势 + 分类热度
        try {
            $allComments = \Candy\Extend\Doc\Blog\Comment::listAll($contentRoot, 'all');
            $stats['comment_total'] = count($allComments);
            $stats['comment_pending'] = \Candy\Extend\Doc\Blog\Comment::pendingCount($contentRoot);
            $stats['comment_trend'] = self::commentTrend($allComments, 14);
            $stats['category_stats'] = self::categoryStats($contentRoot, $mdFiles, $allComments);
        } catch (\Throwable) {
        }

        // 内容大小
        $stats['storage_size'] = self::dirSize($contentRoot);

        return $stats;
    }

    /**
     * 统计顶级非隐藏目录数量（博客分类）
     *
     * 与前台 Index::listTopCategories 一致：跳过 . 开头目录（如 .comments）
     *
     * @param string $contentRoot
     * @return int
     */
    private static function countTopCategories(string $contentRoot): int
    {
        if (!is_dir($contentRoot)) {
            return 0;
        }
        $count = 0;
        foreach (scandir($contentRoot) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }
            if (is_dir($contentRoot . DIRECTORY_SEPARATOR . $item)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 统计 Markdown 正文字数（剔除 front matter）
     *
     * @param string $relativePath 相对正文根的 .md 路径
     * @return int
     */
    private static function countWords(string $relativePath): int
    {
        $root = (string) (C('CANDYROOT'));
        $file = $root . 'Doc/Blog/' . (string) (self::$contentDir) . '/' . $relativePath;
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return 0;
        }
        // 剔除顶部 --- front matter ---
        if (str_starts_with(ltrim($raw), '---')) {
            $raw = preg_replace('/^\s*---[\s\S]*?---\s*/u', '', $raw, 1) ?? $raw;
        }
        // 计字符数（含标点，直观对齐 Word 字数）
        return mb_strlen(preg_replace('/[\s]+/u', '', (string) $raw), 'UTF-8');
    }

    /**
     * 评论提交趋势（近 N 天）
     *
     * @param array<int, array<string, mixed>> $comments 全部评论
     * @param int $days 天数
     * @return array<int, array{date:string,count:int}>
     */
    private static function commentTrend(array $comments, int $days): array
    {
        $buckets = [];
        $today = strtotime('today');
        for ($i = $days - 1; $i >= 0; $i--) {
            $buckets[date('Y-m-d', $today - $i * 86400)] = 0;
        }
        foreach ($comments as $c) {
            $ts = (int) ($c['created_at'] ?? 0);
            if ($ts > 0) {
                $day = date('Y-m-d', $ts);
                if (isset($buckets[$day])) {
                    $buckets[$day]++;
                }
            }
        }
        $rows = [];
        foreach ($buckets as $date => $count) {
            $rows[] = ['date' => $date, 'count' => $count];
        }
        return $rows;
    }

    /**
     * 分类热度：每分类文章数与评论数
     *
     * @param string $contentRoot 正文根
     * @param array<int, array{path:string,mtime:int}> $mdFiles 文章列表
     * @param array<int, array<string, mixed>> $comments 全部评论
     * @return array<int, array{name:string,posts:int,comments:int}>
     */
    private static function categoryStats(string $contentRoot, array $mdFiles, array $comments): array
    {
        $cats = [];
        foreach (scandir($contentRoot) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }
            if (is_dir($contentRoot . DIRECTORY_SEPARATOR . $item)) {
                $cats[$item] = ['posts' => 0, 'comments' => 0];
            }
        }
        // 根目录散落文章归为「根目录」
        $cats['根目录'] = ['posts' => 0, 'comments' => 0];

        foreach ($mdFiles as $f) {
            $path = (string) ($f['path'] ?? '');
            $top = explode('/', $path, 2)[0];
            if (isset($cats[$top])) {
                $cats[$top]['posts']++;
            } else {
                $cats['根目录']['posts']++;
            }
        }
        foreach ($comments as $c) {
            $post = (string) ($c['post'] ?? '');
            $top = explode('/', $post, 2)[0];
            if (isset($cats[$top])) {
                $cats[$top]['comments']++;
            } else {
                $cats['根目录']['comments']++;
            }
        }

        $rows = [];
        foreach ($cats as $name => $v) {
            $rows[] = ['name' => $name, 'posts' => $v['posts'], 'comments' => $v['comments']];
        }
        usort($rows, static fn(array $a, array $b): int => $b['posts'] <=> $a['posts']);

        return $rows;
    }

    /** @var string|null 缓存 content_dir，供字数统计拼路径 */
    private static ?string $contentDir = null;

    /**
     * 目录大小
     *
     * @param string $dir
     * @return int 字节数
     */
    private static function dirSize(string $dir): int
    {
        $size = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    /**
     * 博客内容根目录
     *
     * @param array $conf
     * @return string
     */
    private static function contentRoot(array $conf): string
    {
        $dir = trim((string) ($conf['content_dir'] ?? ''));
        self::$contentDir = $dir;
        $base = rtrim((string) (C('CANDYROOT')), '/\\') . '/Doc/Blog/';
        return $dir !== '' ? $base . $dir : $base;
    }
}
