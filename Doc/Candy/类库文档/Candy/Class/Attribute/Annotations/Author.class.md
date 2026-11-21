# Author 类

## 概述

* 作者
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 作者
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Author extends AbstractAnnotation
{
    /**
     * @param string $value 作者名称
     */
    public function __construct(...$value)
    {
        parent:()

