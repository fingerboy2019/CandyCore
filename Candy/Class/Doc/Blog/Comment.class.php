<?php
/***
 * 博客评论（JSON 文件存储）
 *
 * 业务说明：存于 {content_dir}/.comments/{crc32(相对路径)}.json；支持嵌套、标签、审核与表情
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 17:40:00 $
 *
 * @package Candy\Extend\Doc\Blog
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Comment
{
    private const MAX_DEPTH = 4;
    private const MAX_CONTENT = 2000;
    private const MAX_NAME = 32;
    private const MAX_TAGS = 5;
    private const MAX_TAG_LEN = 16;
    private const RATE_SECONDS = 45;

    /**
     * 评论配置（BlogConfig.comments）
     *
     * @param array<string, mixed> $conf
     * @return array{enabled:bool,require_approve:bool,max_depth:int,captcha:bool}
     */
    public static function settings(array $conf = []): array
    {
        $conf = $conf !== [] ? $conf : Config::load();
        $c = is_array($conf['comments'] ?? null) ? $conf['comments'] : [];

        return [
            'enabled' => ($c['enabled'] ?? true) !== false,
            'require_approve' => ($c['require_approve'] ?? true) !== false,
            'max_depth' => max(1, min(6, (int) ($c['max_depth'] ?? self::MAX_DEPTH))),
            'captcha' => ($c['captcha'] ?? true) !== false,
        ];
    }

    /**
     * 垃圾拦截配置 JSON 文件路径
     *
     * @param string $contentRoot 正文根目录
     * @return string
     */
    public static function spamFilterFile(string $contentRoot): string
    {
        return rtrim($contentRoot, '/\\') . '/.comments/spam-filter.json';
    }

    /**
     * 读取垃圾拦截配置（JSON 文件，缺省兜底）
     *
     * @param string $contentRoot 正文根目录
     * @return array{enabled:bool,max_links:int,keywords:array<int,string>,updated_at:int}
     */
    public static function loadSpamFilter(string $contentRoot): array
    {
        $default = [
            'enabled' => true,
            'max_links' => 3,
            'keywords' => [],
            'updated_at' => 0,
        ];
        $file = self::spamFilterFile($contentRoot);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $default;
        }

        return [
            'enabled' => ($data['enabled'] ?? true) !== false,
            'max_links' => max(0, (int) ($data['max_links'] ?? 3)),
            'keywords' => array_values(array_filter(array_map(
                static fn(mixed $w): string => trim((string) $w),
                (array) ($data['keywords'] ?? [])
            ), static fn(string $w): bool => $w !== '')),
            'updated_at' => (int) ($data['updated_at'] ?? 0),
        ];
    }

    /**
     * 保存垃圾拦截配置到 JSON 文件
     *
     * @param string $contentRoot 正文根目录
     * @param bool $enabled 是否启用
     * @param int $maxLinks 链接数量阈值
     * @param array<int, string> $keywords 黑名单关键词
     * @return bool
     */
    public static function saveSpamFilter(string $contentRoot, bool $enabled, int $maxLinks, array $keywords): bool
    {
        $dir = self::storageDir($contentRoot);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $keywords = array_values(array_filter(array_map(
            static fn(mixed $w): string => trim((string) $w),
            $keywords
        ), static fn(string $w): bool => $w !== ''));

        $payload = [
            'version' => 1,
            'enabled' => $enabled,
            'max_links' => max(0, min(20, $maxLinks)),
            'keywords' => $keywords,
            'updated_at' => time(),
        ];

        $file = self::spamFilterFile($contentRoot);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ), LOCK_EX) === false) {
            return false;
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * 自动垃圾检测
     *
     * 规则：黑名单关键词、URL 数量超限、连续重复字符/短句。
     * 返回空串表示正常，否则返回命中原因。
     *
     * @param string $name 昵称
     * @param string $content 内容
     * @param array{enabled:bool,max_links:int,keywords:array<int,string>} $filter 过滤配置
     * @return string
     */
    public static function detectSpam(string $name, string $content, array $filter): string
    {
        if (empty($filter['enabled'])) {
            return '';
        }

        $text = $name . ' ' . $content;

        // 黑名单关键词
        foreach ((array) ($filter['keywords'] ?? []) as $word) {
            $word = trim((string) $word);
            if ($word !== '' && mb_strpos($text, $word, 0, 'UTF-8') !== false) {
                return '命中黑名单关键词：' . $word;
            }
        }

        // URL 数量超限（常见推广特征）
        $maxLinks = (int) ($filter['max_links'] ?? 3);
        if ($maxLinks > 0) {
            $linkCount = preg_match_all('#(?:https?://|www\.)#i', $content) ?: 0;
            if ($linkCount >= $maxLinks) {
                return '链接数量过多';
            }
        }

        // 连续重复字符（如 aaaa、1111）
        if (preg_match('/(.)\1{6,}/u', $content)) {
            return '内容含大量重复字符';
        }

        // 同一短句重复 3 次以上（如「不错。不错。不错。」）
        $sentences = preg_split('/[。！？!?\n]+/u', $content) ?: [];
        $counts = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence, 'UTF-8') >= 2) {
                $counts[$sentence] = ($counts[$sentence] ?? 0) + 1;
            }
        }
        if (max($counts ?: [0]) >= 3) {
            return '内容重复刷屏';
        }

        return '';
    }

    /**
     * 规范化文章相对路径（相对正文根，含 .md）
     *
     * @param string $postRel
     * @return string
     */
    public static function normalizePostRel(string $postRel): string
    {
        $postRel = str_replace('\\', '/', trim($postRel));
        $postRel = ltrim($postRel, '/');
        if ($postRel === '' || str_contains($postRel, '..') || str_contains($postRel, "\0")) {
            return '';
        }
        if (!str_ends_with(strtolower($postRel), '.md')) {
            $postRel .= '.md';
        }

        return $postRel;
    }

    /**
     * 评论文件键：crc32(相对路径) → 8 位十六进制
     *
     * @param string $postRel
     * @return string
     */
    public static function storageKey(string $postRel): string
    {
        $postRel = self::normalizePostRel($postRel);
        if ($postRel === '') {
            return '';
        }

        return sprintf('%08x', crc32($postRel));
    }

    /**
     * 评论存储目录
     *
     * @param string $contentRoot 正文根（尾斜杠）
     * @return string
     */
    public static function storageDir(string $contentRoot): string
    {
        return rtrim($contentRoot, '/\\') . '/.comments';
    }

    /**
     * 评论文件绝对路径
     *
     * @param string $contentRoot
     * @param string $postRel
     * @return string
     */
    public static function storageFile(string $contentRoot, string $postRel): string
    {
        $key = self::storageKey($postRel);
        if ($key === '') {
            return '';
        }

        return self::storageDir($contentRoot) . '/' . $key . '.json';
    }

    /**
     * 读取原始评论包
     *
     * @param string $contentRoot
     * @param string $postRel
     * @return array{post:string,updated_at:int,items:array<int,array<string,mixed>>}
     */
    public static function load(string $contentRoot, string $postRel): array
    {
        $postRel = self::normalizePostRel($postRel);
        $file = self::storageFile($contentRoot, $postRel);
        $empty = ['post' => $postRel, 'updated_at' => 0, 'items' => []];
        if ($postRel === '' || $file === '' || !is_file($file)) {
            return $empty;
        }

        $raw = file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $empty;
        }

        $items = [];
        foreach ((array) ($data['items'] ?? []) as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $items[] = self::normalizeItem($row);
        }

        return [
            'post' => (string) ($data['post'] ?? $postRel),
            'updated_at' => (int) ($data['updated_at'] ?? 0),
            'items' => $items,
        ];
    }

    /**
     * 原子写入评论包
     *
     * @param string $contentRoot
     * @param array{post:string,updated_at?:int,items:array} $pack
     * @return bool
     */
    public static function save(string $contentRoot, array $pack): bool
    {
        $postRel = self::normalizePostRel((string) ($pack['post'] ?? ''));
        if ($postRel === '') {
            return false;
        }

        $dir = self::storageDir($contentRoot);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $file = self::storageFile($contentRoot, $postRel);
        $payload = [
            'post' => $postRel,
            'updated_at' => time(),
            'items' => array_values((array) ($pack['items'] ?? [])),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * 前台：已审核评论树
     *
     * @param string $contentRoot
     * @param string $postRel
     * @param int $maxDepth
     * @return array{total:int,tree:array<int,array>}
     */
    public static function publicTree(string $contentRoot, string $postRel, int $maxDepth = self::MAX_DEPTH): array
    {
        $pack = self::load($contentRoot, $postRel);
        $approved = array_values(array_filter(
            $pack['items'],
            static fn(array $i): bool => ($i['status'] ?? '') === 'approved'
        ));

        return [
            'total' => count($approved),
            'tree' => self::buildTree($approved, null, 0, $maxDepth),
        ];
    }

    /**
     * 提交评论（访客）
     *
     * @param string $contentRoot
     * @param array<string, mixed> $input
     * @param array<string, mixed> $conf
     * @return array{code:int,message:string,data?:array}
     */
    public static function submit(string $contentRoot, array $input, array $conf = []): array
    {
        $settings = self::settings($conf);
        if (!$settings['enabled']) {
            return ['code' => 1, 'message' => '评论已关闭'];
        }

        $postRel = self::normalizePostRel((string) ($input['post'] ?? ''));
        if ($postRel === '' || !is_file(rtrim($contentRoot, '/\\') . '/' . $postRel)) {
            return ['code' => 1, 'message' => '文章不存在'];
        }

        // 草稿不可评
        $raw = (string) file_get_contents(rtrim($contentRoot, '/\\') . '/' . $postRel);
        $meta = \Candy\Extend\Doc\Markdown\Engine::peekMetaFromText($raw);
        if (Index::isDraftMeta($meta) || !empty($meta['password'])) {
            return ['code' => 1, 'message' => '该文章暂不支持评论'];
        }

        if ($settings['captcha']) {
            $captcha = trim((string) ($input['captcha'] ?? ''));
            if ($captcha === '' || !function_exists('captcha_verify') || !captcha_verify($captcha)) {
                return ['code' => 1, 'message' => '验证码错误'];
            }
        }

        $ip = (string)I('server.REMOTE_ADDR', '', '');
        if (!self::passRateLimit($ip, $postRel)) {
            return ['code' => 1, 'message' => '提交过于频繁，请稍后再试'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            return ['code' => 1, 'message' => '昵称需 1～' . self::MAX_NAME . ' 字'];
        }

        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '' || mb_strlen($content, 'UTF-8') > self::MAX_CONTENT) {
            return ['code' => 1, 'message' => '评论内容不能为空且不超过 ' . self::MAX_CONTENT . ' 字'];
        }

        // 自动垃圾拦截：命中直接标 spam，不占用待审队列（规则存 JSON 文件）
        $spamReason = self::detectSpam($name, $content, self::loadSpamFilter($contentRoot));
        if ($spamReason !== '') {
            $pack = self::load($contentRoot, $postRel);
            $id = 'c_' . bin2hex(random_bytes(8));
            $pack['items'][] = [
                'id' => $id,
                'parent' => null,
                'name' => $name,
                'content' => $content,
                'tags' => [],
                'created_at' => time(),
                'status' => 'spam',
                'ip' => (string) I('server.REMOTE_ADDR', '', ''),
                'ua' => mb_substr((string) I('server.HTTP_USER_AGENT', '', ''), 0, 200, 'UTF-8'),
            ];
            self::save($contentRoot, $pack);
            self::markRateLimit($ip, $postRel);

            return ['code' => 1, 'message' => '内容包含疑似广告信息，已拦截', 'data' => ['blocked' => true]];
        }

        $tags = self::normalizeTags($input['tags'] ?? []);
        $parent = trim((string) ($input['parent'] ?? ''));
        $parent = $parent !== '' ? $parent : null;

        $pack = self::load($contentRoot, $postRel);
        $depth = 0;
        if ($parent !== null) {
            $parentItem = self::findItem($pack['items'], $parent);
            if ($parentItem === null || ($parentItem['status'] ?? '') !== 'approved') {
                return ['code' => 1, 'message' => '回复目标不存在或未通过审核'];
            }
            $depth = self::itemDepth($pack['items'], $parent) + 1;
            if ($depth >= $settings['max_depth']) {
                return ['code' => 1, 'message' => '回复层级已达上限'];
            }
        }

        $id = 'c_' . bin2hex(random_bytes(8));
        $status = $settings['require_approve'] ? 'pending' : 'approved';
        $item = [
            'id' => $id,
            'parent' => $parent,
            'name' => $name,
            'content' => $content,
            'tags' => $tags,
            'created_at' => time(),
            'status' => $status,
            'ip' => $ip,
            'ua' => mb_substr((string)I('server.HTTP_USER_AGENT', '', ''), 0, 200, 'UTF-8'),
        ];
        $pack['items'][] = $item;
        if (!self::save($contentRoot, $pack)) {
            return ['code' => 1, 'message' => '保存失败，请稍后重试'];
        }

        self::markRateLimit($ip, $postRel);

        return [
            'code' => 0,
            'message' => $status === 'pending'
                ? ($parent !== null ? '回复已提交，审核通过后显示在楼中' : '评论已提交，审核通过后公开显示')
                : ($parent !== null ? '回复已发布' : '评论发表成功'),
            'data' => [
                'id' => $id,
                'status' => $status,
                'is_reply' => $parent !== null,
                'html' => $status === 'approved' ? self::renderItemHtml($item, 0, $settings['max_depth'], true) : '',
            ],
        ];
    }

    /**
     * 扫描全部评论文件（后台列表）
     *
     * @param string $contentRoot
     * @param string $statusFilter all|pending|approved|spam
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(string $contentRoot, string $statusFilter = 'all'): array
    {
        $dir = self::storageDir($contentRoot);
        if (!is_dir($dir)) {
            return [];
        }

        $rows = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                continue;
            }
            $post = (string) ($data['post'] ?? '');
            foreach ((array) ($data['items'] ?? []) as $item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }
                $item = self::normalizeItem($item);
                if ($statusFilter !== 'all' && ($item['status'] ?? '') !== $statusFilter) {
                    continue;
                }
                $item['post'] = $post;
                $item['file'] = basename($file);
                $rows[] = $item;
            }
        }

        usort($rows, static fn(array $a, array $b): int => ((int) ($b['created_at'] ?? 0)) <=> ((int) ($a['created_at'] ?? 0)));

        return $rows;
    }

    /**
     * 待审核数量
     *
     * @param string $contentRoot
     * @return int
     */
    public static function pendingCount(string $contentRoot): int
    {
        return count(self::listAll($contentRoot, 'pending'));
    }

    /**
     * 更新单条状态
     *
     * @param string $contentRoot
     * @param string $postRel
     * @param string $id
     * @param string $status approved|pending|spam
     * @return array{code:int,message:string}
     */
    public static function setStatus(string $contentRoot, string $postRel, string $id, string $status): array
    {
        if (!in_array($status, ['approved', 'pending', 'spam'], true)) {
            return ['code' => 1, 'message' => '无效状态'];
        }
        $pack = self::load($contentRoot, $postRel);
        $found = false;
        foreach ($pack['items'] as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item['status'] = $status;
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) {
            return ['code' => 1, 'message' => '评论不存在'];
        }
        if (!self::save($contentRoot, $pack)) {
            return ['code' => 1, 'message' => '保存失败'];
        }

        $labels = [
            'approved' => '已通过审核，前台可见',
            'pending' => '已标为待审核',
            'spam' => '已标为垃圾评论',
        ];

        return ['code' => 0, 'message' => $labels[$status] ?? '已更新'];
    }

    /**
     * 删除评论（含子树）
     *
     * @param string $contentRoot
     * @param string $postRel
     * @param string $id
     * @return array{code:int,message:string}
     */
    public static function delete(string $contentRoot, string $postRel, string $id): array
    {
        $pack = self::load($contentRoot, $postRel);
        $remove = self::collectDescendantIds($pack['items'], $id);
        $remove[$id] = true;
        $before = count($pack['items']);
        $pack['items'] = array_values(array_filter(
            $pack['items'],
            static fn(array $i): bool => !isset($remove[(string) ($i['id'] ?? '')])
        ));
        if (count($pack['items']) === $before) {
            return ['code' => 1, 'message' => '评论不存在'];
        }
        if ($pack['items'] === []) {
            $file = self::storageFile($contentRoot, $postRel);
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }

            return ['code' => 0, 'message' => '已删除该评论及子回复'];
        }
        if (!self::save($contentRoot, $pack)) {
            return ['code' => 1, 'message' => '保存失败'];
        }

        return ['code' => 0, 'message' => '已删除该评论及子回复'];
    }

    /**
     * 管理员回复（直接通过）
     *
     * @param string $contentRoot
     * @param array<string, mixed> $input
     * @param string $adminName
     * @param array<string, mixed> $conf
     * @return array{code:int,message:string}
     */
    public static function adminReply(string $contentRoot, array $input, string $adminName, array $conf = []): array
    {
        $settings = self::settings($conf);
        $postRel = self::normalizePostRel((string) ($input['post'] ?? ''));
        $parent = trim((string) ($input['parent'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));
        if ($postRel === '' || $parent === '' || $content === '') {
            return ['code' => 1, 'message' => '参数不完整'];
        }
        if (mb_strlen($content, 'UTF-8') > self::MAX_CONTENT) {
            return ['code' => 1, 'message' => '内容过长'];
        }

        $pack = self::load($contentRoot, $postRel);
        $parentItem = self::findItem($pack['items'], $parent);
        if ($parentItem === null) {
            return ['code' => 1, 'message' => '父评论不存在'];
        }
        $depth = self::itemDepth($pack['items'], $parent) + 1;
        if ($depth >= $settings['max_depth']) {
            return ['code' => 1, 'message' => '回复层级已达上限'];
        }

        $pack['items'][] = [
            'id' => 'c_' . bin2hex(random_bytes(8)),
            'parent' => $parent,
            'name' => $adminName !== '' ? $adminName : '博主',
            'content' => $content,
            'tags' => self::normalizeTags($input['tags'] ?? ['博主']),
            'created_at' => time(),
            'status' => 'approved',
            'ip' => '',
            'ua' => '',
            'is_admin' => true,
        ];
        if (!self::save($contentRoot, $pack)) {
            return ['code' => 1, 'message' => '保存失败'];
        }

        return ['code' => 0, 'message' => '回复已发布到前台'];
    }

    /**
     * 渲染评论 HTML（安全转义 + 表情）
     *
     * @param string $content
     * @return string
     */
    public static function formatContentHtml(string $content): string
    {
        $html = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        $html = \Candy\Extend\Doc\Markdown\Engine::applyEmoji($html);
        $html = nl2br($html, false);

        return $html;
    }

    /**
     * 渲染单条（含子级）HTML
     *
     * @param array<string, mixed> $item
     * @param int $depth
     * @param int $maxDepth
     * @param bool $withChildren
     * @param array<int, array<string, mixed>>|null $siblingsForTree
     * @return string
     */
    public static function renderItemHtml(
        array $item,
        int $depth = 0,
        int $maxDepth = self::MAX_DEPTH,
        bool $withChildren = false,
        ?array $siblingsForTree = null
    ): string {
        $id = htmlspecialchars((string) ($item['id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $time = (int) ($item['created_at'] ?? 0);
        $timeStr = $time > 0 ? date('Y-m-d H:i', $time) : '';
        $body = self::formatContentHtml((string) ($item['content'] ?? ''));
        $isAdmin = !empty($item['is_admin']);
        $tagsHtml = '';
        foreach ((array) ($item['tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            $tagsHtml .= '<span class="blog-comment-tag">' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $canReply = $depth + 1 < $maxDepth;
        $replyBtn = $canReply
            ? '<button type="button" class="blog-comment-reply" data-reply-to="' . $id . '" data-reply-name="' . $name . '">回复</button>'
            : '';

        $childrenHtml = '';
        if ($withChildren && $siblingsForTree !== null) {
            $children = self::buildTree($siblingsForTree, (string) ($item['id'] ?? ''), $depth + 1, $maxDepth);
            foreach ($children as $child) {
                $childrenHtml .= self::renderItemHtml($child, $depth + 1, $maxDepth, true, $siblingsForTree);
            }
        } elseif ($withChildren && !empty($item['children']) && is_array($item['children'])) {
            foreach ($item['children'] as $child) {
                $childrenHtml .= self::renderItemHtml($child, $depth + 1, $maxDepth, true);
            }
        }

        return '<li class="blog-comment-item' . ($isAdmin ? ' is-admin' : '') . '" id="comment-' . $id . '" data-id="' . $id . '" data-depth="' . (int) $depth . '">'
            . '<div class="blog-comment-card">'
            . '<header class="blog-comment-head">'
            . '<span class="blog-comment-author">' . $name . ($isAdmin ? '<em class="blog-comment-badge">博主</em>' : '') . '</span>'
            . ($timeStr !== '' ? '<time datetime="' . date('c', $time) . '">' . $timeStr . '</time>' : '')
            . '</header>'
            . ($tagsHtml !== '' ? '<div class="blog-comment-tags">' . $tagsHtml . '</div>' : '')
            . '<div class="blog-comment-body">' . $body . '</div>'
            . '<footer class="blog-comment-foot">' . $replyBtn . '</footer>'
            . '</div>'
            . ($childrenHtml !== '' ? '<ul class="blog-comment-children">' . $childrenHtml . '</ul>' : '')
            . '</li>';
    }

    /**
     * 渲染整棵公开评论树 HTML
     *
     * @param string $contentRoot
     * @param string $postRel
     * @param int $maxDepth
     * @return string
     */
    public static function renderPublicListHtml(string $contentRoot, string $postRel, int $maxDepth = self::MAX_DEPTH): string
    {
        $data = self::publicTree($contentRoot, $postRel, $maxDepth);
        if ($data['tree'] === []) {
            return '<p class="blog-comment-empty">还没有评论，来抢沙发吧 😄</p>';
        }

        $html = '<ul class="blog-comment-list">';
        foreach ($data['tree'] as $node) {
            $html .= self::renderItemHtml($node, 0, $maxDepth, true);
        }
        $html .= '</ul>';

        return $html;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeItem(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'parent' => isset($row['parent']) && $row['parent'] !== '' && $row['parent'] !== null
                ? (string) $row['parent'] : null,
            'name' => (string) ($row['name'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'tags' => array_values(array_filter(array_map('strval', (array) ($row['tags'] ?? [])))),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'status' => (string) ($row['status'] ?? 'pending'),
            'ip' => (string) ($row['ip'] ?? ''),
            'ua' => (string) ($row['ua'] ?? ''),
            'is_admin' => !empty($row['is_admin']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function buildTree(array $items, ?string $parentId, int $depth, int $maxDepth): array
    {
        if ($depth >= $maxDepth) {
            return [];
        }
        $branch = [];
        foreach ($items as $item) {
            $p = $item['parent'] ?? null;
            $match = ($parentId === null && ($p === null || $p === ''))
                || ($parentId !== null && $p === $parentId);
            if (!$match) {
                continue;
            }
            $node = $item;
            $node['children'] = self::buildTree($items, (string) $item['id'], $depth + 1, $maxDepth);
            $branch[] = $node;
        }

        return $branch;
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function findItem(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function itemDepth(array $items, string $id): int
    {
        $depth = 0;
        $guard = 0;
        $cur = $id;
        while ($guard++ < 20) {
            $item = self::findItem($items, $cur);
            if ($item === null || empty($item['parent'])) {
                break;
            }
            $depth++;
            $cur = (string) $item['parent'];
        }

        return $depth;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, true>
     */
    private static function collectDescendantIds(array $items, string $id): array
    {
        $out = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($items as $item) {
                $cid = (string) ($item['id'] ?? '');
                $pid = (string) ($item['parent'] ?? '');
                if ($cid === '' || isset($out[$cid])) {
                    continue;
                }
                if ($pid === $id || isset($out[$pid])) {
                    $out[$cid] = true;
                    $changed = true;
                }
            }
        }

        return $out;
    }

    /** @param mixed $tags */
    private static function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[,，\s]+/u', $tags) ?: [];
        }
        if (!is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            $tag = preg_replace('/[^\p{L}\p{N}_\-]+/u', '', $tag) ?? '';
            if ($tag === '' || mb_strlen($tag, 'UTF-8') > self::MAX_TAG_LEN) {
                continue;
            }
            $out[$tag] = $tag;
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
        }

        return array_values($out);
    }

    private static function rateKey(string $ip, string $postRel): string
    {
        return 'blog_comment_rate:' . md5($ip . '|' . $postRel);
    }

    private static function passRateLimit(string $ip, string $postRel): bool
    {
        if ($ip === '') {
            return true;
        }
        try {
            $cache = \Candy\Core\Cache::store();
            $last = (int) $cache->get(self::rateKey($ip, $postRel), 0);
            return $last <= 0 || (time() - $last) >= self::RATE_SECONDS;
        } catch (\Throwable) {
            return true;
        }
    }

    private static function markRateLimit(string $ip, string $postRel): void
    {
        if ($ip === '') {
            return;
        }
        try {
            \Candy\Core\Cache::store()->set(self::rateKey($ip, $postRel), time(), self::RATE_SECONDS + 5);
        } catch (\Throwable) {
            // ignore
        }
    }
}
