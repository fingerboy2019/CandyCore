<?php
/***
 * Markdown Inline
 *
 * 业务说明：行内元素、标题与段落解析
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Inline
{
    private static function parseInlineElements(string $text, bool $withImg = true, $withBr = true): string
    {
        // 确保$text始终是字符串
        if (is_null($text)) return '';

        //自动链接
        $text = self::parseAutoLinks($text);

        //图片
        if($withImg) $text = self::parseImage($text);

        //链接
        $text = self::parseLinks($text);

        //内联样式
        $text = self::parseStyle($text);

        return $text;
    }

    private static function parseAutoLinks(string $text) :string 
    {
         // 处理URL链接
        $text = preg_replace_callback(
            '/<((https?|ftp|ftps|ssh|sftp|git|git\+ssh|telnet):[\/\w\.-@:+%?&=]*)>[ ]+?/i',
            function ($matches) { 
                $url = self::escapeHtml($matches[1]);
                return "<a href=\"$url\" target=\"_blank\">$url</a>";    
            },
            $text
        );

        // 处理电话号码
        $text = preg_replace_callback(
            '/<(tel:[\+\d\.-]+)>/i',
            function ($matches) { 
                $tel = self::escapeHtml(substr($matches[1], 4));
                return "<a href=\"tel:$tel\" target=\"_blank\">$tel</a>";
             },
            $text
        );

        // 处理邮箱地址
        $text = preg_replace_callback(
            '/<([-a-zA-Z0-9._%+-]+@[-a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>/i',
            function ($matches) { 
                $email = self::escapeHtml($matches[1]);
                return "<a href=\"mailto:$email\" target=\"_blank\">$email</a>";
             },
            $text
        );
        return $text;
    }

    private static function parseImage(string $text, array $links = []) :string 
    {
        // 1. 处理标准图片尺寸语法：![alt =widthxheight](url "title"){attrs}
        $text = preg_replace_callback(
            '/!\[([^\]]*)\s*=\s*(\d+)(?:x(\d+))?\s*\]\(([^\s\)]+)(?:\s*["\']([^"\']*)["\'])?\)(?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $alt = self::escapeHtml(trim($matches[1]));
                $width = $matches[2];
                $height = isset($matches[3]) ? $matches[3] : '';
                $src = self::resolvePath($matches[4], true);
                $src = self::escapeHtml($src);
                $title = isset($matches[5]) ? " title=\"" . self::escapeHtml($matches[5]) . "\"" : '';
                
                // 解析属性
                [$attrString] = self::parseAttributes($matches[6] ?? null);
                
                // 构建基本属性
                $widthAttr = $width ? " width=\"$width\"" : '';
                $heightAttr = $height ? " height=\"$height\"" : '';
                return "<figure><img src=\"$src\" alt=\"$alt\"$title$widthAttr$heightAttr$attrString><figcaption>$alt</figcaption></figure>";
            },
            $text
        );
        
        // 2. 处理Obsidian图片尺寸语法：![alt|widthxheight](url "title"){attrs}
        $text = preg_replace_callback(
            '/!\[([^\]]*)\|(\d+)(?:x(\d+))?\]\(([^\s\)]+)(?:\s*["\']([^"\']*)["\'])?\)(?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $alt = self::escapeHtml(trim($matches[1]));
                $width = $matches[2];
                $height = isset($matches[3]) ? $matches[3] : '';
                $src = self::resolvePath($matches[4], true);
                $src = self::escapeHtml($src);
                $title = isset($matches[5]) ? " title=\"" . self::escapeHtml($matches[5]) . "\"" : '';
                
                // 解析属性
                [$attrString] = self::parseAttributes($matches[6] ?? null);
                
                // 构建基本属性
                $widthAttr = $width ? " width=\"$width\"" : '';
                $heightAttr = $height ? " height=\"$height\"" : '';
                return "<figure><img src=\"$src\" alt=\"$alt\"$title$widthAttr$heightAttr$attrString><figcaption>$alt</figcaption></figure>";
            },
            $text
        );
        
        // 3. 处理标准图片尺寸语法（仅高度）：![alt =xheight](url "title"){attrs}
        $text = preg_replace_callback(
            '/!\[([^\]]*)\s*=\s*x(\d+)\s*\]\(([^\s\)]+)(?:\s*["\']([^"\']*)["\'])?\)(?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $alt = self::escapeHtml(trim($matches[1]));
                $height = $matches[2];
                $src = self::resolvePath($matches[3], true);
                $src = self::escapeHtml($src);
                $title = isset($matches[4]) ? " title=\"" . self::escapeHtml($matches[4]) . "\"" : '';
                
                // 解析属性
                [$attrString] = self::parseAttributes($matches[5] ?? null);
                
                // 构建基本属性
                $heightAttr = $height ? " height=\"$height\"" : '';
                
                return "<figure><img src=\"$src\" alt=\"$alt\"$title$heightAttr$attrString><figcaption>$alt</figcaption></figure>";
            },
            $text
        );
        
        // 4. 处理Obsidian图片尺寸语法（仅高度）：![alt|xheight](url "title"){attrs}
        $text = preg_replace_callback(
            '/!\[([^\]]*)\|x(\d+)\]\(([^\s\)]+)(?:\s*["\']([^"\']*)["\'])?\)(?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $alt = self::escapeHtml(trim($matches[1]));
                $height = $matches[2];
                $src = self::resolvePath($matches[3], true);
                $src = self::escapeHtml($src);
                $title = isset($matches[4]) ? " title=\"" . self::escapeHtml($matches[4]) . "\"" : '';
                
                // 解析属性
                [$attrString] = self::parseAttributes($matches[5] ?? null);
                
                // 构建基本属性
                $heightAttr = $height ? " height=\"$height\"" : '';
                
                return "<figure><img src=\"$src\" alt=\"$alt\"$title$heightAttr$attrString><figcaption>$alt</figcaption></figure>";
            },
            $text
        );
        
        // 3. 处理内联图片的标准语法（无尺寸）：![alt](url "title"){attrs}
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^\s\)]+)(?:\s*["\']([^"\']*)["\'])?\)(?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $alt = self::escapeHtml($matches[1]);
                $src = self::resolvePath($matches[2], true);
                $src = self::escapeHtml($src);
                $title = isset($matches[3]) ? " title=\"" . self::escapeHtml($matches[3]) . "\"" : '';
                
                // 解析属性
                [$attrString] = self::parseAttributes($matches[4] ?? null);
                
                return "<figure><img src=\"$src\" alt=\"$alt\"$title$attrString><figcaption>$alt</figcaption></figure>";
            },
            $text
        );

        // 2. 处理引用式图片：![alt][key]{attrs}
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\[([^\]]*)\](?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $key = strtolower(empty($matches[2]) ? $matches[1] : $matches[2]);
                if (isset(self::$links[$key])) {
                    $alt = self::escapeHtml($matches[1]);
                    $src = self::resolvePath(self::$links[$key]['url'], true);
                    $src = self::escapeHtml($src);
                    $title = self::$links[$key]['title'];
                    $title = $title ? " title=\"" . self::escapeHtml($title) . "\"" : '';
                    
                    // 解析属性
                    [$attrString] = self::parseAttributes($matches[3] ?? null);
                    
                    return "<figure><img src=\"$src\" alt=\"$alt\"$title$attrString><figcaption>$alt</figcaption></figure>";
                }
                // 如果找不到引用，则返回原始文本
                return $matches[0];
            },
            $text
        );
        return $text;
    }

    private static function parseLinks(string $text) :string 
    {
        $text = preg_replace_callback(
            '/!badge\[([^\]]+)\](?:\(([^)]+)\))?/i',
            function ($matches) {
                $label = $matches[1];
                $attrs = $matches[2] ?? '';
                
                // 解析属性
                $params = explode(' ', $attrs);
                $type = 'secondary'; // 默认类型
                $link = '';
                $size = '';
                $classes = [];
                
                foreach ($params as $param) {
                    if (empty($param)) continue;
                    
                    // 判断参数类型
                    if (in_array($param, ['primary', 'secondary', 'success', 'warning', 'danger', 'info', 'light', 'dark'])) {
                        $type = $param;
                    } elseif ($param == 'pill') {
                        $size = $param;
                    } elseif (filter_var($param, FILTER_VALIDATE_URL)) {
                        $link = $param;
                    } else {
                        $classes[] = $param;
                    }
                }
                
                // 构建类名
                $badgeClasses = ['badge', "bg-$type"];
                if ($size) $badgeClasses[] = "rounded-pill";
                if (!empty($classes)) $badgeClasses = array_merge($badgeClasses, $classes);
                
                // 构建 HTML
                $badgeHtml = "<span class=\"" . implode(' ', $badgeClasses) . '">' . self::escapeHtml($label) . "</span>";
                
                // 如果有链接，包裹 <a> 标签
                if ($link) {
                    $badgeHtml = "<a href=\"$link\" class=\"badge-link\">$badgeHtml</a>";
                }
                
                return $badgeHtml;
            },
            $text
        );

        // 处理 abbr 模式：?[提示语句](显示文字) 解析后为 <abbr title="提示语句">显示文字</abbr>
        $text = preg_replace_callback(
            '/\?\[([^\]]+)\]\(([^\)]+)\)/',
            function ($matches) {
                $title = $matches[1];
                $text = $matches[2];
                return '<abbr title="' . self::escapeHtml($title) . '">' . self::escapeHtml($text) . '</abbr>';
            },
            $text
        );

        //button
        $text = preg_replace_callback(
            '/\=\[([^\]]+)?\](?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $text = $matches[1];
                // 解析属性
                [$attrString] = self::parseAttributes($matches[2] ?? null);
                return '<button type="button"' . $attrString . '>' . $text . '</button>';
            },
            $text
        ); 

        // 处理引用链接
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\[([^\]]*)\](?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $key = strtolower(empty($matches[2]) ? $matches[1] : $matches[2]);
                if (isset(self::$links[$key])) {
                    $url = self::resolvePath(self::$links[$key]['url']);
                    $url = self::escapeHtml($url);
                    $title = self::$links[$key]['title'];
                    $title = self::escapeHtml($title);
                    $titleAttr = $title ? " title=\"$title\"" : '';
                    $target = strpos($url, 'http') === false ? '' : ' target="_blank"';  //外链添加 _blank
                    
                    // 解析属性
                    [$attrString] = self::parseAttributes($matches[3] ?? null);
                    
                    return '<a href="'. $url . '"' . $titleAttr . $target . $attrString . '>' . $matches[1] . '</a>';
                }
                return $matches[0];
            },
            $text
        );

        // 处理普通链接
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^\s\)]+)(?:\s*["\']([^"\']*)["\'])?\)(?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $text = $matches[1];
                $url = self::resolvePath($matches[2]);
                $url = self::escapeHtml($url);
                $title = isset($matches[3]) ? $matches[3] : '';
                $titleAttr = $title ? " title=\"$title\"" : '';
                $target = strpos($url, 'http') === false ? '' : ' target="_blank"';  //外链添加 _blank
                
                // 解析属性
                [$attrString] = self::parseAttributes($matches[4] ?? null);
                
                return '<a href="'. $url . '"' . $titleAttr . $target . $attrString . '>' . $text . '</a>';
            },
            $text
        );
        
        // 在处理其他替换之后，处理脚注引用
        $text = preg_replace_callback(
            '/\[\^([^\]]+)\]/',
            function ($matches) {
                $key = $matches[1];
                return "<sup><a href=\"#fn-$key\" id=\"fnref-$key\">[$key]</a></sup>";
            },
            $text
        );

        return $text;
    }

    private static function parseStyle(string $text) :string 
    {
        //处理span标签 格式： [[[内容]]]{样式 非必须}
        $text = preg_replace_callback(
            "/\[\[\[([^`\n]+?)\]\]\](\s*\{(.*)\})?/",
            function($matches) {
                [$attrs] = self::parseAttributes($matches[2] ?? null);
                return '<span'. $attrs .'>'. $matches[1] .'</span>';
            },
            $text
        );

        //处理p标签 格式： [[段落]]{样式 非必须}
        $text = preg_replace_callback(
            "/\[\[([^`\n]+?)\]\](\s*\{(.*)\})?/",
            function($matches) {
                [$attrs] = self::parseAttributes($matches[2] ?? null);
                return '<p'. $attrs .'>'. $matches[1] .'</p>';
            },
            $text
        );

        // 支持水平线属性，格式：--- {#id .class style="..."}
        $text = preg_replace_callback(
            '/^([\*\-_])\s*\1{2,}(\s*\{(.*)\})?\s*$/m',
            function($matches) {
                $html = '<hr';
                [$attrs] = self::parseAttributes($matches[3] ?? null);
                $html .= $attrs . '>';
                return $html;
            },
            $text
        );

        // 先处理粗斜体组合
        // 匹配 *** 和 ___ 包裹的文本，确保边界不是字母、数字或下划线
        $text = preg_replace_callback(
            '/(?<!\w)(\*\*\*|___)([^`\n]+?)(?<!\\\\)\1(?!\w)/',
            function ($matches) {
                return '<strong><em>' . self::formatInlineStyleContent($matches[2]) . '</em></strong>';
            },
            $text
        );

        //处理小字号
        $text = preg_replace_callback(
            '/>>([^`\n]+?)<</',
            function ($matches) {
                return '<small>' . self::formatInlineStyleContent($matches[1]) . '</small>';
            },
            $text
        );

        // 优化粗体处理
        // 匹配 ** 和 __ 包裹的文本，确保边界不是字母、数字或下划线
        $text = preg_replace_callback(
            '/(?<!\w)(\*\*|__)([^`\n]+?)(?<!\\\\\\\\)\1(?!\w)/',
            function ($matches) {
                return '<strong>' . self::formatInlineStyleContent($matches[2]) . '</strong>';
            },
            $text
        );

        // 优化斜体处理
        // 匹配 * 包裹的文本，确保边界不是字母、数字或下划线
        $text = preg_replace_callback(
            '/(?<!\w)([*])([^`\n]+?)(?<!\\\)\1(?!\w)/',
            function ($matches) {
                return '<em>' . self::formatInlineStyleContent($matches[2]) . '</em>';
            },
            $text
        );
        
        // 处理删除线文本
        $text = preg_replace_callback(
            "/~~([^`\n]+?)~~/",
            function ($matches) {
                // 递归解析删除线内部的内联元素
                return "<del>" . $matches[1] . "</del>";
            },
            $text
        );

        // 处理粗体文本
        $text = preg_replace_callback(
            "/(?<!\w)(\*\*)([^`\n]+?)(?<!\\\\)\1(?!\w)/",
            function ($matches) {
                return "<strong>" . self::formatInlineStyleContent($matches[2]) . "</strong>";
            },
            $text
        );

        // 处理斜体文本
        $text = preg_replace_callback(
            "/(?<!\w)([*_])([^`\n]+?)(?<!\\\\)\1(?!\w)/",
            function ($matches) {
                return "<em>" . self::formatInlineStyleContent($matches[2]) . "</em>";
            },
            $text
        );
        
        // 处理下划线
        $text = preg_replace_callback(
            "/(?<!\\w)(__)([^`\n]+?)(?<!\\\\)\\1(?!\\w)/",
            function ($matches) {
                // 递归解析下划线内部的内联元素
                return "<u>" . self::formatInlineStyleContent($matches[2]) . "</u>";
            },
            $text
        );
        
        // 高亮标记
        $text = preg_replace_callback(
            '/==([^`\n]+?)==/',
            function ($matches) {
                return '<mark>' . self::formatInlineStyleContent($matches[1]) . '</mark>';
            },
            $text
        );
        
        // 处理上标
        $text = preg_replace_callback(
            '/\^([^`\n]+?)\^/',
            function ($matches) {
                // 递归解析上标内部的内联元素
                return '<sup>' . self::formatInlineStyleContent($matches[1]) . '</sup>';
            },
            $text
        );
        
        // 处理下标
        $text = preg_replace_callback(
            '/~([^`\n]+?)~/',
            function ($matches) {
                // 递归解析下标内部的内联元素
                return '<sub>' . self::formatInlineStyleContent($matches[1]) . '</sub>';
            },
            $text
        );
        
        // 处理剧透标签
        $text = preg_replace_callback(
            '/!!([^`\n]+?)!!/',
            function ($matches) {
                // 递归解析剧透内部的内联元素
                return '<span class="spoiler">&nbsp;' . self::formatInlineStyleContent($matches[1]) . '&nbsp;</span>';
            },
            $text
        );
        
        //kbd 键位提示
        $text = preg_replace_callback(
            '/@@([^`\n]+?)@@/',
            function ($matches) {
                return '<kbd>' . self::escapeHtml($matches[1]) . '</kbd>';
            },
            $text
        );
        
        return $text;
    }

    private static function processInlineElements(string $text, bool $withToc = false): string
    {
        //处理所有标题行
        $text = self::processTitle($text, $withToc);
        
        //处理所有行级元素
        $text = self::parseInlineElements($text);

        //处理所有需要上下文联系的行
        $text = self::parseCorrelationElements($text);

        //转义占位符号
        $text = self::replaceEscapedMark($text, true);

        return $text;
    }

    private static function parseCorrelationElements(string $text): string
    {
        $html = '';
        $lines = explode("\n", $text);
        $inIndentBlock = false; // 使用本地变量而不是静态变量
        foreach ($lines as $line) {
            // 普通段落或空行：统一处理结构关闭
            if (empty(trim($line))) {
                // 结束缩进代码块
                if ($inIndentBlock) {
                    $inIndentBlock = false;
                    $html .= '</code></pre></div>';
                } 
                continue;
            }
            
            // 占位符处理
            if (str_starts_with(trim($line), '<!--')) {
                // 结束缩进代码块
                if ($inIndentBlock) {
                    $inIndentBlock = false;
                    $html .= '</code></pre></div>';
                }
                if (str_ends_with(trim($line), '-->')) {
                    $blockKey = trim($line);
                    if (!isset(self::$allBlocks[$blockKey])) {
                        $html .= self::restoreBlockPlaceholders($line);
                        continue;
                    }
                    $html .= self::$allBlocks[$blockKey];
                    continue;
                } else {
                    $line = self::replaceEscapedMark($line, true);
                }
            }

            if (str_contains($line, '<!--block-')) {
                $line = self::restoreBlockPlaceholders($line);
            }

            $line = preg_replace('/\s{2,}$/', self::$paragraphWrap[2], $line);
            
            //检查是否是缩进代码块
            if(self::$paragraphWrap[3]){
                if (preg_match('/^(\s{4}|\t)(.*)/', $line, $indentMatches)) {
                    if (!$inIndentBlock) {
                        $inIndentBlock = true;
                        $html .= '<div class="mac-code-container"><div class="mac-code-header"><div class="mac-code-buttons"><div class="mac-code-button close"></div><div class="mac-code-button minimize"></div><div class="mac-code-button maximize"></div></div><div class="mac-code-title"><em>code</em></div></div><pre><code class="language-php line-numbers match-braces" data-prismjs-copy="📋"; data-prismjs-copy-success="✅"; data-prismjs-copy-error="❌">';
                    }
                    // 添加内容，剔除第一个四格缩进和转义字符
                    $html .= self::escapeHtml(self::replaceEscapedMark(preg_replace('/^(\s{4}|\t)(.*)/', '$2', $line), true, true)) . "\n";
                    continue;
                } else {
                    // 结束缩进代码块
                    if ($inIndentBlock) {
                        $inIndentBlock = false;
                        $html .= '</code></pre></div>';
                    } 
                }
            }
            
            $html .=  self::$paragraphWrap[0] . preg_replace('/\s{2,}/', self::$paragraphWrap[2], $line) . self::$paragraphWrap[1];
        }
        
        // 如果方法结束时仍在代码块中，关闭它
        if ($inIndentBlock) {
            $html .= '</code></pre></div>';
        }

        return $html;
    }

    private static function processTitle(string $text, bool $withToc = false): string
    {
        $toc = $titleList = [];
        $headingCounter = 0;
        $text = preg_replace_callback(
            '/^(#{1,6})\s+(.*)/m',
            function ($matches) use (&$headingCounter, &$toc, &$titleList) {
                $level = strlen($matches[1]);
                $titleContent = $matches[2];
                
                // 提取标题文本和属性部分
                $titleText = $titleContent;
                $attrsPart = '';
                
                // 初始化变量
                $attrString = '';
                $reserve = [];
                
                // 匹配{attrs}模式
                if (preg_match('/\s*\{([^}]*)\}\s*$/', $titleContent, $attrMatches)) {
                    $titleText = trim(substr($titleContent, 0, -strlen($attrMatches[0])));
                    $attrsPart = $attrMatches[1];
                }
                
                // 处理标题文本（先解析行内元素，再还原可能残留的块占位符供目录使用）
                $titleText = self::restoreBlockPlaceholders(
                    self::parseInlineElements($titleText, false, false)
                );
                
                if(!empty($attrsPart)){
                    // 解析属性
                    [$attrString, $reserve] = self::parseAttributes($attrsPart, ['id']);
                }

                isset($reserve['id']) OR $reserve['id'] = $headingCounter++;
                $attrString .= " id=\"{$reserve['id']}\"";

                $blockKey ='<!--line-title-'. self::$index++ .'-->';
                $title = "<h{$level}{$attrString}><span class=\"prefix\"></span><span class=\"content\">{$titleText}</span><span class=\"suffix\"></span></h{$level}>";

                // 为h1标题添加横线
                if ($level == 1) {
                    $title .= "\n<hr>";
                }

                // 生成HTML标题标签
                $titleList[$blockKey] = $title;
                
                // 记录标题信息到目录数组，用于后续生成目录HTML
                if(self::$config['tocDepth'] >= $level){
                    $toc[] = [
                        'level'=>$level, // 标题级别
                        'title'=>$titleText, // 标题文本内容
                        'anchorId'=>$reserve['id'] // 对应的锚点ID，用于链接跳转
                    ];
                }

                return $blockKey;
            },
            $text
        );
        if($withToc){
            self::$toc = array_merge(self::$toc, $toc);
        }

        if(count($titleList)) self::$allBlocks = array_merge(self::$allBlocks, $titleList);

        return $text;
    }

    private static function processInlineCode(string $text): string
    {
        // 处理文件引入
        $text = preg_replace_callback(
            '/<!--\s*@include:\s*([^\s\{]+)\s*(?:\{([\d]+)-([\d]+)\})?\s*-->/',
            function ($matches) {
                $filePath = $matches[1];
                $startLine = isset($matches[2]) ? (int)$matches[2] : 1;
                $endLine = isset($matches[3]) ? (int)$matches[3] : PHP_INT_MAX;

                $docRoot = (string) (self::$config['docRoot'] ?? '');
                $docFileDir = (string) (self::$config['docFileDir'] ?? '');
                if ($docRoot === '' || $docFileDir === '') {
                    return $matches[0];
                }

                // 禁止绝对路径，仅允许相对当前 .md 所在目录
                if (
                    str_starts_with($filePath, '/')
                    || str_starts_with($filePath, '\\')
                    || preg_match('/^[a-zA-Z]:[\\\\\\/]/', $filePath)
                ) {
                    return $matches[0];
                }

                $fullPath = realpath($docFileDir . DIRECTORY_SEPARATOR . $filePath);

                if (
                    $fullPath === false
                    || !self::isPathUnderDocRoot($fullPath, $docRoot)
                    || !is_readable($fullPath)
                ) {
                    return $matches[0];
                }

                $lines = file($fullPath, FILE_IGNORE_NEW_LINES);
                if ($lines) {
                    // 截取指定行范围
                    $lines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
                    return self::processInlineCode(implode("\n", $lines));
                }

                // 如果文件不存在或不可读，返回原始标签
                return $matches[0];
            },
            $text
        );

        return $text;
    }

    private static function processExternalTags(string $text): string
    {
        // 处理外部CSS引入标签 @link
        $text = preg_replace_callback(
            '/<!--\s*@link\s+([^\s]+)\s*-->/',
            function ($matches) {
                $cssFile = $matches[1];
                self::$mark['include'] .= '<link rel="stylesheet" href="' . $cssFile . '" />
';
                return '';
            },
            $text
        );
        
        // 处理外部JS引入标签 @script
        $text = preg_replace_callback(
            '/<!--\s*@script\s+([^\s]+)\s*-->/',
            function ($matches) {
                $jsFile = $matches[1];
                self::$mark['include'] .= '<script src="' . $jsFile . '"></script>
';
                return '';
            },
            $text
        );
                
             
        return $text;
    }
}
