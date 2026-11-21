<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/**
 * 闭包辅助类，用于获取闭包的描述信息。
 *
 * 该类提供了获取闭包函数的详细描述的方法，包括函数名、所属类（如果是类方法）以及定义位置。
 *
 * @internal
 */
final class ClosureHelper
{
    /**
     * 获取闭包的描述信息。
     *
     * 该方法通过反射获取闭包的详细信息，包括函数名、所属类（如果是类方法）以及定义位置。
     * 如果反射失败，则返回 '???'。
     *
     * @param \Closure $closure 要获取描述的闭包对象。
     * @return string 闭包的描述信息，格式为 "类名::方法名 defined in 文件路径:行号"（如果是类方法）或 "函数名 defined in 文件路径:行号"。
     */
    public static function getDescription(\Closure $closure): string
    {
        try {
            $reflection = new \ReflectionFunction($closure);

            $description = $reflection->name;

            if ($scopeClass = $reflection->getClosureScopeClass()) {
                $description = $scopeClass->name . '::' . $description;
            }

            if ($reflection->getFileName() !== false && $reflection->getStartLine()) {
                $description .= " defined in " . $reflection->getFileName() . ':' . $reflection->getStartLine();
            }

            return $description;
        } catch (\ReflectionException) {
            return '???';
        }
    }
}
