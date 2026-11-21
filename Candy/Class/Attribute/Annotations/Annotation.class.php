<?php
/**
 * 框架权限/菜单注解（Node 扫描）
 *
 * 控制器方法上使用 #[Annotation(title:, auth:, menu:, order:)]；全局名 Annotation 由
 * Candy/Class/Vendor/Annotation.class.php 桥接，应用层无需改 use。
 *
 * @package Candy\Extend\Attribute\Annotations
 */
declare(strict_types=1);

namespace Candy\Extend\Attribute\Annotations;

#[\Attribute(\Attribute::TARGET_ALL | \Attribute::IS_REPEATABLE)]
class Annotation
{
    /** @var array<string, mixed> */
    private array $args = [];

    public function __construct(...$params)
    {
        $keys = array_keys($params);
        $values = array_values($params);
        foreach ($keys as $i => $key) {
            $this->args[$key] = $values[$i];
        }
    }

    public function get(): array
    {
        $tmpArgs = [];
        foreach ($this->args as $key => $value) {
            $tmpArgs[ucfirst($key)] = $value;
        }

        foreach (['Auth', 'Menu', 'Log'] as $key) {
            $tmpArgs[$key] = isset($tmpArgs[$key]) ? $tmpArgs[$key] === true : false;
        }

        return $tmpArgs;
    }

    public static function data(): array
    {
        return \Candy\Core\Node::getMethodsData();
    }
}
