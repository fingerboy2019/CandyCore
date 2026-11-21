<?php
/**
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
 */

namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Xml {
	private static $xml = null;
	private static array $xmlConfig = [];
	private static $elementArr = [];

	/**
	 * 初始化XML配置
	 * 
	 * 该方法重置内部状态变量，加载默认配置并与用户配置合并。
	 * 默认配置包括XML版本(1.0)、编码(UTF-8)和根元素名(root)。
	 * 支持通过配置文件(incpath/xml.php)自定义这些参数。
	 * 
	 * @return void
	 */
	private static function loadXmlConfig(): void
	{
		//初始化
		self::$xml = null;
		self::$elementArr = [];
		$element = ['version'=>'1.0', 'encoding'=>'UTF-8', 'root'=>'root'];
		$configfile = C('INCPATH').'xml.php';
		$mergedConfig = is_file($configfile) ? include($configfile) : [];
		$mergedConfig = array_merge($element, $mergedConfig);
		self::$xmlConfig = $mergedConfig;
	}
	
	/**
	 * 将PHP数组转换为XML格式字符串
	 * 
	 * 该方法接收一个PHP数组，并将其转换为标准的XML格式字符串。
	 * 支持多维数组的递归处理，自动为数值键添加默认的'item'标签。
	 * 自动设置适当的Content-Type头信息，适用于API响应或XML文件生成。
	 * 
	 * @param array $data 待转换的PHP数组，支持多维结构
	 * @return string 转换后的XML格式字符串
	 * 
	 * @example
	 * ```php
	 * // 转换简单数组
	 * $data = ['name' => 'Candy', 'version' => '1.0'];
	 * $xml = Xml::arrayToXml($data);
	 * // 输出: <?xml version="1.0" encoding="UTF-8"?><root><name><![CDATA[Candy]]></name><version>1.0</version></root>
	 * 
	 * // 转换多维数组
	 * $data = ['user' => ['id' => 1, 'name' => '张三', 'roles' => ['admin', 'user']]];
	 * $xml = Xml::arrayToXml($data);
	 * ```
	 */
	public static function arrayToXml(array $data): string
	{
		self::loadXmlConfig();
		self::startDocument();
		self::buildXml($data, false);
		if(isWebServer()){
            // 在CLI环境中输出提示信息
            echo "[Content-Type:text/xml; charset=utf-8]\n";
	}else{
		header('Content-Type:text/xml; charset=utf-8');
	}
		return self::$xml;
	}
	
	/**
	 * 将XML格式字符串转换为PHP数组
	 * 
	 * 该方法接收一个XML格式的字符串，并将其转换为PHP数组结构。
	 * 自动禁用外部实体加载，防止XML外部实体注入(XE)攻击，提高安全性。
	 * 使用LIBXML_NOCDATA选项保留CDATA标签中的数据内容。
	 * 
	 * @param string $xml XML格式的字符串数据
	 * @return array 转换后的PHP数组，保持与XML结构一致的层次关系
	 * 
	 * @example
	 * ```php
	 * // 将XML字符串转换为数组
	 * $xml = '<?xml version="1.0" encoding="UTF-8"?><root><name>Candy</name><version>1.0</version></root>';
	 * $array = Xml::xmlToArray($xml);
	 * // 输出: ['name' => 'Candy', 'version' => '1.0']
	 * 
	 * // 转换包含CDATA的XML
	 * $xml = '<?xml version="1.0" encoding="UTF-8"?><root><content><![CDATA[<p>测试内容</p>]]></content></root>';
	 * $array = Xml::xmlToArray($xml);
	 * // 输出: ['content' => '<p>测试内容</p>']
	 * ```
	 */
	public static function xmlToArray(string $xml): array
	{
		// PHP 8.0+ 中 libxml2 2.9.0+ 已默认禁用外部实体加载，无需手动设置
		if(PHP_VERSION_ID < 80000){
			libxml_disable_entity_loader(true);
		}
		$xmlstring = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
		$val = json_decode(json_encode($xmlstring),true);
		return $val;
	} 
	
	/**
	 * 递归构建XML结构
	 * 
	 * 该方法递归遍历数组，构建完整的XML结构。处理根元素和子元素的创建、嵌套关系，
	 * 以及数值键的特殊处理。
	 * 
	 * @param array $data 要构建XML的数组数据
	 * @param bool $eIsArray 是否为子数组的标记，用于判断是否需要添加根元素
	 * @return void
	 */
	private static function buildXml(array $data,bool $eIsArray=false): void
	{
		if(!$eIsArray){
			self::startElement(self::$xmlConfig['root']);
		}
		foreach($data as $key => $value){
			if(is_numeric($key)){
				$key  = 'item';
			}
			if(is_array($value)){
				self::startElement($key);
				self::buildXml($value, TRUE);
				self::endElement();
				continue;
			}
			self::writeElement($key, $value);
		}
		if(!$eIsArray){
			self::endElement();
		}
	}
	
	/**
	 * 创建并写入XML元素内容
	 * 
	 * 该方法创建一个XML元素，根据值的类型决定是否使用CDATA标签包裹。
	 * 数值类型直接写入，字符串类型使用CDATA标签包裹以避免特殊字符解析问题。
	 * 
	 * @param string $key XML元素的名称
	 * @param mixed $value XML元素的值，可以是字符串、数值或其他类型
	 * @return void
	 */
	private static function writeElement(string $key,mixed $value): void
	{
		self::startElement($key);
		if(is_numeric($value)){
			self::$xml .= $value;
		}else{
			self::$xml .= '<![CDATA['.$value.']]>';
		}
		self::endElement();
	}

	/**
	 * 创建XML元素的开始标签
	 * 
	 * 该方法生成并添加XML元素的开始标签，并将元素名称添加到内部栈中以跟踪嵌套关系。
	 * 
	 * @param string $element 元素的名称
	 * @return void
	 */
	private static function startElement(string $element): void
	{
		self::$xml .= "<$element>";
		self::$elementArr[] = $element;
	}
	
	/**
	 * 创建XML文档声明
	 * 
	 * 该方法生成并添加XML文档的声明部分，包含版本和编码信息。
	 * 声明内容基于配置参数生成。
	 * 
	 * @return void
	 */
	private static function startDocument(): void
	{
		self::$xml .= '<?xml version="'. self::$xmlConfig['version'] .'" encoding="'. self::$xmlConfig['encoding'] .'"?>';
	}
	
	/**
	 * 创建XML元素的结束标签
	 * 
	 * 该方法从内部栈中弹出最近添加的元素名称，并生成相应的结束标签。
	 * 确保XML结构的正确嵌套和闭合。
	 * 
	 * @return void
	 */
	private static function endElement(): void
	{
		$element = array_pop(self::$elementArr);
		self::$xml .= "</$element>";
	}	
}

