<?php
/**
 * Candy框架 字符串操作工具类
 * 
 * 该类提供了一系列静态方法，用于处理各种字符串操作，包括字符串判断、转换、格式化、随机生成等功能。
 * 所有方法都是静态的，无需实例化即可使用，同时类内部使用缓存机制优化性能。
 * 
 * 主要功能：
 * - 字符串包含、开头、结尾判断
 * - 随机字符串生成
 * - 大小写转换
 * - 字符串长度获取和截取
 * - 驼峰命名与下划线命名互相转换
 * - 唯一ID生成（数字和日期格式）
 * - 敏感词过滤
 * - 数据获取和配置生成辅助功能
 * - 数组到字符串的转换
 * 
 * @package Candy\Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-02-03 14:00:00
 * @final 该类为最终类，不允许继承
 * @requires PHP 7.0+
 * @requires mbstring 扩展
 * 
 * @example
 * ```php
 * // 检查字符串包含
 * $result = Str::contains('hello world', 'world'); // true
 * 
 * // 生成随机字符串
 * $random = Str::random(10, 2); // 生成10位包含数字和字母的随机字符串
 * 
 * // 驼峰转下划线
 * $snake = Str::snake('HelloWorld'); // hello_world
 * ```
 */

declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Str {
	protected static $snakeCache = [];
    protected static $camelCache = [];
    protected static $studlyCache = [];

    /**
     * 检查字符串中是否包含指定的子字符串
     * 
     * 该方法用于检查目标字符串是否包含一个或多个指定的子字符串。
     * 支持单个字符串或字符串数组作为子字符串参数，只要包含任一子字符串即返回true。
     * 
     * @param string $haystack 要检查的目标字符串
     * @param string|array $needles 要查找的子字符串或子字符串数组
     * @return bool 如果包含任一子字符串则返回true，否则返回false
     * 
     * @example
     * ```php
     * // 检查单个子字符串
     * $result = Str::contains('hello world', 'world'); // true
     * 
     * // 检查多个子字符串
     * $result = Str::contains('hello world', ['hello', 'php']); // true
     * ```
     */
    public static function contains(string $haystack,string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ('' != $needle && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查字符串是否以指定的子字符串结尾
     * 
     * 该方法用于检查目标字符串是否以一个或多个指定的子字符串结尾。
     * 支持单个字符串或字符串数组作为结束子字符串参数，只要以任一子字符串结尾即返回true。
     * 内部使用PHP的str_ends_with函数实现，提高性能。
     * 
     * @param string $haystack 要检查的目标字符串
     * @param string|array $needles 要匹配的结束子字符串或子字符串数组
     * @return bool 如果以任一子字符串结尾则返回true，否则返回false
     * 
     * @example
     * ```php
     * // 检查单个结束子字符串
     * $result = Str::endsWith('hello world', 'world'); // true
     * 
     * // 检查多个结束子字符串
     * $result = Str::endsWith('hello world', ['world', 'php']); // true
     * ```
     */
    public static function endsWith(string $haystack,string|array $needles): bool
    {
        $needles = is_string($needles) ? [$needles] : $needles;
        foreach ($needles as $needle) {
            if (str_ends_with($haystack, (string)$needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查字符串是否以指定的子字符串开头
     * 
     * 该方法用于检查目标字符串是否以一个或多个指定的子字符串开头。
     * 支持单个字符串或字符串数组作为开头子字符串参数，只要以任一子字符串开头即返回true。
     * 内部使用PHP的str_starts_with函数实现，提高性能。
     * 
     * @param string $haystack 要检查的目标字符串
     * @param string|array $needles 要匹配的开头子字符串或子字符串数组
     * @return bool 如果以任一子字符串开头则返回true，否则返回false
     * 
     * @example
     * ```php
     * // 检查单个开头子字符串
     * $result = Str::startsWith('hello world', 'hello'); // true
     * 
     * // 检查多个开头子字符串
     * $result = Str::startsWith('hello world', ['hello', 'php']); // true
     * ```
     */
    public static function startsWith(string $haystack,string|array $needles): bool
    {
        $needles = is_string($needles) ? [$needles] : $needles;
        foreach ($needles as $needle) {
            if (str_starts_with($haystack, (string)$needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成指定长度的随机字符串
     * 
     * 该方法可以生成各种类型的随机字符串，支持自定义长度、字符类型和附加字符。
     * 提供了多种预设的字符类型组合，满足不同场景下的随机字符串生成需求。
     * 对于中文随机字符串，使用type=6参数可以生成随机中文字符。
     * 
     * @param int $length 生成的随机字符串长度，默认为4
     * @param int $type 字符类型：
     *                  0 = 仅数字
     *                  1 = 数字 + 小写字母
     *                  2 = 数字 + 大小写字母
     *                  3 = 仅小写字母
     *                  4 = 大小写字母
     *                  5 = 仅大写字母
     *                  6 = 中文随机字符
     * @param string $addChars 附加字符，将添加到随机字符池中
     * @return string 生成的随机字符串
     * 
     * @example
     * ```php
     * // 生成6位数字验证码
     * $code = Str::random(6, 0); // 例如: 123456
     * 
     * // 生成8位包含数字和字母的随机密码
     * $password = Str::random(8, 2); // 例如: Ab12Cd34
     * 
     * // 使用自定义字符集
     * $custom = Str::random(10, 2, '!@#$%'); // 例如: x!2Y$7aB@9
     * ```
     */
    public static function random(int $length = 4, int $type = 0, string $addChars = ''): string
    {
        $str = '';
		$_length = strlen($addChars);
		$typelist = [
			[0, 8 + $_length],
			[0, 31 + $_length*2],
			[0, 55 + $_length*3],
			[8 + $_length, 31 + $_length*2],
			[8 + $_length, 55 + $_length*3],
			[31 + $_length*2, 55 + $_length*3]
		];
        switch ($type) {
            case 0:
            case 1:
            case 2:
            case 3:
            case 4:
            case 5:
                $chars = '123456789'. $addChars .'abcdefghgkmnpqrstuvwxyz'. $addChars .'ABCDEFGHJKLMNPQRSTUVWXYZ' . $addChars;
				break;
            case 6:
                $chars = "们以我到他会作时要动国产的一是工就年阶义发成部民可出能方进在了不和有大这主中人上为来分生对于学下级地个用同行面说种过命度革而多子后自社加小机也经力线本电高量长党得实家定深法表着水理化争现所二起政三好十战无农使性前等反体合斗路图把结第里正新开论之物从当两些还天资事队批点育重其思与间内去因件日利相由压员气业代全组数果期导平各基或月毛然如应形想制心样干都向变关问比展那它最及外没看治提五解系林者米群头意只明四道马认次文通但条较克又公孔领军流入接席位情运器并飞原油放立题质指建区验活众很教决特此常石强极土少已根共直团统式转别造切九你取西持总料连任志观调七么山程百报更见必真保热委手改管处己将修支识病象几先老光专什六型具示复安带每东增则完风回南广劳轮科北打积车计给节做务被整联步类集号列温装即毫知轴研单色坚据速防史拉世设达尔场织历花受求传口断况采精金界品判参层止边清至万确究书" . $addChars;
				$typelist[] = [0, mb_strlen($chars, 'utf-8') - 1];
				break;
			default : 
				$type = 0;
        }
		for($i=0;$i<$length;$i++){
			$key = rand($typelist[$type][0],$typelist[$type][1]);
			$str .= mb_substr($chars, $key, 1);
		}
        return $str;
    }

    /**
     * 将字符串转换为小写
     * 
     * 该方法将输入字符串转换为全小写形式。
     * 使用mb_strtolower函数确保对多字节字符（如中文）的正确处理。
     * 
     * @param string $value 要转换的字符串
     * @return string 转换后的小写字符串
     * 
     * @example
     * ```php
     * // 转换英文单词为小写
     * $lower = Str::lower('Hello World'); // 'hello world'
     * 
     * // 处理包含特殊字符的字符串
     * $lower = Str::lower('HELLO@WORLD'); // 'hello@world'
     * ```
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * 将字符串转换为大写
     * 
     * 该方法将输入字符串转换为全大写形式。
     * 使用mb_strtoupper函数确保对多字节字符（如中文）的正确处理。
     * 
     * @param string $value 要转换的字符串
     * @return string 转换后的大写字符串
     * 
     * @example
     * ```php
     * // 转换英文单词为大写
     * $upper = Str::upper('hello world'); // 'HELLO WORLD'
     * 
     * // 处理包含特殊字符的字符串
     * $upper = Str::upper('hello@world'); // 'HELLO@WORLD'
     * ```
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * 获取字符串的长度
     * 
     * 该方法返回输入字符串的字符数。
     * 使用mb_strlen函数确保对多字节字符（如中文）的正确处理。
     * 
     * @param string $value 要测量长度的字符串
     * @return int 字符串的字符数
     * 
     * @example
     * ```php
     * // 获取英文单词长度
     * $length = Str::length('hello'); // 5
     * 
     * // 获取包含中文的字符串长度
     * $length = Str::length('你好，世界'); // 5
     * ```
     */
    public static function length(string $value): int
    {
        return mb_strlen($value);
    }

    /**
     * 截取字符串的指定部分
     * 
     * 该方法从指定位置开始截取字符串的一部分。
     * 使用mb_substr函数确保对多字节字符（如中文）的正确处理。
     * 
     * @param string $string 要截取的源字符串
     * @param int $start 开始截取的位置，从0开始计数
     * @param int|null $length 要截取的长度，为null时截取到字符串末尾
     * @return string 截取后的子字符串
     * 
     * @example
     * ```php
     * // 从开头截取3个字符
     * $sub = Str::substr('hello world', 0, 3); // 'hel'
     * 
     * // 从第6个字符开始截取到末尾
     * $sub = Str::substr('hello world', 6); // 'world'
     * 
     * // 截取中文子字符串
     * $sub = Str::substr('你好，世界', 2, 3); // '，世界'
     * ```
     */
    public static function substr(string $string, int $start,?int $length = null): string
    {
        return mb_substr($string, $start, $length, 'UTF-8');
    }

    /**
     * 将驼峰命名格式的字符串转换为下划线命名格式
     * 
     * 该方法将驼峰命名法（如camelCase或PascalCase）的字符串转换为下划线分隔的格式。
     * 支持控制首字母的大小写，可以选择转换为全小写或保持原始首字母大写。
     * 内部使用缓存机制优化性能，避免重复计算相同的转换。
     * 
     * @param string $value 要转换的驼峰命名字符串
     * @param string $first 首字母处理方式：'lower'表示转换为首字母小写，其他值保持原始首字母
     * @param string $delimiter 分隔符，默认为下划线'_'
     * @return string 转换后的下划线命名格式字符串
     * 
     * @example
     * ```php
     * // 标准驼峰转下划线
     * $snake = Str::snake('helloWorld'); // 'hello_world'
     * 
     * // 保持首字母大写
     * $snake = Str::snake('HelloWorld', 'upper'); // 'Hello_World'
     * 
     * // 使用自定义分隔符
     * $snake = Str::snake('helloWorld', 'lower', '-'); // 'hello-world'
     * ```
     */
    public static function snake(string $value,string $first = 'lower', string $delimiter = '_'): string
    {
        $key = $value.$first;

        if (isset(self::$snakeCache[$key][$delimiter])) {
            return self::$snakeCache[$key][$delimiter];
        }

        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', $value);
			
			if($first == 'lower'){
				$value = self::lower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
			}else{
				$value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value);
			}
        }

        return self::$snakeCache[$key][$delimiter] = $value;
    }

    /**
     * 将下划线命名格式的字符串转换为驼峰命名格式（首字母小写）
     * 
     * 该方法将下划线分隔的字符串（如'snake_case'）转换为驼峰命名格式（如'camelCase'），
     * 首字母保持小写。内部通过调用studly方法并使用lcfirst函数实现首字母小写。
     * 使用缓存机制优化性能，避免重复计算相同的转换。
     * 
     * @param string $value 要转换的下划线命名字符串
     * @return string 转换后的驼峰命名格式字符串（首字母小写）
     * 
     * @example
     * ```php
     * // 下划线转小驼峰
     * $camel = Str::camel('hello_world'); // 'helloWorld'
     * 
     * // 处理连字符
     * $camel = Str::camel('hello-world'); // 'helloWorld'
     * ```
     */
    public static function camel(string $value): string
    {
        if (isset(self::$camelCache[$value])) {
            return self::$camelCache[$value];
        }

        return self::$camelCache[$value] = lcfirst(self::studly($value));
    }

    /**
     * 将下划线命名格式的字符串转换为驼峰命名格式（首字母大写）
     * 
     * 该方法将下划线分隔的字符串（如'snake_case'）转换为帕斯卡命名格式（如'PascalCase'），
     * 每个单词的首字母都大写。内部使用ucwords函数将每个单词首字母大写，然后移除空格。
     * 使用缓存机制优化性能，避免重复计算相同的转换。
     * 
     * @param string $value 要转换的下划线命名字符串
     * @return string 转换后的驼峰命名格式字符串（首字母大写）
     * 
     * @example
     * ```php
     * // 下划线转大驼峰
     * $studly = Str::studly('hello_world'); // 'HelloWorld'
     * 
     * // 处理连字符
     * $studly = Str::studly('hello-world'); // 'HelloWorld'
     * ```
     */
    public static function studly(string $value): string
    {
        if (isset(self::$studlyCache[$value])) {
            return self::$studlyCache[$value];
        }

        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return self::$studlyCache[$value] = str_replace(' ', '', $value);
    }

	/**
	 * 规范化控制器短名（保留多驼峰）
	 *
	 * 用于 URL / 节点段与源码类名、Views/、Languages/ 目录对齐
	 * Demo_Item、demo-item → DemoItem；已是 DemoItem 则保持不变
	 * 禁止对整段 strtolower 后再 ucfirst，否则 DemoItem 会变成 Demoitem
	 *
	 * @param string $name PATH、$_GET['m'] 或 C('CONTROL') 中的控制器段
	 * @return string PascalCase 短名
	 * @access public
	 * @static
	 */
	public static function controlName(string $name): string
	{
		$name = trim($name);
		if ($name === '') {
			return $name;
		}
		if (str_contains($name, '_') || str_contains($name, '-')) {
			return self::studly($name);
		}

		return ucfirst($name);
	}

    /**
     * 将字符串转换为标题格式（每个单词首字母大写）
     * 
     * 该方法将输入字符串转换为标题格式，即每个单词的首字母大写，其余字母小写。
     * 使用mb_convert_case函数确保对多字节字符（如中文）的正确处理。
     * 
     * @param string $value 要转换的字符串
     * @return string 转换后的标题格式字符串
     * 
     * @example
     * ```php
     * // 转换为标题格式
     * $title = Str::title('hello world'); // 'Hello World'
     * 
     * // 处理已经部分大写的字符串
     * $title = Str::title('HELLO WORLD'); // 'Hello World'
     * ```
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
	
	/**
	 * 生成唯一标识符（基于MD5加密的字符串）
	 * 
	 * 该方法生成一个唯一的标识符字符串，基于内存使用量、随机数、时间戳和IP地址等因素。
	 * 可以选择是否以UUID格式返回结果。此方法生成的标识符具有很高的唯一性。
	 * 
	 * @param bool $md5 是否以UUID格式返回，默认为false
	 * @return string 生成的唯一标识符
	 * 
	 * @example
	 * ```php
	 * // 生成标准唯一标识符
	 * $id = Str::uniqidNumber(); // 32位MD5字符串
	 * 
	 * // 生成UUID格式的唯一标识符
	 * $uuid = Str::uniqidNumber(true); // 如: {869e778c-061e-4c79-a11f-21a03e1b2795}
	 * ```
	 */
	public static function uniqidNumber(bool $md5 = false): string
	{
		$remoteAddr = (string)I('server.REMOTE_ADDR', '', '');
		$string = md5(memory_get_usage(). self::random() .uniqid('', true).mt_rand(1,99999).$remoteAddr);
		if($md5){
			$string=
				'{'.
				substr($string,0,8).'-'.
				substr($string,8,4).'-'.
				substr($string,12,4).'-'.
				substr($string,16,4).'-'.
				substr($string,20,12).
				'}';
		}
		return $string;
	}
	
	/**
	 * 生成基于日期的唯一标识符
	 * 
	 * 该方法生成一个基于当前日期和时间的唯一标识符，格式为年月日时分秒加上随机数字。
	 * 可以指定最终字符串的长度，如果长度不足，会在末尾添加随机数字补充。
	 * 最小值为14（精确到秒的日期时间）。
	 * 
	 * @param int $size 生成的唯一标识符的长度，默认为16，最小值为14
	 * @return string 基于日期的唯一标识符
	 * 
	 * @example
	 * ```php
	 * // 生成默认长度(16位)的日期唯一标识符
	 * $id = Str::uniqidDate(); // 如: 2024032812345678
	 * 
	 * // 生成指定长度(20位)的日期唯一标识符
	 * $id = Str::uniqidDate(20); // 如: 20240328123456789012
	 * ```
	 */
	public static function uniqidDate(int $size = 16): string
	{
		if ($size < 14) $size = 14;
		$string = date('Ymd') . (date('H') + date('i')) . date('s');
		while (strlen($string) < $size) $string .= rand(0, 9);
		return $string;
	}
	
	/**
	 * 敏感词检测与过滤
	 * 
	 * 该方法用于检测字符串中是否包含敏感词，并返回过滤后的结果。
	 * 如果敏感词库为空，会尝试从API获取最新的敏感词库并缓存。
	 * 结果包含过滤后的内容、检测信息和检测到的敏感词列表。
	 * 
	 * @param string $string 要检测的字符串
	 * @return array 返回包含以下信息的数组：
	 *               - content: 过滤后的字符串
	 *               - msg: 检测信息，包含敏感词数量和具体敏感词
	 *               - sensitive: 检测到的敏感词列表
	 * 
	 * @example
	 * ```php
	 * // 检测字符串中的敏感词
	 * $result = Str::getSensitive('这是一段包含敏感词的文本');
	 * // 输出过滤后的内容
	 * echo $result['content'];
	 * // 输出检测信息
	 * echo $result['msg'];
	 * ```
	 */
    public static function getSensitive(string $string): array
	{
		$list = loadConfig('Sensitive') ?: [];
		if(empty($list)){
			self::strToInc(self::getWebInfo(API_URL . '/Word/getSensitive.shtml'), 'Sensitive');
			empty($list) && $list = loadConfig('Sensitive');
		}
		
		if(empty($list)) return ['content'=>'','msg'=>'敏感词库为空，请检查！'];
		
        $count = 0;
        $sensitiveWord = '';
        $stringAfter = $string;
        $pattern = "/". implode( "|",$list) . "/i";
        if(preg_match_all($pattern, $string, $matches))
		{
            $patternList = $matches[ 0];
            $count = count($patternList);
            $sensitiveWord = implode(',', $patternList);
            $replaceArray = array_combine($patternList,array_fill(0,count($patternList), '*'));
            $stringAfter = strtr($string, $replaceArray);
        }
		
		$msg = '';
        if($count > 0){
			$msg = '匹配到 [' . $count . '] 个敏感词：[' . $sensitiveWord . ']';
        }
        return ['content'=>$stringAfter,'msg'=>$msg,'sensitive'=>$sensitiveWord];
    }
	
	/**
	 * 从指定URL获取网页内容
	 * 
	 * 该方法用于从指定的URL获取网页内容。
	 * 使用PHP的file_get_contents函数实现，添加了错误抑制以处理可能的网络错误。
	 * 如果获取失败，返回空字符串。
	 * 
	 * @param string $url 要获取内容的URL地址
	 * @return string 获取到的网页内容，如果失败则返回空字符串
	 * 
	 * @example
	 * ```php
	 * // 获取网页内容
	 * $content = Str::getWebInfo('https://example.com');
	 * // 检查是否获取成功
	 * if (!empty($content)) {
	 *     // 处理获取到的内容
	 * }
	 * ```
	 */
	public static function getWebInfo(string $url): string
	{
		if(empty($url)) return '';
		$words = @file_get_contents($url);
		return $words ?: '';
	} 
	
	/**
	 * 将字符串或数组转换为配置文件并保存
	 * 
	 * 该方法用于将字符串或数组转换为PHP配置文件并保存到指定位置。
	 * 如果输入是字符串，会先按指定分隔符分割成数组，然后生成配置文件内容。
	 * 生成的配置文件是一个返回数组的PHP脚本。
	 * 
	 * @param string|array $words 要转换的字符串或数组
	 * @param string $incName 配置文件名称，将作为文件名的一部分
	 * @param string $separator 字符串分隔符，默认为'|'
	 * @return bool 是否成功保存配置文件
	 * 
	 * @example
	 * ```php
	 * // 从字符串生成配置文件
	 * $result = Str::strToInc('word1|word2|word3', 'words');
	 * 
	 * // 从数组生成配置文件
	 * $result = Str::strToInc(['word1', 'word2', 'word3'], 'words');
	 * ```
	 */
	public static function strToInc(string|array $words,string $incName, string $separator = '|'): bool
	{
		if(empty($words) || empty($incName)) return false;
		
		is_string($words) && $words = explode($separator, $words);
		$wordArr = '<?php' . PHP_EOL . 'return [' . PHP_EOL;
		
		is_array($words) && $wordArr .= self::arrToStr($words);
		
		$wordArr .= '];' . PHP_EOL;
		
		$listPath = C('INCPATH') . $incName .'.inc.php';
		file_put_contents($listPath, $wordArr);
		
		return true;
	}
	
	/**
	 * 将数组转换为格式化的字符串
	 * 
	 * 该方法递归地将多维数组转换为格式化的字符串表示形式，
	 * 适合用于生成配置文件或调试输出。支持自定义缩进和行分隔符。
	 * 保留数组的键值对结构，并正确处理多维数组的嵌套关系。
	 * 
	 * @param array $arr 要转换的数组
	 * @param int $depth 当前递归深度，默认为0
	 * @param string $tab 缩进字符，默认为制表符'\t'
	 * @param string $le 行分隔符，默认为PHP_EOL
	 * @return string 格式化后的字符串表示
	 * 
	 * @example
	 * ```php
	 * // 将简单数组转换为字符串
	 * $array = ['key1' => 'value1', 'key2' => 'value2'];
	 * $string = Str::arrToStr($array);
	 * 
	 * // 将多维数组转换为字符串
	 * $array = ['key1' => ['subkey1' => 'value1', 'subkey2' => 'value2']];
	 * $string = Str::arrToStr($array);
	 * ```
	 */
	public static function arrToStr(array $arr,int $depth = 0, string $tab = "\t", string $le = PHP_EOL): string
	{
		$depth++;
		$str = '';
		foreach($arr as $key=>$value){
			$i = $depth;
			if(is_array($value)){
				$nextstr = '';
				while($i>0){
					$nextstr .= $tab;
					$i--;
				}
				$next = self::arrToStr($value, $depth, $tab, $le);
				$str .= $nextstr . (is_int($key) ? '' : '\''. $key .'\'=>') . $nextstr .'[' . $le . $next . $nextstr .'],' . $le;
			}else{
				while($i>0){
					$str .= $tab;
					$i--;
				}
				$str .= (is_int($key) ? '' : '\''. $key .'\'=>') . '\'' . (isset($value) ? trim((string)$value) : '') . '\',' . $le;
			}
		}
		
		return trim ($str, ',');
	}
	
	/**
     * 将节点名称中的驼峰命名转换为下划线命名格式
     * 
     * 该方法专门用于处理路径形式的节点名称，将路径中的驼峰命名部分转换为下划线命名。
     * 支持处理包含'/'分隔符的路径，以及包含'.'分隔符的节点名称。
     * 主要用于配置文件路径或路由节点的标准化处理。
     * 
     * @param string $node 要解析的节点名称，可能包含路径分隔符
     * @return string 解析后的节点名称，驼峰部分已转换为下划线格式
     * 
     * @example
     * ```php
     * // 转换简单节点名称
     * $parsed = Str::parseString('UserController'); // 'user_controller'
     * 
     * // 转换带路径的节点名称
     * $parsed = Str::parseString('api/UserController.index'); // 'api/user_controller.index'
     * ```
     */
    public static function parseString(string $node): string
    {
        if (count($nodes = explode('/', $node)) > 1) {
            $dots = [];
            foreach (explode('.', $nodes[1]) as $dot) {
                $dots[] = trim(preg_replace("/[A-Z]/", "_\\0", $dot), "_");
            }
            $nodes[1] = join('.', $dots);
        }
        return join('/', $nodes);
    }

	/**
	 * 通用中间脱敏
	 *
	 * 保留首尾可见字符，中间用 maskChar 填充；长度不足时整段替换为等长掩码
	 *
	 * @param string $value 原文
	 * @param int $keepStart 左侧保留字符数
	 * @param int $keepEnd 右侧保留字符数
	 * @param string $maskChar 掩码字符（取首字符）
	 * @return string
	 * @access public
	 * @static
	 */
	public static function mask(string $value, int $keepStart = 1, int $keepEnd = 1, string $maskChar = '*'): string
	{
		$maskChar = $maskChar === '' ? '*' : mb_substr($maskChar, 0, 1);
		$len = mb_strlen($value);
		if ($len === 0) {
			return '';
		}
		$keepStart = max(0, $keepStart);
		$keepEnd = max(0, $keepEnd);
		if ($keepStart + $keepEnd >= $len) {
			return str_repeat($maskChar, $len);
		}
		$middle = $len - $keepStart - $keepEnd;
		return mb_substr($value, 0, $keepStart)
			. str_repeat($maskChar, $middle)
			. mb_substr($value, $len - $keepEnd);
	}

	/**
	 * 手机号脱敏（11 位国内号：138****8000；其它长度走通用 mask）
	 *
	 * @param string $phone 手机号
	 * @return string
	 * @access public
	 * @static
	 */
	public static function maskPhone(string $phone): string
	{
		$phone = trim($phone);
		$digits = preg_replace('/\D+/', '', $phone) ?? '';
		if (strlen($digits) === 11) {
			return substr($digits, 0, 3) . '****' . substr($digits, -4);
		}
		return self::mask($phone, 3, 4);
	}

	/**
	 * 邮箱脱敏（保留 @ 前后各部分首尾：us**@ex****.com）
	 *
	 * @param string $email 邮箱
	 * @return string
	 * @access public
	 * @static
	 */
	public static function maskEmail(string $email): string
	{
		$email = trim($email);
		$at = mb_strpos($email, '@');
		if ($at === false) {
			return self::mask($email, 2, 2);
		}
		$local = mb_substr($email, 0, $at);
		$domain = mb_substr($email, $at + 1);
		$localMasked = mb_strlen($local) <= 2
			? str_repeat('*', mb_strlen($local))
			: self::mask($local, 2, 0);
		$dot = mb_strrpos($domain, '.');
		if ($dot === false) {
			return $localMasked . '@' . self::mask($domain, 1, 0);
		}
		$name = mb_substr($domain, 0, $dot);
		$tld = mb_substr($domain, $dot);
		$nameMasked = mb_strlen($name) <= 1
			? str_repeat('*', max(1, mb_strlen($name)))
			: self::mask($name, 2, 0);
		return $localMasked . '@' . $nameMasked . $tld;
	}

	/**
	 * 身份证号脱敏（保留前 4 后 4，中间 *）
	 *
	 * @param string $idCard 证件号
	 * @return string
	 * @access public
	 * @static
	 */
	public static function maskIdCard(string $idCard): string
	{
		$idCard = trim($idCard);
		$len = mb_strlen($idCard);
		if ($len < 8) {
			return self::mask($idCard, 1, 1);
		}
		return self::mask($idCard, 4, 4);
	}
}
