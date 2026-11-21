<?php
/**
 * Candy框架 网络爬虫类
 * 
 * Snoopy类是一个强大的网络爬虫工具，提供了丰富的网页内容获取、分析和处理功能。
 * 它基于QueryList库构建，支持网页内容抓取、表单提交、链接提取、编码转换等功能。
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2023-06-09 14:00:00
 * @final 此类不允许被继承
 */
declare(strict_types=1);
namespace Extend;
use \Candy\Core\Debug;
use \QL\QueryList;
use \Candy\Extend\Network\Http as NetworkHttp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Snoopy {
    public static $host = 'www.icandy.top';	//地址
    public static $port = 80;	//端口
    public static $lastRedirectAddr = '';
    public static $results = ''; //结果集
    public static $headers = [];
    private static $_ql = null;	//QueryList对象
	
	//网页编码类型
	private static $charset = ['gb2312', 'utf-8', 'big5', 'gbk', 'ascii', 'cp936', 'ibm037', 'ibm437', 'ibm500', 'asmo-708', 'dos-720', 'ibm737', 'ibm775', 'ibm850', 'ibm852', 'ibm855', 'ibm857', 'ibm00858', 'ibm861', 'ibm860', 'dos-862', 'ibm863', 'ibm864', 'ibm865', 'cp866', 'ibm869', 'ibm870', 'windows-874', 'cp875', 'shift_jis', 'ks_c_5601-1987', 'ibm1026', 'ibm01047', 'ibm01047', 'ibm01040', 'ibm01041', 'ibm01042', 'ibm01043', 'ibm01044', 'ibm01045', 'ibm01046', 'ibm01047', 'ibm01048', 'ibm01049', 'utf-16', 'unicodefffe', 'windows-1250', 'windows-1251', 'windows-1252', 'windows-1253', 'windows-1254', 'windows-1255', 'windows-1256', 'windows-1257', 'windows-1258', 'johab', 'macintosh', 'x-mac-japanese', 'x-mac-chinesetrad', 'x-mac-korean', 'x-mac-arabic', 'x-mac-hebrew', 'x-mac-greek', 'x-mac-cyrillic', 'x-mac-chinesesimp', 'x-mac-romanian', 'x-mac-ukrainian', 'x-mac-thai', 'x-mac-ce', 'x-mac-icelandic', 'x-mac-turkish', 'x-mac-croatian', 'x-chinese-cns', 'x-cp20001', 'x-chinese-eten', 'x-cp20003', 'x-cp20004', 'x-cp20005', 'x-ia5', 'x-ia5-german', 'x-ia5-swedish', 'x-ia5-norwegian', 'us-ascii', 'x-cp20261', 'x-cp20269', 'ibm273', 'ibm277', 'ibm278', 'ibm280', 'ibm284', 'ibm285', 'ibm290', 'ibm420', 'ibm423', 'ibm424', 'x-ebcdic-koreanextended', 'ibm-thai', 'koi8-r', 'ibm871', 'ibm880', 'ibm905', 'ibm00924', 'x-cp20936', 'x-cp20949', 'cp1025', 'koi8-u', 'iso-8859-1', 'iso-8859-2', 'iso-8859-3', 'iso-8859-4', 'iso-8859-5', 'iso-8859-6', 'iso-8859-7', 'iso-8859-8', 'iso-8859-9', 'iso-8859-13', 'iso-8859-15', 'x-europa', 'iso-8859-8-i', 'iso-2022-jp', 'csiso2022jp', 'iso-2022-jp', 'iso-2022-kr', 'x-cp50227', 'euc-jp', 'euc-cn', 'euc-kr', 'hz-gb-2312', 'gb18030', 'x-iscii-de', 'x-iscii-be', 'x-iscii-ta', 'x-iscii-te', 'x-iscii-as', 'x-iscii-or', 'x-iscii-ka', 'x-iscii-ma', 'x-iscii-gu', 'x-iscii-pa', 'utf-7', 'utf-32', 'utf-32be'];
	
	public function __call($name, $arguments)
    {
        if(method_exists(self::$_ql,$name)){
            $result = self::$_ql->$name(...$arguments);
        }else{
            //方法不存在
			Debug::showMessage('函数: <font style="color:red;">' . $name . '</font>不存在。');
        }
       return $result;
    }
	
    public static function __callStatic($name, $arguments)
    {
        $instance = N('QL\QueryList');
        return $instance->$name(...$arguments);
    }
	
	/**
     * 获取网页内容并返回QueryList对象
     * 
     * 通过HTTP请求获取指定URL的网页内容，并自动进行编码转换后返回QueryList对象，
     * 便于后续使用QueryList的CSS选择器进行内容提取和分析。
     *
     * @param string $URI 请求地址，支持HTTP和HTTPS协议
     * @return string|QueryList 失败时返回错误信息字符串，成功时返回QueryList对象
     */
	public static function get(string $URI = ''): QueryList|string
    {
		if (self::fetch($URI) !== false) {
			return self::$_ql;
		} else
			return '地址链接失败。';
	}
	
	/**
     * 获取QueryList实例
     * 
     * 直接返回QueryList的实例，方便直接使用QueryList提供的所有功能。
     *
     * @return QueryList 返回QueryList类的实例
     */
	public static function getInstance(): QueryList
    {
		return QueryList::getInstance();
	}
		
	/**
     * 获取网页内容并进行编码转换
     * 
     * 这是核心的网页内容获取方法，支持HTTP和HTTPS协议，自动处理URL解析、编码转换，
     * 并创建QueryList对象用于后续内容提取。
     *
     * @param string $URI 请求地址
     * @param array|string $query 查询参数，支持数组或字符串格式
     * @param array $options 请求选项，可配置超时、代理等参数
     * @return string|bool 成功时返回转换编码后的网页内容，失败时返回false
     */
	public static function fetch(string $URI,array|string $query = '',array $options = []): string|bool
    {
        $URI_PARTS = parse_url($URI);
		self::$host = $URI_PARTS['host'];
		self::$port = $URI_PARTS['port'];
		switch (strtolower($URI_PARTS['scheme'])) {
			case'https':
            case 'http':
				self::$results = NetworkHttp::get($URI, $query, $options);
				//是否跳转
				if(self::$results['url'] != $URI){
					self::$lastRedirectAddr = self::$results['url'];
				}
				//转码
				if(self::$results['http_code'] == 200) {
					$charset = self::_getCharset();
					if($charset != 'utf-8') {
						if($charset == 'windows-1252') {
							self::$results['content'] = self::_uniDecode(self::$results['content']);
						} else {
							self::$results['content'] = mb_convert_encoding(self::$results['content'], 'UTF-8', $charset);
						}
					}
				}
				//创建QueryList对象
				self::$_ql = QueryList::html(self::$results['content']);
				return self::$results['content'];
            default:
                throw Debug::showMessage('无效的协议头: <font style="color:red;">' . $URI_PARTS['scheme'] . '</font>');
		}
        return '采集地址配置错误。';
    }
	
	/**
     * 提交表单数据并获取响应
     * 
     * 用于模拟HTML表单提交，支持POST数据、文件上传和自定义HTTP头信息，
     * 自动处理响应内容的编码转换，并创建QueryList对象用于后续内容提取。
     *
     * @param string $URI 请求地址
     * @param array $formvars 表单参数数组，格式为['字段名' => '字段值']
     * @param array $formfiles 文件上传信息，格式为['字段名' => ['name' => '文件名', 'tmp_name' => '临时路径']]
     * @param array $header 自定义HTTP头信息数组
     * @return string|bool 成功时返回转换编码后的响应内容，失败时返回错误信息
     */
    public static function submit(string $URI,array $formvars = [],array $formfiles = [],array $header = [])
    {
		$URI_PARTS = parse_url($URI);
		self::$host = $URI_PARTS['host'];
		self::$port = $URI_PARTS['port'];
		
		match (strtolower($URI_PARTS['scheme'])) {
			'https', 'http' => (function() use ($URI, $formvars, $formfiles, $header) {
				self::$results = NetworkHttp::submit($URI, $formvars, $formfiles, $header);
				//是否跳转
				if(self::$results['url'] != $URI){
					self::$lastRedirectAddr = self::$results['url'];
				}
				//转码
				if(self::$results['http_code'] == 200) {
					$charset = self::_getCharset();
					if($charset != 'utf-8') {
						if($charset == 'windows-1252') {
							self::$results['content'] = self::_uniDecode(self::$results['content']);
						} else {
							self::$results['content'] = mb_convert_encoding(self::$results['content'], 'UTF-8', $charset);
						}
					}
				}
				//创建QueryList对象
				self::$_ql = QueryList::html(self::$results['content']);
				return self::$results['content'];
			})(),
            default => Debug::showMessage('无效的协议头: <font style="color:red;">' . $URI_PARTS['scheme'] . '</font>'),
		};
        return '提交地址配置错误。';
    }
	
	/**
     * 提取网页中的链接
     * 
     * 获取指定URL页面中的所有链接，并自动将相对链接补全为绝对链接。
     * 可通过node参数指定要提取链接的HTML元素，默认为'a'标签。
     *
     * @param string $URI 请求地址
     * @param string $node HTML元素选择器，默认为'a'标签，用于指定从哪些元素中提取链接
     * @return string|array 失败时返回错误信息，成功时返回链接数组
     */
    public static function fetchLinks(string $URI,string $node = ''): array|string
    {
        if (self::fetch($URI) !== false) {
            if (self::$lastRedirectAddr)
                $URI = self::$lastRedirectAddr;
			
			$node == '' && $node = 'a';
			//补全后的URL
            return self::_expandLinks(self::$_ql->find($node)->attrs('href')->all(), $URI);
        } else
            return '地址链接失败。';
    }
	
	/**
     * 提取网页中的表单元素
     * 
     * 获取指定URL页面中的所有表单元素，包括input、select、textarea等，
     * 返回包含完整表单结构的HTML字符串。
     *
     * @param string $URI 请求地址
     * @return string|array 失败时返回错误信息，成功时返回表单HTML字符串
     */
    public static function fetchForm(string $URI): array|string
    {

        if (self::fetch($URI) !== false) {
            return self::_stripForm(self::$results['content']);
        } else
            return '地址链接失败。';
    }
	
	/**
     * 提取网页中的纯文本内容
     * 
     * 获取指定URL页面中的纯文本内容，自动去除HTML标签、JavaScript代码和特殊字符，
     * 返回干净的文本内容。
     *
     * @param string $URI 请求地址
     * @return string|array 失败时返回错误信息，成功时返回清理后的纯文本内容
     */
    public static function fetchText(string $URI): array|string
    {
        if (self::fetch($URI) !== false) {
            return self::_stripText(self::$results['content']);
        } else
            return '地址链接失败。';
    }
	
	/**
     * 提取网页中的框架(frame)地址
     * 
     * 获取指定URL页面中所有frame标签的src属性值，并自动将相对URL转换为绝对URL。
     * 这对于处理包含框架的复杂网页很有用。
     *
     * @param string $URI 请求地址
     * @return string|array 失败时返回错误信息，成功时返回框架URL数组
     */
    public static function fetchFrame(string $URI): array|string
    {
        if (self::fetch($URI) !== false) {
			$frameurls = [];
			if (preg_match_all("'<frame\s+.*src[\s]*=[\'\"]?([^\'\"\>]+)'i", self::$results['content'], $match)) {
				$URI_PARTS = parse_url($URI);
				//for ($x = 0; $x < count($match[1]); $x++)
					//$frameurls[] = self::_expandLinks($match[1][$x], $URI_PARTS['scheme'] . '://' . $URI_PARTS['host']);

                $frameurls[] = self::_expandLinks($match[1], $URI_PARTS['scheme'] . '://' . $URI_PARTS['host']);
			}
            return $frameurls;
        } else
            return '地址链接失败。';
    }
	
	/**
     * 获取网页的元数据信息
     * 
     * 提取指定URL页面的标题(title)、关键词(keywords)、描述(description)和IP地址等元数据信息。
     * 这些信息对于SEO分析和网页内容初步判断非常有用。
     *
     * @param string $URI 请求地址
     * @return string|array 失败时返回错误信息，成功时返回包含元数据的关联数组
     */
    public static function fetchInfo(string $URI): array|string
    {
		if (self::fetch($URI) !== false) {
			$info['title'] = self::$_ql->find('title')->text();
			$info['keywords'] = self::$_ql->find('meta[name=keywords]')->content;
			$info['desc'] = self::$_ql->find('meta[name=description]')->content;
			
			//ip地址
			preg_match("/^[^\?]+/", $URI, $match);
			$match = preg_replace("|/[^\/\.]+\.[^\/\.]+$|", "", $match[0]);
			$match = preg_replace("|/$|", "", $match);
			$match_part = parse_url($match);
			$ip = @gethostbyname($match_part['host']);
			$ip_arr = explode(".", $ip);
			if(count($ip_arr)==4) {
				$info['ip']=$ip;
			}
			
			return $info;
        } else
            return '地址链接失败。';
    }
	
	/**
     * 提交表单后提取响应中的链接
     * 
     * 先提交表单数据到指定URL，然后从响应页面中提取所有链接，
     * 并自动将相对链接补全为绝对链接。
     *
     * @param string $URI 请求地址
     * @param array $formvars 表单参数数组
     * @param array $formfiles 文件上传信息，格式为['字段名' => ['name' => '文件名', 'tmp_name' => '临时路径']]
     * @return string|array 失败时返回错误信息，成功时返回链接数组
     */
    public static function submitLinks(string $URI,array $formvars = [],array $formfiles = []): array|string
    {
        if (self::submit($URI, $formvars, $formfiles) !== false) {
            if (self::$lastRedirectAddr)
                $URI = self::$lastRedirectAddr;
			
			//补全后的URL
            return self::_expandLinks(self::$_ql->find('a')->attrs('href')->all(), $URI);
        } else
            return '地址链接失败。';
    }
	
	/**
     * 提交表单后提取响应中的纯文本内容
     * 
     * 先提交表单数据到指定URL，然后从响应页面中提取纯文本内容，
     * 自动去除HTML标签、JavaScript代码和特殊字符。
     *
     * @param string $URI 请求地址
     * @param array $formvars 表单参数数组
     * @param array $formfiles 文件上传信息，格式为['字段名' => ['name' => '文件名', 'tmp_name' => '临时路径']]
     * @return string|array 失败时返回错误信息，成功时返回清理后的纯文本内容
     */
    public static function submitText(string $URI,array $formvars = [],array $formfiles = []): array|string
    {
        if (self::submit($URI, $formvars, $formfiles) !== false) {
            if (self::$lastRedirectAddr)
                $URI = self::$lastRedirectAddr;
			
			return self::_expandLinks([self::_stripText(self::$results['content'])], $URI);
        } else
            return '地址链接失败。';
    }
	
	/**
     * 获取网页的完整响应信息
     * 
     * 获取指定URL的完整HTTP响应，包括状态码、头信息和内容，并对文本内容进行编码转换。
     * 与fetch方法不同，此方法返回完整的响应对象，而不仅仅是内容。
     *
     * @param string $URI 请求地址
     * @param array|string $query 查询参数，支持数组或字符串格式
     * @param array $options 请求选项，可配置超时、代理等参数
     * @return string|array 失败时返回错误信息，成功时返回完整的响应数组
     */
    public static function mirror(string $URI,array|string $query = '',array $options = []): string|array
    {
        $URI_PARTS = parse_url($URI);
		switch (strtolower($URI_PARTS['scheme'])) {
			case'https':
           	case 'http':
				self::$results = NetworkHttp::get($URI, $query, $options);
				//转码
				if(self::$results['http_code'] == 200 && stripos(self::$results['content_type'], 'text') !== false) {
					$charset = self::_getCharset();
					if($charset != 'utf-8') {
						if($charset == 'windows-1252') {
							self::$results['content'] = self::_uniDecode(self::$results['content']);
						} else {
							self::$results['content'] = mb_convert_encoding(self::$results['content'], 'UTF-8', $charset);
						}
					}
				}
				//返回结果
				return self::$results;
            default:
                throw Debug::showMessage('无效的协议头: <font style="color:red;">' . $URI_PARTS['scheme'] . '</font>');
		}
        return '镜像地址打不开。';
    }
	
	/**
     * 从HTML文档中提取表单元素
     * 
     * 使用正则表达式从HTML文档中提取所有表单相关元素，包括FORM、INPUT、SELECT、TEXTAREA等，
     * 返回提取后的HTML字符串。
     *
     * @param string $document HTML文档内容
     * @return string 提取的表单元素HTML字符串
     */
    private static function _stripForm(string $document): string
    {
        preg_match_all("'<\/?(FORM|INPUT|SELECT|TEXTAREA|(OPTION))[^<>]*>(?(2)(.*(?=<\/?(option|select)[^<>]*>[\r\n]*)|(?=[\r\n]*))|(?=[\r\n]*))'Usi", $document, $elements);
        $match = implode("\r\n", $elements[0]);
		
        return $match;
    }
	
	/**
     * 从HTML文档中提取纯文本内容
     * 
     * 使用正则表达式去除HTML文档中的所有HTML标签、JavaScript代码、空白字符和特殊字符，
     * 转换HTML实体为对应的字符，返回干净的纯文本内容。
     *
     * @param string $document HTML文档内容
     * @return string 清理后的纯文本内容
     */
    private static function _stripText(string $document): string
    {
        $search = ["'<script[^>]*?>.*?</script>'si", //javascript段落
            "'<[\/\!]*?[^<>]*?>'si", //html标签
            "'([\r\n])[\s]+'", //空格
            "'&(quot|#34|#034|#x22);'i", //html实体
            "'&(amp|#38|#038|#x26);'i", //十六进制字符
            "'&(lt|#60|#060|#x3c);'i",
            "'&(gt|#62|#062|#x3e);'i",
            "'&(nbsp|#160|#xa0);'i",
            "'&(iexcl|#161);'i",
            "'&(cent|#162);'i",
            "'&(pound|#163);'i",
            "'&(copy|#169);'i",
            "'&(reg|#174);'i",
            "'&(deg|#176);'i",
            "'&(#39|#039|#x27);'",
            "'&(euro|#8364);'i", // europe
            "'&a(uml|UML);'", // german
            "'&o(uml|UML);'",
            "'&u(uml|UML);'",
            "'&A(uml|UML);'",
            "'&O(uml|UML);'",
            "'&U(uml|UML);'",
            "'&szlig;'i",
        ];
        $replace = ["",
            "",
            "\\1",
            "\"",
            "&",
            "<",
            ">",
            " ",
            chr(161),
            chr(162),
            chr(163),
            chr(169),
            chr(174),
            chr(176),
            chr(39),
            chr(128),
            "ä",
            "ö",
            "ü",
            "Ä",
            "Ö",
            "Ü",
            "ß",
        ];

        $text = preg_replace($search, $replace, $document);

        return $text;
    }
	
	/**
     * 将相对链接转换为绝对链接
     * 
     * 处理各种类型的相对链接（如以'/'开头、以'//'开头或直接以文件名开头的链接），
     * 将它们转换为基于指定URI的完整绝对链接。
     *
     * @param array $links 原始链接数组
     * @param string $URI 基准URI，用于解析相对路径
     * @return array 转换后的绝对链接数组
     */
    private static function _expandLinks(array $links,string $URI): array
    {

        preg_match("/^[^\?]+/", $URI, $match);

        $match = preg_replace("|/[^\/\.]+\.[^\/\.]+$|", "", $match[0]);
        $match = preg_replace("|/$|", "", $match);
        $match_part = parse_url($match);
        $match_scheme = $match_part['scheme'] . '://';
        $match_root = $match_part['scheme'] . '://' . $match_part['host'];

        $search = [
			"|^http://" . preg_quote(self::$host) . "|i",
            "|^(\/\/)|i",
            "|^(\/)|i",
            "|^(?!http://)(?!https://)(?!mailto:)|i",
            "|/\./|",
            "|/[^\/]+/\.\./|"
        ];
		
        $replace = [
			'',
            $match_scheme,
            $match_root . "/",
            $match . "/",
            "/",
            "/"
        ];
		
        $expandedLinks = preg_replace($search, $replace, $links);

        return $expandedLinks;
    }
	
	/**
     * 检测字符串的编码格式
     * 
     * 通过分析字符串的字节模式来判断其编码格式，目前支持检测UTF-8和GB2312编码。
     * 这种检测方法基于字符的字节序列特征，适用于多字节编码的识别。
     *
     * @param string $string 要检测编码的字符串
     * @return string 检测到的编码名称，返回小写形式（utf-8或gb2312）
     */
	private static function _t(string $string): string
	{
		$leg = mb_strlen($string);
		for($i=0;$i < $leg; $i++){
			if (ord($string[$i])<128)
				continue;
			
			if((ord($string[$i])&224) == 224) {
				//第一个字节判断通过
				$char = $string[++$i];
				if((ord($char)&128) == 128) {
					//第二个字节判断通过
					$char = $string[++$i];
					if((ord($char)&128) == 128) {
						$encoding = 'UTF-8';
						break;
					}
				}
			}
			
			if((ord($string[$i])&192) == 192) {
				//第一个字节判断通过
				$char = $string[++$i];
				if((ord($char)&128) == 128) {
					//第二个字节判断通过
					$encoding = 'GB2312';
					break;
				}
			}
		}
		return strtolower($encoding);
	}
	
	/**
     * 将Unicode HTML实体转换为UTF-8字符
     * 
     * 处理HTML中的Unicode实体编码（如&#1234;格式），将其转换为对应的UTF-8字符。
     * 可选地，可以将结果进一步转换为其他编码格式。
     *
     * @param string $str 包含Unicode实体的字符串
     * @param string $code 目标编码，默认为UTF-8
     * @return string 转换后的字符串
     */
	private static function _uniDecode(string $str, string $code = 'utf-8'): string{
		$str = json_decode(preg_replace_callback('/&＃(\d{5});/', function ($str){ return '\u' . dechex($str[1]); }, $str));
		if($code != 'utf-8'){ $str = iconv('utf-8', $code, $str); }
		return $str;
	}
	
	/**
     * 自动检测网页内容的字符编码
     * 
     * 通过多种方法检测网页内容的编码格式：
     * 1. 首先从HTML meta标签中提取charset属性
     * 2. 如果失败，从HTTP响应头中查找编码信息
     * 3. 最后使用mb_detect_encoding函数进行内容分析
     * 
     * 如果检测到GB2312编码，会进一步调用_t方法进行二次验证。
     *
     * @return string|bool 成功时返回检测到的编码名称（小写形式），失败时返回false
     */
	private static function _getCharset(): string|bool
	{
		//首先从html获取编码
		preg_match('/charset=[^\w]?([-\w]+)/is', self::$results['content'], $temp) ? strtolower($temp[1]) : '';
		if($temp[1] != '') {
			if(in_array($temp[1], self::$charset)) {
				if($temp[1] == 'gb2312') {
					$tmp_charset = self::_t(self::$results['content']);
					if($tmp_charset == $temp[1]) {
						return $temp[1];
					}
				} else {
					return $temp[1];
				}
			}
		}
		if(!empty(self::$headers)) {
			//从header中获取编码
			$hstr=strtolower(implode('|||',self::$headers));
			preg_match('/charset=[^\w]?([-\w]+)/is',$hstr,$lang) ? strtolower($lang[1]) : '';
			if($lang[1] != '') {
				return $lang[1];
			}
		}
		$encoded = mb_detect_encoding(self::$results['content'], ['UTF-8','GB2312','GBK','BIG5','ASCII','EUC-JP','Shift_JIS','CP936','ISO-8859-1','JIS','eucjp-win','sjis-win']);
		if($encoded) {
			return strtolower($encoded);
		} else {
			return false;
		}
	}
}
