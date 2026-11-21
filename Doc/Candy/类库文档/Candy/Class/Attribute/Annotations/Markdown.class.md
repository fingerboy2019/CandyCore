# Markdown 类

## 概述

* Markdown
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * Markdown
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Markdown extends AbstractAnnotation
{
    /**
     * 引入md内容
     * @var string|array
     */
    public $ref;

    /**
     * @param string $name Markdown文档内容
     * @param string $ref 引入md文件路径
     */
    public function __construct(
        $name = '',
        string $ref = ''
    )
    {
        parent:()

