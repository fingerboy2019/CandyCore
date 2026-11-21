# Header 类

## 概述

* 请求头
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})

## 主要方法


### /**
 * 请求头
 * @package Candy\Extend\Attribute\annotations
 * @Annotation
 * @Target({"METHOD"})
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Header extends ParamBase
{


    /**
     * mock
     * @var string
     */
    public $mock;

    /**
     * @param string $name 字段名
     * @param string $type 字段类型
     * @param string $desc 字段名称
     * @param bool $requiredd 是否必须
     * @param string $default 默认值
     * @param string|array $ref 引用注解/模型
     * @param string $md Md文本内容
     * @param string|array $field 指定Ref引入的字段
     * @param string|array $withoutField 排除Ref引入的字段
     * @param string $mock Mock规则
     */
    public function __construct(
        $name = '',
        string $type = '',
        string $desc = '',
        bool   $require = false,
        string $default = "",
        $ref = "",
        string $md = "",
        $field = "",
        $withoutField = "",
        string $mock = ""
    )
    {
        parent:()

