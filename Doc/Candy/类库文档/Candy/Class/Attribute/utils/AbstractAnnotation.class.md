# AbstractAnnotation 类

## 概述

* AbstractAnnotation 抽象类
 * 
 * 注解处理的抽象基类，提供注解参数格式化和属性设置的基础功能
 * 所有具体的注解处理器都应该继承此类并实现data()方法
 * 
 * @package Candy\Extend\Attribute\utils
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-03 20:08:03
 * @abstract 注解处理的抽象基类，提供注解参数格式化和属性设置的基础功能

## 主要方法


### /**
 * AbstractAnnotation 抽象类
 * 
 * 注解处理的抽象基类，提供注解参数格式化和属性设置的基础功能
 * 所有具体的注解处理器都应该继承此类并实现data()方法
 * 
 * @package Candy\Extend\Attribute\utils
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-03 20:08:03
 * @abstract 注解处理的抽象基类，提供注解参数格式化和属性设置的基础功能
 */

declare (strict_types=1);

namespace Candy\Extend\Attribute\utils;

abstract class AbstractAnnotation
{

    /**
     * 注解的主要值或名称
     * 
     * 当注解参数中包含'value'键但类中不存在value属性时，该值会被赋值给此属性
     * 也可以直接通过数组形式的'name'键设置
     * 
     * @var mixed
     */
    public $name;

    /**
     * 构造函数，处理注解参数并设置对象属性
     * 
     * 接收注解参数，通过formatParams方法格式化后，将参数作为对象属性设置
     * 特殊处理'value'键，如果类中不存在value属性，则将其值赋给name属性
     * 
     * @param mixed ...$value 注解参数，可以是单个值或关联数组
     */
    public function __construct(...$value)
    {
        $formattedValue = $this->formatParams($value);
        foreach ($formattedValue as $key => $val) {
            if ($key=="value" && !property_exists($this, $key)){
                $this->name = $val;
            }else{
                $this->{$key} = $val;
            }
        }
    }

    /**
     * 格式化注解参数，确保返回规范化的关联数组
     * 
     * 处理传入的参数，将其转换为标准格式：
     * 1. 如果是数组且只有一个元素，则取第一个元素
     * 2. 如果不是数组，则封装为['name' => $value]的关联数组
     * 3. 如果是嵌套数组且包含'value'键，则提取'value'值并递归处理
     * 4. 如果是索引数组且包含多个元素，则使用反射获取构造函数参数名，将索引数组转换为关联数组
     * 
     * @param mixed $value 需要格式化的参数值
     * @return array 格式化后的关联数组参数
     */
    protected function formatParams($value):()


### /**
     * 魔术方法，当调用不存在的方法时触发
     * 
     * 主要用于提示子类实现data()方法，这是所有注解处理器必须实现的方法
     * 当子类没有实现data()方法时，调用任何方法都会触发此提示
     * 
     * @param string $name 调用的方法名
     * @param array $arguments 方法参数
     * @return string 错误提示信息
     */
    public function __call($name, $arguments):()

