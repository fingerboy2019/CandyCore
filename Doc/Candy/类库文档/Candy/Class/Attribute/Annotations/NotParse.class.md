# NotParse 类

## 概述

* 标记不解析的控制器/方法
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})

## 主要方法


### /**
 * 标记不解析的控制器/方法
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotParse extends AbstractAnnotation
{
    /**
     * @param string $value 不解析
     */
    public function __construct(...$value)
    {
        parent:()

