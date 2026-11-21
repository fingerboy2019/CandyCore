<?php
/***
 * Markdown Placeholder
 *
 * 业务说明：块占位符多轮还原与行内样式安全处理
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 21:30:42 $
 *
 * @package Candy\Extend\Doc\Markdown\Trait
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Markdown\Trait;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Placeholder
{
    /** @var array<string, true>|null 跳过标签哈希表，避免 in_array 线性扫描 */
    private static ?array $htmlExtractSkipTagMap = null;

    /** @var string|null 用于 hasExtractableUserHtml 的预编译负向前瞻 */
    private static ?string $htmlExtractSkipPattern = null;

    /**
     * 判断 HTML 标签是否属于解析器输出（不参与 block-html 提取）
     *
     * @param string $tag 小写标签名
     * @return bool
     */
    private static function isHtmlExtractSkippedTag(string $tag): bool
    {
        self::$htmlExtractSkipTagMap ??= array_fill_keys(self::$htmlExtractSkipTags, true);
        return isset(self::$htmlExtractSkipTagMap[$tag]);
    }

    /**
     * 是否含有需提取的用户内联 HTML（排除 scode、表格等已编译标签）
     *
     * @param string $text 待检测文本
     * @return bool
     */
    private static function hasExtractableUserHtml(string $text): bool
    {
        if (!str_contains($text, '<')) {
            return false;
        }
        self::$htmlExtractSkipPattern ??= '(?:' . implode('|', self::$htmlExtractSkipTags) . ')';
        return (bool)preg_match(
            '/<(?!(?:' . self::$htmlExtractSkipPattern . ')\b)[a-z][a-z0-9]*[\s>\/]/i',
            $text
        );
    }

    /**
     * 是否可能包含定义列表或需提取的用户 HTML
     *
     * 用于跳过绝大多数技术文档的全篇 HTML 正则扫描
     *
     * @param string $text 待检测文本
     * @return bool
     */
    private static function mayContainDefinitionList(string $text, ?bool $hasUserHtml = null): bool
    {
        if (preg_match('/^(?!\s*#|\s*[\*\-\+]\s|\s*\d+\.\s|\s*>|\s*```)([^\n]+)\n\s*:\s*[^\n]/m', $text)) {
            return true;
        }
        return $hasUserHtml ?? self::hasExtractableUserHtml($text);
    }

    private static function restoreBlockPlaceholders(string $text): string
    {
        if (empty(self::$allBlocks) || !str_contains($text, '<!--block-')) {
            return $text;
        }

        // 首轮全量替换最快；仅当仍有嵌套占位符时再按命中键过滤
        $text = strtr($text, self::$allBlocks);
        if (!str_contains($text, '<!--block-')) {
            return $text;
        }

        $limit = 8;
        do {
            $prev = $text;
            $active = [];
            foreach (self::$allBlocks as $key => $value) {
                if (str_contains($text, $key)) {
                    $active[$key] = $value;
                }
            }
            if ($active === []) {
                break;
            }
            $text = strtr($text, $active);
        } while ($prev !== $text && --$limit > 0);

        return $text;
    }

    private static function formatInlineStyleContent(string $content): string
    {
        $content = self::restoreBlockPlaceholders($content);
        if ($content === '') {
            return '';
        }
        if (preg_match('/<[a-z][a-z0-9]*[\s>\/]/i', $content)) {
            return $content;
        }
        return self::escapeHtml($content);
    }
}
