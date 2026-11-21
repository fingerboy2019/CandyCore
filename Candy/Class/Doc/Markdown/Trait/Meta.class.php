<?php
/***
 * Markdown Meta
 *
 * 业务说明：元数据、YAML 与密码页
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:55:00 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Meta
{
    private static function parseMetaData(string &$text): array
    {
        $metaData = [];
        
        // 检查文本是否以---开头
        if (strpos($text, '---') === 0) {
            // 查找元数据结束标记
            $endPos = strpos($text, '---', 3);
            
            if ($endPos !== false) {
                // 提取元数据部分
                $metaText = substr($text, 3, $endPos - 3);
                
                // 解析YAML元数据
                $metaData = self::parseYaml($metaText);
                
                // 从文本中移除元数据部分
                $text = substr($text, $endPos + 3);
            }
        }
        
        return $metaData;
    }

    private static function parseYaml(string $yaml): array
    {
        $metaData = [];
        
        // 按行分割YAML文本
        $lines = explode("\n", $yaml);
        
        foreach ($lines as $line) {
            // 跳过空行和注释
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            // 查找冒号位置
            $colonPos = strpos($line, ':');
            
            if ($colonPos !== false) {
                // 提取键和值
                $key = trim(substr($line, 0, $colonPos));
                $value = trim(substr($line, $colonPos + 1));
                
                // 处理值
                if (!empty($value)) {
                    // 尝试转换为合适的类型
                    if (is_numeric($value)) {
                        $value = $value + 0;
                    } elseif (strtolower($value) === 'true') {
                        $value = true;
                    } elseif (strtolower($value) === 'false') {
                        $value = false;
                    } elseif (strtolower($value) === 'null') {
                        $value = null;
                    }
                    
                    $metaData[$key] = $value;
                }
            }
        }
        
        return $metaData;
    }

    /**
     * 将 Front Matter 映射到解析器配置与规范化字段
     *
     * 支持 toc、toc_depth、sidebar、lang 等页面级开关
     * sidebar 与 lang 未设置时使用文档站默认值
     *
     * @param array $metaData 原始元数据
     * @return array 规范化后的元数据
     * @access private
     * @static
     */
    private static function applyMetaToConfig(array $metaData): array
    {
        if (array_key_exists('toc', $metaData)) {
            self::setConfig(['enableToc' => self::metaToBool($metaData['toc'], true)]);
        }

        if (isset($metaData['toc_depth'])) {
            $depth = max(1, min(6, (int) $metaData['toc_depth']));
            self::setConfig(['tocDepth' => $depth]);
            $metaData['toc_depth'] = $depth;
        }

        if (!array_key_exists('sidebar', $metaData)) {
            $metaData['sidebar'] = true;
        } else {
            $metaData['sidebar'] = self::metaToBool($metaData['sidebar'], true);
        }

        if (empty($metaData['lang'])) {
            $metaData['lang'] = 'zh-CN';
        }

        if (!empty($metaData['theme'])) {
            $theme = strtolower(trim((string) $metaData['theme']));
            $metaData['theme'] = in_array($theme, ['dark', 'light'], true) ? $theme : 'light';
        }

        if (array_key_exists('cache', $metaData)) {
            self::setConfig(['useCache' => self::metaToBool($metaData['cache'], false)]);
        }

        if (isset($metaData['cache_time'])) {
            self::setConfig(['cacheTtl' => max(0, (int) $metaData['cache_time'])]);
            $metaData['cache_time'] = max(0, (int) $metaData['cache_time']);
        }

        return $metaData;
    }

    /**
     * 判断是否启用解析结果缓存
     *
     * DEBUG 模式下强制关闭，避免开发期缓存干扰
     *
     * @param array $metaData 元数据
     * @return bool
     * @access private
     * @static
     */
    private static function shouldUseParserCache(array $metaData): bool
    {
        if (defined('DEBUG') && DEBUG) {
            return false;
        }

        return (bool) self::getConfig('useCache');
    }

    /**
     * 仅解析文本顶部 Front Matter，不渲染正文
     *
     * @param string $text Markdown 原文
     * @return array 规范化后的元数据
     * @access public
     * @static
     */
    public static function peekMetaFromText(string $text): array
    {
        $copy = $text;

        return self::applyMetaToConfig(self::parseMetaData($copy));
    }

    /**
     * 校验文档密码（Session）
     *
     * @param string $rawText 含 Front Matter 的原文
     * @param array $metaData 元数据
     * @return bool 通过返回 true
     * @access public
     * @static
     */
    public static function verifyDocPassword(string $rawText, array $metaData): bool
    {
        if (empty($metaData['password'])) {
            return true;
        }

        $docKey = md5($rawText);
        $passInfo = session('doc_password');

        return isset($passInfo[$docKey]) && $passInfo[$docKey] == $metaData['password'];
    }

    /**
     * 判断元数据功能开关是否启用
     *
     * @param array $meta 元数据
     * @param string $feature 字段名（download、print 等）
     * @return bool
     * @access public
     * @static
     */
    public static function isMetaFeatureEnabled(array $meta, string $feature): bool
    {
        return array_key_exists($feature, $meta) && self::metaToBool($meta[$feature], false);
    }

    /**
     * 将元数据中的 css/js 注入页面资源区
     *
     * 追加到 $mark['include']，与正文 @link/@script 标签行为一致
     *
     * @param array $metaData 元数据
     * @return void
     * @access private
     * @static
     */
    private static function applyMetaAssets(array $metaData): void
    {
        if (!empty($metaData['css'])) {
            foreach (self::metaToPathList($metaData['css']) as $cssFile) {
                self::$mark['include'] .= '<link rel="stylesheet" href="'
                    . htmlspecialchars($cssFile, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
            }
        }

        if (!empty($metaData['js'])) {
            foreach (self::metaToPathList($metaData['js']) as $jsFile) {
                self::$mark['include'] .= '<script src="'
                    . htmlspecialchars($jsFile, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
            }
        }
    }

    /**
     * 根据元数据生成文档页模板变量
     *
     * 输出 htmlLang、favicon、Open Graph、文档作者日期、侧栏与 logo 等
     * og 字段缺省时回退到页面 title/description
     *
     * @param array $meta 元数据
     * @param string $plainTitle 纯文本标题
     * @param string $plainDescription 纯文本描述
     * @param string $requestUri 当前请求 URI，用于生成下载链接
     * @return array 模板变量键值对
     * @access public
     * @static
     */
    public static function buildDocTemplateVars(
        array $meta,
        string $plainTitle,
        string $plainDescription = '',
        string $requestUri = ''
    ): array
    {
        $htmlLang = htmlspecialchars((string) ($meta['lang'] ?? 'zh-CN'), ENT_QUOTES, 'UTF-8');

        $favicon = '';
        if (!empty($meta['favicon'])) {
            $favicon = '<link rel="icon" href="'
                . htmlspecialchars((string) $meta['favicon'], ENT_QUOTES, 'UTF-8') . '">';
        } else {
            // 默认 favicon（无 front matter 配置时）
            $favicon = '<link rel="icon" type="image/svg+xml" href="/Doc/Static/img/favicon.svg">';
        }

        $ogTitle = htmlspecialchars((string) ($meta['og_title'] ?? $plainTitle), ENT_QUOTES, 'UTF-8');
        $ogDesc = htmlspecialchars((string) ($meta['og_description'] ?? $plainDescription), ENT_QUOTES, 'UTF-8');
        $openGraph = '<meta property="og:title" content="' . $ogTitle . '">';
        if ($ogDesc !== '') {
            $openGraph .= "\n        " . '<meta property="og:description" content="' . $ogDesc . '">';
        }
        if (!empty($meta['og_image'])) {
            $openGraph .= "\n        " . '<meta property="og:image" content="'
                . htmlspecialchars((string) $meta['og_image'], ENT_QUOTES, 'UTF-8') . '">';
        }
        if (!empty($meta['og_url'])) {
            $openGraph .= "\n        " . '<meta property="og:url" content="'
                . htmlspecialchars((string) $meta['og_url'], ENT_QUOTES, 'UTF-8') . '">';
        }
        if (!empty($meta['twitter_card'])) {
            $openGraph .= "\n        " . '<meta name="twitter:card" content="'
                . htmlspecialchars((string) $meta['twitter_card'], ENT_QUOTES, 'UTF-8') . '">';
        }

        $docMetaParts = [];
        if (!empty($meta['author'])) {
            $docMetaParts[] = '作者：' . htmlspecialchars((string) $meta['author'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($meta['date'])) {
            $docMetaParts[] = '创建：' . htmlspecialchars((string) $meta['date'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($meta['update'])) {
            $docMetaParts[] = '更新：' . htmlspecialchars((string) $meta['update'], ENT_QUOTES, 'UTF-8');
        }
        if (!empty($meta['category'])) {
            $catLabel = htmlspecialchars((string) $meta['category'], ENT_QUOTES, 'UTF-8');
            if (!empty($meta['category_url'])) {
                $catUrl = htmlspecialchars((string) $meta['category_url'], ENT_QUOTES, 'UTF-8');
                $docMetaParts[] = '分类：<a href="' . $catUrl . '" class="doc-meta-link">' . $catLabel . '</a>';
            } else {
                $docMetaParts[] = '分类：' . $catLabel;
            }
        }
        if (!empty($meta['tags'])) {
            $tagList = is_array($meta['tags'])
                ? $meta['tags']
                : self::metaToPathList($meta['tags']);
            $tagList = array_map(
                static fn ($tag) => htmlspecialchars((string) $tag, ENT_QUOTES, 'UTF-8'),
                $tagList
            );
            if ($tagList !== []) {
                $docMetaParts[] = '标签：' . implode('、', $tagList);
            }
        }
        $docMeta = $docMetaParts === []
            ? ''
            : '<div class="doc-meta">' . implode(' · ', $docMetaParts) . '</div>';

        $sidebarEnabled = !isset($meta['sidebar']) || $meta['sidebar'] !== false;

        $logoHtml = '';
        if (!empty($meta['logo'])) {
            $logoHtml = '<img src="'
                . htmlspecialchars((string) $meta['logo'], ENT_QUOTES, 'UTF-8')
                . '" alt="logo" class="doc-logo">';
        }

        $docTheme = '';
        if (!empty($meta['theme']) && in_array($meta['theme'], ['light', 'dark'], true)) {
            $docTheme = (string) $meta['theme'];
        }

        $showPrint = self::isMetaFeatureEnabled($meta, 'print');
        $showDownload = self::isMetaFeatureEnabled($meta, 'download');
        $downloadUrl = '';
        if ($showDownload && $requestUri !== '') {
            $baseUri = preg_replace('/\?.*$/', '', $requestUri) ?? $requestUri;
            // 下载须为绝对路径，防止无 / 前缀时被浏览器相对解析
            if (!str_starts_with($baseUri, '/') && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $baseUri)) {
                $baseUri = '/' . ltrim($baseUri, '/');
            }
            $downloadUrl = htmlspecialchars($baseUri . '?download=1', ENT_QUOTES, 'UTF-8');
        }

        // Canonical 链接：requestUri 相对路径时补全域名，避免重复内容
        $canonical = '';
        if ($requestUri !== '') {
            $canonicalHref = (string) $requestUri;
            if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $canonicalHref)) {
                $host = (string) (($_SERVER['HTTP_HOST'] ?? '') ?: 'localhost');
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $canonicalHref = $scheme . '://' . $host . ($canonicalHref === '/' ? '/' : '/' . ltrim($canonicalHref, '/'));
            }
            $canonical = '<link rel="canonical" href="'
                . htmlspecialchars($canonicalHref, ENT_QUOTES, 'UTF-8') . '">';
        }

        return [
            'htmlLang' => $htmlLang,
            'canonical' => $canonical,
            'favicon' => $favicon,
            'openGraph' => $openGraph,
            'docMeta' => $docMeta,
            'sidebarEnabled' => $sidebarEnabled,
            'logoHtml' => $logoHtml,
            'docTheme' => $docTheme,
            'showPrint' => $showPrint,
            'showDownload' => $showDownload,
            'downloadUrl' => $downloadUrl,
        ];
    }

    /**
     * 元数据布尔值规范化
     *
     * @param mixed $value 原始值
     * @param bool $default 无法识别时的默认值
     * @return bool
     * @access private
     * @static
     */
    private static function metaToBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * 将元数据中的资源路径转为列表
     *
     * 支持逗号分隔字符串或数组
     *
     * @param mixed $value css/js 元数据值
     * @return array 路径列表
     * @access private
     * @static
     */
    private static function metaToPathList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    private static function showPasswordPrompt(string $docKey): string
    {
        $html = <<<HTML
        <div class="password-prompt">
            <div class="password-container">
                <h3>文档需要密码</h3>
                <p>请输入文档密码以继续查看</p>
                <form method="post" action="">
                    <input type="hidden" name="doc_key" value="$docKey">
                    <input type="password" name="doc_password" placeholder="请输入密码" required>
                    <button type="submit">提交</button>
                </form>
            </div>
        </div>
        HTML;
        
        return $html;
    }
}
