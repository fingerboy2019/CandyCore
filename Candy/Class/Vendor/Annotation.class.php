<?php
/**
 * 全局 Annotation 桥接（保留 #[Annotation] 无命名空间写法）
 *
 * 实现已迁至 Candy\Extend\Attribute\Annotations\Annotation
 *
 * @deprecated 请使用 Candy\Extend\Attribute\Annotations\Annotation；本文件仅作 class_alias 桥接
 */
declare(strict_types=1);

$canonical = \Candy\Extend\Attribute\Annotations\Annotation::class;

if (!class_exists($canonical, false)) {
    require_once dirname(__DIR__) . '/Attribute/Annotations/Annotation.class.php';
}

if (!class_exists('Annotation', false)) {
    class_alias($canonical, 'Annotation');
}

