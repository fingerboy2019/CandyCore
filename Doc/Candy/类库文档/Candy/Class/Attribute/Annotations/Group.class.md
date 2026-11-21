# Group 类

## 概述

* 分组
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"CLASS"})

## 主要方法


### /**
 * 分组
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Group extends AbstractAnnotation
{
    /**
     * @param string $name 分组
     */
    public function __construct(...$value)
    {
        parent:()

