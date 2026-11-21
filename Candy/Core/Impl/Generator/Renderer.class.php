<?php
/***
 * 代码生成器模板渲染
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Impl\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Impl\Generator;

use Candy\Core\Impl\Generator\Contract\StyleProviderInterface;
use Candy\Core\Trait\Generator\Placeholder;
use Candy\Core\Trait\Generator\TemplateResolver;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Renderer
{
    use TemplateResolver;
    use Placeholder;

    /**
     * 渲染 stub 为最终代码
     *
     * @param string $styleId 风格标识（default 或 Source/Generator 下扩展名）
     * @param string $stubName stub 文件名
     * @param array<string, scalar> $context 占位符
     * @return string|null 失败返回 null
     */
    public static function render(string $styleId, string $stubName, array $context): ?string
    {
        $path = self::resolveGeneratorStub($styleId, $stubName);
        if ($path === null) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $provider = self::makeStyleProvider($styleId);
        if ($provider instanceof StyleProviderInterface) {
            $context = $provider->extendContext($stubName, $context);
        }

        return self::renderPlaceholders($content, $context);
    }
}
