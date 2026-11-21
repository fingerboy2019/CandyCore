<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 调试时请求类型
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ContentType extends AbstractAnnotation
{
    /**
     * @param string $value 调试时请求类型
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
