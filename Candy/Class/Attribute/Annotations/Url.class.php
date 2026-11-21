<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 接口Url
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Url extends AbstractAnnotation
{
    /**
     * @param string $value 接口Url
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
