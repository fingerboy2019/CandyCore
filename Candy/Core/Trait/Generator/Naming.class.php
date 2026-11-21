<?php
/***
 * 生成器命名转换 Trait
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-15 07:40:00 $
 *
 * @package Candy\Core\Trait\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Trait\Generator;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait Naming
{
    /**
     * 读取当前项目的表前缀（与 DB 配置 TABPREFIX 一致）
     *
     * 优先 C()/G() 运行时配置，CLI 或未引导时回退 loadConfig('Config')
     *
     * @return string 如 sa_，未配置时为空串
     * @access protected
     * @static
     */
    protected static function resolveTablePrefix(): string
    {
        if (function_exists('C')) {
            $prefix = (string)(C('TABPREFIX') ?? '');
            if ($prefix !== '') {
                return $prefix;
            }
        }

        if (function_exists('G')) {
            $prefix = (string)(G('TABPREFIX') ?? G('DBPREFIX') ?? '');
            if ($prefix !== '') {
                return $prefix;
            }
        }

        if (function_exists('loadConfig')) {
            $config = loadConfig('Config');
            if (is_array($config)) {
                $default = $config['DB']['default'] ?? [];
                if (is_array($default)) {
                    $prefix = (string)($default['TABPREFIX'] ?? '');
                    if ($prefix !== '') {
                        return $prefix;
                    }
                }
                $prefix = (string)($config['TABPREFIX'] ?? '');
                if ($prefix !== '') {
                    return $prefix;
                }
            }
        }

        return '';
    }

    /**
     * 去掉表名中的配置前缀
     *
     * @param string $table 物理表名
     * @param string $prefix 表前缀，留空则自动 resolveTablePrefix()
     * @return string 去前缀后的表名
     * @access protected
     * @static
     */
    protected static function stripTablePrefix(string $table, string $prefix = ''): string
    {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        if ($prefix === '') {
            $prefix = self::resolveTablePrefix();
        }

        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            $table = substr($table, strlen($prefix));
        }

        return $table;
    }

    /**
     * 表名转 PascalCase 类名（剥离 TABPREFIX，如 sa_article → Article）
     *
     * @param string $table 数据表名
     * @param string $prefix 表前缀，留空则自动读取配置
     * @return string
     */
    protected static function tableToClassName(string $table, string $prefix = ''): string
    {
        $table = self::stripTablePrefix($table, $prefix);

        $parts = preg_split('/[_\s]+/', $table) ?: [$table];
        $name = '';
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $name .= ucfirst(strtolower($part));
        }

        return $name !== '' ? $name : 'Model';
    }

    /**
     * 规范应用名（首字母大写）
     *
     * @param string $app
     * @return string
     */
    protected static function normalizeAppName(string $app): string
    {
        $app = ucfirst(strtolower(trim($app)));
        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $app) ? $app : '';
    }
}
