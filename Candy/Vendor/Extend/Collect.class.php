<?php
/**
 * Collect 文章采集与内容提取类
 * 
 * 该类提供了一套完整的网页内容采集和信息提取工具，主要功能包括：
 * - 获取远程网页的HTML源码
 * - 提取特定区间的HTML内容
 * - 从HTML中解析链接和标题
 * - 根据配置规则过滤和提取文章信息（标题、时间、内容等）
 * 
 * 使用场景：
 * - 网站内容自动采集
 * - 数据爬取和分析
 * - 文章内容提取和格式化
 * 
 * 注意事项：
 * - 使用前确保PHP环境已启用curl扩展或允许使用file_get_contents函数
 * - 采集功能可能受到目标网站反爬机制的限制
 * - 请确保采集行为符合相关法律法规
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * $Date: 2026-08-18 19:19:11 $
 * @final 本类为最终实现禁止继承
 */

declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Collect {
	/**
	 * SSL 证书校验开关（默认 true；自签名/测试环境可置 false）
	 *
	 * @var bool|null
	 */
	public static $sslVerify = true;

    /**
     * 当前操作的网页URL地址
     * 
     * 该静态属性用于存储当前正在处理的网页URL，由getContent方法自动设置。
     * 
     * 功能作用：
     * - 为后续方法（如getSubContent、getAllUrl等）提供上下文信息
     * - 作为相对URL转换为绝对URL的基准
     * - 记录当前采集任务的来源地址
     * 
     * 使用说明：
     * - 通常情况下，该属性会在调用getContent方法时自动更新
     * - 也可以手动设置此属性，以便在不调用getContent的情况下提供正确的URL上下文
     * - 在处理多个不同URL时，每个URL调用getContent后此值会被覆盖
     * 
     * @var string|null 当前操作的URL地址，初始为null，调用getContent后更新
     */
    public static $url;

    /**
     * 获取目标网址的HTML源码内容
     * 
     * 该方法通过两种方式获取远程网页内容：
     * 1. 优先使用curl扩展（如果已启用）
     * 2. 如果curl不可用，则使用file_get_contents作为备选方案
     * 
     * 功能特点：
     * - 自动处理URL重定向（CURLOPT_FOLLOWLOCATION）
     * - 支持HTTPS协议（禁用SSL验证以提高兼容性）
     * - 仅获取页面内容，不包含HTTP响应头
     * - 自动保存当前URL到静态属性，供后续方法使用
     * 
     * @param string $url 目标网页的URL地址，必须是HTTP或HTTPS协议
     * @return string 返回获取到的HTML源码，已自动去除首尾空白字符
     * @throws Exception 当无法获取页面内容或协议不受支持时可能抛出异常
     */
	public static function getContent(string $url): string
	{
		if (!self::isPublicUrl($url)) {
			return '';
		}

		self::$url = $url;
		
        $content = '';
        if (extension_loaded('curl')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_MAXFILESIZE, 33554432);
            // 默认开启 SSL 证书校验（原实现强制关闭存在中间人风险）
            $sslVerify = self::$sslVerify ?? true;
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            $content = curl_exec($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
                'https' => [
                    'timeout' => 15,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $content = @file_get_contents($url, false, $context);
        }
		
		return trim(is_string($content) ? $content : '');
	}

    /**
     * 判断采集地址是否为公网 HTTP(S) 地址。
     *
     * @param string $url 待请求地址
     * @return bool 是否允许请求
     */
    private static function isPublicUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } elseif (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            $ips = is_array($resolved) ? $resolved : [];
        }
        if ($ips === []) {
            return false;
        }

        foreach (array_unique($ips) as $ip) {
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return false;
            }
        }
        return true;
    }
	
    /**
     * 从HTML内容中提取指定起始和结束标记之间的子内容
     * 
     * 该方法通过查找指定的起始和结束字符串，提取它们之间的HTML内容。
     * 功能特点：
     * - 支持灵活的标记定位，使用字符串或正则表达式
     * - 自动处理嵌套标记情况（选择最外层匹配）
     * - 可选是否包含起始和结束标记本身
     * - 支持提取多个匹配区间
     * 
     * 典型应用场景：
     * - 提取文章正文内容（从<h1>标题后到评论区前）
     * - 提取特定区域的HTML（如侧边栏、页脚等）
     * - 从复杂页面中分离出需要处理的部分
     * 
     * @param string $html 要处理的完整HTML内容
     * @param string|array $start 起始标记，可以是普通字符串或正则表达式
     * @param string|array $end 结束标记，可以是普通字符串或正则表达式
     * @param bool $includeTags 是否在结果中包含起始和结束标记（默认为false）
     * @param bool $multiple 是否提取所有匹配项（默认为false，仅返回第一个匹配）
     * @return string|array 如果multiple为false，返回第一个匹配的内容字符串；
     *                     如果multiple为true，返回包含所有匹配内容的数组
     * @throws Exception 当参数无效或找不到指定区间时可能抛出异常
     */		
    public static function getSubContent(string $html,string $start,string $end): string
	{
		if (empty($html)) return '';
        if ($start == '' || $end == '') {
            return $html;
        }

        $html = str_replace(["\r", "\n"], "", $html);
		$start = str_replace(["\r", "\n"], "", $start);
		$end = str_replace(["\r", "\n"], "", $end);		
		
        $html = explode(trim($start), $html);
		if(is_array($html)) $html = explode(trim($end), $html[1]);
		return trim($html[0]);
    }
	
    /**
     * 从HTML内容中提取所有链接及关联标题
     * 
     * 该方法使用正则表达式从HTML文本中提取所有<a>标签中的链接和文本内容，并返回结构化数组。
     * 功能特点：
     * - 提取所有<a>标签中的href属性和文本内容
     * - 自动将相对URL转换为绝对URL
     * - 对链接文本进行清理，去除多余的空白字符
     * - 支持自定义链接筛选条件
     * 
     * @param string $html 区间内的HTML源码
     * @param string $urlContain 网址中必须包含的字符串，用于筛选特定链接
     * @param string $urlExcept 网址中不能包含的字符串，用于排除特定链接
     * @return array 返回提取的链接数组，格式为：
     *              [
     *                'url' => [0 => '完整URL地址1', 1 => '完整URL地址2', ...],
     *                'title' => [0 => '链接标题1', 1 => '链接标题2', ...]
     *              ]
     * @throws Exception 当HTML解析失败或参数无效时可能抛出异常
     */	
    public static function getAllUrl(string $html,string $urlContain='',string $urlExcept=''): array
	{
		
		$html = str_replace(["\r", "\n"], '', $html);
		$html = str_replace(["</a>", "</A>"], "</a>\n", $html);
        preg_match_all('/<a ([^>]*)>([^\/a>].*)<\/a>/i', $html, $out);
		
		$data = [
			'url' => [],
			'title' => [],
		];

		foreach ($out[1] as $k=>$v) {
			if (preg_match('/href=[\'"]?([^\'" ]*)[\'"]?/i', $v, $match_out)) {
				if ($urlContain) {
					if (strpos($match_out[1], $urlContain) === false) {
						continue;
					} 
				}

				if ($urlExcept) {
					if (strpos($match_out[1], $urlExcept) !== false) {
						continue;
					} 
				}
				$url2 = $match_out[1];
				$url2 = self::urlCheck($url2, self::$url);
				
				$title = strip_tags($out[2][$k]);

				if(empty($url2) || empty($title)) continue;
				
				$data['url'][$k] = $url2;
				$data['title'][$k] = $title;

			} else {
				continue;
			}
		}
        
		//去除重复数据
        $arr = [];		
		$data['url'] = array_unique($data['url']);
		foreach($data['url'] as $k => $v){
			$arr['url'][] = $data['url'][$k];
			$arr['title'][] = $data['title'][$k];
		}
		
        return $arr;
    }
	
	/**
	 * 根据文章内容页的HTML源码，过滤并提取相关信息
	 * 
	 * 该方法是Collect类的核心功能之一，用于根据灵活的配置规则从HTML中提取和过滤文章数据。
	 * 支持提取文章标题、发布时间和正文内容等关键信息。
	 * 
	 * 配置参数结构示例：
	 * ```php
	 * $config = [
	 *   'title_rule' => ['<h1>', '</h1>'], // 标题提取规则，指定起始和结束标记
	 *   'title_html_rule' => ['<.*?>'], // 标题内容清洗规则，使用正则表达式数组
	 *   'time_rule' => ['<div class="time">', '</div>'], // 时间提取规则
	 *   'time_html_rule' => ['<.*?>'], // 时间内容清洗规则
	 *   'content_rule' => ['<div class="content">', '</div>'], // 内容提取规则
	 *   'content_html_rule' => ['<script.*?</script>', '<style.*?</style>'] // 内容清洗规则
	 * ];
	 * ```
	 * 
	 * 处理流程：
	 * 1. 使用getSubContent方法根据配置的规则提取指定区间的HTML内容
	 * 2. 通过replaceItem方法应用清洗规则，去除不需要的HTML标签和内容
	 * 3. 对时间格式进行特殊处理，转换为UNIX时间戳
	 * 
	 * @param string $html 文章内容页面的HTML源码
	 * @param array $config 过滤配置数组，包含各类提取和清洗规则
	 * @return array 返回提取并过滤后的文章数据数组，格式为：
	 *              [
	 *                'title' => '文章标题',
	 *                'inputtime' => 1623456789, // UNIX时间戳
	 *                'content' => '处理后的文章内容'
	 *              ]
	 * @throws Exception 当配置无效或提取失败时可能抛出异常
	 */	
    public static function getFilterHtml(string $html,array $config = []): array
	{
        $data =[];
		
		//获取内容
		$data['title'] = self::replaceItem(self::getSubContent($html, $config['title_rule'][0], $config['title_rule'][1]), $config['title_html_rule']);
		
		//获取时间
		if($config['time_rule'][0]!='' && $config['time_rule'][1]!=''){
            $data['inputtime'] = self::replaceItem(self::getSubContent($html, $config['time_rule'][0], $config['time_rule'][1]), $config['time_html_rule']);	
            $data['inputtime'] = !empty($data['inputtime']) ? strtotime($data['inputtime']) : time();			
		}else{
			$data['inputtime'] = time();
		}
		

		//获取内容
		$data['content'] = self::replaceItem(self::getSubContent($html, $config['content_rule'][0], $config['content_rule'][1]), $config['content_html_rule']);

		return $data;
		
    }
	
	/**
	 * 增强型字符串分割处理方法
	 * 
	 * 该方法是对PHP原生explode函数的封装和增强，提供更完善的字符串分割功能，
	 * 特别是在处理正则表达式匹配结果或需要规范化分割的场景中非常有用。
	 * 功能特点：
	 * - 自动去除字符串中的换行符和回车符，确保分割结果整洁
	 * - 自动清理末尾可能产生的空元素
	 * - 为空字符串提供默认返回值，避免后续处理出现错误
	 * - 支持任意字符串作为分隔符
	 * 
	 * 应用场景：
	 * - 处理正则表达式匹配后的结果字符串
	 * - 分割配置项、CSV格式数据或其他需要按特定字符拆分的文本
	 * - 规范化分割后的数组格式，确保数据一致性
	 * 
	 * @param string $separator 分隔字符串，用于分割原始字符串的字符或子串
	 * @param string $string 需要被分割处理的原始字符串
	 * @return array 返回分割后的字符串数组，如果原始字符串为空则返回['','']
	 * @throws 无异常抛出，但会对输入参数进行基本验证和处理
	 */		
	public static function explodeTrim(string $separator, string $string): array
	{
		if(empty($string)) return ['',''];
		$string = str_replace(["\r", "\n"],'',$string);
		$arr = explode($separator, $string);
		if($arr[count($arr)-1] == '') unset($arr[count($arr)-1]);
		return $arr;
	}

	/** @deprecated 使用 explodeTrim */
	public static function myexp(string $separator, string $string): array
	{
		return self::explodeTrim($separator, $string);
	}
	
    /**
	 * 过滤代码
	 *
	 * @param string $html  HTML代码
	 * @param array $config 过滤配置
	 * @return string
	 */
	protected static function replaceItem(string $html,array $config): string
	{
		if (!is_array($config) || empty($config)) return $html;

		$patterns = $replace = [];
		foreach($config as $k=>$v){
			$patterns[] = '/'.str_replace('/', '\/', $v).'/i';
			$replace[] = '';
		}

		return trim(preg_replace($patterns, $replace, $html));
	}
	
    /**
	 * URL地址检查
	 *
	 * @param string $url      需要检查的URL
	 * @param string $baseurl  基本URL
	 * @return string
	 */
	protected static function urlCheck(string $url,string $baseurl): string
	{
        $urlinfo = parse_url($baseurl);
		if(strpos($url, '://') === false) {
			if($url[0] == '/'){
				$url = $urlinfo['scheme'].'://'.$urlinfo['host'].$url;
			}else{
				$baseurl = $urlinfo['scheme'].'://'.$urlinfo['host'].(substr($urlinfo['path'], -1, 1) === '/' ? substr($urlinfo['path'], 0, -1) : str_replace('\\', '/', dirname($urlinfo['path']))).'/';
				$url = $baseurl.$url;
			}
		}

		if(substr($url, 0, 4) != 'http') \Candy\Core\Debug::shutdown('链接地址仅允许HTTP和HTTPS协议！');
		
		return $url;
	}
}
