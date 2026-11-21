<?php
/***
 * 生成器占位符替换 Trait
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Trait\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Generator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Placeholder
{
    /**
     * 将 stub 中的 {{key}} 替换为上下文值
     *
     * @param string $content 模板内容
     * @param array<string, scalar> $context 占位符上下文
     * @return string
     */
    protected static function renderPlaceholders(string $content, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $replace['{{' . $key . '}}'] = (string)$value;
        }

        return strtr($content, $replace);
    }
}
