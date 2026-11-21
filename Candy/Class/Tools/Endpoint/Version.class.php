<?php
/***
 * 框架级端点：版本与运行环境
 *
 * 业务说明：框架自身运行所必需的功能性端点。
 * 注册方式见 Registry::$frameworkEndpoints —— **恒定开启，不受任何配置影响，项目无法关闭**。
 *
 * 与 sys/health、sys/ping 的区别：
 *   - sys/health / sys/ping 属「运维可选」，默认不开启，由 enable 白名单控制
 *   - sys/version 属「框架级」，始终可访问，用于诊断框架装载是否正常
 *
 * 不输出敏感信息：不含绝对路径、不含数据库连接、不含密钥、不含 phpinfo 细节。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-13 18:30:00 $
 *
 * @package Candy\Extend\Tools\Endpoint
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Version
{
    /**
     * 返回框架版本与运行环境
     *
     * @return string JSON
     */
    public static function info(): string
    {
        return (string) json_encode([
            'framework' => 'CandyPHP',
            'version'   => self::frameworkVersion(),
            'php'       => PHP_VERSION,
            'sapi'      => PHP_SAPI,
            'runtime'   => self::runtime(),
            'opcache'   => function_exists('opcache_get_status') ? (bool) @opcache_get_status(false) : false,
            'time'      => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 读框架版本号
     *
     * 优先取 Dev/Version 类（若存在），否则回落到 Workerman 版本之外的自定义常量。
     * 注意：这里只读**框架自身**版本，不涉及业务。
     *
     * @return string
     */
    protected static function frameworkVersion(): string
    {
        // 框架可能定义 CANDY_VERSION 常量（存在则用）
        if (defined('CANDY_VERSION')) {
            return (string) CANDY_VERSION;
        }

        // 兜底：从 Candy 目录的签名/版本文档推断不可靠，直接给 unknown
        return 'unknown';
    }

    /**
     * 判定当前运行模式
     *
     * 判据对齐框架自身的 isWebServer()（Candy/Func/bootstrap.func.php:24）：
     *   - 常驻模式（workerman / swoole）会 define('CLIWORKING')
     *   - 其中 isSwooleCoroutine() 为真则是 swoole 协程运行时
     *   - 没有 CLIWORKING 且 SAPI 含 fpm 则是 FPM
     *
     * @return string  fpm | workerman | swoole | cli
     */
    protected static function runtime(): string
    {
        // 常驻模式：框架定义 CLIWORKING（见 isWebServer 的判据）
        if (defined('CLIWORKING')) {
            if (function_exists('isSwooleCoroutine') && isSwooleCoroutine()) {
                return 'swoole';
            }
            return 'workerman';
        }

        $sapi = PHP_SAPI;
        if (stripos($sapi, 'fpm') !== false) {
            return 'fpm';
        }
        if ($sapi === 'cli') {
            return 'cli';
        }
        return 'other';
    }
}
