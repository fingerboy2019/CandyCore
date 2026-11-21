# RouteMiddleware 类

## 概述

* 路由中间件，自动注册路由时有效
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})

## 主要方法


### /**
 * 路由中间件，自动注册路由时有效
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class RouteMiddleware extends AbstractAnnotation
{
    /**
     * 路由中间件，自动注册路由时有效
     * @param array $value
     */
    public function __construct(...$value)
    {
        parent:()

