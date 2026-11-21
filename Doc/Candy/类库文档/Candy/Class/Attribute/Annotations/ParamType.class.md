# ParamType 类

## 概述

* 参数类型
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 参数类型
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ParamType extends AbstractAnnotation
{
    /**
     * @param string $value 参数类型，formdata
     */
    public function __construct(...$value)
    {
        parent:()

