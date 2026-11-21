# Sort 类

## 概述

* 排序
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"CLASS"})

## 主要方法


### /**
 * 排序
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Sort extends AbstractAnnotation
{
    /**
     * @param string|int $value 排序
     */
    public function __construct(...$value)
    {
        parent:()

