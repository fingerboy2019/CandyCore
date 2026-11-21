<?php

namespace Candy\Extend\Attribute\Annotations;

use \Candy\Extend\Attribute\utils\AbstractAnnotation;

/**
 * 标记不解析的控制器/方法
 * @package Candy\Extend\Attribute\Annotations
 * @Annotation
 * @Target({"METHOD","CLASS"})
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NotParse extends AbstractAnnotation
{
    /**
     * @param string $value 不解析
     */
    public function __construct(...$value)
    {
        parent::__construct(...$value);
    }
}
