<?php
/***
 * Markdown 特性静态资源
 *
 * 业务说明：代码高亮、图表、幻灯片等按 Front Matter 按需注入，Doc/Blog 共用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 06:10:00 $
 *
 * @package Candy\Extend\Doc\Markdown
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Features
{
    /**
     * 按解析标记组装特性资源 HTML
     *
     * @param array $mark Markdown 解析标记
     * @param string $prismTheme Prism 主题 CSS 文件名（不含 .min.css）
     * @return string
     */
    public static function bundle(array $mark, string $prismTheme = 'prism-tomorrow'): string
    {
        $html = '';
        if (!empty($mark['code'])) {
            $html .= self::prismBundle($prismTheme);
        }
        if (!empty($mark['mermaid'])) {
            $html .= self::mermaidBundle();
        }
        if (!empty($mark['math'])) {
            $html .= self::mathBundle();
        }
        if (!empty($mark['flow'])) {
            $html .= '<script src="/Doc/Static/vendor/raphael/raphael.min.js"></script>'
                . '<script src="/Doc/Static/vendor/flowchart/flowchart.min.js"></script>';
        }
        if (!empty($mark['markmap'])) {
            $html .= '<script src="/Doc/Static/vendor/markmap/d3.min.js"></script>'
                . '<script src="/Doc/Static/vendor/markmap/markmap-view.min.js"></script>'
                . '<script src="/Doc/Static/vendor/markmap/markmap-lib.min.js"></script>'
                . '<script>var transformer = new markmap.Transformer((markmap.builtInPlugins || []).filter(function (p) { return p && p.name !== \'hljs\'; }));</script>';
        }
        if (!empty($mark['chartjs'])) {
            $html .= '<script src="/Doc/Static/vendor/chartjs/chart.umd.js"></script>'
                . '<script src="/Doc/Static/js/chart.util.js"></script>';
        }
        if (!empty($mark['echarts'])) {
            $html .= '<script src="/Doc/Static/vendor/echarts/echarts.min.js"></script>';
        }
        if (!empty($mark['slideshow'])) {
            $html .= self::slideshowBundle($mark);
        }

        $html .= (string) ($mark['include'] ?? '');

        $docLoaded = self::slideshowInitScript($mark);
        if ($docLoaded !== '') {
            $html .= '<script>document.addEventListener("DOMContentLoaded",function(){' . $docLoaded . '});</script>';
        }

        return $html;
    }

    /**
     * @param string $prismTheme Prism 主题名
     * @return string
     */
    private static function prismBundle(string $prismTheme): string
    {
        $theme = htmlspecialchars($prismTheme, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<script src="/Doc/Static/vendor/prism/prism.min.js"></script>
<script src="/Doc/Static/vendor/prism/plugins/autoloader/prism-autoloader.min.js"></script>
<script>window.Prism = window.Prism || {}; window.Prism.plugins = window.Prism.plugins || {}; window.Prism.plugins.autoloader = { languages_path: '/Doc/Static/vendor/prism/components/' };</script>
<link href="/Doc/Static/vendor/prism/themes/{$theme}.min.css" rel="stylesheet">
<link rel="stylesheet" href="/Doc/Static/vendor/prism/plugins/line-numbers/prism-line-numbers.min.css">
<script src="/Doc/Static/vendor/prism/plugins/line-numbers/prism-line-numbers.min.js"></script>
<link rel="stylesheet" href="/Doc/Static/vendor/prism/plugins/toolbar/prism-toolbar.min.css">
<script src="/Doc/Static/vendor/prism/plugins/toolbar/prism-toolbar.min.js"></script>
<script src="/Doc/Static/vendor/prism/plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js"></script>
<script src="/Doc/Static/vendor/prism/plugins/match-braces/prism-match-braces.min.js"></script>
<link rel="stylesheet" href="/Doc/Static/vendor/prism/plugins/treeview/prism-treeview.min.css">
<script>Prism.highlightAll({async:(data)=>Prism.highlight(data.code,data.grammar)});</script>
HTML;
    }

    /**
     * @return string
     */
    private static function mermaidBundle(): string
    {
        return <<<HTML
<script src="/Doc/Static/vendor/mermaid/mermaid.min.js"></script>
<script>mermaid.initialize({startOnLoad:true,theme:'default',securityLevel:'loose',flowchart:{useMaxWidth:true,htmlLabels:true},sequence:{showSequenceNumbers:false}});</script>
HTML;
    }

    /**
     * @return string
     */
    private static function mathBundle(): string
    {
        return <<<HTML
<script id="MathJax-script" async src="/Doc/Static/vendor/mathjax/tex-mml-chtml.js"></script>
<script>window.MathJax={tex:{inlineMath:[['$','$']],displayMath:[['$$','$$']]},chtml:{fontURL:'https://cdn.jsdelivr.net/npm/mathjax@3/es5/output/chtml/fonts/woff-v2'}};</script>
HTML;
    }

    /**
     * @param array $mark 解析标记
     * @return string
     */
    private static function slideshowBundle(array $mark): string
    {
        $theme = (string) (($mark['slideshowConfig']['theme'] ?? null) ?: 'black');

        return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js/dist/reveal.min.css">'
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js/dist/theme/' . htmlspecialchars($theme, ENT_QUOTES) . '.min.css">'
            . '<script src="https://cdn.jsdelivr.net/npm/reveal.js/dist/reveal.min.js"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/reveal.js/plugin/markdown/markdown.min.js"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/reveal.js/plugin/notes/notes.min.js"></script>'
            . '<style>.reveal-container{position:relative;width:100%;height:600px;margin:20px 0;overflow:hidden;border:1px solid #eee;}'
            . '.reveal-container .reveal-viewport{position:absolute;top:0;left:0;width:100%;height:100%;}'
            . 'html.reveal-full-page{height:auto!important;overflow:auto!important;}'
            . 'body{overflow:auto!important;}'
            . '.reveal-container .reveal{position:relative!important;width:100%!important;height:100%!important;margin:0 auto!important;}</style>';
    }

    /**
     * @param array $mark 解析标记
     * @return string
     */
    private static function slideshowInitScript(array $mark): string
    {
        if (empty($mark['slideshow'])) {
            return '';
        }

        $js = "Reveal.initialize({hash:true,transition:'slide',controls:true,progress:true,slideNumber:true,center:true,touch:true,loop:false,embedded:true,";
        $config = is_array($mark['slideshowConfig'] ?? null) ? $mark['slideshowConfig'] : [];
        foreach ($config as $key => $value) {
            if ($key === 'theme') {
                continue;
            }
            if (is_numeric($value) || $value === 'true' || $value === 'false' || $value === 'null') {
                $js .= $key . ':' . $value . ',';
            } else {
                $js .= $key . ":'" . str_replace("'", "\\'", (string) $value) . "',";
            }
        }
        $js .= 'plugins:[RevealMarkdown,RevealNotes]});';

        return $js;
    }
}
