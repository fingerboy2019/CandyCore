<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 成功响应体Markdown内容
 * @package Candy\Extend\Attribute\Annotations
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
        parent::__construct(...func_get_args());
    }
}
