# Xml 类

## 概述

* XML操作类
 * 
 * 该类提供了XML和数组之间的相互转换功能，支持简单和复杂的XML结构处理。
 * 主要功能包括将PHP数组转换为标准XML格式字符串，以及将XML字符串解析为PHP数组。
 * 
 * 特点：
 * - 支持自定义XML版本、编码格式和根元素
 * - 自动处理CDATA标签，确保特殊字符被正确转义
 * - 内置防XML外部实体注入(XE)功能，提高安全性
 * - 支持CLI和Web环境下的Content-Type设置
 * 
 * 使用场景：
 * - API接口数据格式转换
 * - 配置文件解析与生成
 * - 数据交换与导入导出
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2019-08-05 14:00:00
 * @final 该类为最终类，不允许继承

## 主要方法


### arrayToXml()


### xmlToArray()


### /**
	 * 递归构建XML结构
	 * 
	 * 该方法递归遍历数组，构建完整的XML结构。处理根元素和子元素的创建、嵌套关系，
	 * 以及数值键的特殊处理。
	 * 
	 * @param array $data 要构建XML的数组数据
	 * @param bool $eIsArray 是否为子数组的标记，用于判断是否需要添加根元素
	 * @return void
	 */
	private static function buildXml(array $data,bool $eIsArray=false):()


### /**
	 * 创建并写入XML元素内容
	 * 
	 * 该方法创建一个XML元素，根据值的类型决定是否使用CDATA标签包裹。
	 * 数值类型直接写入，字符串类型使用CDATA标签包裹以避免特殊字符解析问题。
	 * 
	 * @param string $key XML元素的名称
	 * @param mixed $value XML元素的值，可以是字符串、数值或其他类型
	 * @return void
	 */
	private static function writeElement(string $key,mixed $value):()


### /**
	 * 创建XML元素的开始标签
	 * 
	 * 该方法生成并添加XML元素的开始标签，并将元素名称添加到内部栈中以跟踪嵌套关系。
	 * 
	 * @param string $element 元素的名称
	 * @return void
	 */
	private static function startElement(string $element):()


### /**
	 * 创建XML文档声明
	 * 
	 * 该方法生成并添加XML文档的声明部分，包含版本和编码信息。
	 * 声明内容基于配置参数生成。
	 * 
	 * @return void
	 */
	private static function startDocument():()


### /**
	 * 创建XML元素的结束标签
	 * 
	 * 该方法从内部栈中弹出最近添加的元素名称，并生成相应的结束标签。
	 * 确保XML结构的正确嵌套和闭合。
	 * 
	 * @return void
	 */
	private static function endElement():()

