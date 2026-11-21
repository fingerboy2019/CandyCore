<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * Tag
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Tag extends AbstractAnnotation
{
    /**
     * @param string $value Tag
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
