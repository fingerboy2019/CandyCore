# Tag 类

## 概述

* Tag
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * Tag
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Tag extends AbstractAnnotation
{
    /**
     * @param string $value Tag
     */
    public function __construct(...$value)
    {
        parent:()

