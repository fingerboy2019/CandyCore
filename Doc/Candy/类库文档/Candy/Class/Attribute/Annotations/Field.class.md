# Field 类

## 概述

* 指定Ref的字段
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 指定Ref的字段
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Field extends AbstractAnnotation
{
    /**
     * @param string|array $value 指定Ref的字段，逗号分割
     */
    public function __construct(...$value)
    {
        parent:()

