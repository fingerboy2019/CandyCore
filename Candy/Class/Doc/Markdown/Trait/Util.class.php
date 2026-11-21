<?php
/***
 * Markdown Util
 *
 * 业务说明：配置、转义、路径与属性解析工具
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Util
{
    private static function setConfig(array $config): void 
    {
        self::$config = array_merge(self::$config, $config);
    }

    private static function getConfig(?string $key = null) {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? false;
    }

    private static function escapeHtml(string $content): string
    {
        return self::$config['escapeHtml'] ? htmlspecialchars($content, ENT_QUOTES, 'UTF-8') : $content;
    }

    /**
     * 获取相对链接解析所用的当前页 URL 目录
     *
     * 由 Core Markdown 按根模式（/）或 /Doc/ 兼容模式注入 pageUrlBase
     *
     * @return string
     * @access private
     * @static
     */
    private static function getLinkBasePath(): string
    {
        if (!empty(self::$config['pageUrlBase'])) {
            return rtrim((string) self::$config['pageUrlBase'], '/');
        }

        return rtrim(self::$pathUrl, '/');
    }

    private static function resolvePath(string $path, $image = false): string
    {
        // 如果是锚点链接（以#开头），直接返回
        if (strpos($path, '#') === 0) {
            return $path;
        }
        
        // 如果是绝对URL（以协议开头），直接返回
        if (preg_match('/^(https?|ftp|ftps|ssh|sftp|git|git\+ssh|tel|telnet|mailto):/', $path)) {
            return $path;
        }
        
        // 如果是绝对文件路径（以/开头），直接返回
        if (strpos($path, '/') === 0) {
            return $path;
        }
        
        // 获取基础路径
        if (empty(self::$config['basePath'])) {
            return $path;
        }
        
        // 解析锚点
        $anchor = '';
        $anchorPos = strpos($path, '#');
        if ($anchorPos !== false) {
            $anchor = substr($path, $anchorPos);
            $path = substr($path, 0, $anchorPos);
        }
        
        // 链接按当前页 URL 目录（url 模式）；图片仍按文档磁盘镜像路径 basePath
        $base = $image
            ? rtrim((string) (self::$config['basePath'] ?? ''), '/')
            : self::getLinkBasePath();
        // 去掉 .md 后缀；仅剥离 ./ 前缀，保留 ../ 上级路径语义
        $relative = preg_replace('/\.md$/i', '', $path) ?? $path;
        if (str_starts_with($relative, './')) {
            $relative = substr($relative, 2);
        }
        $absolutePath = ($base !== '' ? $base . '/' : '/') . $relative;
        
        // 清理路径中的 ../、./ 与因前导 / 产生的空段
        $parts = array_filter(explode('/', $absolutePath), static function (string $part): bool {
            return $part !== '' && $part !== '.';
        });

        $cleanParts = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                if (!empty($cleanParts)) {
                    array_pop($cleanParts);
                }
            } else {
                $cleanParts[] = $part;
            }
        }

        $resolvedPath = '/' . implode('/', $cleanParts) . $anchor;
        
        return $resolvedPath;
    }

    /**
     * 判断真实路径是否位于指定根目录之下
     *
     * @param string $realPath 待校验路径（可未 realpath）
     * @param string $docRoot 根目录
     * @return bool
     * @access private
     * @static
     */
    private static function isPathUnderDocRoot(string $realPath, string $docRoot): bool
    {
        $realFile = realpath($realPath);
        $realRoot = realpath($docRoot);
        if ($realFile === false || $realRoot === false) {
            return false;
        }
        $root = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($realFile, $root);
    }

    private static function parseAttributes(?string $attrs, array $reserve = [], array $ext = []): array
    {
        $result = $reserved = [];
        $attrString = '';

        if(is_null($attrs)) $attrs = '';
        
        if (empty($attrs) && empty($ext)) {
            return [$attrString, $reserved];
        }
        
        if (!empty($attrs)) {
            if (preg_match('/style=(["\'])(.*?)\1/', $attrs, $matches)) {
                $result['style'] = $matches[2];
                $attrs = str_replace($matches[0], '', $attrs);
            }

            if (preg_match('/#([\w-]+)[ ]/', $attrs, $matches)) {
                $result['id'] = $matches[1];
                $attrs = str_replace($matches[0], '', $attrs);
            }
            
            preg_match_all('/\.([\w-]+)/', $attrs, $matches);
            if (!empty($matches[1])) {
                $result['class'] = implode(' ', $matches[1]);
                $attrs = str_replace($matches[0], '', $attrs);
            }

            //旧式写法
            if (strpos($attrs, ':') !== false && strpos($attrs, ';') !== false) {
                $styleParts = explode(';', $attrs);
                foreach ($styleParts as $part) {
                    $part = trim($part);
                    if (empty($part) || strpos($part, ':') === false) continue;
                    [$key, $value] = explode(':', $part, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!empty($key) && !empty($value)) {
                        $ext[trim($key)] = trim($value);
                        $attrs = str_replace($part, '', $attrs);
                    }
                }
            }
            
            preg_match_all('/([\w-]+)=(["\'])(.*?)\2/', $attrs, $matches);
            if (!empty($matches[1])) {
                for ($i = 0; $i < count($matches[1]); $i++) {
                    $ext[$matches[1][$i]] = $matches[3][$i];
                    $attrs = str_replace($matches[0][$i], '', $attrs);
                }
            }
        }
        
        $styleAttributes = ['width','height','max-width','max-height','min-width','min-height','margin','margin-top','margin-right','margin-bottom','margin-left','padding','padding-top','padding-right','padding-bottom','padding-left','border','border-width','border-style','border-color','border-radius','background','background-color','background-image','color','font-size','font-weight','font-family','font-style','text-align','text-decoration','text-indent','line-height','display','position','top','right','bottom','left','z-index','overflow','grid-template-columns','columns','gap','flex','flex-direction','justify-content','align-items','style'];
        foreach ($ext as $key => $value) {
            if (in_array($key, $reserve)) {
                $reserved[$key] = $value;
            } else {
                if ($key === 'class') {
                    if(isset($result['class'])){
                        $result['class'] = $result['class'] . ' ' . $value;
                    } else {
                        $result['class'] = $value;
                    }
                } elseif (in_array($key, $styleAttributes)) {
                    if($key == 'columns') $key = 'grid-template-columns';
                    if(isset($result['style'])){
                        $result['style'] = $result['style'] . ';' . ($key == 'style' ? '' : $key . ':') . $value;
                    } else {
                        $result['style'] = ($key == 'style' ? '' : $key . ':') . $value;
                    }
                } else {
                    // 普通属性处理，包括表单验证属性
                    $attrString .= ' ' . $key . '="' . self::escapeHtml($value) . '"';
                }
            }
        }

        if (count($reserve) === 1 && $reserve[0] === 'all') {
            return $reserved;
        }
        
        foreach ($result as $key => $value) {
            if (in_array($key, $reserve)) {
                $reserved[$key] = $value;
                continue;
            }
            $attrString .= ' ' . $key . '="' . self::escapeHtml($value) . '"';
        }

        $attrs = array_filter(explode(' ',trim($attrs)));
        if(!empty($attrs)){
            // 定义布尔属性列表（HTML5）
            $attributes = ['required', 'disabled', 'readonly', 'checked', 'selected', 'multiple', 'autofocus', 'novalidate', 'formnovalidate', 'autocomplete', 'autocorrect', 'autocapitalize', 'autosave', 'draggable', 'spellcheck', 'translate', 'hidden'];
            foreach($attrs as $attr){
                if(in_array($attr, $attributes)) $attrString .= ' ' . $attr;
            }
        }
        
        return [$attrString, $reserved];
    }

    private static function preprocessText(string $text): string
    {
        // 预处理换行符
        $text = str_replace(["\n\n", "\n"], "\n", $text);

        // 将制表符转换为四个空格，方便统一处理
        $text = str_replace("\t", "    ", $text);

        // 先将所有转义的字符替换为临时标记
        $text = self::replaceEscapedMark($text);
        
        // 解析 Emoji（文档无 :emoji: 语法时跳过整表 strtr）
        if (self::$config['enableEmoji'] && str_contains($text, ':')) {
            $text = strtr($text, self::$emojiMap);
        }
        
        return $text;
    }

    private static function replaceEscapedMark(string $text, bool $rechange = false, $reserve = false): string
    {
        $startHeader = $reserve ? '\\' : '';
        // 将临时标记替换回原始字符
        $escapedChars = $rechange ? [
            '<!--escaped-backslash-->'      => $startHeader . '\\',
            '<!--escaped-colon-->'          => $startHeader . ':',
            '<!--escaped-asterisk-->'       => $startHeader . '*',
            '<!--escaped-underscore-->'     => $startHeader . '_',
            '<!--escaped-hash-->'           => $startHeader . '#',
            '<!--escaped-plus-->'           => $startHeader . '+',
            '<!--escaped-minus-->'          => $startHeader . '-',
            '<!--escaped-dot-->'            => $startHeader . '.',
            '<!--escaped-exclamation-->'    => $startHeader . '!',
            '<!--escaped-pipe-->'           => $startHeader . '|',
            '<!--escaped-bracket-left-->'   => $startHeader . '[',
            '<!--escaped-bracket-right-->'  => $startHeader . ']',
            '<!--escaped-brace-left-->'     => $startHeader . '{',
            '<!--escaped-brace-right-->'    => $startHeader . '}',
            '<!--escaped-parenthesis-left-->'  => $startHeader . '(',
            '<!--escaped-parenthesis-right-->' => $startHeader . ')',
            '<!--escaped-backtick-->'          => $startHeader . '`',
            '<!--escaped-caret-->'          => $startHeader . '^',
            '<!--escaped-tilde-->'          => $startHeader . '~',
            '<!--escaped-greater-->'          => $startHeader . '>',
            '<!--escaped-equal-->'          => $startHeader . '=',
            '<!--escaped-header1-->'    => $startHeader . '#',
            '<!--escaped-header2-->'    => $startHeader . '##',
            '<!--escaped-header3-->'    => $startHeader . '###',
            '<!--escaped-header4-->'    => $startHeader . '####',
            '<!--escaped-header5-->'    => $startHeader . '#####',
            '<!--escaped-header6-->'    => $startHeader . '######',
            '<!--escaped-code-block-->' => '```'
        ] : [
            '\\\\' => '<!--escaped-backslash-->',
            '\\:'   => '<!--escaped-colon-->',
            '\\*'   => '<!--escaped-asterisk-->',
            '\\_'   => '<!--escaped-underscore-->',
            '\\#'   => '<!--escaped-hash-->',
            '\\+'   => '<!--escaped-plus-->',
            '\\-'   => '<!--escaped-minus-->',
            '\\.'   => '<!--escaped-dot-->',
            '\\!'   => '<!--escaped-exclamation-->',
            '\\|'   => '<!--escaped-pipe-->',
            '\\['   => '<!--escaped-bracket-left-->',
            '\\]'   => '<!--escaped-bracket-right-->',
            '\\{'   => '<!--escaped-brace-left-->',
            '\\}'   => '<!--escaped-brace-right-->',
            '\\('   => '<!--escaped-parenthesis-left-->',
            '\\)'   => '<!--escaped-parenthesis-right-->',
            '\\`'   => '<!--escaped-backtick-->',
            '\\^'   => '<!--escaped-caret-->',
            '\\~'   => '<!--escaped-tilde-->',
            '\\>'   => '<!--escaped-greater-->',
            '\\='   => '<!--escaped-equal-->',
            '\\#'   => '<!--escaped-header1-->',
            '\\##'   => '<!--escaped-header2-->',
            '\\###'   => '<!--escaped-header3-->',
            '\\####'   => '<!--escaped-header4-->',
            '\\#####'   => '<!--escaped-header5-->',
            '\\######'   => '<!--escaped-header6-->',
            '+```' => '<!--escaped-code-block-->'
        ];

        $text = strtr($text, $escapedChars);

        //消除所有占位
        //if($rechange && stripos('<!--', $text)) $text = strtr($text, self::$allBlocks);
        
        return $text;
    }
}
