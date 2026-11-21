<?php
/***
 * 方法级切面注解
 *
 * 用法（标在任意有方法体的 public/protected 方法上）：
 *   #[Aspect(LogAspect::class)]
 *   public function updateAssignees(int $id, array $users): void { ... }
 *
 *   #[Aspect(LogAspect::class, priority: 10)]      // 优先级大的先执行
 *   #[Aspect(CacheAspect::class, priority: 5)]     // 可叠加多个（IS_REPEATABLE）
 *   public function listTasks(array $filter): array { ... }
 *
 * 编译期会把被标注的方法体包一层切面调用，运行时执行 order 由 priority 决定。
 *
 * ⚠️ 只有「有方法体的方法」会被织入：抽象方法 / 接口方法 / __construct / 静态方法 自动跳过。
 * ⚠️ 织入后会用 php -l 自检，失败则回退原文 —— 宁可没切面，不能让整类编译坏掉。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-14 11:12:00 $
 *
 * @package Candy\Core\Attribute
 */

declare(strict_types=1);

namespace Candy\Core\Attribute;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Aspect
{
    /**
     * @param string $class    切面类名（完整 FQCN 或简称，实现 AspectInterface）
     * @param int    $priority 优先级，大的先执行（默认 0）
     */
    public function __construct(
        public string $class,
        public int $priority = 0
    ) {
    }

    /**
     * 兼容框架注解解析器
     *
     * Candy 的 Tool::parseMethodAnnotation() 会对类名前缀匹配的注解统一调 get()，
     * 期望拿到可合并进 Node 权限数据的数组。本注解不参与权限合并，返回空数组即可。
     *
     * @return array
     */
    public function get(): array
    {
        return [];
    }
}
