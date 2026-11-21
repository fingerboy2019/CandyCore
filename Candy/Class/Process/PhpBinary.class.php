<?php
/**
 * PHP 解释器定位
 *
 * 解决一个反复踩的坑：**CLI 与 FPM 是两套 PHP**。
 * 开发机 `/usr/bin/php` 是 8.4.2 但没有 PDO 驱动、没有 mbstring；
 * 生产机默认 `php` 是 8.3 且缺 pdo_pgsql/redis，必须用 `/www/server/php/85/bin/php`。
 * 所以「起子进程时用哪个 php」绝不能写死，也不能靠 PATH。
 *
 * 解析优先级：
 *  1. 环境变量 CANDY_PHP_BIN
 *  2. 配置 C('PHP_BIN') / C('PHPBIN')
 *  3. 当前 SAPI 为 cli 时，用 PHP_BINARY（就是它自己，绝对可靠）
 *  4. PHP_BINDIR 下的 php / php.exe
 *  5. 兜底返回 'php'（交给 PATH，需调用方知晓风险）
 *
 * @package Candy\Extend\Process
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 11:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Process;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class PhpBinary
{
    /**
     * 已解析结果（每次请求只解析一次）
     *
     * @var string|null
     */
    private static ?string $resolved = null;

    /**
     * 解析结果来源，便于 env 命令与排障时说明「这个值是哪来的」
     *
     * @var string
     */
    private static string $source = '';

    /**
     * 取 PHP 二进制路径
     *
     * @return string
     */
    public static function resolve(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        return self::$resolved = self::detect();
    }

    /**
     * 取解析来源
     *
     * @return string
     */
    public static function source(): string
    {
        if (self::$resolved === null) {
            self::detect();
        }
        return self::$source;
    }

    /**
     * 清空缓存（改配置后调用）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$resolved = null;
        self::$source = '';
    }

    /**
     * 是否拿到了绝对路径（否则就是交给 PATH 的裸名）
     *
     * @return bool
     */
    public static function isAbsolute(): bool
    {
        $bin = self::resolve();
        return str_contains($bin, '/') || str_contains($bin, '\\');
    }

    /**
     * 环境自省信息，供 `php candy env` 输出
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return [
            'binary'  => self::resolve(),
            'source'  => self::source(),
            'sapi'    => PHP_SAPI,
            'version' => PHP_VERSION,
            'bindir'  => defined('PHP_BINDIR') ? PHP_BINDIR : '',
        ];
    }

    /**
     * 实际探测
     *
     * @return string
     */
    private static function detect(): string
    {
        $env = getenv('CANDY_PHP_BIN');
        if (is_string($env) && $env !== '' && self::usable($env)) {
            self::$source = 'env:CANDY_PHP_BIN';
            return $env;
        }

        foreach (['PHP_BIN', 'PHPBIN'] as $key) {
            $val = function_exists('C') ? C($key) : null;
            if (is_string($val) && $val !== '' && self::usable($val)) {
                self::$source = 'config:' . $key;
                return $val;
            }
        }

        if (in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            $self = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
            if ($self !== '' && self::usable($self)) {
                self::$source = 'PHP_BINARY';
                return $self;
            }
        }

        $bindir = defined('PHP_BINDIR') ? (string) PHP_BINDIR : '';
        if ($bindir !== '') {
            $names = PHP_OS_FAMILY === 'Windows'
                ? ['php.exe', 'php8.exe']
                : ['php', 'php8.5', 'php8.4', 'php8.3'];
            foreach ($names as $name) {
                $candidate = rtrim($bindir, '/\\') . DIRECTORY_SEPARATOR . $name;
                if (self::usable($candidate)) {
                    self::$source = 'PHP_BINDIR/' . $name;
                    return $candidate;
                }
            }
            // FPM 下宝塔常设 open_basedir，is_file() 会被限制而对真实存在的文件返回 false。
            // 此时不能据此判定「不存在」—— PHP_BINDIR 是 PHP 自己报的，直接采信更可靠。
            if (self::openBasedirRestricted()) {
                self::$source = 'PHP_BINDIR/' . $names[0] . '(open_basedir 下未校验)';
                return rtrim($bindir, '/\\') . DIRECTORY_SEPARATOR . $names[0];
            }
        }

        self::$source = 'fallback:PATH';
        return 'php';
    }

    /**
     * 是否处于 open_basedir 限制下（此时 is_file 结果不可信）
     *
     * @return bool
     */
    private static function openBasedirRestricted(): bool
    {
        $open = ini_get('open_basedir');
        return is_string($open) && trim($open) !== '';
    }

    /**
     * 判断该值是否可直接使用
     *
     * 含路径分隔符的必须真实存在（Windows 上 is_executable 不可靠，只判 is_file）；
     * 裸文件名（如 'php'）视为交给 PATH，不做检查。
     *
     * @param string $bin 候选路径
     * @return bool
     */
    private static function usable(string $bin): bool
    {
        if (str_contains($bin, '/') || str_contains($bin, '\\')) {
            return is_file($bin);
        }
        return true;
    }
}
