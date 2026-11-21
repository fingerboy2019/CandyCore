# RouteParam 类

## 概述

* 路由参数
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 路由参数
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class RouteParam extends ParamBase
{
    /**
     * @param string $name 字段名
     * @param string $type 字段类型
     * @param bool $requiredd 是否必须
     * @param string|int|bool $default 默认值
     * @param string|array $ref 引用注解/模型
     * @param string|array $field 指定Ref引入的字段
     * @param string|array $withoutField 排除Ref引入的字段
     * @param string $desc 字段名称
     * @param string $md Md文本内容
     * @param string $mock Mock规则
     */
    public function __construct(
        $name = '',
        string $type = '',
        bool $required = false,
        $default = "",
        $ref = "",
        $field = "",
        $withoutField = "",
        string $desc = '',
        string $md = "",
        string $mock = "",
        ...$attrs
    )
    {
        parent:()

