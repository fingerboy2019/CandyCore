# WithoutField 类

## 概述

* 排除Ref的字段
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 排除Ref的字段
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class WithoutField extends AbstractAnnotation
{
    /**
     * @param string|array $value 排除Ref的字段，逗号分割
     */
    public function __construct(...$value)
    {
        parent:()

