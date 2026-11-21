# Method 类

## 概述

* 请求类型
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 请求类型
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Method extends AbstractAnnotation
{
    /**
     * @param string $value 请求类型
     */
    public function __construct(...$value)
    {
        parent:()

