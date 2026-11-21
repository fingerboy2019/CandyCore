<?php
/***
 * 生成器 MVCS 路径布局 Trait
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Trait\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Generator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait PathLayout
{
    /**
     * 应用根目录 Application/{App}/
     *
     * @param string $app
     * @return string
     */
    protected static function appRoot(string $app): string
    {
        return rtrim((string)C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR
            . 'Application' . DIRECTORY_SEPARATOR . $app . DIRECTORY_SEPARATOR;
    }

    /**
     * Model 文件路径
     *
     * @param string $app
     * @param string $className
     * @return string
     */
    protected static function modelPath(string $app, string $className): string
    {
        return self::appRoot($app) . 'Models' . DIRECTORY_SEPARATOR . $className . '.class.php';
    }

    /**
     * Control 文件路径
     *
     * @param string $app
     * @param string $className
     * @return string
     */
    protected static function controlPath(string $app, string $className): string
    {
        return self::appRoot($app) . 'Controls' . DIRECTORY_SEPARATOR . $className . '.class.php';
    }

    /**
     * Service 文件路径
     *
     * @param string $app
     * @param string $className
     * @return string
     */
    protected static function servicePath(string $app, string $className): string
    {
        return self::appRoot($app) . 'Services' . DIRECTORY_SEPARATOR . $className . '.class.php';
    }

    /**
     * 视图目录
     *
     * @param string $app
     * @param string $controlClass
     * @return string
     */
    protected static function viewDir(string $app, string $controlClass): string
    {
        return self::appRoot($app) . 'Views' . DIRECTORY_SEPARATOR . $controlClass . DIRECTORY_SEPARATOR;
    }

    /**
     * 语言包文件路径
     *
     * @param string $app
     * @param string $controlClass
     * @param string $locale
     * @return string
     */
    protected static function langPath(string $app, string $controlClass, string $locale = 'zh-cn'): string
    {
        return self::appRoot($app) . 'Languages' . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR
            . $controlClass . '.lang.php';
    }

    /**
     * 扫描已安装应用名列表
     *
     * @return array<int, string>
     */
    protected static function listApplications(): array
    {
        $root = rtrim((string)C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR . 'Application' . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return [];
        }

        $apps = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($root . $entry)) {
                $apps[] = $entry;
            }
        }
        sort($apps);

        return $apps;
    }
}
