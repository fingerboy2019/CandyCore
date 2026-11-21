<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/19
 */

namespace QL\Dom;

use \QL\phpQuery\phpQueryObject;

/**
 * DOM操作类
 * 提供DOM元素查找和操作功能
 */
class Dom
{
    /**
     * phpQuery文档对象
     * @var phpQueryObject
     */
    protected $document;

    /**
     * Dom构造函数
     *
     * @param phpQueryObject $document phpQuery文档对象
     */
    public function __construct(phpQueryObject $document)
    {
        $this->document = $document;
    }

    /**
     * 查找DOM元素
     *
     * @param string $selector CSS选择器
     * @return Elements 元素集合对象
     */
    public function find($selector)
    {
        $elements =  $this->document->find($selector);
        return new Elements($elements);
    }
}