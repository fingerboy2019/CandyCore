<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 不使用默认作者
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotDefaultAuthor extends AbstractAnnotation
{

    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
