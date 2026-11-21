<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 异常响应体的Markdown内容
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ResponseErrorMarkdown extends AbstractAnnotation
{
    /**
     * 引入md文件
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
        parent::__construct(...func_get_args());
    }
}
