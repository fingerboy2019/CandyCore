<?php
/***
 * 应用骨架生成（封装 Build::bulidSpaces）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Impl\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Impl\Generator;

use Candy\Core\Build;
use Candy\Core\Trait\Generator\Naming;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Scaffold
{
    use Naming;

    /**
     * 创建 Application / Addon / Plugin 骨架
     *
     * @param string $name 应用名
     * @param string $type 空=应用 addon|plug
     * @return GenerateResult
     */
    public static function run(string $name, string $type = ''): GenerateResult
    {
        $app = self::normalizeAppName($name);
        if ($app === '') {
            return new GenerateResult(errors: ['invalid app name']);
        }

        $ok = Build::bulidSpaces($app, $type);
        if (!$ok) {
            return new GenerateResult(errors: ['scaffold failed or already exists: ' . $app]);
        }

        $root = rtrim((string)C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR . 'Application' . DIRECTORY_SEPARATOR . $app;
        return new GenerateResult(created: is_dir($root) ? [$root] : []);
    }
}
