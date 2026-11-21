<?php
/***
 * Markdown 博客配置读写
 *
 * 业务说明：配置位于 Source/Inc/BlogConfig.inc.php；支持独立域名绑定（blog_domain，默认可空）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 19:06:11 $
 *
 * @package Candy\Extend\Doc\Blog
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $loadedConfig = null;

    /** @var bool|null 当前 Host 是否命中 blog_domain */
    private static ?bool $standaloneHostHit = null;

    /** @var string|null 缓存对应的 Host；长驻进程不能只缓存 bool */
    private static ?string $standaloneHostCacheKey = null;

    /**
     * 博客配置文件路径（Source/Inc）
     *
     * @return string
     */
    public static function incPath(): string
    {
        return C('INCPATH') . 'BlogConfig.inc.php';
    }

    /**
     * 历史配置路径（已废弃，仅用于迁移）
     *
     * @return string
     */
    public static function legacyPath(): string
    {
        return CANDYPATH . 'Config' . DS . 'BlogConfig.php';
    }

    /**
     * 博客是否已完成初始化
     *
     * @return bool
     */
    public static function isInstalled(): bool
    {
        return fileExistsCase(self::incPath()) || fileExistsCase(self::legacyPath());
    }

    /**
     * 读取博客配置（自动从旧路径迁移）
     *
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        if (self::$loadedConfig !== null) {
            return self::$loadedConfig;
        }

        self::migrateLegacyIfNeeded();

        if (!fileExistsCase(self::incPath())) {
            self::$loadedConfig = [];

            return self::$loadedConfig;
        }

        $config = function_exists('loadConfig') ? loadConfig('BlogConfig') : include self::incPath();

        self::$loadedConfig = is_array($config) ? $config : [];

        return self::$loadedConfig;
    }

    /**
     * 规范化绑定域名（去协议、端口、首尾空白，小写）
     *
     * @param string $domain
     * @return string 空字符串表示未绑定
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }

        if (str_contains($domain, '://')) {
            $parsed = parse_url($domain, PHP_URL_HOST);
            $domain = is_string($parsed) ? strtolower($parsed) : '';
        }

        if ($domain === '') {
            return '';
        }

        $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;

        return strtolower(trim($domain, '.'));
    }

    /**
     * 读取配置的独立博客域名
     *
     * @param array<string, mixed> $conf
     * @return string
     */
    public static function standaloneDomain(array $conf = []): string
    {
        $conf = $conf !== [] ? $conf : self::load();

        return self::normalizeDomain((string) ($conf['blog_domain'] ?? ''));
    }

    /**
     * 当前请求是否通过独立博客域名访问
     *
     * 配置 blog_domain 为空时恒为 false；仅内存比较，不查库
     *
     * @param string|null $host 默认取 HTTP_HOST
     * @param array<string, mixed>|null $conf
     * @return bool
     */
    public static function isStandaloneHost(?string $host = null, ?array $conf = null): bool
    {
        $useRequestCache = $host === null;
        $currentHost = self::normalizeHost($host ?? (string)I('server.HTTP_HOST', '', ''));
        if ($useRequestCache && self::$standaloneHostCacheKey === $currentHost && self::$standaloneHostHit !== null) {
            return self::$standaloneHostHit;
        }

        if (!self::isInstalled()) {
            $hit = false;
        } else {
            $conf = $conf ?? self::load();
            $bound = self::standaloneDomain($conf);
            if ($bound === '') {
                $hit = false;
            } else {
                $hit = $currentHost !== '' && $currentHost === $bound;
            }
        }

        if ($useRequestCache) {
            self::$standaloneHostCacheKey = $currentHost;
            self::$standaloneHostHit = $hit;
        }

        return $hit;
    }

    /**
     * 博客前台 URL 前缀（独立域名为 /，路径模式为 /Blog/）
     *
     * @param array<string, mixed> $conf
     * @return string 以 / 结尾
     */
    public static function publicBasePath(array $conf = []): string
    {
        return self::isStandaloneHost(null, $conf !== [] ? $conf : null) ? '/' : '/Blog/';
    }

    /**
     * @param string|null $host
     * @return string
     */
    private static function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return '';
        }

        return preg_replace('/:\d+$/', '', $host) ?? $host;
    }

    /**
     * 博客正文磁盘根目录（绝对路径，末尾 /）
     *
     * @param array<string, mixed> $conf
     * @return string
     */
    public static function contentRoot(array $conf = []): string
    {
        $conf = $conf !== [] ? $conf : self::load();
        $docRoot = (string) ($conf['doc_root'] ?? 'Doc/Blog/');
        $contentDir = (string) ($conf['content_dir'] ?? '');
        if (!preg_match('/^[a-z0-9]{8}$/', $contentDir)) {
            return '';
        }

        $installRoot = rtrim(
            str_starts_with($docRoot, '/')
                ? $docRoot
                : C('CANDYROOT') . ltrim($docRoot, '/'),
            '/'
        );

        return $installRoot . '/' . $contentDir . '/';
    }

    /**
     * 管理后台 URL 前缀（含 content_dir）
     *
     * 路径模式：/Blog/{content_dir}/admin；独立域名：/{content_dir}/admin
     *
     * @param array<string, mixed> $conf
     * @return string
     */
    public static function adminBaseUrl(array $conf = []): string
    {
        $conf = $conf !== [] ? $conf : self::load();
        $contentDir = (string) ($conf['content_dir'] ?? '');
        if (!preg_match('/^[a-z0-9]{8}$/', $contentDir)) {
            return '';
        }

        $prefix = rtrim(self::publicBasePath($conf), '/');

        return ($prefix === '' ? '' : $prefix) . '/' . $contentDir . '/admin';
    }

    /**
     * 默认管理员认证配置
     *
     * @return array<string, mixed>
     */
    public static function defaultAuth(): array
    {
        return [
            'username' => 'admin',
            'password' => password_hash('admin123', PASSWORD_DEFAULT),
            'captcha' => true,
        ];
    }

    /**
     * 持久化博客配置到 Source/Inc/BlogConfig.inc.php
     *
     * @param array<string, mixed> $config
     * @return bool
     */
    public static function save(array $config): bool
    {
        $path = self::incPath();
        $php = self::exportIncFile($config);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $ok = file_put_contents($path, $php, LOCK_EX) !== false;
        if ($ok) {
            self::$loadedConfig = $config;
            self::$standaloneHostCacheKey = null;
            self::$standaloneHostHit = null;
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $config
     * @return string
     */
    private static function exportIncFile(array $config): string
    {
        $date = date('Y-m-d H:i:s');
        $body = self::exportValue($config, 1);

        return <<<PHP
<?php
/***
 * Markdown 博客配置
 *
 * 路径：Source/Inc/BlogConfig.inc.php（勿与框架 Config 目录混淆）
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$date} \$
 */
return {$body};

PHP;
    }

    /**
     * @param mixed $value
     */
    private static function exportValue(mixed $value, int $depth): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat("\t", $depth);
        $childPad = str_repeat("\t", $depth + 1);
        $lines = ["["];
        foreach ($value as $key => $item) {
            $keyExport = is_int($key) ? $key : var_export((string) $key, true);
            $lines[] = $childPad . $keyExport . ' => ' . self::exportValue($item, $depth + 1) . ',';
        }
        $lines[] = $pad . ']';

        return implode("\n", $lines);
    }

    /**
     * 初始化用默认配置内容
     *
     * @param string $contentDir 8 位正文目录名
     * @return string
     */
    public static function defaultIncContent(string $contentDir): string
    {
        $contentDir = preg_replace('/[^a-z0-9]/', '', strtolower($contentDir)) ?: 'blogpost1';
        $defaultPassHash = password_hash('admin123', PASSWORD_DEFAULT);
        $defaultPassHash = str_replace("'", "\\'", $defaultPassHash);

        return <<<PHP
<?php
/***
 * Markdown 博客配置
 *
 * 路径：Source/Inc/BlogConfig.inc.php（勿与框架 Config 目录混淆）
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: 2026-07-16 06:15:00 \$
 */
return [
	'doc_root' => 'Doc/Blog/',
	'content_dir' => '{$contentDir}',
	'blog_domain' => '',
	'site_name' => 'Candy 博客',
	'site_description' => '基于 Markdown 的轻量博客',
	'site_url' => '',
	'feed_limits' => [
		'recent' => 5,
		'featured' => 4,
		'sidebar' => 6,
		'carousel' => 3,
		'category_notes' => 3,
		'tag_cloud' => 24,
		'home_skip' => 2,
		'home_stream' => 6,
		'list_page' => 3,
		'rss_items' => 20,
		'sitemap_items' => 1000,
	],
	'auth' => [
		'username' => 'admin',
		'password' => '{$defaultPassHash}',
		'captcha' => true,
	],
];

PHP;
    }

    /**
     * 将 backend/Config/BlogConfig.php 迁移到 Source/Inc
     *
     * @return void
     */
    private static function migrateLegacyIfNeeded(): void
    {
        if (fileExistsCase(self::incPath()) || !fileExistsCase(self::legacyPath())) {
            return;
        }

        $raw = file_get_contents(self::legacyPath());
        if ($raw === false || trim($raw) === '') {
            return;
        }

        $content = preg_replace(
            '/(@\\*\\*\\*.*?Markdown 博客配置.*?)(\\*\\*\\*)/s',
            '$1 * 路径：Source/Inc/BlogConfig.inc.php（由 Config/BlogConfig.php 自动迁移）$2',
            $raw,
            1
        );
        if ($content === null) {
            $content = $raw;
        }

        $dir = dirname(self::incPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::incPath(), $content);
        @unlink(self::legacyPath());
    }
}
