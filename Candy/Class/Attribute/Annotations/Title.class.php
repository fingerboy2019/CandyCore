<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 标题
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Title extends AbstractAnnotation
{
    /**
     * @param string $name 控制器/接口名称
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
