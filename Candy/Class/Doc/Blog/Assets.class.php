<?php
/***
 * 博客页面静态资源片段
 *
 * 业务说明：根据 Markdown 解析标记组装 head 内 link/script
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 05:48:00 $
 *
 * @package Candy\Extend\Doc\Blog
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Assets
{
    /**
     * 构建博客页 head 资源 HTML
     *
     * @param array $mark Markdown 解析标记
     * @param string $staticBase 静态资源前缀
     * @param string $tplName 页面模板 index|post|list|404
     * @param string $basePath 博客 URL 前缀（如 /Blog/）
     * @param string $siteName 站点名（RSS alternate title）
     * @return string
     */
    public static function headHtml(
        array $mark,
        string $staticBase,
        string $tplName = 'post',
        string $basePath = '/Blog/',
        string $siteName = 'Blog'
    ): string {
        $jsVer = (string) (@filemtime(C('CANDYROOT') . 'Doc/Blog/Static/js/blog.js') ?: time());
        $rssUrl = rtrim($basePath, '/') . '/rss.xml';
        $html = '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>'
            . '<link type="text/css" rel="stylesheet" href="' . $staticBase . 'css/blog.css">'
            . '<link type="text/css" rel="stylesheet" href="' . $staticBase . 'css/blog-print.css" media="print">'
            . '<link rel="alternate" type="application/rss+xml" title="'
            . htmlspecialchars($siteName . ' RSS', ENT_QUOTES, 'UTF-8')
            . '" href="' . htmlspecialchars($rssUrl, ENT_QUOTES, 'UTF-8') . '">'
            . '<script src="' . $staticBase . 'js/blog.js?v=' . $jsVer . '" defer></script>';

        if ($tplName === 'index') {
            $html .= '<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js" defer></script>'
                . '<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/renderers/CSS3DRenderer.js" defer></script>'
                . '<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/TrackballControls.js" defer></script>'
                . '<script src="https://cdn.jsdelivr.net/npm/@tweenjs/tween.js@18.6.4/dist/tween.umd.js" defer></script>'
                . '<script src="' . $staticBase . 'js/wordsphere.js" defer></script>';
        }

        $html .= \Candy\Extend\Doc\Markdown\Features::bundle($mark, 'prism-tomorrow');

        return $html;
    }
}
