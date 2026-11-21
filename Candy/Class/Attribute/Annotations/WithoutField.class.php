<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 排除Ref的字段
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class WithoutField extends AbstractAnnotation
{
    /**
     * @param string|array $value 排除Ref的字段，逗号分割
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
