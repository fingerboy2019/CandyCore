<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 排序
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Sort extends AbstractAnnotation
{
    /**
     * @param string|int $value 排序
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
