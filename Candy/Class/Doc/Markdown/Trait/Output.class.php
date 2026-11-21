<?php
/***
 * Markdown Output
 *
 * 业务说明：对外 API、缓存与完整 HTML 输出
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Output
{
    public static function parse(string $text, bool $full = true, ?string $cacheSalt = null): string
    {
        // 先解析元数据，以便 cache / toc 等配置参与后续流程
        $metaData = self::parseMetaData($text);
        $metaData = self::applyMetaToConfig($metaData);
        isset($metaData['style']) OR $metaData['style'] = 'default';

        $cacheData = null;
        $cacheTtl = max(0, (int) self::getConfig('cacheTtl'));
        self::$cache = self::shouldUseParserCache($metaData) ? Candy\Core\Cache::store() : false;
        if (self::$cache != false) {
            $tmpKey = md5(self::PARSER_VERSION . '|' . ($cacheSalt ?? '') . '|' . $text);
            $cacheData = self::$cache->get($tmpKey, null);
        }

        //返回缓存
        if(is_null($cacheData) === false){
            self::$mark = $cacheData['mark'];
            return $cacheData['html'];
        }

        // 检查是否需要密码
        if (isset($metaData['password']) && !empty($metaData['password'])) {
            // 检查session中是否存在当前文档的密码
            $docKey = md5($text);
            $passInfo = session('doc_password');
            if (!isset($passInfo[$docKey]) || $passInfo[$docKey] != $metaData['password']) {
                self::initializeData();
                self::$mark['password'] = true;
                self::$mark['meta'] = $metaData;
                self::applyMetaAssets($metaData);
                // 输出密码输入提示
                return self::showPasswordPrompt($docKey);
            }
            unset($metaData['password']);
        }

        $text = self::parseHtml($text);
        self::applyMetaAssets($metaData);

        //生成导航和脚注
        $html = self::generateFullHtml($text, $full);

        //存储到self::$mark数组中
        self::$mark['meta'] = $metaData;

        // 缓存数据
        if(self::$cache != false){
            self::$cache->set($tmpKey, ['html'=>$html,'mark'=>self::$mark], $cacheTtl);
        }
        
        return $html;
    }

    public static function parseFile(string $filePath,bool $full = true, array $config = []): array
    {
        // 设置基础路径为当前文件所在目录
        isset($config['basePath']) OR $config['basePath'] = str_replace(C('CANDYROOT'),'/',dirname($filePath));
        
        if (!file_exists($filePath)) {
            showExceptionMsg('halt', '文档:'. str_replace(C('CANDYROOT'), '', $filePath) .'不存在!');
            stop();
        }
        
        if(count($config) > 0) {
            self::setConfig($config);
        }

        $mtime = @filemtime($filePath) ?: 0;
        $urlScope = (string) (self::getConfig('urlBasePath') ?? '') . '|' . (string) (self::getConfig('pageUrlBase') ?? '');
        $cacheSalt = $filePath . '@' . $mtime . '@' . ($full ? '1' : '0') . '@' . $urlScope;
        $content = self::parse(file_get_contents($filePath), $full, $cacheSalt);
        
        return [self::$mark, $content];
    }

    private static function generateFullHtml(string $html,bool $full): string
    {
        // 循环结束后，添加脚注列表
        if (!empty(self::$footnotes)) {
            $html .= '<hr><div class="footnotes"><ol>';
            foreach (self::$footnotes as $id => $content) {
                $html .= '<li id="fn-' . $id . '">';
                $html .= self::parseInlineElements($content, false, false);
                $html .= '<a href="#fnref-' . $id . '" class="footnote-backref">↩</a>';
                $html .= '</li>';
            }
            $html .= '</ol></div>';
        }

        // 生成目录 HTML（meta toc: false 时不输出）
        $tocHtml = '';
        if (!empty(self::$toc) && self::getConfig('enableToc') !== false) {
            $tocHtml = '<div class="toc-container">';
            $tocHtml .= '<button class="toc-toggle" aria-label="Toggle Table of Contents" title="目录"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="5" cy="6" r="1.2"/><circle cx="5" cy="12" r="1.2"/><circle cx="5" cy="18" r="1.2"/></svg></button>';
            $tocHtml .= '<div class="toc">';
            $tocHtml .= '<div class="toc-header"><span class="toc-header-title">' . self::escapeHtml(self::$config['docName'] ?? '目录') . '</span></div>';
            $tocHtml .= '<ul>';
            $lastLevel = 1;
            $stack = [];
            foreach (self::$toc as $item) {
                $currentLevel = $item['level'];
                if ($currentLevel > $lastLevel) {
                    for ($i = $lastLevel; $i < $currentLevel; $i++) {
                        $tocHtml .= '<ul>';
                        array_push($stack, $i);
                    }
                } elseif ($currentLevel < $lastLevel) {
                    for ($i = $lastLevel; $i > $currentLevel; $i--) {
                        $tocHtml .= '</ul></li>';
                        array_pop($stack);
                    }
                } else {
                    if ($lastLevel != 1) {
                        $tocHtml .= '</li>';
                    }
                }
                $tocHtml .= '<li><a href="#' . $item['anchorId'] . '">' . self::formatInlineStyleContent($item['title']) . '</a>';
                $lastLevel = $currentLevel;
            }
            for ($i = count($stack); $i > 0; $i--) {
                $tocHtml .= '</ul></li>';
            }
            $tocHtml .= '</ul>';
            $tocHtml .= '</div>';
            $tocHtml .= '</div>';
        }

        //面包屑（首页与各级链接跟随 urlBasePath：根模式 / 或 /Doc/ 兼容模式）
        $urlBase = rtrim((string) (self::$config['urlBasePath'] ?? ''), '/');
        $homeHref = $urlBase === '' ? '/' : $urlBase . '/';
        $uri = urldecode(trim((string)I('server.REQUEST_URI', '', ''), '/'));
        if (stripos($uri, '?') !== false) {
            [$uri] = explode('?', $uri, 2);
        }
        $segments = array_values(array_filter(explode('/', $uri), static fn(string $segment): bool => $segment !== ''));
        $path = $urlBase;
        $segpath = '<div class="breadcrumb"><a href="' . htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') . '" class="breadcrumb-item">' . self::escapeHtml(self::$config['docName'] ?? 'Candy框架文档') . '</a>';
        $lastIndex = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($i === $lastIndex) {
                $href = '#';
            } else {
                $path = ($path === '' ? '' : $path) . '/' . $segment;
                $href = $path === '' ? '/' : $path;
            }
            $segpath .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="breadcrumb-item">' . self::escapeHtml($segment) . '</a>';
        }
        $segpath .= '</div>';

        if($full){
            $html = $segpath . $tocHtml . $html;
        } else {
            self::$mark['segpath'] = $segpath;
            self::$mark['tocHtml'] = $tocHtml;
        }

        return self::restoreBlockPlaceholders($html);
    }
}
