<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 分组
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Group extends AbstractAnnotation
{
    /**
     * @param string $name 分组
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
