<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 描述
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS |\Attribute::TARGET_METHOD |\Attribute::IS_REPEATABLE)]
class Desc extends AbstractAnnotation
{
    /**
     * @param string $value 描述
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
