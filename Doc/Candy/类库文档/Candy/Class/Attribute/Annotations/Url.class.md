# Url 类

## 概述

* 接口Url
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 接口Url
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Url extends AbstractAnnotation
{
    /**
     * @param string $value 接口Url
     */
    public function __construct(...$value)
    {
        parent:()

