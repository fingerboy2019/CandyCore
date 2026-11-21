# Title 类

## 概述

* 标题
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})

## 主要方法


### /**
 * 标题
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Title extends AbstractAnnotation
{
    /**
     * @param string $name 控制器/接口名称
     */
    public function __construct(...$value)
    {
        parent:()

