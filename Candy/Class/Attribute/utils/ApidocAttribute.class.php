<?php
/***
 * Apidoc 注解识别工具
 *
 * 业务说明：区分 Apidoc 文档注解与 Node 权限注解，供 ParseAnnotation 反射解析 PHP 8 Attribute
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 18:20:00 $
 *
 * @package Candy\Extend\Attribute\utils
 */

declare(strict_types=1);

namespace Candy\Extend\Attribute\utils;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ApidocAttribute
{
    private const CANDY_NS = 'Candy\\Extend\\Attribute\\Annotations\\';

    /**
     * 不参与 Apidoc 扫描的 Candy 注解（Node / 中间件等）
     *
     * @var list<string>
     */
    private const EXCLUDED_SHORT_NAMES = [
        'Annotation',
        'RateLimiter',
    ];

    /**
     * 可重复声明的 Apidoc 注解键（小写类名）
     *
     * @var list<string>
     */
    private const REPEATABLE_KEYS = [
        'query', 'param', 'header', 'body', 'responseSuccess', 'responseError',
        'addField', 'field', 'withoutField', 'before', 'after', 'tag', 'markdown',
        'responseSuccessMarkdown', 'responseErrorMarkdown', 'returned', 'routeParam',
        'routeMiddleware', 'property', 'notQueries', 'notParams', 'notHeaders',
        'notResponses', 'notResponseSuccess', 'notResponseError', 'sort',
    ];

    /**
     * 判断反射 Attribute 类名是否属于 Apidoc 文档注解
     *
     * @param string $className 完全限定类名或短名
     */
    public static function isApidocClass(string $className): bool
    {
        $className = ltrim($className, '\\');

        if (stripos($className, 'apidoc') !== false) {
            return true;
        }

        if (str_starts_with($className, self::CANDY_NS)) {
            $short = substr($className, strlen(self::CANDY_NS));
            return !in_array($short, self::EXCLUDED_SHORT_NAMES, true);
        }

        if (str_starts_with($className, 'APP\\Extend\\Annotations\\')) {
            return true;
        }

        return false;
    }

    /**
     * 注解键是否允许多次出现（如多个 Query）
     */
    public static function isRepeatableKey(string $key): bool
    {
        return in_array($key, self::REPEATABLE_KEYS, true);
    }
}
