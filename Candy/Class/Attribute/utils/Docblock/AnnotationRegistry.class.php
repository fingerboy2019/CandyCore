<?php
/***
 * 注解类自动加载注册表
 *
 * 业务说明：DocParser 解析时按 FQCN 触发类加载，替代原 Doctrine AnnotationRegistry
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 18:12:00 $
 *
 * @package Candy\Extend\Attribute\utils\Docblock
 */

declare(strict_types=1);

namespace Candy\Extend\Attribute\utils\Docblock;

final class AnnotationRegistry
{
    /**
     * 加载注解类（存在则返回 true）
     *
     * @param string $fqcn 完全限定类名
     * @return bool
     */
    public static function loadAnnotationClass(string $fqcn): bool
    {
        if (!class_exists($fqcn, false)) {
            class_exists($fqcn);
        }

        return class_exists($fqcn, false);
    }
}
