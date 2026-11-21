# ContentType 类

## 概述

* 调试时请求类型
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 调试时请求类型
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ContentType extends AbstractAnnotation
{
    /**
     * @param string $value 调试时请求类型
     */
    public function __construct(...$value)
    {
        parent:()

