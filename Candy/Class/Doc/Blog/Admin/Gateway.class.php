<?php
/***
 * 博客管理后台入口
 *
 * 业务说明：/Blog/{content_dir}/admin 路由分发、登录与页面渲染
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 19:05:39 $
 *
 * @package Candy\Extend\Doc\Blog\Admin
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog\Admin;

use Candy\Core\Respond;
use Candy\Core\Trait\App\AppEnvironment;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Gateway
{
    /** @var array<string, mixed> */
    private static array $conf = [];

    private static string $baseUrl = '';

    /**
     * 博客管理后台入口
     *
     * @return void
     */
    public static function start(): void
    {
        debug(0);

        $route = self::parseRoute();
        if ($route === null) {
            respond('File Not Found.', 404);
        }

        if (!\Candy\Extend\Doc\Blog\Config::isInstalled()) {
            showExceptionMsg('halt', '博客未初始化，请访问 ?init=mdblog');
        }

        self::$conf = \Candy\Extend\Doc\Blog\Config::load();
        if (($route['content_dir'] ?? '') !== (string) (self::$conf['content_dir'] ?? '')) {
            respond('File Not Found.', 404);
        }

        self::$baseUrl = \Candy\Extend\Doc\Blog\Config::adminBaseUrl(self::$conf);

        $action = (string) ($route['action'] ?? '');
        $isJson = str_ends_with($action, '.json') || I('server.HTTP_X_REQUESTED_WITH', '', '') === 'XMLHttpRequest';
        $action = preg_replace('/\.json$/', '', $action) ?: 'index';

        [$module, $method] = array_pad(explode('/', $action, 2), 2, 'index');
        $publicModules = ['login', 'captcha', 'code'];

        if (!in_array($module, $publicModules, true) && !Auth::check()) {
            if ($isJson || (self::requestServer()['REQUEST_METHOD'] ?? '') === 'POST') {
                self::json(['code' => 401, 'message' => '请先登录']);
            }
            header('Location: ' . self::url('login'));
            stop();
        }

        // CSRF 校验：已登录的对受保护模块的 POST 写请求须携带合法令牌，
        // 防止跨站表单提交（SameSite=Lax 不拦截顶级 POST 导航）。
        // 令牌经 X-CSRF-Token 头传输（兼容 form-urlencoded 与 JSON body），
        // 兜底读取 POST 字段 csrf_token。
        $httpMethod = strtoupper((string) (self::requestServer()['REQUEST_METHOD'] ?? 'GET'));
        if (
            !in_array($module, $publicModules, true)
            && $httpMethod === 'POST'
        ) {
            $token = (string) I('server.HTTP_X_CSRF_TOKEN', '', '');
            if ($token === '') {
                $post = self::requestPost();
                $token = (string) ($post['csrf_token'] ?? '');
            }
            if (!\Candy\Core\Security::verifyCsrfToken($token)) {
                self::json(['code' => 401, 'message' => '请求校验失败，请刷新页面后重试']);
            }
        }

        $result = match ($module) {
            '', 'index' => self::handleDash($method, $isJson),
            'dash' => self::handleDash($method, $isJson),
            'login' => self::handleLogin($method, $isJson),
            'captcha', 'code' => self::captcha(),
            'logout' => self::logout(),
            'editor' => self::handleEditor($method, $isJson),
            'file' => self::handleFile($method, $isJson),
            'config' => self::handleConfig($method, $isJson),
            'comment' => self::handleComment($method, $isJson),
            default => null,
        };

        if ($result === null) {
            respond('File Not Found.', 404);
        }

        if (is_string($result)) {
            Respond::create($result, 'html', 200)->send();
        }

        stop();
    }

    /** @return array<string,mixed> 当前请求 SERVER 数据 */
    private static function requestServer(): array
    {
        return I('server.', [], '');
    }

    /** @return array<string,mixed> 当前请求 GET 数据 */
    private static function requestGet(): array
    {
        return I('get.', [], '');
    }

    /** @return array<string,mixed> 当前请求 POST 数据 */
    private static function requestPost(): array
    {
        return I('post.', [], '');
    }

    /** @return string 当前请求原始正文 */
    private static function requestRaw(): string
    {
        return (string)I('raw');
    }

    /**
     * @return array{content_dir:string,action:string}|null
     */
    private static function parseRoute(): ?array
    {
        $uri = urldecode(explode('?', (string)(self::requestServer()['REQUEST_URI'] ?? ''), 2)[0]);
        if (preg_match('#^/blog/([a-z0-9]{8})/admin(?:/(.*))?$#i', $uri, $m)) {
            return [
                'content_dir' => strtolower($m[1]),
                'action' => trim((string) ($m[2] ?? ''), '/'),
            ];
        }

        if (\Candy\Extend\Doc\Blog\Config::isStandaloneHost() && preg_match('#^/([a-z0-9]{8})/admin(?:/(.*))?$#i', $uri, $m)) {
            return [
                'content_dir' => strtolower($m[1]),
                'action' => trim((string) ($m[2] ?? ''), '/'),
            ];
        }

        return null;
    }

    /**
     * @param string $path
     * @return string
     */
    public static function url(string $path = ''): string
    {
        $path = trim($path, '/');

        return $path === '' ? self::$baseUrl . '/' : self::$baseUrl . '/' . $path;
    }

    /**
     * @param array<string, mixed> $data
     * @return never
     */
    private static function json(array $data): void
    {
        debug(0);
        Respond::create(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json', 200)->send();
        stop();
    }

    /**
     * @param string $method
     * @param bool $isJson
     * @return string|null
     */
    private static function handleLogin(string $method, bool $isJson): ?string
    {
        if ($method === 'do' || (($method === 'index' || $method === '') && (self::requestServer()['REQUEST_METHOD'] ?? '') === 'POST')) {
            $post = self::requestPost();
            if ($isJson && empty($post)) {
                $raw = self::requestRaw();
                $decoded = json_decode($raw ?: '', true);
                if (is_array($decoded)) {
                    $post = $decoded;
                }
            }
            $remember = !empty($post['remember']) || !empty($post['keep']);
            $res = Auth::login(
                trim((string) ($post['username'] ?? '')),
                (string) ($post['password'] ?? ''),
                trim((string) ($post['captcha'] ?? '')),
                $remember
            );
            if ($res['code'] === 0) {
                $res['url'] = self::url('');
            }
            self::json($res);
        }

        if (Auth::check()) {
            header('Location: ' . self::url(''));
            stop();
        }

        return self::render('login', [
            'title' => '博客管理登录',
            'baseUrl' => self::$baseUrl,
            'captchaEnabled' => Auth::captchaEnabled(),
            'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
        ]);
    }

    /**
     * @return never
     */
    private static function captcha(): void
    {
        debug(0);
        verificationCode('', '', 38, 100);
        stop();
    }

    /**
     * @return never
     */
    private static function logout(): void
    {
        Auth::logout();
        header('Location: ' . self::url('login'));
        stop();
    }

    /**
     * @param string $method
     * @param bool $isJson
     * @return string|null
     */
    private static function handleDash(string $method, bool $isJson): ?string
    {
        if ($isJson && $method === 'overview') {
            self::json([
                'code' => 0,
                'message' => '',
                'data' => Stats::overview(self::$conf),
            ]);
            return null;
        }

        return self::render('dash', [
            'title' => '管理概览',
            'baseUrl' => self::$baseUrl,
            'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
            'username' => Auth::username(),
            'needDashboard' => true,
        ]);
    }

    private static function handleEditor(string $method, bool $isJson): ?string
    {
        try {
            $editor = new Editor();
        } catch (\Throwable $e) {
            if ($isJson || $method !== 'index') {
                self::json(['code' => 1, 'message' => $e->getMessage(), 'count' => 0, 'data' => []]);
            }

            return self::render('editor', [
                'title' => '文章编辑',
                'baseUrl' => self::$baseUrl,
                'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
                'username' => Auth::username(),
                'writable' => false,
                'needCodeEditor' => true,
                'editorError' => $e->getMessage(),
            ]);
        }

        if ($method === 'index' || ($method === '' && !$isJson)) {
            return self::render('editor', [
                'title' => '文章编辑',
                'baseUrl' => self::$baseUrl,
                'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
                'username' => Auth::username(),
                'writable' => $editor->isWritable(),
                'needCodeEditor' => true,
            ]);
        }

        $input = array_merge(self::requestGet(), self::requestPost());
        if (empty($input) && $isJson) {
            $raw = self::requestRaw();
            $decoded = json_decode($raw ?: '', true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        $result = match ($method) {
            'list' => $editor->getList($input),
            'read' => $editor->readFile((string) ($input['path'] ?? '')),
            'save' => $editor->saveFile(
                (string) ($input['path'] ?? ''),
                (string) ($input['content'] ?? ''),
                (string) ($input['content_encoding'] ?? '')
            ),
            'mkdir' => $editor->mkdir((string) ($input['path'] ?? ''), (string) ($input['name'] ?? '')),
            'create' => $editor->createFile((string) ($input['path'] ?? ''), (string) ($input['name'] ?? '')),
            'rename' => $editor->rename((string) ($input['path'] ?? ''), (string) ($input['name'] ?? '')),
            'preview' => self::previewMarkdown((string) ($input['content'] ?? '')),
            'remove' => $editor->remove((string) ($input['path'] ?? '')),
            default => ['code' => 1, 'message' => '未知操作'],
        };

        self::json($result);
    }

    /**
     * @param string $method
     * @param bool $isJson
     * @return string|null
     */
    private static function handleConfig(string $method, bool $isJson): ?string
    {
        if ($method === 'save') {
            $post = self::requestPost();
            $get = self::requestGet();
            $tab = trim((string) ($post['tab'] ?? $get['tab'] ?? ''));
            $input = $post;
            unset($input['tab']);
            self::json(ConfigSaver::saveTab($tab, $input));
        }

        if ($method === 'backup') {
            self::json(ConfigSaver::backup());
        }
        if ($method === 'snapshots') {
            self::json(['code' => 0, 'message' => '', 'data' => ConfigSaver::snapshots()]);
        }
        if ($method === 'restoreSnapshot') {
            $post = self::requestPost();
            self::json(ConfigSaver::restoreSnapshot((string) ($post['name'] ?? '')));
        }
        if ($method === 'restore') {
            $post = self::requestPost();
            self::json(ConfigSaver::restore((string) ($post['content'] ?? '')));
        }

        if ($method === 'generateRss' || $method === 'generate_rss') {
            self::json(\Candy\Extend\Doc\Blog\Feed::writeStaticRss(self::$conf));
        }
        if ($method === 'generateSitemap' || $method === 'generate_sitemap') {
            self::json(\Candy\Extend\Doc\Blog\Feed::writeStaticSitemap(self::$conf));
        }
        if ($method === 'generateFeed' || $method === 'generate_feed') {
            self::json(\Candy\Extend\Doc\Blog\Feed::regenerateStatic(self::$conf));
        }

        if ($method === 'index' || $method === '') {
            $rssFile = \Candy\Extend\Doc\Blog\Feed::staticFile('rss');
            $mapFile = \Candy\Extend\Doc\Blog\Feed::staticFile('sitemap');

            return self::render('config', [
                'title' => '博客配置',
                'baseUrl' => self::$baseUrl,
                'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
                'username' => Auth::username(),
                'config' => self::$conf,
                'tabs' => ConfigSaver::tabs(),
                'snapshots' => ConfigSaver::snapshots(),
                'spamFilter' => \Candy\Extend\Doc\Blog\Comment::loadSpamFilter(\Candy\Extend\Doc\Blog\Config::contentRoot(self::$conf)),
                'feedStatic' => [
                    'rss_exists' => is_file($rssFile),
                    'rss_mtime' => is_file($rssFile) ? (int) filemtime($rssFile) : 0,
                    'rss_url' => \Candy\Extend\Doc\Blog\Feed::staticPublicUrl('rss'),
                    'rss_live' => \Candy\Extend\Doc\Blog\Feed::feedPath(
                        \Candy\Extend\Doc\Blog\Config::publicBasePath(self::$conf)
                    ),
                    'sitemap_exists' => is_file($mapFile),
                    'sitemap_mtime' => is_file($mapFile) ? (int) filemtime($mapFile) : 0,
                    'sitemap_url' => \Candy\Extend\Doc\Blog\Feed::staticPublicUrl('sitemap'),
                    'sitemap_live' => \Candy\Extend\Doc\Blog\Feed::sitemapPath(
                        \Candy\Extend\Doc\Blog\Config::publicBasePath(self::$conf)
                    ),
                ],
            ]);
        }

        return null;
    }

    /**
     * 附件文件管理（复用 Admin File 服务）
     *
     * @param string $method
     * @param bool $isJson
     * @return string|null
     */
    private static function handleFile(string $method, bool $isJson): ?string
    {
        if ($method === 'download') {
            $get = self::requestGet();
            $paths = File::collectPaths(array_merge($get, self::requestPost()));
            $single = trim((string) ($get['path'] ?? ''));
            if ($single !== '' && $paths === []) {
                $paths = [$single];
            }
            File::download($paths);
        }

        if ($method === 'index' || ($method === '' && !$isJson)) {
            $path = '';
            try {
                $path = File::normalizePath((string) (self::requestGet()['path'] ?? ''));
            } catch (\Throwable) {
                $path = '';
            }

            return self::render('file', [
                'title' => '文件管理',
                'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
                'username' => Auth::username(),
                'currentPath' => $path,
                'storageType' => File::storageType(),
                'storageLabel' => File::storageLabel(),
                'needFileManager' => true,
            ]);
        }

        $input = array_merge(self::requestGet(), self::requestPost());
        if (empty($input) && $isJson) {
            $raw = self::requestRaw();
            $decoded = json_decode($raw ?: '', true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        try {
            $dir = File::normalizePath((string) ($input['path'] ?? ''));
        } catch (\Throwable $e) {
            self::json(['code' => 1, 'message' => $e->getMessage()]);

            return null;
        }

        $result = match ($method) {
            'list' => File::list($input),
            'view' => File::view(trim((string) ($input['path'] ?? ''))),
            'upload' => File::upload($dir),
            'mkdir' => self::filePostOrError(fn () => File::mkdir($dir, trim((string) ($input['name'] ?? '')))),
            'remove' => self::filePostOrError(fn () => File::remove(File::collectPaths($input))),
            'compress' => self::filePostOrError(fn () => File::compress(
                $dir,
                File::collectPaths($input),
                trim((string) ($input['name'] ?? ''))
            )),
            'extract' => self::filePostOrError(fn () => File::extract($dir, File::collectPaths($input))),
            'paste' => self::filePostOrError(fn () => File::paste(
                $dir,
                trim((string) ($input['from'] ?? '')),
                trim((string) ($input['mode'] ?? ''))
            )),
            default => ['code' => 1, 'message' => '未知操作'],
        };

        self::json($result);
    }

    /**
     * 评论审核
     *
     * @param string $method
     * @param bool $isJson
     * @return string|null
     */
    /**
     * Markdown 实时预览渲染
     *
     * @param string $content Markdown 原文
     * @return array<string, mixed>
     */
    private static function previewMarkdown(string $content): array
    {
        try {
            $html = \Candy\Extend\Doc\Markdown\Trait\Output::parse($content, true);
            return ['code' => 0, 'message' => '', 'data' => ['html' => $html]];
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }
    }

    private static function filterCommentRows(array $rows, string $keyword): array
    {
        if ($keyword === '') {
            return $rows;
        }
        $kwLower = mb_strtolower($keyword);
        return array_values(array_filter($rows, static function (array $row) use ($kwLower): bool {
            foreach (['name', 'email', 'content', 'post'] as $field) {
                if (mb_strpos(mb_strtolower((string) ($row[$field] ?? '')), $kwLower) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * 评论导出下载（CSV / JSON）
     *
     * @param array<int, array<string, mixed>> $rows 评论列表
     * @param string $format csv|json
     * @param string $keyword 搜索关键词
     * @param string $status 状态筛选
     * @return never
     */
    private static function exportComments(array $rows, string $format, string $keyword, string $status): void
    {
        debug(0);
        $statusMap = ['all' => '全部', 'pending' => '待审', 'approved' => '已通过', 'spam' => '垃圾'];
        $suffix = $status !== 'all' ? '-' . $statusMap[$status] : '';
        $kwPart = $keyword !== '' ? '-' . $keyword : '';
        $filename = 'blog-comments' . $suffix . $kwPart . '-' . date('Ymd-His') . '.' . $format;

        if ($format === 'json') {
            $payload = [
                'version' => 1,
                'exported_at' => date('Y-m-d H:i:s'),
                'filter' => ['status' => $status, 'keyword' => $keyword],
                'count' => count($rows),
                'comments' => $rows,
            ];
            $body = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $type = 'application/json';
        } else {
            $lines = [];
            $lines[] = ['ID', '状态', '昵称', '内容', '文章', '回复目标', '时间', 'IP', '标签'];
            foreach ($rows as $row) {
                $created = (int) ($row['created_at'] ?? 0);
                $lines[] = [
                    (string) ($row['id'] ?? ''),
                    (string) ($statusMap[$row['status'] ?? ''] ?? (string) ($row['status'] ?? '')),
                    (string) ($row['name'] ?? ''),
                    (string) ($row['content'] ?? ''),
                    (string) ($row['post'] ?? ''),
                    (string) ($row['parent'] ?? ''),
                    $created > 0 ? date('Y-m-d H:i:s', $created) : '',
                    (string) ($row['ip'] ?? ''),
                    implode(' ', (array) ($row['tags'] ?? [])),
                ];
            }
            $body = self::toCsv($lines);
            $type = 'text/csv; charset=utf-8';
        }

        if (headers_sent()) {
            showExceptionMsg('halt', '导出失败：响应已开始');
        }
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        stop();
    }

    /**
     * 数组转 CSV（UTF-8 带 BOM，Excel 兼容）
     *
     * @param array<int, array<int, string>> $lines
     * @return string
     */
    private static function toCsv(array $lines): string
    {
        $out = "ï»¿";
        foreach ($lines as $line) {
            $cells = [];
            foreach ($line as $cell) {
                $cell = str_replace(["", "
"], ' ', (string) $cell);
                if (preg_match('/[",\n\r]/', $cell) || str_starts_with($cell, '=') || str_starts_with($cell, '+') || str_starts_with($cell, '-') || str_starts_with($cell, '@')) {
                    $cell = '"' . str_replace('"', '""', $cell) . '"';
                }
                $cells[] = $cell;
            }
            $out .= implode(',', $cells) . "\r\n";
        }
        return $out;
    }

    private static function handleComment(string $method, bool $isJson): ?string
    {
        $contentRoot = \Candy\Extend\Doc\Blog\Config::contentRoot(self::$conf);

        // 评论导出（CSV / JSON）
        if ($method === 'export') {
            $get = self::requestGet();
            $status = trim((string) ($get['status'] ?? 'all'));
            if (!in_array($status, ['all', 'pending', 'approved', 'spam'], true)) {
                $status = 'all';
            }
            $format = strtolower(trim((string) ($get['format'] ?? 'csv')));
            if (!in_array($format, ['csv', 'json'], true)) {
                $format = 'csv';
            }
            $keyword = trim((string) ($get['keyword'] ?? ''));
            $rows = \Candy\Extend\Doc\Blog\Comment::listAll($contentRoot, $status);
            if ($keyword !== '') {
                $kwLower = mb_strtolower($keyword);
                $rows = array_values(array_filter($rows, static function (array $row) use ($kwLower): bool {
                    foreach (['name', 'email', 'content', 'post'] as $field) {
                        if (mb_strpos(mb_strtolower((string) ($row[$field] ?? '')), $kwLower) !== false) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
            self::exportComments($rows, $format, $keyword, $status);
        }

        // 垃圾拦截配置读写（JSON 文件）
        if ($method === 'spamFilter' || $method === 'spam_filter') {
            $input = array_merge(self::requestGet(), self::requestPost());
            if (!empty($input) && isset($input['save'])) {
                self::json([
                    'code' => \Candy\Extend\Doc\Blog\Comment::saveSpamFilter(
                        $contentRoot,
                        !empty($input['enabled']),
                        (int) ($input['max_links'] ?? 3),
                        (array) ($input['keywords'] ?? [])
                    ) ? 0 : 1,
                    'message' => !empty($input['enabled']) ? '拦截规则已保存' : '已保存',
                ]);
            } else {
                self::json(['code' => 0, 'message' => '', 'data' => \Candy\Extend\Doc\Blog\Comment::loadSpamFilter($contentRoot)]);
            }
        }
        if ($contentRoot === '' || !is_dir($contentRoot)) {
            if ($isJson) {
                self::json(['code' => 1, 'message' => '正文目录不存在', 'count' => 0, 'data' => []]);
            }

            return self::render('comments', [
                'title' => '评论管理',
                'baseUrl' => self::$baseUrl,
                'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
                'username' => Auth::username(),
                'pendingCount' => 0,
                'statusFilter' => 'pending',
                'rows' => [],
                'portalUrl' => \Candy\Extend\Doc\Blog\Config::publicBasePath(self::$conf),
            ]);
        }

        if ($method === 'index' || ($method === '' && !$isJson)) {
            $status = trim((string) (self::requestGet()['status'] ?? 'pending'));
            if (!in_array($status, ['all', 'pending', 'approved', 'spam'], true)) {
                $status = 'pending';
            }
            $keyword = trim((string) (self::requestGet()['keyword'] ?? ''));
            $rows = \Candy\Extend\Doc\Blog\Comment::listAll($contentRoot, $status);
            if ($keyword !== '') {
                $kwLower = mb_strtolower($keyword);
                $rows = array_values(array_filter($rows, static function (array $row) use ($kwLower): bool {
                    foreach (['name', 'email', 'content', 'post'] as $field) {
                        if (mb_strpos(mb_strtolower((string) ($row[$field] ?? '')), $kwLower) !== false) {
                            return true;
                        }
                    }
                    return false;
                }));
            }

            return self::render('comments', [
                'title' => '评论管理',
                'baseUrl' => self::$baseUrl,
                'siteName' => (string) (self::$conf['site_name'] ?? '博客'),
                'username' => Auth::username(),
                'pendingCount' => \Candy\Extend\Doc\Blog\Comment::pendingCount($contentRoot),
                'statusFilter' => $status,
                'keyword' => $keyword,
                'rows' => $rows,
                'needCommentsAdmin' => true,
                'portalUrl' => \Candy\Extend\Doc\Blog\Config::publicBasePath(self::$conf),
            ]);
        }

        if ((self::requestServer()['REQUEST_METHOD'] ?? '') !== 'POST' && $isJson) {
            // list.json 允许 GET
            if ($method !== 'list') {
                self::json(['code' => 1, 'message' => '请使用 POST']);
            }
        }

        $input = array_merge(self::requestGet(), self::requestPost());
        if (empty($input) && $isJson) {
            $raw = self::requestRaw();
            $decoded = json_decode($raw ?: '', true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        $result = match ($method) {
            'list' => [
                'code' => 0,
                'message' => '',
                'count' => 0,
                'data' => self::filterCommentRows(
                    \Candy\Extend\Doc\Blog\Comment::listAll(
                        $contentRoot,
                        (string) ($input['status'] ?? 'all')
                    ),
                    trim((string) ($input['keyword'] ?? ''))
                ),
            ],
            'approve' => \Candy\Extend\Doc\Blog\Comment::setStatus(
                $contentRoot,
                (string) ($input['post'] ?? ''),
                (string) ($input['id'] ?? ''),
                'approved'
            ),
            'pending' => \Candy\Extend\Doc\Blog\Comment::setStatus(
                $contentRoot,
                (string) ($input['post'] ?? ''),
                (string) ($input['id'] ?? ''),
                'pending'
            ),
            'spam' => \Candy\Extend\Doc\Blog\Comment::setStatus(
                $contentRoot,
                (string) ($input['post'] ?? ''),
                (string) ($input['id'] ?? ''),
                'spam'
            ),
            'remove' => \Candy\Extend\Doc\Blog\Comment::delete(
                $contentRoot,
                (string) ($input['post'] ?? ''),
                (string) ($input['id'] ?? '')
            ),
            'reply' => \Candy\Extend\Doc\Blog\Comment::adminReply(
                $contentRoot,
                $input,
                Auth::username() !== '' ? Auth::username() : '博主',
                self::$conf
            ),
            default => ['code' => 1, 'message' => '未知操作'],
        };

        if ($method === 'list' && is_array($result['data'] ?? null)) {
            $result['count'] = count($result['data']);
        }

        self::json($result);
    }

    /**
     * 附件写操作须为 POST
     *
     * @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private static function filePostOrError(callable $callback): array
    {
        if ((self::requestServer()['REQUEST_METHOD'] ?? '') !== 'POST') {
            return ['code' => 1, 'message' => '请使用 POST 请求'];
        }

        return $callback();
    }

    /**
     * @param string $view
     * @param array<string, mixed> $vars
     * @return string
     */
    private static function render(string $view, array $vars): string
    {
        $viewFile = C('CANDYROOT') . 'Doc/Blog/admin/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            return '视图不存在: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8');
        }

        $vars = self::mergeViewVars($vars);
        $vars['contentView'] = $viewFile;
        extract($vars, EXTR_OVERWRITE);
        ob_start();
        include C('CANDYROOT') . 'Doc/Blog/admin/views/layout.php';

        return (string) ob_get_clean();
    }

    /**
     * 合并布局公共变量
     *
     * 博客后台在 UrlParser 劫持阶段执行，早于 App::loading()，须自行初始化公共资源路径
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private static function mergeViewVars(array $vars): array
    {
        if (is_null(G('C_PUBLIC'))) {
            AppEnvironment::loading();
        }

        return array_merge([
            'baseUrl' => self::$baseUrl,
            'publicBase' => rtrim((string) G('C_PUBLIC'), '/'),
            'portalUrl' => \Candy\Extend\Doc\Blog\Config::publicBasePath(self::$conf),
            'csrfToken' => \Candy\Core\Security::generateCsrfToken(),
        ], $vars);
    }
}
