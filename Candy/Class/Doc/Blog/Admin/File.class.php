<?php
/***
 * 博客后台静态资源管理
 *
 * 业务说明：以 Doc/Blog/Static 为根目录管理博客可公开访问的静态文件（图片、CSS、JS 等）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 19:05:39 $
 *
 * @package Candy\Extend\Doc\Blog\Admin
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog\Admin;

use APP\Extend\FileOps;
use Candy\Core\Trait\App\AppEnvironment;
use Candy\Extend\Dir;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class File
{
  /** 博客静态资源 Web 访问前缀（与 UrlParser 放行规则一致） */
    public const STATIC_URL_BASE = '/Doc/Blog/Static/';

    /** @var array<string, array<int, string>> */
    private const TYPE_EXT_MAP = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'],
        'video' => ['mp4', 'webm', 'avi', 'mov', 'mkv', 'flv'],
        'audio' => ['mp3', 'wav', 'ogg', 'aac', 'flac'],
        'doc' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'txt', 'md', 'csv'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'],
    ];

    /**
     * 博客静态资源磁盘根目录（绝对路径）
     *
     * @return string
     */
    public static function staticRoot(): string
    {
        return rtrim(C('CANDYROOT'), '/\\') . '/Doc/Blog/Static';
    }

    /**
     * 获取绑定 Static 目录的 FileOps 实例
     *
     * @return FileOps
     */
    private static function ops(): FileOps
    {
        static $ops = null;
        if ($ops === null) {
            if (is_null(G('C_PUBLIC'))) {
                AppEnvironment::loading();
            }
            $root = self::staticRoot();
            if (!is_dir($root)) {
                throw new \RuntimeException('博客静态目录不存在：Doc/Blog/Static');
            }
            $ops = fileOps($root);
        }

        return $ops;
    }

    /**
     * 博客静态目录固定为本地存储
     *
     * @return string
     */
    public static function storageType(): string
    {
        return 'local';
    }

    /**
     * @return string
     */
    public static function storageLabel(): string
    {
        return 'Doc/Blog/Static';
    }

    /**
     * @param string $path
     * @return string
     */
    public static function normalizePath(string $path): string
    {
        return self::ops()->normalizeRelativePath($path);
    }

    /**
     * 将相对路径转为前台可访问 URL
     *
     * @param string $relativePath
     * @param bool $isDir
     * @return string
     */
    public static function publicUrl(string $relativePath, bool $isDir = false): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return rtrim(self::STATIC_URL_BASE, '/') . '/';
        }
        $segments = array_map('rawurlencode', explode('/', $relativePath));

        return rtrim(self::STATIC_URL_BASE, '/') . '/' . implode('/', $segments) . ($isDir ? '/' : '');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function list(array $params): array
    {
        $path = self::normalizePath((string) ($params['path'] ?? ''));
        $type = strtolower(trim((string) ($params['type'] ?? 'all')));
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = max(1, min(200, (int) ($params['limit'] ?? 20)));

        try {
            $entries = self::listDirectory($path);
        } catch (\Throwable $e) {
            return [
                'code' => 1,
                'message' => $e->getMessage(),
                'count' => 0,
                'data' => [],
                'storage' => 'local',
            ];
        }

        $entries = self::filterEntries($entries, $type, $keyword);
        usort($entries, static function (array $a, array $b): int {
            if (($a['is_dir'] ?? 0) !== ($b['is_dir'] ?? 0)) {
                return ($b['is_dir'] ?? 0) <=> ($a['is_dir'] ?? 0);
            }

            return strcmp((string) ($b['mtime'] ?? ''), (string) ($a['mtime'] ?? ''));
        });

        $total = count($entries);
        $data = array_slice($entries, ($page - 1) * $limit, $limit);

        return [
            'code' => 0,
            'message' => '',
            'count' => $total,
            'data' => $data,
            'storage' => 'local',
        ];
    }

    /**
     * @param string $dir
     * @return array<string, mixed>
     */
    public static function upload(string $dir): array
    {
        if (I('server.REQUEST_METHOD', '', '') !== 'POST') {
            return ['code' => 1, 'message' => '请使用 POST 上传'];
        }

        try {
            $dir = self::normalizePath($dir);
            $result = self::ops()->uploadFromField($dir, 'file', ['isLocalStorage' => true]);
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }

        if ($result['uploaded'] === []) {
            return ['code' => 1, 'message' => $result['errors'][0] ?? '上传失败'];
        }

        // 上传结果中含 .md 时同步更新静态 RSS / Sitemap
        foreach ($result['uploaded'] as $item) {
            $name = '';
            if (is_string($item)) {
                $name = $item;
            } elseif (is_array($item)) {
                $name = (string) ($item['path'] ?? $item['name'] ?? $item['url'] ?? '');
            }
            if ($name !== '' && str_ends_with(strtolower($name), '.md')) {
                \Candy\Extend\Doc\Blog\Feed::afterMarkdownChanged($name);
                break;
            }
        }

        return [
            'code' => 0,
            'message' => '上传成功',
            'data' => $result['uploaded'],
            'errors' => $result['errors'],
        ];
    }

    /**
     * @param string $dir
     * @param string $name
     * @return array<string, mixed>
     */
    public static function mkdir(string $dir, string $name): array
    {
        try {
            $ops = self::ops();
            $dir = $ops->normalizeRelativePath($dir);
            $folderName = $ops->sanitizeFolderName($name);
            $ops->mkdirLocal($dir, $folderName);
            $relativePath = $dir === '' ? $folderName : $dir . '/' . $folderName;

            return [
                'code' => 0,
                'message' => '创建成功',
                'data' => ['path' => $relativePath, 'name' => $folderName],
            ];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<int, string> $paths
     * @return never
     */
    public static function download(array $paths): void
    {
        try {
            $ops = self::ops();
            $normalized = [];
            foreach ($paths as $path) {
                $path = $ops->normalizeRelativePath((string) $path);
                if ($path !== '') {
                    $normalized[] = $path;
                }
            }
            $normalized = array_values(array_unique($normalized));
            if ($normalized === []) {
                throw new \RuntimeException('请选择要下载的文件');
            }
            if (count($normalized) === 1) {
                $ops->streamFile($normalized[0]);
            } else {
                $ops->streamZipDownload($normalized);
            }
            stop();
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => 1, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            stop();
        }
    }

    /**
     * @param array<int, string> $paths
     * @return array<string, mixed>
     */
    public static function remove(array $paths): array
    {
        if ($paths === []) {
            return ['code' => 1, 'message' => '请选择要删除的项'];
        }

        $ops = self::ops();
        $failed = 0;
        $touchedMd = false;
        foreach ($paths as $path) {
            try {
                $path = $ops->normalizeRelativePath((string) $path);
                if ($path === '') {
                    $failed++;
                    continue;
                }
                if ($ops->isDirectory($path) || str_ends_with(strtolower($path), '.md')) {
                    $touchedMd = true;
                }
                if ($ops->isDirectory($path)) {
                    $ops->deleteDirectoryLocal($path);
                } else {
                    $full = $ops->resolveFile($path);
                    if (!is_file($full) || !@unlink($full)) {
                        throw new \RuntimeException('删除失败');
                    }
                }
            } catch (\Throwable) {
                $failed++;
            }
        }

        if ($failed > 0) {
            return ['code' => 1, 'message' => '部分或全部删除失败'];
        }
        if ($touchedMd) {
            \Candy\Extend\Doc\Blog\Feed::afterMarkdownChanged('.md');
        }

        return ['code' => 0, 'message' => '删除成功'];
    }

    /**
     * @param string $dir
     * @param array<int, string> $paths
     * @param string $name
     * @return array<string, mixed>
     */
    public static function compress(string $dir, array $paths, string $name = ''): array
    {
        if ($paths === []) {
            return ['code' => 1, 'message' => '请选择要压缩的文件'];
        }

        try {
            $ops = self::ops();
            $dir = $ops->normalizeRelativePath($dir);
            $normalized = [];
            foreach ($paths as $path) {
                $normalized[] = $ops->normalizeRelativePath((string) $path);
            }
            $data = $ops->compressToDirectory($dir, $normalized, $name);

            return ['code' => 0, 'message' => '压缩成功', 'data' => $data];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param string $dir
     * @param array<int, string> $paths
     * @return array<string, mixed>
     */
    public static function extract(string $dir, array $paths): array
    {
        if ($paths === []) {
            return ['code' => 1, 'message' => '请选择 ZIP 文件'];
        }

        try {
            $ops = self::ops();
            $dir = $ops->normalizeRelativePath($dir);
            $normalized = [];
            foreach ($paths as $path) {
                $normalized[] = $ops->normalizeRelativePath((string) $path);
            }
            $data = $ops->extractToDirectory($dir, $normalized);

            return ['code' => 0, 'message' => '解压成功', 'data' => $data];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * 文件预览（读取内容 + 类型识别）
     *
     * @param string $path 文件相对路径
     * @return array<string, mixed>
     */
    public static function view(string $path): array
    {
        $relative = self::normalizePath($path);
        if ($relative === '' || $relative === '.') {
            return ['code' => 1, 'message' => '无效路径'];
        }

        $root = self::staticRoot();
        $abs = realpath(rtrim($root, '/') . '/' . $relative);
        if ($abs === false || !is_file($abs)) {
            return ['code' => 1, 'message' => '文件不存在'];
        }

        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $size = (int) filesize($abs);

        // 图片直接返回 url
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];
        if (in_array($ext, $imageExts, true)) {
            return [
                'code' => 0,
                'message' => '',
                'data' => [
                    'name' => basename($abs),
                    'type' => 'image',
                    'url' => self::publicUrl($relative),
                ],
            ];
        }

        // 文本类（含 markdown）
        $textExts = ['md', 'markdown', 'txt', 'text', 'html', 'htm', 'css', 'js', 'json', 'xml',
            'yaml', 'yml', 'ini', 'conf', 'php', 'log', 'csv', 'sql', 'sh', 'py', 'java', 'c',
            'cpp', 'h', 'go', 'rs', 'ts', 'vue', 'gitignore', 'env', 'lock', 'toml', 'tsx', 'jsx'];
        if (!in_array($ext, $textExts, true)) {
            // 非文本非图片：小文件尝试按文本读
            if ($size > 1024 * 1024) {
                return ['code' => 0, 'message' => '', 'data' => ['name' => basename($abs), 'type' => 'binary', 'size' => $size]];
            }
            $mime = (string) (mime_content_type($abs) ?: '');
            if (!str_starts_with($mime, 'text/') && !in_array($ext, ['sh', 'py', 'conf'], true)) {
                return ['code' => 0, 'message' => '', 'data' => ['name' => basename($abs), 'type' => 'binary', 'size' => $size]];
            }
        }

        if ($size > 2 * 1024 * 1024) {
            return ['code' => 1, 'message' => '文件超过 2MB，暂不支持预览'];
        }

        $content = (string) file_get_contents($abs);
        if ($ext === 'md' || $ext === 'markdown') {
            try {
                $html = \Candy\Extend\Doc\Markdown\Trait\Output::parse($content, true);
                $type = 'markdown';
            } catch (\Throwable) {
                $html = '';
                $type = 'text';
            }

            return [
                'code' => 0,
                'message' => '',
                'data' => [
                    'name' => basename($abs),
                    'type' => $type,
                    'html' => $html,
                    'content' => $content,
                ],
            ];
        }

        return [
            'code' => 0,
            'message' => '',
            'data' => [
                'name' => basename($abs),
                'type' => 'text',
                'content' => $content,
            ],
        ];
    }

    /**
     * @param string $destDir
     * @param string $from
     * @param string $mode copy|cut
     * @return array<string, mixed>
     */
    public static function paste(string $destDir, string $from, string $mode): array
    {
        try {
            $ops = self::ops();
            $from = $ops->normalizeRelativePath($from);
            if ($from === '') {
                return ['code' => 1, 'message' => '粘贴失败'];
            }
            $destDir = $ops->normalizeRelativePath($destDir);
            $mode = strtolower(trim($mode));
            $newPath = match ($mode) {
                'cut' => $ops->moveEntryLocal($from, $destDir),
                'copy' => $ops->copyEntryLocal($from, $destDir),
                default => throw new \RuntimeException('粘贴失败'),
            };

            return [
                'code' => 0,
                'message' => '粘贴成功',
                'data' => ['path' => $newPath],
            ];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    public static function collectPaths(array $input): array
    {
        $paths = $input['paths'] ?? [];
        if (is_string($paths) && $paths !== '') {
            return [$paths];
        }
        if (!is_array($paths)) {
            return [];
        }

        $list = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                $list[] = $path;
            }
        }

        return $list;
    }

    /**
     * 列举当前目录一层
     *
     * @param string $path
     * @return array<int, array<string, mixed>>
     */
    private static function listDirectory(string $path): array
    {
        $ops = self::ops();
        $dirReal = $ops->resolveDirectory($path);
        $items = [];
        foreach (Dir::listChildren($dirReal) as $meta) {
            $name = (string) ($meta['filename'] ?? '');
            if ($name === '' || $name === 'safefile') {
                continue;
            }
            $relative = $path === '' ? $name : $path . '/' . $name;
            $isDir = !empty($meta['isDir']);
            $items[] = self::formatEntry([
                'name' => $name,
                'path' => $relative,
                'is_dir' => $isDir ? 1 : 0,
                'ext' => (string) ($meta['ext'] ?? ''),
                'size' => (int) ($meta['size'] ?? 0),
                'mtime' => (int) ($meta['mtime'] ?? 0),
                'url' => $isDir ? '' : self::publicUrl($relative),
                'perm_owner' => Dir::permOwner($meta),
            ]);
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param string $type
     * @param string $keyword
     * @return array<int, array<string, mixed>>
     */
    private static function filterEntries(array $entries, string $type, string $keyword): array
    {
        $allowedExt = self::TYPE_EXT_MAP[$type] ?? null;
        $keyword = mb_strtolower($keyword);

        return array_values(array_filter($entries, static function (array $row) use ($allowedExt, $keyword): bool {
            if (!empty($row['is_dir'])) {
                return $keyword === '' || str_contains(mb_strtolower((string) $row['name']), $keyword);
            }
            if ($allowedExt !== null && !in_array((string) ($row['ext'] ?? ''), $allowedExt, true)) {
                return false;
            }
            if ($keyword !== '' && !str_contains(mb_strtolower((string) $row['name']), $keyword)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * 按博客模板规则生成静态资源引用
     *
     * img/ 下资源使用 blog('img_base')，其余使用 blog('static_base')
     * Markdown / Front Matter 使用以 /Doc/Blog/Static/ 开头的站点绝对路径
     *
     * @param string $relativePath Static 根下相对路径
     * @return array<string, string>
     */
    public static function buildTemplateRefs(string $relativePath): array
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $path = self::publicUrl($relativePath);

        if (str_starts_with($relativePath, 'img/')) {
            $suffix = substr($relativePath, 4);
            $base = 'img_base';
        } else {
            $suffix = $relativePath;
            $base = 'static_base';
        }

        $escapedSuffix = str_replace("'", "\\'", $suffix);
        $php = "blog('{$base}') . '{$escapedSuffix}'";

        return [
            'base' => $base,
            'suffix' => $suffix,
            'path' => $path,
            'php' => $php,
            'echo' => "<?= blog_esc({$php}) ?>",
            'markdown' => $path,
            'markdown_image' => $path !== '' ? '![](' . $path . ')' : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatEntry(array $row): array
    {
        $mtime = (int) ($row['mtime'] ?? 0);
        $isDir = !empty($row['is_dir']);
        $relativePath = (string) ($row['path'] ?? '');
        $entry = [
            'name' => (string) ($row['name'] ?? ''),
            'path' => $relativePath,
            'is_dir' => (int) $isDir,
            'ext' => (string) ($row['ext'] ?? ''),
            'size' => (int) ($row['size'] ?? 0),
            'size_text' => $isDir ? '-' : sizeCount((int) ($row['size'] ?? 0)),
            'mtime' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '-',
            'url' => (string) ($row['url'] ?? ''),
            'perm_owner' => (string) ($row['perm_owner'] ?? '-'),
            'storage' => 'local',
        ];

        if (!$isDir && $relativePath !== '') {
            $entry['refs'] = self::buildTemplateRefs($relativePath);
        }

        return $entry;
    }
}
