<?php
/***
 * 文档站模板上下文
 *
 * 业务说明：为 Doc 视图提供 $doc 数组与侧栏导航等数据
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 *
 * @package Candy\Extend\Doc\Site
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Site;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

require_once __DIR__ . '/helpers.php';

final class Context
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $page = [];

    private string $tpl = 'page';

    /**
     * 初始化当前请求的文档上下文
     *
     * @param array<string, mixed> $payload
     * @return void
     */
    public static function boot(array $payload): void
    {
        self::$instance = new self();
        self::$instance->hydrate($payload);
    }

    /**
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 读取 $doc 视图变量（支持 sidebar.enabled 点分键）
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::instance()->read($key, $default);
    }

    /**
     * 导出 $doc 数组
     *
     * @return array<string, mixed>
     */
    public static function viewData(): array
    {
        return self::instance()->buildViewData();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrate(array $payload): void
    {
        $this->tpl = (string) ($payload['tpl'] ?? 'page');
        $this->page = is_array($payload['page'] ?? null) ? $payload['page'] : [];
    }

    private function read(string $key, mixed $default = null): mixed
    {
        $data = $this->buildViewData();
        $cursor = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(): array
    {
        $sidebarEnabled = ($this->page['sidebar_enabled'] ?? true) !== false;
        $bodyClasses = [];
        if (!$sidebarEnabled) {
            $bodyClasses[] = 'no-sidebar';
        }

        return [
            'site' => [
                'manual_title' => (string) ($this->page['manual_title'] ?? 'Candy框架开发手册'),
                'home_url' => (string) ($this->page['home_url'] ?? '/Doc/'),
                'year' => (int) date('Y'),
            ],
            'tpl' => $this->tpl,
            'static_base' => '/Doc/Static/',
            'article' => [
                'title' => (string) ($this->page['plain_title'] ?? ''),
                'content' => (string) ($this->page['content'] ?? ''),
                'meta_html' => (string) ($this->page['doc_meta'] ?? ''),
                'prev' => is_array($this->page['prev_page'] ?? null) ? $this->page['prev_page'] : [],
                'next' => is_array($this->page['next_page'] ?? null) ? $this->page['next_page'] : [],
                'password' => !empty($this->page['mark']['password'] ?? false),
            ],
            'sidebar' => [
                'enabled' => $sidebarEnabled,
                'nav_html' => (string) ($this->page['nav_html'] ?? ''),
                'logo_html' => (string) ($this->page['logo_html'] ?? ''),
            ],
            'layout' => [
                'body_class' => implode(' ', $bodyClasses),
                'doc_theme' => (string) ($this->page['doc_theme'] ?? ''),
                'html_lang' => (string) ($this->page['html_lang'] ?? 'zh-CN'),
            ],
            'head' => [
                'title' => (string) ($this->page['title_tag'] ?? ''),
                'keywords' => (string) ($this->page['keywords'] ?? ''),
                'description' => (string) ($this->page['description_tag'] ?? ''),
                'canonical' => (string) ($this->page['canonical'] ?? ''),
                'favicon' => (string) ($this->page['favicon'] ?? ''),
                'open_graph' => (string) ($this->page['open_graph'] ?? ''),
                'assets' => (string) ($this->page['head_assets'] ?? ''),
            ],
            'toolbar' => [
                'show_print' => !empty($this->page['show_print']),
                'show_download' => !empty($this->page['show_download']),
                'download_url' => (string) ($this->page['download_url'] ?? ''),
            ],
        ];
    }
}
