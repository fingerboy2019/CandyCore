<?php
/***
 * 文档站页面静态资源片段
 *
 * 业务说明：Bootstrap、文档主题 CSS 与 Markdown 特性资源
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 *
 * @package Candy\Extend\Doc\Site
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Site;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Assets
{
    /** @var array<int, string> 可切换主题白名单 */
    private const THEMES = [
        'default', 'academic', 'bauhaus', 'brutalism', 'cyberpunk',
        'film', 'glass', 'knowledge', 'luxury', 'morandi', 'shopping', 'wemd',
    ];

    /**
     * 用户选择的主题（cookie doc_theme，白名单校验）
     *
     * @param string $fallback 文章声明 style 或 default
     * @return string
     */
    public static function selectedTheme(string $fallback = 'default'): string
    {
        $cookie = trim((string) (I('cookie.doc_theme', '', '') ?: ($_COOKIE['doc_theme'] ?? '')));
        if ($cookie !== '' && in_array($cookie, self::THEMES, true)) {
            return $cookie;
        }

        return in_array($fallback, self::THEMES, true) ? $fallback : 'default';
    }

    /**
     * 构建文档页 head 资源 HTML
     *
     * 主题优先级：用户 cookie 选择 > 文章 front matter style > default
     *
     * @param array $mark Markdown 解析标记
     * @param string $styleCss 主题样式文件名（不含 .css）
     * @return string
     */
    public static function headHtml(array $mark, string $styleCss = 'default'): string
    {
        $styleCss = self::selectedTheme($styleCss);

        $html = '<link href="/Doc/Static/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">'
            . '<script src="/Doc/Static/vendor/bootstrap/bootstrap.bundle.min.js"></script>'
            . '<script src="/Doc/Static/js/onloaded.min.js?v=' . self::version('js/onloaded.min.js') . '"></script>'
            . '<link type="text/css" rel="stylesheet" href="/Doc/Static/css/' . $styleCss . '.css?v=' . self::version('css/' . $styleCss . '.css') . '" id="doc-theme-css">'
            . '<link type="text/css" rel="stylesheet" href="/Doc/Static/css/print.css?v=' . self::version('css/print.css') . '" media="print">'
            . '<script src="/Doc/Static/js/theme.js?v=' . self::version('js/theme.js') . '" defer></script>';

        return $html . \Candy\Extend\Doc\Markdown\Features::bundle($mark, 'prism-okaidia');
    }

    /**
     * 静态资源版本号（文件 mtime），CSS/JS 变更后自动失效浏览器缓存
     *
     * @param string $rel Doc/Static 下的相对路径（如 css/default.css）
     * @return string 版本串（mtime 时间戳，失败回退为空）
     */
    public static function version(string $rel): string
    {
        $rel = ltrim($rel, '/');
        $path = rtrim((string) (C('CANDYROOT') ?: ''), '/\\') . '/Doc/Static/' . $rel;
        $mtime = is_file($path) ? @filemtime($path) : false;

        return $mtime === false ? '' : (string) $mtime;
    }
}
