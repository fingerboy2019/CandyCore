<?php
/***
 * Candy框架 内置phpQuery类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2023-06-28 10:14:25 $   
 */

declare(strict_types=1);
namespace QL;
use \QL\phpQuery\{DOMEvent,DOMDocumentWrapper,phpQueryEvents,Callback,phpQueryObject};
use \Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

// 继承类名
define('DOMDOCUMENT', 'DOMDocument');
define('DOMELEMENT', 'DOMElement');
define('DOMNODELIST', 'DOMNodeList');
define('DOMNODE', 'DOMNode');

//补充方法
requireCache(CANDYPATH . 'Candy/Vendor/QL/phpQuery/compat/mbstring.php');

/**
 * phpQuery核心类
 * 基于jQuery语法的PHP DOM操作库，提供简洁的API用于HTML/XML文档操作
 */
abstract class phpQuery {
	/**
	 * 是否支持mbstring扩展
	 * @var bool
	 */
	public static $mbstringSupport = true;
	
	/**
	 * 是否开启调试模式
	 * @var bool
	 */
	public static $debug = false;
	
	/**
	 * 文档集合
	 * @var array
	 */
	public static $documents = [];
	
	/**
	 * 默认文档ID
	 * @var string|null
	 */
	public static $defaultDocumentID = null;
	
	/**
	 * 默认HTML文档类型声明，仅适用于HTML
	 * @var string
	 */
	public static $defaultDoctype = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">';
	
	/**
	 * 默认字符集
	 * @var string
	 */
	public static $defaultCharset = 'UTF-8';
	
	/**
	 * 插件实例
	 * @var phpQueryPlugins
	 */
	public static $plugins = [];
	
	/**
	 * 已加载的插件列表
	 * @var array
	 */
	public static $pluginsLoaded = [];
	
	/**
	 * 插件方法映射
	 * @var array
	 */
	public static $pluginsMethods = [];
	
	/**
	 * 插件静态方法映射
	 * @var array
	 */
	public static $pluginsStaticMethods = [];
	
	/**
	 * 扩展方法映射
	 * @var array
	 */
	public static $extendMethods = [];
	
	/**
	 * 扩展静态方法映射
	 * @var array
	 */
	public static $extendStaticMethods = [];
	
	/**
	 * AJAX允许的主机列表
	 * @var array
	 */
	public static $ajaxAllowedHosts = ['.'];
	
	/**
	 * AJAX设置
	 * @var array
	 */
	public static $ajaxSettings = [
		'url' => '', //TODO
		'global' => true,
		'type' => "GET",
		'timeout' => null,
		'contentType' => "application/x-www-form-urlencoded",
		'processData' => true,
		//		'async' => true,
		'data' => null,
		'username' => null,
		'password' => null,
		'accepts' => [
			'xml' => "application/xml, text/xml",
			'html' => "text/html",
			'script' => "text/javascript, application/javascript",
			'json' => "application/json, text/javascript",
			'text' => "text/plain",
			'_default' => "*/*"
		]
	];
	
	/**
	 * 最后修改时间
	 * @var null
	 */
	public static $lastModified = null;
	
	/**
	 * 活跃请求数
	 * @var int
	 */
	public static $active = 0;
	
	/**
	 * 转储计数
	 * @var int
	 */
	public static $dumpCount = 0;
	/**
	 * 入口方法类似与 $.()
	 *
	 * 1. 将标记导入现有文档（无任何附加）:
	 * - 导入所选文档:
	 *   pq('<div/>')				//接受输入字符串开头的文本节点
	 * - 根据ID导入文档 ID来自$pq->getDocumentID():
	 *   pq('<div/>', $pq->getDocumentID())
	 * - 导入到DOMNode所属的同一文档中:
	 *   pq('<div/>', DOMNode)
	 * - 从phpQuery对象导入文档:
	 *   pq('<div/>', $pq)
	 *
	 * 2. 执行query方法:
	 * - 根据样式节点查找:
	 *   pq('div.myClass')
	 * - 根据ID查找 ID来自$pq->getDocumentID():
	 *   pq('div.myClass', $pq->getDocumentID())
	 * - 从DOMNode所属的同一文档中查找:
	 *   pq('div.myClass', DOMNode)
	 * - 从phpQuery对象查找:
	 *   pq('div.myClass', $pq)
	 *
	 * @param string|DOMNode|DOMNodeList|array	$arg1	HTML标记、CSS选择器、DOMNode或DOMNode数组
	 * @param string|phpQueryObject|DOMNode	$context	DOM ID 、phpQuery对象 或DOMNode 
	 *
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery|QueryTemplatesPhpQuery|false
	 * phpQuery object or false in case of error.
	 */
	public static function pq($arg1, $context = null)
	{
		if ($arg1 instanceof \DOMNODE && !isset($context)) {
			foreach(self::$documents as $documentWrapper) {
				$compare = $arg1 instanceof \DOMDocument ? $arg1 : $arg1->ownerDocument;
				if ($documentWrapper->document->isSameNode($compare))
					$context = $documentWrapper->id;
			}
		}
		if (!$context) {
			$domId = self::$defaultDocumentID;
			if (!$domId)
				Debug::showMessage('无法使用为创建的DOM，请先使用self::newDocument()进行创建。');
			
		} else if (is_object($context) && $context instanceof phpQueryObject)
			$domId = $context->getDocumentID();
		else if ($context instanceof \DOMDOCUMENT) {
			$domId = self::getDocumentID($context);
			if (! $domId) {
				$domId = self::newDocument($context)->getDocumentID();
			}
		} else if ($context instanceof \DOMNODE) {
			$domId = self::getDocumentID($context);
			if (!$domId) {
				Debug::showMessage('Orphaned DOMNode。');
			}
		} else
			$domId = $context;
		if ($arg1 instanceof phpQueryObject) {
			if ($arg1->getDocumentID() == $domId)
				return $arg1;
			$class = get_class($arg1);
			// 通过将旧对象传递给重载构造函数来支持继承
			$phpQuery = $class != 'phpQuery\phpQuery' ? new $class($arg1, $domId) : new phpQueryObject($domId);
			$phpQuery->elements = [];
			foreach($arg1->elements as $node)
				$phpQuery->elements[] = $phpQuery->document->importNode($node, true);
				
			return $phpQuery;
		} else if ($arg1 instanceof \DOMNODE || (is_array($arg1) && isset($arg1[0]) && $arg1[0] instanceof \DOMNODE)) {
			/*
			 * 使用phpQuery对象包装DOM节点，在需要时导入到文档中:
			 * pq(array($domNode1, $domNode2))
			 */
			$phpQuery = new phpQueryObject($domId);
			if (!($arg1 instanceof \DOMNODELIST) && ! is_array($arg1))
				$arg1 = [$arg1];
			
			$phpQuery->elements = [];
			foreach($arg1 as $node) {
				$sameDocument = $node->ownerDocument instanceof \DOMDOCUMENT && ! $node->ownerDocument->isSameNode($phpQuery->document);
				$phpQuery->elements[] = $sameDocument ? $phpQuery->document->importNode($node, true) : $node;
			}
			return $phpQuery;
		} else if (self::isMarkup($arg1)) {
			/**
			 * Import HTML:
			 * pq('<div/>')
			 */
			$phpQuery = new phpQueryObject($domId);
			return $phpQuery->newInstance(
				$phpQuery->documentWrapper->import($arg1)
			);
		} else {
			/**
			 * Run CSS query:
			 * pq('div.myClass')
			 */
			$phpQuery = new phpQueryObject($domId);
//			if ($context && ($context instanceof PHPQUERY || is_subclass_of($context, 'phpQueryObject')))
			if ($context && $context instanceof phpQueryObject)
				$phpQuery->elements = $context->elements;
			else if ($context && $context instanceof \DOMNODELIST) {
				$phpQuery->elements = [];
				foreach($context as $node)
					$phpQuery->elements[] = $node;
			} else if ($context && $context instanceof \DOMNODE)
				$phpQuery->elements = [$context];
			
			return $phpQuery->find($arg1);
		}
	}
	/**
	 * 设置默认文档
	 * to using this method.
	 * $id 通过函数 getDocumentID() 或 getDocumentIDRef() 获取。
	 *
	 * @param unknown_type $id
	 */
	public static function selectDocument($id) {
		$id = self::getDocumentID($id);
		self::debug("Selecting document '$id' as default one");
		self::$defaultDocumentID = self::getDocumentID($id);
	}
	
	/**
	 * 获取phpQueryObject对象
	 * $id 通过函数 getDocumentID() 或 getDocumentIDRef() 获取。
	 *
	 * @see phpQuery::selectDocument()
	 * @param unknown_type $id
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function getDocument($id = null) {
		if ($id)
			self::selectDocument($id);
		else
			$id = self::$defaultDocumentID;
		
		return new phpQueryObject($id);
	}
	/**
	 * 根据标记创建新文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocument($markup = null, $contentType = null) {
		if (!$markup)
			$markup = '';
		
		$documentID = self::createDocumentWrapper($markup, $contentType);
		return new phpQueryObject($documentID);
	}
	/**
	 * 根据标记创建新HTML文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentHTML($markup = null, $charset = null) {
		$contentType = $charset ? ";charset=$charset" : '';
		return self::newDocument($markup, "text/html{$contentType}");
	}
	/**
	 * 根据标记创建新XML文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentXML($markup = null, $charset = null) {
		$contentType = $charset ? ";charset=$charset" : '';
		return self::newDocument($markup, "text/xml{$contentType}");
	}
	/**
	 * 根据标记创建新XHTML文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentXHTML($markup = null, $charset = null) {
		$contentType = $charset ? ";charset=$charset" : '';
		return self::newDocument($markup, "application/xhtml+xml{$contentType}");
	}
	/**
	 * 根据标记创建新PHP文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentPHP($markup = null, $contentType = "text/html") {
		//如果可能，将字符集传递给phpToMarkup（使用DOMDocumentWrapper函数）
		$markup = self::phpToMarkup($markup, self::$defaultCharset);
		return self::newDocument($markup, $contentType);
	}
	
	public static function phpToMarkup($php, $charset = 'utf-8') {
		$regexes = [
			'@(<(?!\\?)(?:[^>]|\\?>)+\\w+\\s*=\\s*)(\')([^\']*)<'.'?php?(.*?)(?:\\?>)([^\']*)\'@s',
			'@(<(?!\\?)(?:[^>]|\\?>)+\\w+\\s*=\\s*)(")([^"]*)<'.'?php?(.*?)(?:\\?>)([^"]*)"@s',
		];
		foreach($regexes as $regex)
			while (preg_match($regex, $php, $matches)) {
				$php = preg_replace_callback($regex, ['phpQuery\phpQuery', '_phpToMarkupCallback'], $php);
			}
		$regex = '@(^|>[^<]*)+?(<\?php(.*?)(\?>))@s';
		
		$php = preg_replace($regex, '\\1<php><!-- \\3 --></php>', $php);
		return $php;
	}
	
	public static function _phpToMarkupCallback($php, $charset = 'utf-8') {
		return $m[1].$m[2]
			.htmlspecialchars("<"."?php".$m[4]."?".">", ENT_QUOTES|ENT_NOQUOTES, $charset)
			.$m[5].$m[2];
	}
	
	public static function _markupToPHPCallback($m) {
		return "<"."?php ".htmlspecialchars_decode($m[1])." ?".">";
	}
	
	/**
	 * 由 phpQuery::php() 根据标记生成的PHP代码的文档
	 *
	 * @param string|phpQueryObject $content
	 * @return string PHP code.
	 */
	public static function markupToPHP($content) {
		if ($content instanceof phpQueryObject)
			$content = $content->markupOuter();
		
		$content = preg_replace_callback('@<php>\s*<!--(.*?)-->\s*</php>@s', ['phpQuery\phpQuery', '_markupToPHPCallback'], $content);
		$regexes = [
			'@(<(?!\\?)(?:[^>]|\\?>)+\\w+\\s*=\\s*)(\')([^\']*)(?:&lt;|%3C)\\?(?:php)?(.*?)(?:\\?(?:&gt;|%3E))([^\']*)\'@s',
			'@(<(?!\\?)(?:[^>]|\\?>)+\\w+\\s*=\\s*)(")([^"]*)(?:&lt;|%3C)\\?(?:php)?(.*?)(?:\\?(?:&gt;|%3E))([^"]*)"@s',
		];
		foreach($regexes as $regex)
			while (preg_match($regex, $content))
				$content = preg_replace_callback(
					$regex, function ($m){
						return $m[1].$m[2].$m[3]."<?php " .str_replace(["%20", "%3E", "%09", "&#10;", "&#9;", "%7B", "%24", "%7D", "%22", "%5B", "%5D"], [" ", ">", "	", "\n", "	", "{", "$", "}", '"', "[", "]"], htmlspecialchars_decode($m[4])) ." ?>".$m[5].$m[2];
					}, $content);
			
		return $content;
	}
	
	/**
	 * 从文件创建新文档
	 *
	 * @param string $file URLs allowed. See File wrapper page at php.net for more supported sources.
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentFile($file, $contentType = null) {
		$documentID = self::createDocumentWrapper(file_get_contents($file), $contentType);
		return new phpQueryObject($documentID);
	}
	
	/**
	 * 从文件创建新HTML文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentFileHTML($file, $charset = null) {
		$contentType = $charset ? ";charset=$charset" : '';
		return self::newDocumentFile($file, "text/html{$contentType}");
	}
	
	/**
	 * 从文件创建新XML文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentFileXML($file, $charset = null) {
		$contentType = $charset ? ";charset=$charset" : '';
		return self::newDocumentFile($file, "text/xml{$contentType}");
	}
	
	/**
	 * 从文件创建新XHTML文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentFileXHTML($file, $charset = null) {
		$contentType = $charset ? ";charset=$charset" : '';
		return self::newDocumentFile($file, "application/xhtml+xml{$contentType}");
	}
	
	/**
	 * 从文件创建新PHP文档
	 *
	 * @param unknown_type $markup
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 */
	public static function newDocumentFilePHP($file, $contentType = null) {
		return self::newDocumentPHP(file_get_contents($file), $contentType);
	}
	
	/**
	 * 加载文档对象(未完成)
	 *
	 * @param $document DOMDocument
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 * @TODO support DOMDocument
	 */
	public static function loadDocument($document) {
		// TODO
		die('TODO loadDocument');
	}
	/**
	 * 创建DOMDocumentWrapper对象
	 *
	 * @param unknown_type $html
	 * @param unknown_type $domId
	 * @return unknown New DOM ID
	 * @todo support PHP tags in input
	 * @todo support passing DOMDocument object from self::loadDocument
	 */
	protected static function createDocumentWrapper($html, $contentType = null, $documentID = null) {
		if (function_exists('domxml_open_mem'))
			Debug::showMessage('phpQuery已不支持PHP4 DOMXML扩展。');
		
		$document = null;
		if ($html instanceof \DOMDOCUMENT) {
			if (self::getDocumentID($html)) {
				// document already exists in phpQuery::$documents, make a copy
				$document = clone $html;
			} else {
				// new document, add it to phpQuery::$documents
				$wrapper = new DOMDocumentWrapper($html, $contentType, $documentID);
			}
		} else {
			$wrapper = new DOMDocumentWrapper($html, $contentType, $documentID);
		}
		// bind document
		self::$documents[$wrapper->id] = $wrapper;
		// remember last loaded document
		self::selectDocument($wrapper->id);
		return $wrapper->id;
	}
	/**
	 * 扩展类命名空间
	 *
	 * @param string|array $target
	 * @param array $source
	 * @TODO support string $source
	 * @return unknown_type
	 */
	public static function extend($target, $source) {
		$targetRef = $targetRef2 = [];
		switch($target) {
			case 'phpQueryObject':
				$targetRef = &self::$extendMethods;
				$targetRef2 = &self::$pluginsMethods;
				break;
			case 'phpQuery\phpQuery':
				$targetRef = &self::$extendStaticMethods;
				$targetRef2 = &self::$pluginsStaticMethods;
				break;
			default:
				Debug::showMessage('不支持$target类型。');
		}
		if (is_string($source))
			$source = [$source => $source];
		foreach($source as $method => $callback) {
			if (isset($targetRef[$method])) {
				self::debug("Duplicate method '{$method}', can\'t extend '{$target}'");
				continue;
			}
			if (isset($targetRef2[$method])) {
				self::debug("Duplicate method '{$method}' from plugin '{$targetRef2[$method]}',"
					." can\'t extend '{$target}'");
				continue;
			}
			$targetRef[$method] = $callback;
		}
		return true;
	}
	/**
	 * 给phpQuery加载扩展
	 *
	 * @param string $class Extending class name. Real class name can be prepended phpQuery_.
	 * @param string $file Filename to include. Defaults to "{$class}.php".
	 */
	public static function plugin($class, $file = null) {
		if (in_array($class, self::$pluginsLoaded))
			return true;
		if (! $file)
			$file = $class.'.php';
		$objectClassExists = class_exists('phpQueryObjectPlugin_'.$class);
		$staticClassExists = class_exists('phpQueryPlugin_'.$class);
		if (! $objectClassExists && ! $staticClassExists)
			require_once($file);
		self::$pluginsLoaded[] = $class;
		// static methods
		if (class_exists('phpQueryPlugin_'.$class)) {
			$realClass = 'phpQueryPlugin_'.$class;
			$vars = get_class_vars($realClass);
			$loop = isset($vars['phpQueryMethods'])
				&& ! is_null($vars['phpQueryMethods'])
				? $vars['phpQueryMethods']
				: get_class_methods($realClass);
			foreach($loop as $method) {
				if ($method == '__initialize')
					continue;
				if (! is_callable([$realClass, $method]))
					continue;
				if (isset(self::$pluginsStaticMethods[$method])) {
					Debug::showMessage("Duplicate method '{$method}' from plugin '{$c}' conflicts with same method from plugin '".self::$pluginsStaticMethods[$method]."'");
					return;
				}
				self::$pluginsStaticMethods[$method] = $class;
			}
			if (method_exists($realClass, '__initialize'))
				call_user_func_array([$realClass, '__initialize'], []);
		}
		// object methods
		if (class_exists('phpQueryObjectPlugin_'.$class)) {
			$realClass = 'phpQueryObjectPlugin_'.$class;
			$vars = get_class_vars($realClass);
			$loop = isset($vars['phpQueryMethods'])
				&& ! is_null($vars['phpQueryMethods'])
				? $vars['phpQueryMethods']
				: get_class_methods($realClass);
			foreach($loop as $method) {
				if (!is_callable([$realClass, $method]))
					continue;
				if (isset(self::$pluginsMethods[$method])) {
					Debug::showMessage("Duplicate method '{$method}' from plugin '{$c}' conflicts with same method from plugin '".self::$pluginsMethods[$method]."'");
					continue;
				}
				self::$pluginsMethods[$method] = $class;
			}
		}
		return true;
	}
	/**
	 * 删除文档数据
	 *
	 * @param mixed $documentID @see phpQuery::getDocumentID() for supported types.
	 */
	public static function unloadDocuments($id = null) {
		if (isset($id)) {
			if ($id = self::getDocumentID($id))
				unset(self::$documents[$id]);
		} else {
			foreach(self::$documents as $k => $v) {
				unset(self::$documents[$k]);
			}
		}
	}
	/**
	 * 解析php代码
	 *
	 * @param phpQuery|string $content
	 * @deprecated
	 * @return string
	 */
	public static function unsafePHPTags($content) {
		return self::markupToPHP($content);
	}
	
	public static function DOMNodeListToArray($DOMNodeList) {
		$array = [];
		if (! $DOMNodeList)
			return $array;
		foreach($DOMNodeList as $node)
			$array[] = $node;
		return $array;
	}
	/**
	 * 检查是否为“<”开头的HTML字符串
	 *
	 * @deprecated
	 * @param String $input
	 * @return Bool
	 * @todo still used ?
	 */
	public static function isMarkup($input) {
		return ! is_array($input) && substr(trim($input), 0, 1) == '<';
	}
	
	public static function debug($text) {
		if (self::$debug)
			print var_dump($text);
	}
	
	/**
	 * Returns source's document ID.
	 *
	 * @param $source DOMNode|phpQueryObject
	 * @return string
	 */
	public static function getDocumentID($source) {
		if ($source instanceof \DOMDOCUMENT) {
			foreach(self::$documents as $id => $document) {
				if ($source->isSameNode($document->document))
					return $id;
			}
		} else if ($source instanceof \DOMNODE) {
			foreach(self::$documents as $id => $document) {
				if ($source->ownerDocument->isSameNode($document->document))
					return $id;
			}
		} else if ($source instanceof phpQueryObject)
			return $source->getDocumentID();
		else if (is_string($source) && isset(self::$documents[$source]))
			return $source;
	}
	/**
	 * Get DOMDocument object related to $source.
	 * Returns null if such document doesn't exist.
	 *
	 * @param $source DOMNode|phpQueryObject|string
	 * @return string
	 */
	public static function getDOMDocument($source) {
		if ($source instanceof \DOMDOCUMENT)
			return $source;
		$source = self::getDocumentID($source);
		return $source
			? self::$documents[$id]['document']
			: null;
	}

	// UTILITIES
	// http://docs.jquery.com/Utilities

	/**
	 *
	 * @return unknown_type
	 * @link http://docs.jquery.com/Utilities/jQuery.makeArray
	 */
	public static function makeArray($obj) {
		$array = [];
		if (is_object($object) && $object instanceof \DOMNODELIST) {
			foreach($object as $value)
				$array[] = $value;
		} else if (is_object($object) && ! ($object instanceof \Iterator)) {
			foreach(get_object_vars($object) as $name => $value)
				$array[0][$name] = $value;
		} else {
			foreach($object as $name => $value)
				$array[0][$name] = $value;
		}
		return $array;
	}
	public static function inArray($value, $array) {
		return in_array($value, $array);
	}
	/**
	 *
	 * @param $object
	 * @param $callback
	 * @return unknown_type
	 * @link http://docs.jquery.com/Utilities/jQuery.each
	 */
	public static function each($object, $callback, $param1 = null, $param2 = null, $param3 = null) {
		$paramStructure = null;
		if (func_num_args() > 2) {
			$paramStructure = func_get_args();
			$paramStructure = array_slice($paramStructure, 2);
		}
		if (is_object($object) && ! ($object instanceof \Iterator)) {
			foreach(get_object_vars($object) as $name => $value)
				self::callbackRun($callback, [$name, $value], $paramStructure);
		} else {
			foreach($object as $name => $value)
				self::callbackRun($callback, [$name, $value], $paramStructure);
		}
	}
	
	public static function map($array, $callback, $param1 = null, $param2 = null, $param3 = null) {
		$result = [];
		$paramStructure = null;
		if (func_num_args() > 2) {
			$paramStructure = func_get_args();
			$paramStructure = array_slice($paramStructure, 2);
		}
		foreach($array as $v) {
			$vv = self::callbackRun($callback, [$v], $paramStructure);
			$vv = self::callbackRun($callback, [$v], $paramStructure);
			if (is_array($vv))  {
				foreach($vv as $vvv)
					$result[] = $vvv;
			} else if ($vv !== null) {
				$result[] = $vv;
			}
		}
		return $result;
	}
	/**
	 *
	 * @param $callback Callback
	 * @param $params
	 * @param $paramStructure
	 * @return unknown_type
	 */
	public static function callbackRun($callback, $params = [], $paramStructure = null) {
		if (!$callback)
			return;
		if ($callback instanceof CallbackParameterToReference) {
			// TODO support ParamStructure to select which $param push to reference
			if (isset($params[0]))
				$callback->callback = $params[0];
			return true;
		}
		if ($callback instanceof Callback) {
			$paramStructure = $callback->params;
			$callback = $callback->callback;
		}
		if (! $paramStructure)
			return call_user_func_array($callback, $params);
		$p = 0;
		foreach($paramStructure as $i => $v) {
			$paramStructure[$i] = $v instanceof CallbackParam
				? $params[$p++]
				: $v;
		}
		return call_user_func_array($callback, $paramStructure);
	}
	/**
	 * Merge 2 phpQuery objects.
	 * @param array $one
	 * @param array $two
	 * @protected
	 * @todo node lists, phpQueryObject
	 */
	public static function merge($one, $two) {
		$elements = $one->elements;
		foreach($two->elements as $node) {
			$exists = false;
			foreach($elements as $node2) {
				if ($node2->isSameNode($node))
					$exists = true;
			}
			if (! $exists)
				$elements[] = $node;
		}
		return $elements;
//		$one = $one->newInstance();
//		$one->elements = $elements;
//		return $one;
	}
	/**
	 *
	 * @param $array
	 * @param $callback
	 * @param $invert
	 * @return unknown_type
	 * @link http://docs.jquery.com/Utilities/jQuery.grep
	 */
	public static function grep($array, $callback, $invert = false) {
		$result = [];
		foreach($array as $k => $v) {
			$r = call_user_func_array($callback, [$v, $k]);
			if ($r === !(bool)$invert)
				$result[] = $v;
		}
		return $result;
	}
	public static function unique($array) {
		return array_unique($array);
	}
	/**
	 *
	 * @param $function
	 * @return unknown_type
	 * @TODO there are problems with non-static methods, second parameter pass it
	 * 	but doesnt verify is method is really callable
	 */
	public static function isFunction($function) {
		return is_callable($function);
	}
	public static function trim($str) {
		return trim($str);
	}
	/* PLUGINS NAMESPACE */
	/**
	 *
	 * @param $url
	 * @param $callback
	 * @param $param1
	 * @param $param2
	 * @param $param3
	 * @return phpQueryObject
	 */
	public static function browserGet($url, $callback, $param1 = null, $param2 = null, $param3 = null) {
		if (self::plugin('WebBrowser')) {
			$params = func_get_args();
			return self::callbackRun([self::$plugins, 'browserGet'], $params);
		} else {
			self::debug('WebBrowser plugin not available...');
		}
	}
	/**
	 *
	 * @param $url
	 * @param $data
	 * @param $callback
	 * @param $param1
	 * @param $param2
	 * @param $param3
	 * @return phpQueryObject
	 */
	public static function browserPost($url, $data, $callback, $param1 = null, $param2 = null, $param3 = null) {
		if (self::plugin('WebBrowser')) {
			$params = func_get_args();
			return self::callbackRun([self::$plugins, 'browserPost'], $params);
		} else {
			self::debug('WebBrowser plugin not available...');
		}
	}
	/**
	 *
	 * @param $ajaxSettings
	 * @param $callback
	 * @param $param1
	 * @param $param2
	 * @param $param3
	 * @return phpQueryObject
	 */
	public static function browser($ajaxSettings, $callback, $param1 = null, $param2 = null, $param3 = null) {
		if (self::plugin('WebBrowser')) {
			$params = func_get_args();
			return self::callbackRun([self::$plugins, 'browser'], $params);
		} else {
			self::debug('WebBrowser plugin not available...');
		}
	}
	/**
	 *
	 * @param $code
	 * @return string
	 */
	public static function php($code) {
		return self::code('php', $code);
	}
	/**
	 *
	 * @param $type
	 * @param $code
	 * @return string
	 */
	public static function code($type, $code) {
		return "<$type><!-- ".trim($code)." --></$type>";
	}

	public static function __callStatic($method, $params) {
		return call_user_func_array(
			[self::$plugins, $method],
			$params
		);
	}
	protected static function dataSetupNode($node, $documentID) {
		// search are return if alredy exists
		foreach(self::$documents[$documentID]->dataNodes as $dataNode) {
			if ($node->isSameNode($dataNode))
				return $dataNode;
		}
		// if doesn't, add it
		self::$documents[$documentID]->dataNodes[] = $node;
		return $node;
	}
	protected static function dataRemoveNode($node, $documentID) {
		// search are return if alredy exists
		foreach(self::$documents[$documentID]->dataNodes as $k => $dataNode) {
			if ($node->isSameNode($dataNode)) {
				unset(self::$documents[$documentID]->dataNodes[$k]);
				unset(self::$documents[$documentID]->data[ $dataNode->dataID ]);
			}
		}
	}
	public static function data($node, $name, $data, $documentID = null) {
		if (! $documentID)
			// TODO check if this works
			$documentID = self::getDocumentID($node);
		$document = self::$documents[$documentID];
		$node = self::dataSetupNode($node, $documentID);
		if (! isset($node->dataID))
			$node->dataID = ++self::$documents[$documentID]->uuid;
		$id = $node->dataID;
		if (! isset($document->data[$id]))
			$document->data[$id] = [];
		if (! is_null($data))
			$document->data[$id][$name] = $data;
		if ($name) {
			if (isset($document->data[$id][$name]))
				return $document->data[$id][$name];
		} else
			return $id;
	}
	public static function removeData($node, $name, $documentID) {
		if (! $documentID)
			// TODO check if this works
			$documentID = self::getDocumentID($node);
		$document = self::$documents[$documentID];
		$node = self::dataSetupNode($node, $documentID);
		$id = $node->dataID;
		if ($name) {
			if (isset($document->data[$id][$name]))
				unset($document->data[$id][$name]);
			$name = null;
			foreach($document->data[$id] as $name)
				break;
			if (! $name)
				self::removeData($node, $name, $documentID);
		} else {
			self::dataRemoveNode($node, $documentID);
		}
	}
}

/**
 * 插件静态命名空间类
 * 用于处理插件的静态方法调用
 *
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 * @package QL
 * @todo 移动插件方法到这里（作为静态方法）
 */
class phpQueryPlugins {
	/**
	 * 调用插件方法
	 *
	 * @param string $method 方法名
	 * @param array $args 方法参数
	 * @return mixed
	 */
	public function __call($method, $args) {
		if (isset(self::$extendStaticMethods[$method])) {
			$return = call_user_func_array(
				self::$extendStaticMethods[$method],
				$args
			);
		} else if (isset(self::$pluginsStaticMethods[$method])) {
			$class = self::$pluginsStaticMethods[$method];
			$realClass = "phpQueryPlugin_$class";
			$return = call_user_func_array(
				[$realClass, $method],
				$args
			);
			return isset($return)
				? $return
				: $this;
		} else
			Debug::showMessage("Method '{$method}' doesnt exist");
	}
}

\QL\phpQuery::$plugins = new phpQueryPlugins();