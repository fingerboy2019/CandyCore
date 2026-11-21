# ResponseSuccessMarkdown 类

## 概述

* 成功响应体Markdown内容
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 成功响应体Markdown内容
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ResponseSuccessMarkdown extends AbstractAnnotation
{
    /**
     * 引入md内容
     * @var string
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

