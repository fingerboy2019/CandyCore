<?php
/***
 * 博客正文目录文件编辑
 *
 * 业务说明：仅在 BlogConfig 正文目录内浏览与编辑 Markdown/文本
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 19:05:39 $
 *
 * @package Candy\Extend\Doc\Blog\Admin
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog\Admin;

use Candy\Extend\Dir;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Editor
{
    private const EDITABLE = ['md', 'txt', 'json', 'html', 'htm', 'css', 'js', 'xml', 'yml', 'yaml'];

    /** 分类目录仅允许创建在正文根下（一级），禁止嵌套子目录 */
    private const MAX_CATEGORY_DEPTH = 1;

    private const MAX_BYTES = 2097152;

    private string $root = '';

    public function __construct()
    {
        $root = \Candy\Extend\Doc\Blog\Config::contentRoot();
        if ($root === '' || !preg_match('#[/\\\\][a-z0-9]{8}[/\\\\]$#', $root)) {
            throw new \RuntimeException('博客正文目录未配置或 content_dir 无效');
        }
        $real = realpath(rtrim($root, '/\\'));
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException('博客正文目录不存在，请检查配置与磁盘目录是否一致');
        }
        $this->root = $real . '/';
    }

    /**
     * 博客后台已登录即可写（不依赖 DEBUG 开关）
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        return Auth::check();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getList(array $params): array
    {
        try {
            $path = $this->normalize((string) ($params['path'] ?? ''));
            $keyword = trim((string) ($params['keyword'] ?? ''));
            $page = max(1, (int) ($params['page'] ?? 1));
            $limit = max(1, min(200, (int) ($params['limit'] ?? 20)));

            $dirReal = $path === '' ? $this->rootReal() : $this->resolveDir($path);
            if (!is_dir($dirReal)) {
                throw new \RuntimeException('目录不存在');
            }

            $entries = scandir($dirReal);
            if ($entries === false) {
                throw new \RuntimeException('无法读取目录内容');
            }

            $rows = [];
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if ($keyword !== '' && stripos($name, $keyword) === false) {
                    continue;
                }
                $full = $dirReal . '/' . $name;
                $rel = $path === '' ? $name : $path . '/' . $name;
                $isDir = is_dir($full);
                $rows[] = [
                    'name' => $name,
                    'path' => $rel,
                    'is_dir' => $isDir ? 1 : 0,
                    'editable' => !$isDir && $this->isEditable($name) ? 1 : 0,
                    'size_text' => $isDir ? '-' : sizeCount((int) filesize($full)),
                    'mtime' => date('Y-m-d H:i:s', (int) filemtime($full)),
                ];
            }

            usort($rows, static function (array $a, array $b): int {
                if (($a['is_dir'] ?? 0) !== ($b['is_dir'] ?? 0)) {
                    return ($b['is_dir'] ?? 0) <=> ($a['is_dir'] ?? 0);
                }

                return strcmp((string) $a['name'], (string) $b['name']);
            });

            $total = count($rows);
            $data = array_slice($rows, ($page - 1) * $limit, $limit);

            return ['code' => 0, 'message' => '', 'count' => $total, 'data' => $data];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage(), 'count' => 0, 'data' => []];
        }
    }

    /**
     * @param string $path
     * @return array<string, mixed>
     */
    public function readFile(string $path): array
    {
        try {
            $path = $this->normalize($path);
            $fileReal = $this->resolveFile($path);
            if (!is_file($fileReal)) {
                throw new \RuntimeException('文件不存在');
            }
            if (!$this->isEditable(basename($fileReal))) {
                throw new \RuntimeException('该文件类型不可编辑');
            }
            $size = (int) filesize($fileReal);
            if ($size > self::MAX_BYTES) {
                throw new \RuntimeException('文件过大');
            }
            $content = file_get_contents($fileReal);
            if ($content === false || !mb_check_encoding($content, 'UTF-8')) {
                throw new \RuntimeException('无法读取文件');
            }

            return [
                'code' => 0,
                'message' => '',
                'data' => [
                    'path' => $path,
                    'name' => basename($fileReal),
                    'content' => $content,
                    'writable' => $this->isWritable() && is_writable($fileReal),
                ],
            ];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $encoding
     * @return array<string, mixed>
     */
    public function saveFile(string $path, string $content, string $encoding = ''): array
    {
        if (!$this->isWritable()) {
            return ['code' => 1, 'message' => '请先登录管理后台'];
        }
        try {
            if (strtolower(trim($encoding)) === 'base64') {
                $decoded = base64_decode($content, true);
                if ($decoded === false) {
                    throw new \RuntimeException('内容解码失败');
                }
                $content = $decoded;
            }
            $path = $this->normalize($path);
            $fileReal = $this->resolveFile($path);
            if (!is_file($fileReal) || !is_writable($fileReal)) {
                throw new \RuntimeException('文件不可写');
            }
            if (strlen($content) > self::MAX_BYTES) {
                throw new \RuntimeException('内容过大');
            }
            if (file_put_contents($fileReal, $content, LOCK_EX) === false) {
                throw new \RuntimeException('保存失败');
            }
            // Markdown 变更后重建静态 RSS / Sitemap
            \Candy\Extend\Doc\Blog\Feed::afterMarkdownChanged($path);

            return ['code' => 0, 'message' => '保存成功'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param string $parent
     * @param string $name
     * @return array<string, mixed>
     */
    public function mkdir(string $parent, string $name): array
    {
        if (!$this->isWritable()) {
            return ['code' => 1, 'message' => '请先登录管理后台'];
        }
        try {
            $parent = $this->normalize($parent);
            if ($this->pathDepth($parent) >= self::MAX_CATEGORY_DEPTH) {
                throw new \RuntimeException('仅允许在根目录下创建分类目录，不支持嵌套子目录');
            }
            $name = $this->sanitizeName($name);
            $parentReal = $this->rootReal();
            $dirReal = $parentReal . '/' . $name;
            $this->assertWithinRoot($dirReal);
            if (is_dir($dirReal)) {
                throw new \RuntimeException('目录已存在');
            }
            if (!mkdir($dirReal, 0755, true) && !is_dir($dirReal)) {
                throw new \RuntimeException('创建目录失败');
            }

            return ['code' => 0, 'message' => '创建成功'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param string $parent
     * @param string $name
     * @return array<string, mixed>
     */
    public function createFile(string $parent, string $name): array
    {
        if (!$this->isWritable()) {
            return ['code' => 1, 'message' => '请先登录管理后台'];
        }
        try {
            $parent = $this->normalize($parent);
            $name = $this->sanitizeName($name);
            if (!str_contains($name, '.')) {
                $name .= '.md';
            }
            if (!$this->isEditable($name)) {
                throw new \RuntimeException('不允许创建该类型文件');
            }
            $rel = $parent === '' ? $name : $parent . '/' . $name;
            $fileReal = $this->resolveFile($rel);
            if (is_file($fileReal)) {
                throw new \RuntimeException('文件已存在');
            }
            $dir = dirname($fileReal);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('目录创建失败');
            }
            if (file_put_contents($fileReal, "# {$name}\n\n", LOCK_EX) === false) {
                throw new \RuntimeException('创建文件失败');
            }
            \Candy\Extend\Doc\Blog\Feed::afterMarkdownChanged($rel);

            return ['code' => 0, 'message' => '创建成功', 'data' => ['path' => $rel]];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param string $path
     * @return array<string, mixed>
     */
    public function remove(string $path): array
    {
        if (!$this->isWritable()) {
            return ['code' => 1, 'message' => '请先登录管理后台'];
        }
        try {
            $path = $this->normalize($path);
            if ($path === '' || $path === 'README.md') {
                throw new \RuntimeException('不能删除该路径');
            }
            $realPath = realpath($this->root . $path);
            if ($realPath === false || !$this->pathIsInsideRoot($realPath)) {
                throw new \RuntimeException('非法路径');
            }
            $wasMarkdown = is_dir($realPath) || str_ends_with(strtolower($path), '.md');
            if (is_dir($realPath)) {
                if (!Dir::del($realPath)) {
                    throw new \RuntimeException('删除目录失败');
                }
            } elseif (is_file($realPath)) {
                if (!unlink($realPath)) {
                    throw new \RuntimeException('删除文件失败');
                }
            } else {
                throw new \RuntimeException('路径不存在');
            }
            if ($wasMarkdown) {
                \Candy\Extend\Doc\Blog\Feed::afterMarkdownChanged($path);
            }

            return ['code' => 0, 'message' => '删除成功'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    /**
     * 重命名文件或目录
     *
     * @param string $path 原相对路径
     * @param string $newName 新名称（不含路径）
     * @return array<string, mixed>
     */
    public function rename(string $path, string $newName): array
    {
        if (!$this->isWritable()) {
            return ['code' => 1, 'message' => '请先登录管理后台'];
        }
        try {
            $path = $this->normalize($path);
            if ($path === '' || $path === 'README.md') {
                throw new \RuntimeException('不能重命名该路径');
            }
            $newName = $this->sanitizeName(trim($newName));
            if ($newName === '') {
                throw new \RuntimeException('新名称不能为空');
            }
            $realPath = realpath($this->root . $path);
            if ($realPath === false || !$this->pathIsInsideRoot($realPath)) {
                throw new \RuntimeException('非法路径');
            }

            // 目录重命名
            if (is_dir($realPath)) {
                $parent = dirname($realPath);
                $target = $parent . '/' . $newName;
                if (is_dir($target)) {
                    throw new \RuntimeException('目标目录已存在');
                }
                if (!rename($realPath, $target)) {
                    throw new \RuntimeException('重命名失败');
                }
                $newRel = ($path === '' || $parent === $this->root)
                    ? $newName
                    : substr(str_replace('\\', '/', $target), strlen($this->root));
                \Candy\Extend\Doc\Blog\Feed::afterMarkdownChanged($newRel);
                return ['code' => 0, 'message' => '重命名成功', 'data' => ['path' => ltrim($newRel, '/')]];
            }

            // 文件重命名
            if (is_file($realPath)) {
                $ext = pathinfo($realPath, PATHINFO_EXTENSION);
                if (strtolower($ext) === 'md' && !str_ends_with(strtolower($newName), '.md')) {
                    $newName .= '.md';
                }
                if (!$this->isEditable($newName)) {
                    throw new \RuntimeException('不允许该类型文件');
                }
                $target = dirname($realPath) . '/' . $newName;
                if (is_file($target)) {
                    throw new \RuntimeException('目标文件已存在');
                }
                if (!rename($realPath, $target)) {
                    throw new \RuntimeException('重命名失败');
                }
                $newRel = substr(str_replace('\\', '/', $target), strlen($this->root));
                \Candy\Extend\Doc\Blog\Feed::afterMarkdownChanged($newRel);
                return ['code' => 0, 'message' => '重命名成功', 'data' => ['path' => ltrim($newRel, '/')]];
            }

            throw new \RuntimeException('路径不存在');
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (str_contains($path, "\0")) {
            throw new \RuntimeException('非法路径');
        }
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');
        if ($path === '' || $path === '.') {
            return '';
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('非法路径');
            }
        }

        return $path;
    }

    /**
     * 内容根目录 realpath（缓存）
     *
     * @return string
     */
    private function rootReal(): string
    {
        static $cached = null;
        if ($cached === null) {
            $cached = realpath(rtrim($this->root, '/'));
            if ($cached === false) {
                throw new \RuntimeException('内容目录不可用');
            }
        }

        return $cached;
    }

    /**
     * 校验绝对路径位于内容根目录内（含符号链接解析后的目标）
     *
     * @param string $absolutePath
     * @return void
     */
    private function assertWithinRoot(string $absolutePath): void
    {
        $real = realpath($absolutePath);
        if ($real !== false) {
            if (!$this->pathIsInsideRoot($real)) {
                throw new \RuntimeException('非法路径');
            }

            return;
        }
        $parent = realpath(dirname($absolutePath));
        if ($parent === false || !$this->pathIsInsideRoot($parent)) {
            throw new \RuntimeException('非法路径');
        }
        $base = basename($absolutePath);
        if ($base === '' || $base === '.' || $base === '..') {
            throw new \RuntimeException('非法路径');
        }
    }

    /**
     * @param string $realPath 已解析的绝对路径
     * @return bool
     */
    private function pathIsInsideRoot(string $realPath): bool
    {
        $rootReal = str_replace('\\', '/', $this->rootReal());
        $realPath = str_replace('\\', '/', $realPath);
        if ($realPath === $rootReal) {
            return true;
        }

        return str_starts_with($realPath, $rootReal . '/');
    }

    private function resolveDir(string $rel): string
    {
        $real = realpath($this->root . $rel);
        if ($real === false || !$this->pathIsInsideRoot($real)) {
            throw new \RuntimeException('非法路径');
        }

        return $real;
    }

    private function resolveFile(string $rel): string
    {
        $candidate = $this->root . $rel;
        $real = realpath($candidate);
        if ($real !== false) {
            $this->assertWithinRoot($real);

            return $real;
        }
        $parentRel = dirname($rel);
        if ($parentRel === '.' || $parentRel === '') {
            $parentReal = $this->rootReal();
        } else {
            $parentReal = $this->resolveDir($parentRel);
        }
        $base = basename($candidate);
        if ($base === '' || $base === '.' || $base === '..') {
            throw new \RuntimeException('非法路径');
        }
        $target = $parentReal . '/' . $base;
        $this->assertWithinRoot($target);

        return $target;
    }

    private function isEditable(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, self::EDITABLE, true);
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(str_replace(['\\', '/'], '', $name));
        if ($name === '' || preg_match('/[<>:"|?*]/', $name)) {
            throw new \RuntimeException('名称非法');
        }

        return $name;
    }

    /**
     * 相对路径深度（根目录为 0，一级分类为 1）
     *
     * @param string $rel
     * @return int
     */
    private function pathDepth(string $rel): int
    {
        $rel = trim($rel, '/');
        if ($rel === '') {
            return 0;
        }

        return substr_count($rel, '/') + 1;
    }
}
