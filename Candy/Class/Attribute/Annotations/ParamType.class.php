<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 参数类型
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class ParamType extends AbstractAnnotation
{
    /**
     * @param string $value 参数类型，formdata
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
