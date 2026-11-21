<?php
/***
 * Markdown BlockCollector
 *
 * 业务说明：各类 Markdown 块级元素收集器
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait BlockCollector
{
    private static function collectQuoteBlocks(string $text): string
    {
        // 初始化引用块存储数组
        $quote = [];
        
        // 使用正则表达式匹配所有引用块（支持多行和多层级）
        // 匹配模式：^>(?:\s*>)*\s?.+(?:\n>(?:\s*>)*\s?.+)*
        // - ^> : 以>开头
        // - (?:\s*>)* : 可选的后续>符号（支持嵌套）
        // - \s?.+ : 空格后的内容
        // - (?:\n>...) : 可选的换行后继续引用
        $text = preg_replace_callback(
            '/(^>(?:\s*>)*\s?.+(?:\n>(?:\s*>)*\s?.+)*)/m',
            function($matches) use (&$quote) {
                // 将匹配到的引用块按行分割处理
                $quoteList = explode("\n", $matches[1]);
                $quoteContent = $tmpContent = '';
                $lastLevel = 0; // 记录上一个处理的引用层级
                $blockquoteAttrs = ''; // 用于存储第一层blockquote的属性
                
                // 逐行处理引用内容
                foreach($quoteList as $index => $line){
                    // 使用正则表达式提取引用层级和纯内容（更高效的方式）
                    // 匹配模式：^(>\s*)+ 匹配行首的>符号和后面的空格
                    if (preg_match('/^(>\s*)+/', $line, $matches)) {
                        // 计算>符号的数量来确定引用层级
                        $level = substr_count($matches[0], '>');
                        // 提取纯内容（移除行首所有>和空格）
                        $pureLine = preg_replace('/^(>\s*)+/', '', $line);
                        
                        // 如果是第一行且为最外层引用（level=1），检查是否有属性语法
                        // 支持语法：> [标题]{id="quote1" class="highlight"}
                        if ($index === 0 && $level === 1) {
                            // 检查是否包含属性语法 {....}
                            // 匹配模式：^(.+?)\s*(\{[^}]+\})\s*$
                            if (preg_match('/^(.+?)\s*\{([^}]+)\}\s*$/', $pureLine, $attrMatches)) {
                                $pureLine = trim($attrMatches[1]); // 移除属性部分，保留纯内容
                                $attrString = $attrMatches[2]; // 提取属性字符串
                                
                                // 解析属性字符串
                                [$blockquoteAttrs] = self::parseAttributes($attrString);
                            }
                        }
                    } else {
                        // 非引用行，层级为0
                        $level = 0;
                        $pureLine = $line;
                    }

                    // 根据引用层级变化开关HTML结构
                    if($lastLevel == $level){
                        // 情况1：同级引用，直接追加内容到临时内容中
                        $tmpContent .= $pureLine . "\n";
                    } elseif($lastLevel < $level){
                        // 情况2：进入更深层嵌套引用，增加层级
                        // 先处理完当前级别的内容
                        if(!empty($tmpContent)){
                            $quoteContent .= self::processNestedBlocks($tmpContent);
                            $tmpContent = '';
                        }
                        // 为每一层新增的嵌套创建<blockquote>标签
                        for($i = $lastLevel; $i < $level; $i++){
                            // 只有第一层blockquote应用属性，其他层使用默认属性
                            if ($i === 0) {
                                $quoteContent .= '<blockquote' . $blockquoteAttrs . '>';
                            } else {
                                $quoteContent .= '<blockquote>';
                            }
                        }
                        $tmpContent .= $pureLine . "\n";
                    } else { // $lastLevel > $level
                        // 情况3：退出上层嵌套，减少层级
                        // 先处理完当前级别的内容
                        if(!empty($tmpContent)){
                            $quoteContent .= self::processNestedBlocks($tmpContent);
                            $tmpContent = '';
                        }
                        // 关闭多出来的<blockquote>标签
                        for($i = $level; $i < $lastLevel; $i++){
                            $quoteContent .= '</blockquote>';
                        }
                        $tmpContent .= $pureLine . "\n";
                    }
                    // 更新当前引用层级
                    $lastLevel = $level;
                }

                // 处理完所有行后，如果有剩余的未处理内容
                if(!empty($tmpContent)){
                    $quoteContent .= self::processNestedBlocks($tmpContent);
                    $tmpContent = '';
                }
                
                // 关闭所有剩余的<blockquote>标签
                for($i = $lastLevel; $i > 0; $i--){
                    $quoteContent .= '</blockquote>';
                }

                // 生成唯一的引用块占位符
                $blockKey = '<!--block-quote-'. self::$index++ .'-->';
                
                // 存储引用块内容到全局数组
                $quote[$blockKey] = $quoteContent;
                
                // 返回占位符，替换原文本中的引用块
                return $blockKey;
            },
            $text
        );

        // 如果有引用块被处理，将其合并到全局块数组中
        if(count($quote) > 0) self::$allBlocks = array_merge(self::$allBlocks, $quote);
        
        // 返回处理后的文本（引用块已被占位符替换）
        return $text;
    }

    private static function collectDivBlocks(string $text): string
    {
        $divs = [];
        $level = 0;
        $paragraphWrap = self::$paragraphWrap;
        self::$paragraphWrap = ['', '', '', false];
        $text = preg_replace_callback(
            '/^>:(:+)?[^\S\n\r]*(.*?)?[^\S\n\r]*(?:\{([^\}]+)\})?\s*(.*?)\n^>!:$/ms',
            function ($matches) use (&$level, &$divs) {
                $level = strlen($matches[1]);
                [$attrString] = self::parseAttributes($matches[3] ?? null);
                $content = $matches[4];
                $html = '';
                
                //自定义外层标签
                $html .= '<'. (empty($matches[2]) ? 'div' : $matches[2]) . $attrString . '>';

                //开启布局
                for($i=1;$i<=$level;$i++){
                    $html .= $i == $level ? '<div' . $attrString . '>' : '<div>';
                }

                //临时占位符
                $index = 0;
                $_esc = [];
                
                //处理嵌套
                $content = preg_replace_callback(
                    '/[^\S\n\r]*>:(:+)?\s*(?:\{([^\}]+)\})?[^\S\n\r]*/m',
                    function ($_s) use (&$level, &$_esc, &$index){
                        [$attrString] = self::parseAttributes($_s[2] ?? null);
                        $_level = strlen($_s[1]);
                        if($_level == $level){
                            //平级关闭布局
                            $_html = '</div><div'. $attrString .'>';
                        } else if($_level > $level) {
                            //嵌套
                            for($i=$level;$i<=$_level;$i++){
                                $_html = $i == $_level ? '<div' . $attrString . '>' : '<div>';
                            }
                        }else {
                            //上级
                            for($i=0;$i<=$level-$_level;$i++){
                                $_html = '</div>';
                            }
                        }
                        $level = $_level;
                        $blockKey = '<!--inblock-' . $index++ . '-->';
                        $_esc[$blockKey] = $_html;
                        return $blockKey;
                    },
                    $content
                );

                $html .= strtr(self::processBodyContent($content), $_esc);
                
                //只在布局代码块中处理form
                $html = self::processFormContent($html);
                
                //关闭布局
                for($i=0;$i<=$level-1;$i++){
                    $html .= '</div>';
                }

                $html .= '</'. (empty($matches[2]) ? 'div' : $matches[2]) . '>';

                $blockKey = '<!--block-div-' . self::$index++ . '-->';
                $divs[$blockKey] = $html;
                return $blockKey;
            },
            $text
        );

        // 合并到全局块缓存
        if (count($divs) > 0) {
            self::$allBlocks = array_merge(self::$allBlocks, $divs);
        }

        self::$paragraphWrap = $paragraphWrap;

        return $text;
    }

    private static function processFormContent(string $text): string
    {
        $pattern = [];
        //先把所有的 pattern="value" 替换成占位符 防止影响匹配
        $text = preg_replace_callback(
            '/pattern\s*=\s*(["\'])([\\w\\W]*?)\1/s',
            function ($matches) use (&$pattern) {
                $key = 'PATTERN_PLACEHOLDER_' . count($pattern);
                $pattern[$key] = $matches[2]; // 保存完整的pattern属性
                return 'pattern="' . $key . '"';
            },
            $text
        );
        //处理label标签 格式： ^[内容]{样式 非必须}
        $text = preg_replace_callback(
            "/\^\[([^`\n]+?)\](\s*\{(.*)\})?/",
            function($matches) {
                [$attrs] = self::parseAttributes($matches[2] ?? null);
                return '<label'. $attrs .'>'. $matches[1] .'</label>';
            },
            $text
        );
        // input 控件：@[name]{属性} 解析为 <input name="name" 属性>
        $text = preg_replace_callback(
            '/\@\[([^\]]+)?\](?:\s*\{(.*?)\})?/',
            function ($matches) {
                $name = $matches[1];
                // 解析属性
                [$attrString] = self::parseAttributes($matches[2] ?? null);
                $inputAttr = '';
                if (!empty($name)) {
                    $inputAttr .= ' name="' . self::escapeHtml($name) . '"';
                }
                // 默认类型为 text
                if (!strpos($attrString, 'type=')) {
                    $inputAttr .= ' type="text"';
                }
                return '<input' . $inputAttr . $attrString . '>';
            },
            $text
        );
        
        // textarea 控件：%[name]{属性} 解析为 <textarea name="name" 属性></textarea>
        $text = preg_replace_callback(
            '/\%\[([^\]]+)?\](?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $name = $matches[1] ?? '';
                // 解析属性
                [$attrString] = self::parseAttributes($matches[2] ?? null, ['name']);
                
                // 添加 name 属性
                $textareaAttr = '';
                if (!empty($name)) {
                    $textareaAttr = ' name="' . self::escapeHtml($name) . '"';
                }
                
                return '<textarea' . $textareaAttr . $attrString . '></textarea>';
            },
            $text
        );
        
        // 单选按钮组和复选框组：&[组名]{type="radio"}[选项1|选项2*] 或 &[组名]{type="checkbox"}[选项1|选项2*]
        // * 表示默认选中
        $text = preg_replace_callback(
            '/(?:&amp;|&)\[([^\]]+)\]\{([^\}]+)\}\[(.*?)\]/',
            function ($matches) {
                $groupName = $matches[1];
                $attrInput = $matches[2];
                $optionsStr = $matches[3];
                
                // 解析属性
                [$attrString] = self::parseAttributes($attrInput);
                
                // 获取控件类型，默认 radio
                $controlType = 'radio';
                if (strpos($attrString, 'type="checkbox"') !== false) {
                    $controlType = 'checkbox';
                } elseif (strpos($attrString, "type='checkbox'") !== false) {
                    $controlType = 'checkbox';
                }
                
                // 解析选项列表
                $options = explode('|', $optionsStr);
                
                $groupHtml = '';
                foreach ($options as $option) {
                    // 检查是否有默认选中标记
                    $isChecked = false;
                    $optionText = $option;
                    
                    // 处理默认选中标记
                    if (substr($option, -1) === '*') {
                        $optionText = substr($option, 0, -1);
                        $isChecked = true;
                    }
                    
                    // 生成单个控件HTML
                    $checkedAttr = $isChecked ? ' checked' : '';
                    $nameAttr = $controlType === 'checkbox' ? "name='{$groupName}[]'" : "name='{$groupName}'";
                    
                    $groupHtml .= '<label>';
                    $groupHtml .= "<input type='{$controlType}' {$nameAttr} value='" . self::escapeHtml($optionText) . "'{$checkedAttr}>";
                    $groupHtml .= ' ' . self::escapeHtml($optionText);
                    $groupHtml .= '</label>&nbsp;';
                }
                
                return $groupHtml;
            },
            $text
        );

        // 下拉选择框：$[name]{attributes}[key1:value1|key2:value2*|key3:value3]
        // * 表示默认选中
        $text = preg_replace_callback(
            '/\$\[([^\]]+)\](?:\s*\{([^\}]+)\})?\[([^\]]+)\]/',
            function ($matches) {
                $name = $matches[1];
                $attrInput = $matches[2] ?? null;
                $optionsStr = $matches[3];
                
                // 解析属性
                [$attrString] = self::parseAttributes($attrInput, ['name']);
                
                // 如果没有 name 属性，使用第一个参数作为 name
                $selectAttr = '';
                if (!empty($name)) {
                    $selectAttr = ' name="' . self::escapeHtml($name) . '"';
                }
                
                // 解析选项列表
                $options = explode('|', $optionsStr);
                
                $selectHtml = '';
                
                // 开始 select 标签
                $selectHtml .= '<select' . $selectAttr . $attrString . '>';
                
                // 处理每个选项
                foreach ($options as $option) {
                    // 检查是否有默认选中标记
                    $isSelected = false;
                    $optionKeyValue = $option;
                    
                    // 处理默认选中标记
                    if (substr($option, -1) === '*') {
                        $optionKeyValue = substr($option, 0, -1);
                        $isSelected = true;
                    }
                    
                    // 分割键值对
                    $keyValue = explode(':', $optionKeyValue, 2);
                    $key = trim($keyValue[0]);
                    $value = trim($keyValue[1]);
                    
                    // 添加 selected 属性
                    $selectedAttr = $isSelected ? ' selected' : '';
                    
                    // 生成 option 标签
                    $selectHtml .= '<option value="' . self::escapeHtml($key) . '"' . $selectedAttr . '>' . self::escapeHtml($value) . '</option>';
                }
                
                // 关闭 select 标签
                $selectHtml .= '</select>';
                
                return $selectHtml;
            },
            $text
        );
        
        // 合并处理表单标签：#[src]{attrs} 为开始标签，#[] 为结束标签
        $text = preg_replace_callback(
            '/\#\[([^\]]*)\](?:\s*\{([^\}]+)\})?/',
            function ($matches) {
                $action = $matches[1] ?? '';
                $attrInput = $matches[2] ?? null;
                
                // 判断是开始还是结束标签
                // 只有当既没有action也没有属性时，才是结束标签
                if (empty($action) && empty($attrInput)) {
                    // 结束标签：#[]
                    return '</form>';
                } else {
                    // 开始标签：#[action]{attrs} 或 #[]{attrs}
                    [$attrString] = self::parseAttributes($attrInput);
                    
                    $formAttr = '';
                    // 添加 action 属性
                    if (!empty($action)) {
                        $formAttr .= ' action="' . self::escapeHtml($action) . '"';
                    }
                    // 默认 method 为 post
                    if (!strpos($attrString, 'method=')) {
                        $formAttr .= ' method="post"';
                    }
                    
                    return '<form' . $formAttr . $attrString . '>';
                }
            },
            $text
        );
        
        if(count($pattern) > 0) $text = strtr($text, $pattern);
        
        return $text;
    }

    private static function collectListBlocks(string $text): string
    {
        $list = [];
        // 使用更新的正则表达式匹配列表块，支持属性
        $text = preg_replace_callback(
            '/^(?:(\s*)([-*+]|\d+\.)\s+(?:\[[x ]\]\s+)?[^\n]+((\s+)(?:[-*+]|\d+\.)\s+(?:\[[x ]\]\s+)?[^\n]+)*?)+/m',
            function ($matches) use (&$list) {
                $listInfo = explode("\n", $matches[0]);
                
                // 移除可能的空行
                if(strlen($listInfo[0]) == 0) array_shift($listInfo);

                $listHtml = $ulStyle = $liStyle = $tmpLiStyle = '';
                $pastLevel = [];
                while(count($listInfo)){
                    $line = array_shift($listInfo);
                    if(strlen($line) > 0) {
                        [$trimmedLine, $indentLevel] = self::processIndentation($line);
                        
                        // 先解析基本列表结构
                        if (preg_match('/^(\d+)\.\s+(.*)/', $trimmedLine, $listMatch)) {
                            $listType = 'ol';
                            $listContent = $listMatch[2];
                        } elseif (preg_match('/^[\*\-\+]\s+(.*)/', $trimmedLine, $listMatch)) {
                            $listType = 'ul';
                            $listContent = $listMatch[1];
                        }
                        
                        // 获取上一行信息
                        $lastItem = end($pastLevel);
                        $inlist = $lastItem[0] ?? null;
                        $prevType = $lastItem[1] ?? null;
                        
                        // 列表类型切换处理
                        if ($prevType !== null && $prevType !== $listType) {
                            [$listContent, $liStyle, $ulStyle] = self::getStyleForList($listContent, $listType);
                            if($inlist == $indentLevel){
                                // 同一层次，关闭当前类型并开始新类型
                                $listHtml .= '</li></' . $prevType . '><' . $listType . $ulStyle . '><li' . $liStyle . '>' . $listContent;
                                if (!empty($pastLevel)) {
                                    $pastLevel[count($pastLevel) - 1][1] = $listType; // 更新类型
                                }
                            } else if ($inlist < $indentLevel) {
                                // 进入更深层次的嵌套
                                $listHtml .= '<' . $listType . '><li' . $liStyle . '>' . $listContent;
                                $pastLevel[] = [$indentLevel, $listType];
                            } else {
                                // 回退到上层列表
                                $listHtml .= '</li>';  // 先关闭当前li
                                while (!empty($pastLevel) && end($pastLevel)[0] > $indentLevel) {
                                    $last = array_pop($pastLevel);
                                    $listHtml .= '</' . $last[1] . '>';
                                }
                                // 开始新的列表项
                                $listHtml .= (count($pastLevel) == 0 ? '<' . $listType . $ulStyle . '>' : '') . '<li' . $liStyle . '>' . $listContent;
                                if (!empty($pastLevel)) {
                                    $pastLevel[count($pastLevel) - 1][1] = $listType; // 更新类型
                                }
                            }
                        } else {
                            // 正常缩进级别处理
                            if(is_null($inlist) || $indentLevel > $inlist) {
                                // 第一行或嵌套
                                [$listContent, $liStyle, $ulStyle] = self::getStyleForList($listContent, $listType);
                                $listHtml .= '<' . $listType . $ulStyle . '><li' . $liStyle . '>' . $listContent;
                                $pastLevel[] = [$indentLevel, $listType];
                            } else {
                                [$listContent, $tmpLiStyle] = self::getStyleForList($listContent, $listType);
                                if($indentLevel == $inlist){
                                    // 同一层次，关闭当前li并开始新的li
                                    $listHtml .= '</li><li' . (empty($tmpLiStyle) ? $liStyle : $tmpLiStyle) . '>' . $listContent;
                                    // 更新当前层级的类型
                                    if (!empty($pastLevel)) {
                                        $pastLevel[count($pastLevel) - 1][1] = $listType;
                                    }
                                } else {
                                    // 回退到上层列表
                                    $listHtml .= '</li>';  // 先关闭当前li
                                    // 关闭所有需要关闭的列表级别
                                    while (!empty($pastLevel) && end($pastLevel)[0] > $indentLevel) {
                                        $last = array_pop($pastLevel);
                                        $listHtml .= '</' . $last[1] . '>';
                                    }
                                    $listHtml .= '<li' . (empty($tmpLiStyle) ? $liStyle : $tmpLiStyle) . '>' . $listContent;
                                    // 更新当前层级的类型
                                    if (!empty($pastLevel)) {
                                        $pastLevel[count($pastLevel) - 1][1] = $listType;
                                    }
                                }
                            }
                        }
                    } else {
                        // 特殊情况 两个列表一起展示 中间有空格 先关闭之前的列表
                        while(count($pastLevel) > 0)
                        {
                            $last = array_pop($pastLevel);
                            $listHtml .= '</' . $last[1] . '></li>';
                        }
                    } 
                }

                // 关闭所有未关闭的列表级别
                while(count($pastLevel) > 0)
                {
                    $last = array_pop($pastLevel);
                    $listHtml .= '</' . $last[1] . '>';
                }
                // 关闭最外层的列表项
                if (!empty($listHtml)) {
                    $listHtml .= '</li>';
                }
                $blockKey = '<!--block-list-' . self::$index++ . '-->';
                $list[$blockKey] = $listHtml;
                return $blockKey;
            },
            $text
        );
        
        // 如果找到了列表块，可以将它们存储到全局变量中供后续使用
        if (count($list) > 0)  self::$allBlocks = array_merge(self::$allBlocks, $list);
        
        return $text;
    }

    private static function getStyleForList(?string $line,string $listType = 'ul'): array
    {
        if(is_null($line)) return ['','',''];
        
        // 匹配格式：列表项目内容 {容器样式} {行样式}
        preg_match('/^(.*?)(?:\s*\{\{([^{}]+)\}\})?(?:\s*\{\{([^{}]+)\}\})?$/', trim($line), $matches);
        $content = $matches[1] ?? '';
        [$item] = self::parseAttributes($matches[2] ?? null);
        [$container] = self::parseAttributes($matches[3] ?? null);
        
        // 完成任务列表处理逻辑
        if(!empty($content)){
            $isChecked = null;
            if($listType == 'ul')
            {
                if(preg_match('/^\[([ xX])\]\s*(.*)$/', $content, $matches))
                {
                    $isChecked = in_array(strtolower($matches[1]), ['x']);
                    $content = (self::$config['enableEmoji'] ? ($isChecked ? '✅' : '🕙') : '<input type="checkbox" ' . ($isChecked ? 'checked ' : '') . 'disabled> ') . $matches[2];
                
                    //合并任务样式
                    if (strpos($container, 'class=') !== false) {
                        $container = preg_replace('/class="([^"]*)"/', 'class="$1 task-list-container"', $container);
                    } else {
                        $container .= ' class="task-list-container"';
                    }
                    if (strpos($item, 'class=') !== false) {
                        $item = preg_replace('/class="([^"]*)"/', 'class="$1 task-list-item"', $item);
                    } else {
                        $item .= ' class="task-list-item"';
                    }
                }
            }

            // 调用新方法解析链接
            $content = self::parseInlineElements($content);
        }
        
        return [$content, $item, $container];
    }

    private static function collectDefinitionBlocks(string $text): string
    {
        $hasUserHtml = self::hasExtractableUserHtml($text);
        if (!self::mayContainDefinitionList($text, $hasUserHtml)) {
            return $text;
        }

        if ($hasUserHtml) {
            $text = self::collectHtmlBlocks($text);
        }

        $define = [];
        // 处理定义列表（术语相关标签）
        // 匹配模式：
        // 1. 一个或多个非空非定义行作为术语
        // 2. 一个或多个以冒号开头的定义行
        // 3. 确保术语行不是标题、列表、引用、代码块等
        $text = preg_replace_callback(
            '/^(?!\s*#|\s*[\*\-\+]\s|\s*\d+\.\s|\s*>|\s*```)([^\n]+(?:\n[^\n:]+)*)\n(\s*:\s*[^\n]+(?:\n\s*:\s*[^\n]+)*)/m',
            function ($matches) use (&$define) {
                $term = trim($matches[1]);
                $definitionText = $matches[2];
                
                // 处理定义
                $definitionLines = explode("\n", $definitionText);
                $definitions = [];
                
                foreach ($definitionLines as $line) {
                    $trimmedLine = trim($line);
                    if (empty($trimmedLine)) continue;
                    
                    // 移除定义前的冒号和空格
                    $cleanDef = trim(preg_replace('/^\s*:\s*/', '', $line));
                    if (!empty($cleanDef)) {
                        $definitions[] = self::parseInlineElements($cleanDef);
                    }
                }
                
                if (empty($definitions)) {
                    return $matches[0];
                }
                
                // 生成HTML
                $html = '<dl>';
                $html .= '<dt>' . self::parseInlineElements($term, false, false) . '</dt>';
                
                // 处理所有定义
                foreach ($definitions as $definition) {
                    $html .= '<dd>' . $definition . '</dd>';
                }
                
                $html .= '</dl>';
                
                $blockKey = '<!--block-define-' . self::$index++ . '-->';
                $define[$blockKey] = $html;

                return $blockKey;
            },
            $text
        );

        if(count($define) > 0) self::$allBlocks = array_merge(self::$allBlocks, $define);
        
        return $text;
    }

    private static function collectLinkAndFootnote(string $text): string
    {
        // 处理脚注定义（以 [^ 开头）
        $text = preg_replace_callback(
            '/^\s*\[\^([^\]]+)\]:\s*(.*)$/m',
            function ($matches) {
                $key = $matches[1];
                self::$footnotes[$key] = $matches[2];
                return '';
            },
            $text
        );
        
        // 处理链接引用（排除脚注格式 [^...]，使用负向前瞻 (?!\^) 确保不以 ^ 开头）
        $text = preg_replace_callback(
            '/^\s*\[(?!\^)([^\]]+)\]:\s*(.+?)(?:\s+(?:["\'](.*?)["\']))?$/m',
            function ($matches) {
                $key = strtolower($matches[1]);
                self::$links[$key] = [
                    'url' => trim($matches[2]),
                    'title' => $matches[3] ?? ''
                ];
                return '';
            },
            $text
        );
        
        return $text;
    }

    private static function collectSlideBlocks(string $text): string
    {
        $slide = $slideOptions = [];
        //声明段落包裹符号
        $paragraphWrap = self::$paragraphWrap;
        self::$paragraphWrap = ['<p>', '</p>', '', false];
        $text = preg_replace_callback(
            '/^@slidestart\s*(?:\{([^}]+)\})?\s*$(.*?)^@slideend\s*$/ms',
            function ($matches) use (&$slide, &$slideOptions) {
                $slideAttrs = '';
                $reserve = [];
                if (isset($matches[1])) {
                    $attrs = trim($matches[1]);
                    $reserve = self::parseAttributes($attrs, ['all']);
                }
                $slideContent = self::processInlineElements($matches[2]);
                // 解析幻灯片内容中的分页
                $slides = [];
                $horizontalPages = explode('<--->', $slideContent);
                foreach ($horizontalPages as $horizontalPage) {
                    $verticalPages = explode('<-->', $horizontalPage);
                    $verticalSlides = [];
                    
                    foreach ($verticalPages as $verticalPage) {
                        $verticalSlides[] = '<section>' . trim($verticalPage) . '</section>';
                    }
                    
                    if (count($verticalSlides) > 1) {
                        $slides[] = '<section>' . implode('', $verticalSlides) . '</section>';
                    } else {
                        $slides[] = $verticalSlides[0];
                    }
                }
                
                // 生成幻灯片样式属性
                $width =  '100%';
                if(isset($reserve['width'])){
                    $width = $reserve['width'] . 'px';
                    unset($reserve['width']);
                }
                $height =  '600px';
                if(isset($reserve['height'])){
                    $height = $reserve['height'] . 'px';
                    unset($reserve['height']);
                }
                $style = "width: $width; height: $height;";
                $slideOptions = array_merge($slideOptions, $reserve);
                
                // 构建完整的 Reveal.js 容器，添加自定义类限制样式范围
                $revealHtml = '<div class="reveal-container" style="' . $style . '">';
                $revealHtml .= '<div class="reveal" style="margin: 0 auto;">';
                $revealHtml .= '<div class="slides"' . $slideAttrs . '>';
                $revealHtml .= implode('', $slides);
                $revealHtml .= '</div>';
                $revealHtml .= '</div>';
                $revealHtml .= '</div>';
                
                // 存储幻灯片内容和选项
                $blockKey ='<!--block-slide-'. self::$index++ .'-->';
                $slide[$blockKey] = $revealHtml;
                
                return $blockKey;
            },
            $text
        );

        //追加
        if(count($slide) > 0){
            self::$allBlocks = array_merge(self::$allBlocks, $slide);

            // 存储幻灯片配置
            self::$mark['slideshowConfig'] = $slideOptions;

            //标记
            self::$mark['slideshow'] = true;
        }

        self::$paragraphWrap = $paragraphWrap;
        return $text;
    }

    private static function collectTabsBlocks(string $text): string
    {
        $tabs = [];
        if(self::$config['enableTabs']) {
            // 匹配选项卡容器：::: tabs{atts} or ::: tabs
            $text = preg_replace_callback(
                '/^:::\s*tabs(?:\s*\{([^}]+)\})?\s*\n([\s\S]*?)^:::\s*$/m',
                function ($matches) use (&$tabs) {
                    $attrs = isset($matches[1]) ? $matches[1] : '';
                    $tabContent = $matches[2];
                    
                    // 解析属性
                    [$parsedAttrs, $reserve] = self::parseAttributes($attrs ?? null, ['id'],['class'=>'tabs-container']);
                    $tabId = isset($reserve['id']) ? $reserve['id'] : '';
                    
                    // 分割选项卡内容
                    preg_match_all('/^@tab(?:\:active)?\s+([^#\n]+?)(?:\s*#([\w-]+))?\s*\n([\s\S]*?)(?=\n^@tab|\z)/m', $tabContent, $tabMatches, PREG_SET_ORDER);
                    
                    if (empty($tabMatches)) {
                        return $matches[0];
                    }
                    // 添加data-tab-id（使用解析的id或保持为空）
                    if ($tabId) {
                        $parsedAttrs .= 'data-tab-id="' . $tabId . '" id="' . $tabId . '"';
                    }

                    // 生成容器HTML
                    $html = "\n<div " . $parsedAttrs . '>';
                    $html .= '<div class="tabs-nav">';
                    
                    // 先遍历一次，找到默认激活的选项卡
                    $activeIndex = 0;
                    foreach ($tabMatches as $i => $tabMatch) {
                        if (strpos($tabMatch[0], '@tab:active') === 0) {
                            $activeIndex = $i;
                            break;
                        }
                    }
                    
                    // 然后生成HTML
                    $tabPanels = [];
                    foreach ($tabMatches as $i => $tabMatch) {
                        $tabTitle = trim($tabMatch[1]);
                        // 确保$tabMatch[1]和$tabMatch[2]都被正确捕获
                        $hasCustomValue = isset($tabMatch[2]) && $tabMatch[2] !== '';
                        $tabValue = $hasCustomValue ? $tabMatch[2] : $tabTitle;
                        // 为了确保data-tab-value不为空，添加一个默认值
                        if ($tabValue === '') {
                            $tabValue = 'tab-' . ($i + 1);
                        }
                        $tabPanelContent = trim($tabMatch[3]);
                        
                        // 设置激活状态
                        $activeClass = $i === $activeIndex ? ' active' : '';
                        
                        // 生成选项卡导航
                        $html .= '<button class="tab-nav-item' . $activeClass . '" data-tab-value="' . $tabValue . '">' . self::escapeHtml($tabTitle) . '</button>';
                        
                        // 生成选项卡面板
                        $tabPanels[] = [
                            'value' => $tabValue,
                            'content' => $tabPanelContent,
                            'active' => $activeClass === ' active'
                        ];
                    }
                    
                    $html .= '</div>';
                    $html .= '<div class="tabs-content">';
                    
                    // 生成选项卡面板内容
                    foreach ($tabPanels as $panel) {
                        $activeClass = $panel['active'] ? ' active' : '';
                        $html .= '<div class="tab-panel' . $activeClass . '" data-tab-value="' . $panel['value'] . '">';
                        $html .= self::processNestedBlocks($panel['content']); // 递归解析面板内容
                        $html .= '</div>';
                    }
                    
                    $html .= '</div>';
                    $html .= "</div>\n";
                    
                    // 生成占位符并存储选项卡HTML
                    $blockKey ='<!--block-tabs-'. self::$index++ .'-->';
                    $tabs[$blockKey] = $html;
                    return $blockKey;
                },
                $text
            );
        }

        //追加
        if(count($tabs) > 0) self::$allBlocks = array_merge(self::$allBlocks, $tabs);

        return $text;
    }

    private static function collectDetailsBlocks(string $text): string
    {
        $details = [];
        // 处理折叠块语法
        $text = preg_replace_callback(
            '/^\[(.*?)\]\n(:.*(?:\n:.*)*)/m',
            function ($matches) use (&$details) {
                $summary = trim($matches[1]);
                $contentLines = explode("\n", $matches[2]);
                $parsedLines = [];
                foreach ($contentLines as $line) {
                    $parsedLines[] = preg_replace('/^:\s*/', '', $line);
                }
                $content = implode("\n", $parsedLines);
                $blockKey ='<!--block-details-'. self::$index++ .'-->';
                //self::parseInline(trim($content), false)
                $details[$blockKey] = "\n<details><summary>" . self::escapeHtml(self::replaceEscapedMark($summary, true)) . "</summary>\n<div>" . self::processNestedBlocks(trim($content)) . "\n</div></details>\n";
                return $blockKey;
            },
            $text
        );

        //追加
        if(count($details) > 0) self::$allBlocks = array_merge(self::$allBlocks, $details);

        return $text;
    }

    private static function collectCustomBlocks(string $text): string
    {
        $paragraphWrap = self::$paragraphWrap;
        self::$paragraphWrap = ['', '', '', true];
        $custom = [];
        $text = preg_replace_callback(
            '/^:::\s+(info|tip|note|warning|caution|danger)([\s\S]*?)?\n([\s\S]*?)?:::$/m',
            function ($matches) use (&$custom) {
                $type = self::escapeHtml($matches[1]);
                $iconMap = ['info'=>['ℹ️','重要'],'tip'=>['💡','重要'], 'note'=>['📝','z '], 'warning'=>['⚠️','重要'], 'caution'=>['⚠️','重要'], 'danger'=>['🚨','重要']];
                $msg = isset($matches[2]) && !empty($matches[2]) ? self::escapeHtml(self::replaceEscapedMark($matches[2], true)) : $iconMap[$type][1];
                $content = isset($matches[3]) && !empty($matches[3]) ? self::processNestedBlocks(self::escapeHtml(self::replaceEscapedMark($matches[3])), true) : '';
                $html = "\n<div class=\"custom-container custom-" . $type . '">';
                $html .= '<div class="container-header">';
                $html .= '<span class="container-icon">' . $iconMap[$type][0] . '</span>';
                $html .= '<span class="container-title">' . $msg . '</span>';
                $html .= '</div>';
                if(!empty($content)){
                    $html .= '<div class="container-content">';
                    $html .= $content;
                    $html .= '</div>';
                }
                $html .= "</div>\n";

                $blockKey ='<!--block-custom-'. self::$index++ .'-->';
                $custom[$blockKey] = $html;
                return $blockKey;
            },
            $text
        );

        //兼容Bootstrap5 信息提示框
        $text = preg_replace_callback(
            '/^::\s+([\S]*?)\s*(\{[^}]+\})?\n([\s\S]*?)?::$/m',
            function ($matches) use (&$custom) {
                $type = self::escapeHtml($matches[1]);
                [$attrString, $reserve] = self::parseAttributes($matches[2] ? trim($matches[2], '{}') : null, ['class']);
                $content = isset($matches[3]) && !empty($matches[3]) ? self::processNestedBlocks(self::replaceEscapedMark($matches[3], true)) : '';
                $html = '<div class="alert alert-'. $type . ' ' . ($reserve['class'] ?? '') .'" '. $attrString .'>';
                $html .= $content;
                $html .= '</div>';
                
                $blockKey ='<!--block-custom-'. self::$index++ .'-->';
                $custom[$blockKey] = $html;
                return $blockKey;
            },
            $text
        );
        self::$paragraphWrap = $paragraphWrap;

        if(count($custom) > 0){
             //标记
            self::$mark['custom'] = true;

            //追加
            self::$allBlocks = array_merge(self::$allBlocks, $custom);
        }
        
        return $text;
    }

    private static function collectVideoBlocks(string $text): string
    {
        $video = [];
        if(self::$config['enableVideo']) {
            // 处理视频嵌入
            $text = preg_replace_callback(
                '/!\[video\]\((https?:\/\/(?:www\.youtube\.com\/watch\?v=|youtu\.be\/|www\.bilibili\.com\/video\/)([^\)]+))\)/',
                function ($matches) use (&$video) {
                    $url = $matches[1];
                    $id = $matches[2];
                    if(strpos($url, 'youtube') === false && strpos($url, 'bilibili') === false){
                        return $matches[0];
                    } else {
                        $blockKey ='<!--block-video-'. self::$index++ .'-->';
                        if (strpos($url, 'youtube') !== false) {
                            $embedUrl = "https://www.youtube.com/embed/{$id}";
                        } else {
                            if (strpos($id, 'BV') === 0) {
                                $embedUrl = "https://player.bilibili.com/player.html?bvid={$id}";
                            } else {
                                $embedUrl = "https://player.bilibili.com/player.html?aid={$id}";
                            }
                        }
                        $content = "\n<div class=\"video-container\">"
                        . '<iframe width="560" height="315" src="' . $embedUrl . '" '
                        . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                        . 'allowfullscreen></iframe>'
                        . "</div>\n";
                        $video[$blockKey] = $content;
                        return $blockKey;
                    }
                },
                $text
            );
        }

        //追加
        if(count($video) > 0) self::$allBlocks = array_merge(self::$allBlocks, $video);
        
        return $text;
    }

    private static function collectChartBlocks(string $text): string
    {
        
        if(self::$config['enableChart']) {
            // chart 图表
            $chart = [];
            $text = preg_replace_callback(
                '/```\s*chartjs\s*\n(.*?)\n\s*```/s',
                function ($matches) use (&$chart) {
                    $blockKey ='<!--block-chartjs-'. self::$index++ .'-->';
                    $jsCode = isset($matches[1]) && !empty(trim($matches[1])) ? trim($matches[1]) : '';
                    $chart[$blockKey] = empty($jsCode) ? "" : "<div class=\"doc-chart doc-chart--chartjs\">\n<canvas id=\"myChart" . self::$index . "\"></canvas>\n<div><script>\n" . $jsCode . "\nif (typeof config == 'undefined'){\n var config = {};\n}\nnew Chart(document.getElementById('myChart" . self::$index. "'),config);\n</script>\n";
                    return $blockKey;
                },
                $text
            );
            //追加
            if(count($chart) > 0){
                self::$mark['chartjs'] = true;
                self::$allBlocks = array_merge(self::$allBlocks, $chart);
            }
            
            // Mermaid代码块
            $chart = [];
            $text = preg_replace_callback(
                '/```\s*mermaid\s*\n(.*?)\n\s*```/s',
                function ($matches) use (&$chart) {
                    $blockKey ='<!--block-mermaid-'. self::$index++ .'-->'; 
                    $chart[$blockKey] = "<div class=\"mermaid\">\n". self::replaceEscapedMark($matches[1], true) . "\n</div>\n";
                    return $blockKey;
                },
                $text
            );
            //追加
            if(count($chart) > 0){
                self::$mark['mermaid'] = true;
                self::$allBlocks = array_merge(self::$allBlocks, $chart);
            }
            
            //思维导图
            $chart = [];
            $text = preg_replace_callback(
                '/```\s*markmap\s*(?:\s+((?:\d+(?:%|px))(?:,(?:\d+(?:%|px))){0,2}))?\s*\n(.*?)\n\s*```/s',
                function ($matches) use (&$chart) {
                    $blockKey ='<!--block-markmap-'. self::$index++ .'-->'; 
                    // 尺寸
                    $params = isset($matches[1]) && !empty($matches[1]) ? explode(',', $matches[1]) : [];
                    $height = isset($params[0]) && !empty($params[0]) ? $params[0] : '500px';
                    $width = isset($params[1]) && !empty($params[1]) ? $params[1] : '90%';
                    $maxWidth = isset($params[2]) && !empty($params[2]) ? $params[2] : '1000px';
                    // 获取内容
                    $content = isset($matches[2]) ? $matches[2] : '';
                    $chart[$blockKey] = '<div class="markmap-container" style="height: '. $height .'; width: '. $width .'; max-width: '. $maxWidth .';"><svg id="markmap'. self::$index .'"></svg>' ."</div><script>\nmarkmap.Markmap.create('#markmap" . self::$index ."', null, transformer.transform(`\n{$content}\n`).root);\n</script>\n";
                    return $blockKey;
                },
                $text
            );
            //追加
            if(count($chart) > 0){
                self::$mark['markmap'] = true;
                self::$allBlocks = array_merge(self::$allBlocks, $chart);
            }
            
            //flow流程图
            $chart = [];
            $text = preg_replace_callback(
                '/```\s*flow\s*\n(.*?)\n\s*```/s',
                function ($matches) use (&$chart) {
                    $blockKey ='<!--block-flow-'. self::$index++ .'-->'; 
                    $content = $matches[1];
                    $jsCode = '';
                    
                    // 提取+++js标记之间的JavaScript代码
                    if (preg_match('/\+\+\+config\s*(.*?)\s*\+\+\+/s', $content, $jsMatches)) {
                        $jsCode = $jsMatches[1];
                        // 从内容中移除JavaScript部分
                        $content = trim(preg_replace('/\+\+\+config\s*(.*?)\s*\+\+/s', '', $content));
                    }
                    
                    $flowCode  = '<div id="diagram' . self::$index . '" class="doc-chart doc-chart--flow"></div>';
                    $flowCode .= "<script>\n"
                        . "document.addEventListener('DOMContentLoaded', function() {\n"
                        . (empty($jsCode) ? '' : $jsCode)
                        . "\nif(typeof options == 'undefined') var options = {};\n"
                        . "flowchart.parse(`\n{$content}\n`).drawSVG('diagram" . self::$index . "', options);\n"
                        . "});\n"
                        . "</script>\n";
                    $chart[$blockKey] = $flowCode;
                    return $blockKey;
                },
                $text
            );
            //追加
            if(count($chart) > 0){
                self::$mark['flow'] = true;
                self::$allBlocks = array_merge(self::$allBlocks, $chart);
            }

            //echarts
            $chart = [];
            $text = preg_replace_callback(
                '/```\s*echarts\s*(?:\s+([\w\d%,\s_-]+))?\s*\n(.*?)\n\s*```/s',
                function ($matches) use (&$chart) {
                    $blockKey ='<!--block-echarts-'. self::$index++ .'-->'; 
                    // id名称 和 尺寸
                    $params = isset($matches[1]) && !empty($matches[1]) ? explode(',', $matches[1]) : [];
                    $idName = isset($params[0]) && !empty($params[0]) ? $params[0] : 'echarts' . self::$index;
                    $height = isset($params[1]) && !empty($params[1]) ? $params[1] : '500px';
                    $width = isset($params[2]) && !empty($params[2]) ? $params[2] : '90%';
                    // 获取内容
                    $content = isset($matches[2]) ? $matches[2] : '';
                    $chart[$blockKey] = '<div id="'. $idName .'" class="doc-chart doc-chart--echarts" style="height: '. $height .'; width: '. $width . ";\"></div><script>var tableName = '{$idName}';\n{$content}\n</script>\n";
                    return $blockKey;
                },
                $text
            );
            //追加
            if(count($chart) > 0){
                self::$mark['echarts'] = true;
                self::$allBlocks = array_merge(self::$allBlocks, $chart);
            }
        }

        return $text;
    }

    private static function collectJsBlocks(string $text): string
    {
        $js = [];
        //js代码
        $text = preg_replace_callback(
            '/\+\+\+js\s*(.*?)\s*\+\+\+/s',
            function ($matches) use (&$js) {
                $blockKey ='<!--block-script-'. self::$index++ .'-->';
                $jsCode = "\n<script>\n{$matches[1]}\n</script>\n";
                $js[$blockKey] = $jsCode;
                return $blockKey;
            },
            $text
        );

        //追加
        if(count($js) > 0) self::$allBlocks = array_merge(self::$allBlocks, $js);

        return $text;
    }

    private static function collectMathBlocks(string $text): string
    {
        $math = [];
        if(self::$config['enableMath']) {
            // 处理块级公式：$$...$$
            $text = preg_replace_callback(
                '/\$\$(.*?)\$\$/s',
                function ($matches) use (&$math) {
                    $mathContent = $matches[1];
                    $blockKey ='<!--block-math-block-'. self::$index++ .'-->';
                    $math[$blockKey] = "$$" . $mathContent . "$$";
                    return $blockKey;
                },
                $text
            );
            
            // 处理行内公式：$...$
            $text = preg_replace_callback(
                '/\$([^\n]+?)\$/s',
                function ($matches) use (&$math) {
                    $mathContent = $matches[1];
                    $blockKey ='<!--block-math-inline-'. self::$index++ .'-->';
                    $math[$blockKey] = "$" . $mathContent ."$";
                    return $blockKey;
                },
                $text
            );
        }

        if(count($math) > 0){
            //标记
            self::$mark['math'] = true;

            //追加
            self::$allBlocks = array_merge(self::$allBlocks, $math);
        }
        
        return $text;
    }

    private static function collectCodeBlocks(string $text): string
    {
        $html = '';
        $code = [];

        //用 ``` 分割字符 提取代码内容
        $codeContentArr = explode('```', $text);
        
        $blockDepth = 0;
        $codeContent = $language = '';
        $attrStr = ''; // 初始化属性字符串
        foreach($codeContentArr as $key=>$content){
            //偶数段落是html
            if($key % 2 === 0 && $blockDepth === 0){
                $html .= $content;
                continue;
            }
            // 检查是否是代码块开始标记行
            if($blockDepth === 0) {
                // 分离语言属性和内容（安全检查换行符）
                $parts = explode("\n", $content, 2);
                if (count($parts) < 2) continue; // 没有换行符，跳过
                $markerContent = $parts[0];
                $content = $parts[1];
                $codeBlockClass = '';
                // 正则匹配语言类型、属性部分和底码内容
                if (preg_match('/[^\S\n\r]*(\w*)?[^\S\n\r]*(\{[^}]+\})?$/', $markerContent, $tmp)) {
                    // 提取语言类型（安全检查）
                    $language = isset($tmp[1]) && !empty($tmp[1]) ? trim($tmp[1]) : '';
                    [$codeBlockClass, $reserved] = self::parseAttributes($tmp[2] ?? null,['class']);
                } else {
                    // 如果正则匹配失败，设置默认值
                    $language = '';
                }

                $classStr = $reserved['class'] ?? '';
                $codeBlockClass = ' class="language-' . ($language ?: 'php') . (stripos($classStr, 'no-line-numbers') === false ? ' line-numbers ' : ' ') . $classStr . ' match-braces" data-prismjs-copy="📋"; data-prismjs-copy-success="✅"; data-prismjs-copy-error="❌";' . $codeBlockClass;
                
                //避免代码里有占位符
                if(stripos($content, '<!--') !== false){
                    $content = self::restoreBlockPlaceholders($content);
                }

                //避免缩进引起的多行
                $content = trim($content);
                // 对所有语言的代码块内容都进行HTML转义处理，确保特殊标签（如<?php）被正确显示
                $content = self::escapeHtml(self::replaceEscapedMark($content, true));
                // if(in_array($language, ['html','markdown','js'])){
                //     $content = self::escapeHtml(self::replaceEscapedMark($content, true));
                // }
                
                // 生成代码块HTML开始部分
                $codeContent = '<div class="mac-code-container"><div class="mac-code-header"><div class="mac-code-buttons"><div class="mac-code-button close"></div><div class="mac-code-button minimize"></div><div class="mac-code-button maximize"></div></div><div class="mac-code-title"><em>' . ($language ?: 'text') . '</em></div></div><pre><code' . $codeBlockClass . '>' . $content;
                $blockDepth++;
            } else {
                // 查找代码内容的结束位置（第一个换行符）
                $newlinePos = strpos($content, "\n");
                if ($newlinePos !== false) {
                    // 代码内容部分
                    $codeContent .= substr($content, 0, $newlinePos);
                    // 关闭代码块
                    $codeContent .= '</code></pre></div>';
                    $blockKey ='<!--block-code-'. self::$index++ .'-->';
                    $html .= $blockKey. "\n";
                    $code[$blockKey] = $codeContent;
                    
                    // 重置状态
                    $codeContent = $language = '';
                    $attrStr = '';
                    $blockDepth = 0;
                    
                    // 添加剩余内容到HTML
                    $html .= substr($content, $newlinePos + 1);
                } else {
                    // 没有换行符，可能是文件末尾
                    $codeContent .= $content;
                    // 关闭代码块
                    $codeContent .= '</code></pre></div>';
                    $blockKey ='<!--block-code-'. self::$index++ .'-->';
                    $html .= $blockKey . "\n";
                    $code[$blockKey] = $codeContent;
                    
                    // 重置状态
                    $codeContent = $language = '';
                    $attrStr = '';
                    $blockDepth = 0;
                }
            }
        }

        //标记
        if(count($code)){
            self::$mark['code'] = true;
            self::$allBlocks = array_merge(self::$allBlocks, $code);
        }

        // 内联代码
        $html = preg_replace_callback(
            '/`([^`\n]+?)`/',
            function ($matches) {
                return '<scode>' . self::escapeHtml(self::replaceEscapedMark($matches[1], true)) . '</scode>';
            },
            $html
        );
        
        return $html;
    }

    private static function collectHtmlBlocks(string $text): string
    {
        if (!self::hasExtractableUserHtml($text)) {
            return $text;
        }

        $paragraphWrap = self::$paragraphWrap;
        self::$paragraphWrap = ['','','', false];

        $html = [];
        $text = preg_replace_callback(
            '/<([a-z][a-z0-9]*)([^>]*)>(.*?)<\/\1>|<([a-z][a-z0-9]*)([^\/]*)\/>/is',
            function ($matches) use (&$html){
                $tagName = strtolower($matches[1] ?? $matches[4] ?? '');
                if (self::isHtmlExtractSkippedTag($tagName)) {
                    return $matches[0];
                }
                // 处理闭合标签和自闭合标签
                if (isset($matches[4])) {
                    // 自闭合标签
                    $htmlBlock = "<{$matches[4]}{$matches[5]}/>";
                } else {
                    // 闭合标签：内联 HTML 仅解析行内元素，避免重复跑完整块管线
                    $htmlBlock = "<{$matches[1]}{$matches[2]}>" . self::parseInlineElements($matches[3], false, false) . "</{$matches[1]}>";
                }
                $htmlBlock = self::restoreBlockPlaceholders($htmlBlock);
                $blockKey = '<!--block-html-'. self::$index++ .'-->';
                $html[$blockKey] = $htmlBlock;
                return $blockKey;
            },
            $text
        );

        if(count($html) > 0) self::$allBlocks = array_merge(self::$allBlocks, $html);
        self::$paragraphWrap = $paragraphWrap;

        return $text;
    }
}
