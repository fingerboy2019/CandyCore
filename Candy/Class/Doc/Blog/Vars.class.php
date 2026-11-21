<?php
/***
 * 博客配置自定义变量解析
 *
 * 业务说明：从 BlogConfig.inc.php（vars 段）合并 global 与页面级字段，注入模板作用域
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-16 04:32:00 $
 *
 * @package Candy\Extend\Doc\Blog
 */
declare(strict_types=1);

namespace Candy\Extend\Doc\Blog {

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Vars
{
    /** @var array<string, mixed> 当前请求已解析的扁平变量 */
    private static array $resolved = [];

    /**
     * 解析并缓存当前页面可用自定义变量
     *
     * 合并顺序：vars.global → vars.{页面}（index/post/list/404），后者覆盖前者
     * 仅保留合法 PHP 变量名；值为 null 的项跳过（视为未配置）
     *
     * @param array $conf BlogConfig 配置数组
     * @param string $tplName 模板名 index|post|list|404
     * @return array<string, mixed> 扁平变量，供 extract(EXTR_SKIP) 或模板读取
     */
    public static function resolve(array $conf, string $tplName): array
    {
        $varsRoot = (array) ($conf['vars'] ?? []);
        $merged = [];

        foreach (['global', self::normalizePageKey($tplName)] as $scope) {
            if ($scope === '' || empty($varsRoot[$scope]) || !is_array($varsRoot[$scope])) {
                continue;
            }
            foreach ($varsRoot[$scope] as $key => $value) {
                if (!self::isValidVarName((string) $key) || $value === null) {
                    continue;
                }
                $merged[(string) $key] = $value;
            }
        }

        $system = [
            'site_name' => (string) ($conf['site_name'] ?? ''),
            'site_description' => (string) ($conf['site_description'] ?? ''),
            'blog_home_url' => '/Blog/',
        ];

        foreach ($merged as $key => $value) {
            if (is_string($value)) {
                $merged[$key] = self::interpolate($value, array_merge($system, $merged));
            }
        }

        self::$resolved = $merged;

        return $merged;
    }

    /**
     * 读取自定义变量
     *
     * @param string $key 变量名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$resolved[$key] ?? $default;
    }

    /**
     * 是否已配置且非空
     *
     * @param string $key 变量名
     * @return bool
     */
    public static function has(string $key): bool
    {
        if (!array_key_exists($key, self::$resolved)) {
            return false;
        }

        $value = self::$resolved[$key];

        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    /**
     * 输出转义后的字符串变量
     *
     * @param string $key 变量名
     * @param string $default 默认值
     * @return string
     */
    public static function e(string $key, string $default = ''): string
    {
        return htmlspecialchars((string) self::get($key, $default), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 输出已配置变量（HTML 转义）
     *
     * 内部自动 has 判断，未配置或为空时返回空字符串
     *
     * @param string $key 变量名
     * @return string
     */
    public static function echo(string $key): string
    {
        if (!self::has($key)) {
            return '';
        }

        return htmlspecialchars((string) self::get($key), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 替换字符串中的 {$var} 占位符
     *
     * 未定义的占位符保持原样
     *
     * @param string $text 含占位符的文本
     * @param array<string, mixed> $context 变量上下文
     * @return string
     */
    public static function interpolate(string $text, array $context): string
    {
        if ($text === '' || strpos($text, '{$') === false) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\{\$([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use ($context): string {
                $name = $matches[1];
                if (!array_key_exists($name, $context)) {
                    return $matches[0];
                }
                $val = $context[$name];
                if (is_scalar($val) || $val === null) {
                    return (string) $val;
                }

                return $matches[0];
            },
            $text
        );
    }

    /**
     * 模板名映射为 vars 页面键
     *
     * @param string $tplName 模板名
     * @return string
     */
    private static function normalizePageKey(string $tplName): string
    {
        $tplName = strtolower(trim($tplName));

        return in_array($tplName, ['index', 'post', 'list', '404'], true) ? $tplName : '';
    }

    /**
     * 校验是否为合法 PHP 变量名
     *
     * @param string $name 变量名
     * @return bool
     */
    private static function isValidVarName(string $name): bool
    {
        return $name !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1;
    }
}

}

namespace {

use Candy\Extend\Doc\Blog\Vars;

if (!function_exists('blog_var')) {
    /**
     * 读取博客配置自定义变量
     *
     * @param string $key 变量名
     * @param mixed $default 默认值
     * @return mixed
     */
    function blog_var(string $key, mixed $default = null): mixed
    {
        return Vars::get($key, $default);
    }
}

if (!function_exists('blog_has')) {
    /**
     * 博客配置变量是否已设置且非空
     *
     * @param string $key 变量名
     * @return bool
     */
    function blog_has(string $key): bool
    {
        return Vars::has($key);
    }
}

if (!function_exists('blog_e')) {
    /**
     * 输出转义后的博客配置变量
     *
     * @param string $key 变量名
     * @param string $default 默认值
     * @return string
     */
    function blog_e(string $key, string $default = ''): string
    {
        return Vars::e($key, $default);
    }
}

if (!function_exists('blog_echo')) {
    /**
     * 输出已配置博客变量，未配置返回空字符串
     *
     * @param string $key 变量名
     * @return string
     */
    function blog_echo(string $key): string
    {
        return Vars::echo($key);
    }
}

}
