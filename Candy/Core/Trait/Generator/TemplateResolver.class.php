<?php
/***
 * 生成器模板解析 Trait
 *
 * 框架仅内置 default；扩展风格从 Source/Generator/{style}/ 加载
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Trait\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Generator;

use Candy\Core\Impl\Generator\Contract\StyleProviderInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait TemplateResolver
{
    /**
     * 框架内置 default 模板集标识
     */
    protected static string $generatorDefaultStyle = 'default';

    /**
     * 读取项目 Generator 配置（Source/Inc/Generator.inc.php）
     *
     * @return array{styles?:array,default_style?:string}
     */
    protected static function loadGeneratorConfig(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $loaded = function_exists('loadConfig') ? loadConfig('Generator') : [];
        $config = is_array($loaded) ? $loaded : [];

        return $config;
    }

    /**
     * 框架 default 模板根目录
     *
     * @return string
     */
    protected static function frameworkDefaultTemplateRoot(): string
    {
        return rtrim((string)CANDYPATH, '/\\') . DIRECTORY_SEPARATOR
            . 'Candy' . DIRECTORY_SEPARATOR . 'Tpl' . DIRECTORY_SEPARATOR
            . 'generator' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR;
    }

    /**
     * 项目扩展风格根目录 Source/Generator/
     *
     * @return string
     */
    protected static function projectGeneratorRoot(): string
    {
        return rtrim((string)C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR
            . 'Source' . DIRECTORY_SEPARATOR . 'Generator' . DIRECTORY_SEPARATOR;
    }

    /**
     * 列出可用风格（default + 配置注册 + 目录扫描）
     *
     * @return array<string, array{id:string,label:string,source:string,path?:string,provider?:string}>
     */
    protected static function listGeneratorStyles(): array
    {
        $styles = [
            self::$generatorDefaultStyle => [
                'id' => self::$generatorDefaultStyle,
                'label' => 'Default',
                'source' => 'framework',
                'path' => self::frameworkDefaultTemplateRoot(),
            ],
        ];

        $config = self::loadGeneratorConfig();
        foreach (($config['styles'] ?? []) as $id => $meta) {
            if (!is_string($id) || $id === '' || $id === self::$generatorDefaultStyle) {
                continue;
            }
            $meta = is_array($meta) ? $meta : [];
            $styles[$id] = [
                'id' => $id,
                'label' => (string)($meta['label'] ?? $id),
                'source' => 'project',
                'path' => self::projectStyleRoot($id),
                'provider' => isset($meta['provider']) ? (string)$meta['provider'] : '',
            ];
        }

        $root = self::projectGeneratorRoot();
        if (is_dir($root)) {
            foreach (scandir($root) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === self::$generatorDefaultStyle) {
                    continue;
                }
                $full = $root . $entry;
                if (!is_dir($full)) {
                    continue;
                }
                if (!isset($styles[$entry])) {
                    $styles[$entry] = [
                        'id' => $entry,
                        'label' => $entry,
                        'source' => 'project',
                        'path' => $full . DIRECTORY_SEPARATOR,
                    ];
                }
            }
        }

        return $styles;
    }

    /**
     * 项目风格目录
     *
     * @param string $styleId
     * @return string
     */
    protected static function projectStyleRoot(string $styleId): string
    {
        return self::projectGeneratorRoot() . $styleId . DIRECTORY_SEPARATOR;
    }

    /**
     * 实例化风格 Provider（可选）
     *
     * @param string $styleId
     * @return StyleProviderInterface|null
     */
    protected static function makeStyleProvider(string $styleId): ?StyleProviderInterface
    {
        if ($styleId === self::$generatorDefaultStyle) {
            return null;
        }

        $styles = self::listGeneratorStyles();
        $providerClass = (string)($styles[$styleId]['provider'] ?? '');
        if ($providerClass === '' || !class_exists($providerClass)) {
            return null;
        }

        $provider = new $providerClass();
        return $provider instanceof StyleProviderInterface ? $provider : null;
    }

    /**
     * 解析 stub 绝对路径（扩展目录 → default 回退）
     *
     * @param string $styleId 风格标识
     * @param string $stubName stub 文件名
     * @return string|null
     */
    /**
     * 读取项目配置的默认生成风格（Generator.inc.php default_style）
     *
     * @return string
     */
    protected static function resolveProjectDefaultStyle(): string
    {
        $config = self::loadGeneratorConfig();
        $style = trim((string)($config['default_style'] ?? ''));
        if ($style === '') {
            return self::$generatorDefaultStyle;
        }
        $styles = self::listGeneratorStyles();
        if (isset($styles[$style])) {
            return $style;
        }

        return self::$generatorDefaultStyle;
    }

    /**
     * Resolve Generator Stub
     * @param mixed $styleId
     * @param mixed $stubName
     * @return ?string
     */
    protected static function resolveGeneratorStub(string $styleId, string $stubName): ?string
    {
        $stubName = ltrim($stubName, '/\\');
        if ($stubName === '') {
            return null;
        }

        $provider = self::makeStyleProvider($styleId);
        if ($provider !== null) {
            $custom = $provider->resolveStub($stubName);
            if (is_string($custom) && $custom !== '' && is_file($custom)) {
                return $custom;
            }
        }

        if ($styleId !== self::$generatorDefaultStyle) {
            $projectFile = self::projectStyleRoot($styleId) . $stubName;
            if (is_file($projectFile)) {
                return $projectFile;
            }
        }

        $defaultFile = self::frameworkDefaultTemplateRoot() . $stubName;
        return is_file($defaultFile) ? $defaultFile : null;
    }
}
