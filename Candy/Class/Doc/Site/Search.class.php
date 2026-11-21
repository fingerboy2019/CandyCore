<?php
/***
 * 文档站全文检索
 *
 * 业务说明：递归索引 Markdown 文档、缓存检索结果、生成摘要与高亮片段
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:35:00 $
 *
 * @package Candy\Extend\Doc\Site
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Site;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Search
{
    /** 索引结构版本，变更后自动重建 */
    private const INDEX_VERSION = '1.1.0';

    /**
     * 执行文档搜索
     *
     * 关键词为空时返回空结果；命中按标题、文件名、标题行、正文加权排序
     *
     * @param string $rootDir 文档根目录绝对路径
     * @param string $basePath URL 前缀（如 /Doc/）
     * @param string $keyword 搜索关键词
     * @param int $limit 最大返回条数
     * @param array $options 可选：skip_readme、url_strip_prefix（博客隐藏 content_dir）
     * @return array {keyword, total, results[]}
     * @access public
     * @static
     */
    public static function query(string $rootDir, string $basePath, string $keyword, int $limit = 20, array $options = []): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['keyword' => '', 'total' => 0, 'results' => []];
        }

        $index = self::loadIndex($rootDir, $basePath, $options);
        $keywordLower = mb_strtolower($keyword, 'UTF-8');
        $scored = [];

        foreach ($index as $doc) {
            $score = self::scoreDocument($doc, $keywordLower);
            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'title' => $doc['title'],
                'url' => $doc['url'],
                'category' => (string) ($doc['category'] ?? ''),
                'score' => $score,
                'snippet' => self::buildSnippet($doc, $keyword),
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp($a['title'], $b['title']);
        });

        $results = array_slice($scored, 0, max(1, $limit));

        return [
            'keyword' => $keyword,
            'total' => count($scored),
            'results' => $results,
        ];
    }

    /**
     * 加载或构建文档索引
     *
     * @param string $rootDir 文档根目录
     * @param string $basePath URL 前缀
     * @param array $options 索引选项
     * @return array 索引文档列表
     * @access private
     * @static
     */
    private static function loadIndex(string $rootDir, string $basePath, array $options = []): array
    {
        $rootDir = rtrim($rootDir, '/') . '/';
        $cacheFile = C('CANDYROOT') . 'Runtime/Doc/search-index-' . md5($rootDir . '|' . $basePath) . '.json';
        $signature = self::computeSignature($rootDir);

        if (self::shouldUseCache($cacheFile, $signature)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['documents']) && is_array($cached['documents'])) {
                return $cached['documents'];
            }
        }

        $documents = self::buildIndex($rootDir, $basePath, $options);
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        file_put_contents($cacheFile, json_encode([
            'version' => self::INDEX_VERSION,
            'signature' => $signature,
            'built_at' => date('Y-m-d H:i:s'),
            'documents' => $documents,
        ], JSON_UNESCAPED_UNICODE));

        return $documents;
    }

    /**
     * 是否使用磁盘缓存索引
     *
     * @param string $cacheFile 缓存文件路径
     * @param string $signature 当前签名
     * @return bool
     * @access private
     * @static
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
     * 计算文档目录签名（路径 + mtime）
     *
     * @param string $rootDir 文档根目录
     * @return string
     * @access private
     * @static
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
     * 递归扫描并构建索引
     *
     * @param string $rootDir 文档根目录
     * @param string $basePath URL 前缀
     * @param array $options skip_readme、url_strip_prefix
     * @return array
     * @access private
     * @static
     */
    private static function buildIndex(string $rootDir, string $basePath, array $options = []): array
    {
        $skipReadme = !empty($options['skip_readme']);
        $urlSuffix = (string) ($options['url_suffix'] ?? '');
        $urlStripPrefix = trim(str_replace('\\', '/', (string) ($options['url_strip_prefix'] ?? '')), '/');
        if ($urlStripPrefix !== '') {
            $urlStripPrefix .= '/';
        }

        $documents = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                continue;
            }

            if ($skipReadme && strcasecmp($file->getFilename(), 'README.md') === 0) {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', str_replace($rootDir, '', $absolute)), '/');
            if ($urlStripPrefix !== '' && str_starts_with($relative, $urlStripPrefix)) {
                $relative = substr($relative, strlen($urlStripPrefix));
            }

            $raw = file_get_contents($absolute);
            if ($raw === false) {
                continue;
            }

            $meta = \Candy\Extend\Doc\Markdown\Engine::peekMetaFromText($raw);
            if (!empty($meta['password'])) {
                continue;
            }
            if (\Candy\Extend\Doc\Blog\Index::isDraftMeta($meta)) {
                continue;
            }

            $body = self::stripFrontMatter($raw);
            $filename = pathinfo($relative, PATHINFO_FILENAME);
            $title = (string) ($meta['title'] ?? $filename);
            $plain = self::extractPlainText($body);
            $headings = self::extractHeadings($body);
            $category = self::resolveCategory($relative, $meta);

            $urlPath = str_ends_with($relative, '.md') ? substr($relative, 0, -3) : $relative;
            $documents[] = [
                'title' => $title,
                'filename' => $filename,
                'url' => rtrim($basePath, '/') . '/' . trim(str_replace('\\', '/', $urlPath), '/') . $urlSuffix,
                'category' => $category,
                'text' => $plain,
                'headings' => $headings,
            ];
        }

        return $documents;
    }

    /**
     * 移除 Markdown 顶部 Front Matter
     *
     * @param string $text 原始文本
     * @return string 正文
     * @access private
     * @static
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
     * 从 Markdown 正文提取可检索纯文本
     *
     * @param string $markdown 去掉 Front Matter 后的正文
     * @return string
     * @access private
     * @static
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

    /**
     * 提取文档标题行文本
     *
     * @param string $markdown 正文
     * @return array
     * @access private
     * @static
     */
    private static function extractHeadings(string $markdown): array
    {
        if (!preg_match_all('/^#{1,6}\s+(.+)$/mu', $markdown, $matches)) {
            return [];
        }

        $headings = [];
        foreach ($matches[1] as $heading) {
            $heading = trim(preg_replace('/\{[^}]*\}/u', '', $heading) ?? $heading);
            if ($heading !== '') {
                $headings[] = $heading;
            }
        }

        return $headings;
    }

    /**
     * 计算文档与关键词的匹配得分
     *
     * @param array $doc 索引项
     * @param string $keywordLower 小写关键词
     * @return int
     * @access private
     * @static
     */
    private static function scoreDocument(array $doc, string $keywordLower): int
    {
        $score = 0;

        if (mb_strpos(mb_strtolower($doc['title'], 'UTF-8'), $keywordLower, 0, 'UTF-8') !== false) {
            $score += 100;
        }
        if (mb_strpos(mb_strtolower($doc['filename'], 'UTF-8'), $keywordLower, 0, 'UTF-8') !== false) {
            $score += 80;
        }

        foreach ($doc['headings'] as $heading) {
            if (mb_strpos(mb_strtolower($heading, 'UTF-8'), $keywordLower, 0, 'UTF-8') !== false) {
                $score += 40;
            }
        }

        if (!empty($doc['category']) && mb_strpos(mb_strtolower((string) $doc['category'], 'UTF-8'), $keywordLower, 0, 'UTF-8') !== false) {
            $score += 30;
        }

        if (mb_strpos(mb_strtolower($doc['text'], 'UTF-8'), $keywordLower, 0, 'UTF-8') !== false) {
            $score += 10;
        }

        return $score;
    }

    /**
     * 生成带高亮标记的摘要片段
     *
     * @param array $doc 索引项
     * @param string $keyword 原始关键词
     * @return string
     * @access private
     * @static
     */
    private static function buildSnippet(array $doc, string $keyword): string
    {
        $keywordLower = mb_strtolower($keyword, 'UTF-8');
        $sources = array_merge([$doc['title']], $doc['headings'], [$doc['text']]);

        foreach ($sources as $source) {
            $lower = mb_strtolower($source, 'UTF-8');
            $pos = mb_strpos($lower, $keywordLower, 0, 'UTF-8');
            if ($pos === false) {
                continue;
            }

            $start = max(0, $pos - 24);
            $length = mb_strlen($keyword, 'UTF-8') + 48;
            $snippet = mb_substr($source, $start, $length, 'UTF-8');
            if ($start > 0) {
                $snippet = '…' . $snippet;
            }
            if ($start + $length < mb_strlen($source, 'UTF-8')) {
                $snippet .= '…';
            }

            return self::highlightText($snippet, $keyword);
        }

        return htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8');
    }

    /**
     * 在文本中高亮关键词
     *
     * @param string $text 摘要文本
     * @param string $keyword 关键词
     * @return string 含 mark 标签的安全 HTML
     * @access private
     * @static
     */
    private static function highlightText(string $text, string $keyword): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $keyword = trim($keyword);
        if ($keyword === '') {
            return $escaped;
        }

        $pattern = '/' . preg_quote($keyword, '/') . '/iu';

        return preg_replace(
            $pattern,
            '<mark class="search-hit">$0</mark>',
            $escaped
        ) ?? $escaped;
    }

    /**
     * 解析分类名（与 BlogIndex 规则一致）
     *
     * @param string $relative 相对正文根路径
     * @param array $meta Front Matter
     * @return string
     * @access private
     * @static
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
}
