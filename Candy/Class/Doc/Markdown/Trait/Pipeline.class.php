<?php
/***
 * Markdown Pipeline
 *
 * 业务说明：解析管线编排
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Pipeline
{
    private static function parseHtml(string $text): string
    {
        //初始化参数
        self::initializeData();

        // 处理特殊块
        $text = self::processSpecialBlocks($text);

        return $text;
    }

    private static function processNestedBlocks(string $text): string
    {
        if (self::$config['enableChart']) {
            $text = self::collectChartBlocks($text);
        }

        $text = self::collectCodeBlocks($text);

        if (self::$config['enableTabs']) {
            $text = self::collectTabsBlocks($text);
        }

        $text = self::collectCustomBlocks($text);

        if (self::$config['enableMath']) {
            $text = self::collectMathBlocks($text);
        }

        if (self::$config['enableVideo']) {
            $text = self::collectVideoBlocks($text);
        }

        $text = self::collectListBlocks($text);

        if (self::$config['enableTables']) {
            $text = self::collectTableBlocks($text);
        }

        $text = self::collectSlideBlocks($text);
        $text = self::collectDetailsBlocks($text);
        $text = self::collectDefinitionBlocks($text);
        $text = self::processInlineElements($text, true);

        return $text;
    }

    private static function processSpecialBlocks(string $text): string
    {
        // 处理转义字符、清理特殊格式、标准化换行符等
        // 为后续解析做准备，确保文本格式的一致性
        $text = self::preprocessText($text);
        
        // 优先处理代码引用，保证代码内容不被其他解析规则破坏
        // 包括行内代码标记（`code`）的处理
        $text = self::processInlineCode($text);
        
        // 处理外部JS引入标签 @script 和外部样式 @link
        // 支持通过特殊注释语法引入外部JavaScript和CSS资源
        $text = self::processExternalTags($text);
        
        // 特殊的JavaScript代码块处理，支持语法高亮和特殊功能
        $text = self::collectJsBlocks($text);
        
        // 先收集所有的超链接和脚注，建立索引和引用关系
        // 为后续的引用解析提供基础数据
        $text = self::collectLinkAndFootnote($text);

        //处理div布局块
        $text = self::collectDivBlocks($text);
        
        $text = self::processBodyContent($text);

        //最后处理一遍占位符（多轮展开，处理 HTML 块内嵌套占位符）
        $text = self::restoreBlockPlaceholders($text);
        
        // 返回最终处理完成的HTML文本
        return $text;
    }

    private static function processBodyContent(string $text): string
    {
        // 支持多层嵌套引用和引用属性
        $text = self::collectQuoteBlocks($text);
        
        // 处理复杂的嵌套块元素，包括列表、表格、代码块、图表等
        // 这是最复杂的处理阶段，需要考虑各种嵌套关系
        $text = self::processNestedBlocks($text);

        return $text;
    }
}
