<?php
/***
 * 生成器写盘策略 Trait
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Trait\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Generator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait OverwritePolicy
{
    /**
     * 规范化覆盖策略
     *
     * @param string $policy skip|force
     * @return string
     */
    protected static function normalizeOverwritePolicy(string $policy): string
    {
        return strtolower(trim($policy)) === 'force' ? 'force' : 'skip';
    }
}
