# Desc 类

## 概述

* 描述
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})

## 主要方法


### /**
 * 描述
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS |\Attribute::TARGET_METHOD |\Attribute::IS_REPEATABLE)]
class Desc extends AbstractAnnotation
{
    /**
     * @param string $value 描述
     */
    public function __construct(...$value)
    {
        parent:()

