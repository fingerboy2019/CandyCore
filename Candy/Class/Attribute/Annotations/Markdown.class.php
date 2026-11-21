<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * Markdown
 * @package Candy\Extend\Attribute\Annotations
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
        parent::__construct(...func_get_args());
    }
}
