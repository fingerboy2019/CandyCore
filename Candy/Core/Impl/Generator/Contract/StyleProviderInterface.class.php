<?php
/***
 * 代码生成器风格扩展接口
 *
 * 项目可在 Source/Generator 下提供目录级 stub，或实现本接口做高级定制
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 21:10:00 $
 *
 * @package Candy\Core\Impl\Generator\Contract
 */
declare(strict_types=1);

namespace Candy\Core\Impl\Generator\Contract;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface StyleProviderInterface
{
    /**
     * 风格标识，与 Source/Generator/{id}/ 目录名一致
     *
     * @return string
     */
    public function getStyleId(): string;

    /**
     * 解析单个 stub 的绝对路径
     *
     * 返回 null 时由框架回退到 default 模板
     *
     * @param string $stubName 如 model.stub、control.crud.stub
     * @return string|null
     */
    public function resolveStub(string $stubName): ?string;

    /**
     * 渲染前扩展上下文（字段映射、注解片段等）
     *
     * @param string $stubName stub 文件名
     * @param array $context 占位符上下文
     * @return array 合并后的上下文
     */
    public function extendContext(string $stubName, array $context): array;
}
