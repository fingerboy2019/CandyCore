<?php
/***
 * 博客配置后台保存
 *
 * 业务说明：按 Tab 合并 BlogConfig 并写回 Source/Inc
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 19:05:39 $
 *
 * @package Candy\Extend\Doc\Blog\Admin
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog\Admin;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ConfigSaver
{
    /**
     * Tab 定义
     *
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            'site' => '站点信息',
            'nav' => '顶栏导航',
            'profile' => '博主资料',
            'links' => '友情链接',
            'feed' => '列表数量',
            'syndication' => '订阅与地图',
            'comments' => '评论',
            'vars' => '模板变量',
            'auth' => '后台账号',
            'content_dir' => '目录修改',
        ];
    }

    /**
     * 快照存储目录（Data/blog-config-backups）
     *
     * @return string
     */
    public static function snapshotDir(): string
    {
        return C('CANDYROOT') . 'Data' . DIRECTORY_SEPARATOR . 'blog-config-backups' . DIRECTORY_SEPARATOR;
    }

    /**
     * 保存配置前自动快照当前配置（保留最近 30 份）
     *
     * @param array<string, mixed> $conf 当前配置
     * @return string 快照文件名；失败返回空串
     */
    public static function takeSnapshot(array $conf): string
    {
        $dir = self::snapshotDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return '';
        }

        $name = 'blog-config-' . date('Ymd-His') . '.json';
        $payload = [
            'version' => 1,
            'snapshot_at' => date('Y-m-d H:i:s'),
            'site' => 'Candy Markdown 博客',
            'config' => $conf,
        ];
        $ok = file_put_contents($dir . $name, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ), LOCK_EX) !== false;

        if ($ok) {
            self::pruneSnapshots($dir, 30);
        }

        return $ok ? $name : '';
    }

    /**
     * 清理过期快照（保留最近 N 份）
     *
     * @param string $dir 快照目录
     * @param int $keep 保留份数
     * @return void
     */
    private static function pruneSnapshots(string $dir, int $keep): void
    {
        $files = glob($dir . 'blog-config-*.json') ?: [];
        if (count($files) <= $keep) {
            return;
        }
        // 文件名带时间戳，字典序即时间序
        sort($files, SORT_STRING);
        $remove = array_slice($files, 0, count($files) - $keep);
        foreach ($remove as $f) {
            @unlink($f);
        }
    }

    /**
     * 列出历史快照
     *
     * @return array<int, array{name:string,time:string,size:string}>
     */
    public static function snapshots(): array
    {
        $dir = self::snapshotDir();
        $files = glob($dir . 'blog-config-*.json') ?: [];
        rsort($files, SORT_STRING);

        $rows = [];
        foreach ($files as $f) {
            $rows[] = [
                'name' => basename($f),
                'time' => date('Y-m-d H:i:s', (int) filemtime($f)),
                'size' => self::formatBytes((int) filesize($f)),
            ];
        }

        return $rows;
    }

    /**
     * 从历史快照还原
     *
     * @param string $name 快照文件名
     * @return array<string, mixed>
     */
    public static function restoreSnapshot(string $name): array
    {
        $name = basename($name);
        if (!preg_match('/^blog-config-\d{8}-\d{6}\.json$/', $name)) {
            return ['code' => 1, 'message' => '快照文件名无效'];
        }
        $file = self::snapshotDir() . $name;
        if (!is_file($file)) {
            return ['code' => 1, 'message' => '快照不存在'];
        }

        return self::restore((string) file_get_contents($file));
    }

    /**
     * 字节格式化
     *
     * @param int $bytes
     * @return string
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $n = $bytes / 1024;
        $i = 0;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return number_format($n, 1) . ' ' . $units[$i];
    }

    /**
     * 配置备份（导出 JSON）
     *
     * @return array<string, mixed>
     */
    public static function backup(): array
    {
        $conf = \Candy\Extend\Doc\Blog\Config::load();
        $payload = [
            'version' => 1,
            'exported_at' => date('Y-m-d H:i:s'),
            'site' => 'Candy Markdown 博客',
            'config' => $conf,
        ];

        return [
            'code' => 0,
            'message' => '',
            'data' => [
                'filename' => 'blog-config-backup-' . date('Ymd-His') . '.json',
                'content' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    /**
     * 配置还原（从 JSON 恢复）
     *
     * @param string $json JSON 内容
     * @return array<string, mixed>
     */
    public static function restore(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return ['code' => 1, 'message' => '备份内容为空'];
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return ['code' => 1, 'message' => 'JSON 解析失败，请上传有效的备份文件'];
        }

        $conf = $payload['config'] ?? null;
        if (!is_array($conf) || ($payload['site'] ?? '') !== 'Candy Markdown 博客') {
            return ['code' => 1, 'message' => '不是有效的博客配置备份文件'];
        }

        // 校验关键字段
        $contentDir = (string) ($conf['content_dir'] ?? '');
        if (preg_match('/^[a-z0-9]{8}$/', $contentDir) !== 1) {
            return ['code' => 1, 'message' => '备份中 content_dir 无效'];
        }

        // 还原前快照当前配置，避免还原失败无法回退
        self::takeSnapshot(\Candy\Extend\Doc\Blog\Config::load());

        $ok = \Candy\Extend\Doc\Blog\Config::save($conf);
        if (!$ok) {
            return ['code' => 1, 'message' => '配置写入失败，请检查目录权限'];
        }

        return ['code' => 0, 'message' => '配置已还原'];
    }

    /**
     * 按 Tab 保存配置
     *
     * @param string $tab
     * @param array<string, mixed> $input
     * @return array{code:int,message:string,url?:string}
     */
    public static function saveTab(string $tab, array $input): array
    {
        if ($tab === 'content_dir') {
            return self::changeContentDir($input);
        }

        $config = \Candy\Extend\Doc\Blog\Config::load();
        if ($config === []) {
            return ['code' => 1, 'message' => '博客配置不存在'];
        }

        try {
            match ($tab) {
                'site' => self::mergeSite($config, $input),
                'nav' => self::mergeNav($config, $input),
                'profile' => self::mergeProfile($config, $input),
                'links' => self::mergeLinks($config, $input),
                'feed' => self::mergeFeed($config, $input),
                'syndication' => self::mergeSyndication($config, $input),
                'comments' => self::mergeComments($config, $input),
                'vars' => self::mergeVars($config, $input),
                'auth' => self::mergeAuth($config, $input),
                default => throw new \InvalidArgumentException('未知配置分类'),
            };
        } catch (\Throwable $e) {
            return ['code' => 1, 'message' => $e->getMessage()];
        }

        // 保存前自动快照当前配置，便于改坏回滚
        self::takeSnapshot(\Candy\Extend\Doc\Blog\Config::load());

        if (!\Candy\Extend\Doc\Blog\Config::save($config)) {
            return ['code' => 1, 'message' => '写入配置文件失败'];
        }

        return ['code' => 0, 'message' => '保存成功'];
    }

    /**
     * 修改正文随机目录名并重命名磁盘目录
     *
     * 更新 BlogConfig.content_dir，成功后返回新管理后台地址供前端跳转
     *
     * @param array<string, mixed> $input
     * @return array{code:int,message:string,url?:string}
     */
    public static function changeContentDir(array $input): array
    {
        $config = \Candy\Extend\Doc\Blog\Config::load();
        if ($config === []) {
            return ['code' => 1, 'message' => '博客配置不存在'];
        }

        $oldDir = (string) ($config['content_dir'] ?? '');
        $newDir = strtolower(trim((string) ($input['content_dir'] ?? '')));

        if (!preg_match('/^[a-z0-9]{8}$/', $newDir)) {
            return ['code' => 1, 'message' => '目录名须为 8 位小写字母或数字'];
        }
        if ($newDir === $oldDir) {
            return ['code' => 1, 'message' => '新目录名与当前相同，无需修改'];
        }
        if (!preg_match('/^[a-z0-9]{8}$/', $oldDir)) {
            return ['code' => 1, 'message' => '当前 content_dir 配置无效'];
        }

        $installRoot = self::blogInstallRoot($config);
        $oldPath = $installRoot . DIRECTORY_SEPARATOR . $oldDir;
        $newPath = $installRoot . DIRECTORY_SEPARATOR . $newDir;

        if (!is_dir($oldPath)) {
            return ['code' => 1, 'message' => '当前正文目录不存在：' . $oldDir];
        }
        if (file_exists($newPath)) {
            return ['code' => 1, 'message' => '目标目录名已被占用'];
        }

        if (!@rename($oldPath, $newPath)) {
            return ['code' => 1, 'message' => '重命名目录失败，请检查 Doc/Blog 目录权限'];
        }

        $config['content_dir'] = $newDir;
        if (!\Candy\Extend\Doc\Blog\Config::save($config)) {
            @rename($newPath, $oldPath);

            return ['code' => 1, 'message' => '写入配置文件失败，已回滚目录重命名'];
        }

        $adminUrl = \Candy\Extend\Doc\Blog\Config::adminBaseUrl($config);

        return [
            'code' => 0,
            'message' => '目录已修改，正在跳转到新后台地址…',
            'url' => $adminUrl . '/config?tab=content_dir',
        ];
    }

    /**
     * 解析博客安装根目录（Doc/Blog 绝对路径，无末尾斜杠）
     *
     * @param array<string, mixed> $config
     * @return string
     */
    private static function blogInstallRoot(array $config): string
    {
        $root = \Candy\Extend\Doc\Blog\Config::contentRoot($config);
        $real = realpath(rtrim($root, '/\\'));
        if ($real === false) {
            throw new \RuntimeException('博客安装目录不存在');
        }

        return $real;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    /**
     * 合并评论配置
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     * @return void
     */
    private static function mergeComments(array &$config, array $input): void
    {
        $c = is_array($config['comments'] ?? null) ? $config['comments'] : [];

        $c['enabled'] = isset($input['enabled']);
        $c['require_approve'] = isset($input['require_approve']);
        $c['captcha'] = isset($input['captcha']);
        $c['max_depth'] = max(1, min(6, (int) ($input['max_depth'] ?? $c['max_depth'] ?? 4)));

        $config['comments'] = $c;
    }

    private static function mergeSite(array &$config, array $input): void
    {
        $config['site_name'] = trim((string) ($input['site_name'] ?? $config['site_name'] ?? ''));
        $config['site_description'] = trim((string) ($input['site_description'] ?? $config['site_description'] ?? ''));
        $config['default_author'] = trim((string) ($input['default_author'] ?? $config['default_author'] ?? ''));
        $config['quote'] = trim((string) ($input['quote'] ?? $config['quote'] ?? ''));
        $config['search_limit'] = max(1, min(100, (int) ($input['search_limit'] ?? $config['search_limit'] ?? 20)));

        $domain = \Candy\Extend\Doc\Blog\Config::normalizeDomain((string) ($input['blog_domain'] ?? $config['blog_domain'] ?? ''));
        if ($domain !== '' && !preg_match('/^[a-z0-9]([a-z0-9.-]{0,253}[a-z0-9])?$/i', $domain)) {
            throw new \InvalidArgumentException('绑定域名格式不正确，请填写如 blog.example.com');
        }
        $config['blog_domain'] = $domain;

        $siteUrl = trim((string) ($input['site_url'] ?? $config['site_url'] ?? ''));
        if ($siteUrl !== '') {
            if (!preg_match('#^https?://#i', $siteUrl)) {
                $siteUrl = 'https://' . ltrim($siteUrl, '/');
            }
            $siteUrl = rtrim($siteUrl, '/');
        }
        $config['site_url'] = $siteUrl;
    }

    /**
     * 订阅与站点地图条数
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private static function mergeSyndication(array &$config, array $input): void
    {
        $siteUrl = trim((string) ($input['site_url'] ?? $config['site_url'] ?? ''));
        if ($siteUrl !== '') {
            if (!preg_match('#^https?://#i', $siteUrl)) {
                $siteUrl = 'https://' . ltrim($siteUrl, '/');
            }
            $siteUrl = rtrim($siteUrl, '/');
        }
        $config['site_url'] = $siteUrl;

        $limits = is_array($config['feed_limits'] ?? null) ? $config['feed_limits'] : [];
        if (isset($input['rss_items'])) {
            $n = (int) $input['rss_items'];
            $limits['rss_items'] = $n > 0 ? max(1, min(200, $n)) : 20;
        }
        if (isset($input['sitemap_items'])) {
            $n = (int) $input['sitemap_items'];
            $limits['sitemap_items'] = $n > 0 ? max(1, min(5000, $n)) : 1000;
        }
        $config['feed_limits'] = $limits;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private static function mergeNav(array &$config, array $input): void
    {
        $nav = is_array($config['nav'] ?? null) ? $config['nav'] : [];
        $nav['show_home'] = !empty($input['show_home']);
        $nav['home_label'] = trim((string) ($input['home_label'] ?? '首页'));
        $nav['max_categories'] = max(1, min(20, (int) ($input['max_categories'] ?? 4)));
        $nav['more_label'] = trim((string) ($input['more_label'] ?? '更多'));
        $nav['items'] = self::parseKvRows($input['nav_items'] ?? [], ['label', 'url', 'external']);
        $config['nav'] = $nav;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private static function mergeProfile(array &$config, array $input): void
    {
        $profile = is_array($config['profile'] ?? null) ? $config['profile'] : [];
        foreach (['name', 'bio', 'avatar', 'more_url', 'wechat_qr', 'qq_qr'] as $key) {
            if (array_key_exists($key, $input)) {
                $profile[$key] = trim((string) $input[$key]);
            }
        }
        $config['profile'] = $profile;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private static function mergeLinks(array &$config, array $input): void
    {
        $links = is_array($config['friend_links'] ?? null) ? $config['friend_links'] : [];
        $links['title'] = trim((string) ($input['title'] ?? $links['title'] ?? '友情链接'));
        $links['sidebar'] = !empty($input['sidebar']);
        $links['footer'] = !empty($input['footer']);
        $links['items'] = self::parseKvRows($input['items'] ?? [], ['name', 'url', 'description', 'external']);
        $config['friend_links'] = $links;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private static function mergeFeed(array &$config, array $input): void
    {
        $limits = is_array($config['feed_limits'] ?? null) ? $config['feed_limits'] : [];
        foreach ([
            'recent', 'featured', 'sidebar', 'carousel', 'category_notes', 'tag_cloud',
            'home_skip', 'home_stream', 'list_page', 'rss_items', 'sitemap_items',
        ] as $key) {
            if (!isset($input[$key])) {
                continue;
            }
            $n = (int) $input[$key];
            // RSS / Sitemap 空值或非法值走兜底，与生成逻辑一致
            if ($key === 'rss_items') {
                $limits[$key] = $n > 0 ? max(1, min(200, $n)) : 20;
            } elseif ($key === 'sitemap_items') {
                $limits[$key] = $n > 0 ? max(1, min(5000, $n)) : 1000;
            } else {
                $limits[$key] = max(0, min(200, $n));
            }
        }
        $config['feed_limits'] = $limits;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private static function mergeVars(array &$config, array $input): void
    {
        $vars = is_array($config['vars'] ?? null) ? $config['vars'] : [];
        foreach (['global', 'index', 'post', 'list'] as $scope) {
            if (!isset($input[$scope]) || !is_array($input[$scope])) {
                continue;
            }
            $pairs = [];
            foreach ($input[$scope] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = trim((string) ($row['key'] ?? ''));
                if ($k === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
                    continue;
                }
                $pairs[$k] = (string) ($row['value'] ?? '');
            }
            $vars[$scope] = $pairs;
        }
        $config['vars'] = $vars;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private static function mergeAuth(array &$config, array $input): void
    {
        $auth = is_array($config['auth'] ?? null) ? $config['auth'] : \Candy\Extend\Doc\Blog\Config::defaultAuth();
        $auth['username'] = trim((string) ($input['username'] ?? $auth['username'] ?? 'admin'));
        $auth['captcha'] = !empty($input['captcha']);
        $newPass = (string) ($input['password'] ?? '');
        if ($newPass !== '') {
            $auth['password'] = password_hash($newPass, PASSWORD_DEFAULT);
        }
        $config['auth'] = $auth;
    }

    /**
     * @param mixed $rows
     * @param array<int, string> $fields
     * @return array<int, array<string, mixed>>
     */
    private static function parseKvRows(mixed $rows, array $fields): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [];
            $empty = true;
            foreach ($fields as $field) {
                $val = $row[$field] ?? '';
                if ($field === 'external') {
                    $item[$field] = !empty($val);
                } else {
                    $item[$field] = trim((string) $val);
                }
                if ($item[$field] !== '' && $item[$field] !== false) {
                    $empty = false;
                }
            }
            if (!$empty) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
