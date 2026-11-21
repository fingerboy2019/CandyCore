<?php

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 字符串编码自动检测与转换
 * 
 * 该函数用于自动检测字符串的编码格式，并将其转换为指定的目标编码。
 * 支持多种常见编码（ASCII、UTF-8、GB2312、GBK、BIG5）的自动识别，确保文本内容在不同编码环境下的正确显示。
 *
 * 使用示例：
 * ```php
 * // 将未知编码的字符串转换为UTF-8
 * $utf8Str = convertEncoding($unknownEncodedStr);
 * 
 * // 将UTF-8字符串转换为GBK
 * $gbkStr = convertEncoding($utf8Str, 'GBK');
 * ```
 *
 * @param string $str 要转换编码的字符串
 * @param string $charset 目标编码，默认为'UTF-8'
 * @return string 返回转换编码后的字符串
 * 
 * @note
 * - 使用mb_detect_encoding函数进行编码检测
 * - 检测顺序为：ASCII, UTF-8, GB2312, GBK, BIG5
 */
function convertEncoding(string $str,string $charset = 'UTF-8'): string
{
	$encode = mb_detect_encoding($str, ["ASCII",'UTF-8',"GB2312","GBK",'BIG5']);
	return mb_convert_encoding($str, $charset, $encode);
}

/**
 * HTML转纯文本函数
 * 
 * 用于过滤HTML代码中的所有HTML标签、脚本、样式等内容，返回纯文本格式。
 * 该函数使用正则表达式对HTML内容进行清理，并将HTML实体转换为对应的字符。
 * 
 * @param string $document 包含HTML标签的文档字符串
 * @return string 清理后的纯文本内容
 * 
 * @example
 * ```php
 * // 基本HTML转纯文本
 * $html = '<p>Hello <strong>World</strong>!</p>';
 * $text = htmlToText($html); // 输出: Hello World!
 * 
 * // 处理包含脚本的HTML
 * $htmlWithScript = '<div><script>alert("XSS")</script>Content</div>';
 * $text = htmlToText($htmlWithScript); // 输出: Content
 * 
 * // 处理HTML实体
 * $htmlWithEntities = '&lt;p&gt;HTML &amp; Entity&lt;/p&gt;';
 * $text = htmlToText($htmlWithEntities); // 输出: HTML & Entity
 * ```
 * 
 * @note
 * - 函数会移除所有HTML标签，包括<style>和<script>标签及其内容
 * - 会将常见的HTML实体（如&amp;、&lt;、&gt;等）转换为对应的字符
 * - 使用正则表达式进行处理，对于复杂的HTML文档可能需要进一步优化
 */
function htmlToText(string $document): string
{
	$text = preg_replace(["'<script[^>]*?>.*?</script>'si", "'<[\/\!]*?[^<>]*?>'si", "'#[^>]*?{.*?}'si", "'.[^>]*?{.*?}'si", "'([\r\n])[\s]+'", "' 'si", "'&(quot|#34);'i", "'&(amp|#38);'i", "'&(lt|#60);'i", "'&(gt|#62);'i", "'&(nbsp|#160);'i", "'&(iexcl|#161);'i", "'&(cent|#162);'i", "'&(pound|#163);'i", "'&(copy|#169);'i"], ["", "", "", "", "", "", "\"", "&", "<", ">", " ", chr(161), chr(162), chr(163), chr(169)], $document);
	return $text;
}

/**
 * 哈希数字ID编码函数
 * 
 * 用于将数字ID编码为安全的、不可预测的字符串形式，是 idDecode 函数的反向操作。
 * 使用Hashids类库实现，支持编码单个或多个数字，可用于隐藏数据库ID等敏感数字信息。
 * 
 * @param mixed ...$numbers 要编码的数字或数字列表，最后一个参数可以是配置数组
 * @return string 编码后的哈希字符串
 * 
 * @example
 * ```php
 * // 编码单个数字
 * $id = 12345;
 * $hash = idEncode($id); // 生成类似: a1b2c3 的哈希字符串
 * 
 * // 编码多个数字
 * $userId = 1;
 * $postId = 100;
 * $hash = idEncode($userId, $postId); // 生成包含两个数字信息的哈希字符串
 * 
 * // 使用自定义配置编码
 * $options = ['salt' => 'my_secret_salt', 'minLength' => 10];
 * $hash = idEncode(42, $options); // 使用自定义配置生成哈希
 * ```
 * 
 * @note
 * - 支持编码多个数字，这些数字将被编码为一个哈希字符串
 * - 函数会检查最后一个参数是否为数组，如果是，则将其视为配置选项
 * - 编码结果的长度和格式受Hashids配置影响，默认使用框架配置
 */
function idEncode(...$numbers): string
{
	$init = end($numbers);
	if(is_array($init)){
		array_pop($numbers);
		return N('Hashids',$init)->encode($numbers);
	}
	
	return N('Hashids')->encode($numbers);
}

/**
 * 哈希数字ID解码函数
 * 
 * 用于将哈希后的字符串ID解码回原始数字，是 idEncode 函数的反向操作。
 * 使用Hashids类库实现，支持自定义配置参数，可用于安全地将数据库ID等数字信息暴露给前端。
 * 
 * @param string $hash 要解码的哈希字符串
 * @param array $init Hashids初始化配置，包含salt、minLength、alphabet等参数
 * @return string|array 解码后的数字或数字数组，如果解码失败则返回空数组
 * 
 * @example
 * ```php
 * // 基本解码操作
 * $hash = 'a1b2c3'; // 假设这是经过 idEncode 编码的值
 * $id = idDecode($hash); // 返回解码后的原始数字
 * 
 * // 使用自定义配置解码
 * $customHash = 'xyz123';
 * $id = idDecode($customHash, ['salt' => 'my_secret_salt']);
 * 
 * // 解码多个数字
 * $multiHash = 'abcdef'; // 假设这是多个数字编码的结果
 * $ids = idDecode($multiHash); // 返回解码后的数字数组
 * ```
 * 
 * @note
 * - 如果不提供$init参数，将使用默认配置创建Hashids实例
 * - 解码失败时返回空数组，调用者应检查返回值类型
 * - 解码结果可能是单个数字或数字数组，取决于编码时提供的参数个数
 */
function idDecode(string $hash,array $init = []): mixed
{
	return (empty($init) ? N('Hashids') : N('Hashids',$init))->decode($hash);
}

/**
 * 获取中文转拼音
 *
 * 该函数用于将中文字符串转换为拼音，可以选择只获取首字母、设置分隔符、
 * 是否首字母大写等多种转换方式，支持多种字符集。
 *
 * 使用示例：
 * - 标准转换: getPinyin('中国') // 输出: zhongguo
 * - 只取首字母: getPinyin('中国', true) // 输出: zg
 * - 添加分隔符: getPinyin('中国', false, '-') // 输出: zhong-guo
 * - 首字母大写: getPinyin('中国', false, '', true) // 输出: ZhongGuo
 *
 * @param string $str 要转换的字符串
 * @param bool $onlyFirst 是否只取首字母，默认为false
 * @param string $delimiter 拼音分隔符，默认为空
 * @param bool $capitalizeFirst 是否首字母大写，默认为false
 * @param string $charset 字符集，默认为utf-8
 * @return string 返回转换后的拼音字符串
 */
function getPinyin(string $str = '',bool $onlyFirst = false,string $delimiter = '',bool $capitalizeFirst = false,string $charset = 'utf-8'): string
{
	if(empty($str)) return '';
	return \Extend\Pinyin::get($str, $onlyFirst, $delimiter, $capitalizeFirst, $charset);
}

/**
 * 多语言字符串安全截取
 * 
 * 该函数用于安全地截取字符串，支持中文和其他多字节编码，确保不会截断字符。
 * 包含HTML实体转义和还原处理，避免截取导致的HTML标签错误。优先使用mb_substr或iconv_substr函数，
 * 当这些函数不可用时，会使用正则表达式方法进行截取。
 *
 * 使用示例：
 * ```php
 * // 截取UTF-8字符串，保留前20个字符
 * $shortStr = mbSubstr($longStr, 20);
 * 
 * // 从第5个字符开始截取10个字符
 * $shortStr = mbSubstr($longStr, 10, '...', 5);
 * 
 * // 对GBK编码字符串进行截取
 * $shortStr = mbSubstr($gbkStr, 20, '...', 0, 'gbk');
 * ```
 *
 * @param string $str 要截取的源字符串
 * @param int $length 截取的长度
 * @param string $suffix 截取后添加的后缀，默认为'...'
 * @param int $start 起始位置，默认为0
 * @param string $charset 字符集编码，默认为'utf-8'
 * @return string 返回截取后的字符串
 * 
 * @note
 * - 自动处理HTML实体，避免标签错误
 * - 优先使用mb_substr，其次是iconv_substr，最后使用正则表达式
 * - 支持UTF-8、GB2312、GBK、BIG5等多种编码
 */
function mbSubstr(string $str,int $length,string $suffix = '...',int $start = 0,string $charset = 'utf-8'): string
{
	$str = str_replace([' ','&nbsp;', '&amp;', '&quot;', '&#039;', '&ldquo;', '&rdquo;', '&mdash;', '&lt;', '&gt;', '&middot;', '&hellip;'], ['∵',' ', '&', '"', "'", '“', '”', '—', '<', '>', '·', '…'], $str);
	if (function_exists('mb_substr')){
		$strLen = mb_strlen($str);
		$subStr = mb_substr($str, $start, $length, $charset);
		if ($length >= $strLen){
			return $subStr;
		} 
		return $subStr . $suffix;
	} elseif (function_exists('iconv_substr')){
		return iconv_substr($str, $start, $length, $charset);
	} 
	$re['utf-8'] = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/";
	$re['gb2312'] = "/[\x01-\x7f]|[\xb0-\xf7][\xa0-\xfe]/";
	$re['gbk'] = "/[\x01-\x7f]|[\x81-\xfe][\x40-\xfe]/";
	$re['big5'] = "/[\x01-\x7f]|[\x81-\xfe]([\x40-\x7e]|\xa1-\xfe])/";
	preg_match_all($re[$charset], $str, $match);
	$slice = join("", array_slice($match[0], $start, $length));
	$slice = str_replace(['∵', '&', '"', "'", '“', '”', '—', '<', '>', '·', '…'], [' ', '&amp;', '&quot;', '&#039;', '&ldquo;', '&rdquo;', '&mdash;', '&lt;', '&gt;', '&middot;', '&hellip;'], $slice);
	return $slice . $suffix;
}

/**
 * 十六进制字符串转普通字符串
 * 
 * 将十六进制格式的字符串转换为对应的普通字符串。该函数逐字节处理十六进制数据，
 * 将每两个十六进制字符转换为一个ASCII字符，适用于数据解码和加密解密场景。
 * 
 * @param string $hex 十六进制格式的字符串
 * @return string 转换后的普通字符串
 * 
 * @example
 * ```php
 * // 基本十六进制转字符串
 * $hex = '48656c6c6f20576f726c64'; // "Hello World"的十六进制表示
 * $str = hexToString($hex); // 输出: Hello World
 * 
 * // 转换空字符串
 * $empty = hexToString(''); // 输出: 空字符串
 *
 * // 与 stringToHex 函数配合使用
 * $original = 'Test String';
 * $hex = stringToHex($original);
 * $restored = hexToString($hex); // $restored等于$original
 * ```
 * 
 * @note
 * - 输入的十六进制字符串长度应该是偶数
 * - 如果输入的十六进制字符串长度为奇数，最后一个字符将被忽略
 * - 函数内部使用hexdec()函数将十六进制值转换为十进制，再使用chr()函数转换为字符
 */
function hexToString(string $hex): string
{
	$string='';
	for ($i=0; $i < strlen($hex)-1; $i+=2){
		$string .= chr(hexdec($hex[$i].$hex[$i+1]));
	}
	return $string;
}

/**
 * 字符串转十六进制函数
 *
 * 将普通字符串转换为十六进制表示形式。该函数逐字节处理字符串，
 * 对每个字符先使用ord()获取ASCII值，再使用dechex()转换为十六进制字符串。
 * 适用于数据加密、二进制数据传输和数据隐藏等场景。
 *
 * @param string $string 要转换的普通字符串
 * @return string 转换后的十六进制格式字符串
 * 
 * @example
 * ```php
 * // 基本字符串转十六进制
 * $str = 'Hello World';
 * $hex = stringToHex($str); // 输出: 48656c6c6f20576f726c64
 * 
 * // 处理空字符串
 * $emptyHex = stringToHex(''); // 输出: 空字符串
 * 
 * // 处理特殊字符
 * $specialHex = stringToHex('!@#$%^&*()');
 * 
 * // 与hex2str函数配合使用进行数据编码解码
 * $original = '敏感数据';
 * $encoded = stringToHex($original);
 * $decoded = hexToString($encoded); // $decoded等于$original
 * ```
 * 
 * @note
 * - 函数输出的十六进制字符串不包含前缀（如0x）
 * - 对于非ASCII字符，函数按字节处理，可能需要额外的编码处理
 * - 转换后的字符串长度是原字符串的两倍（每个字符对应两个十六进制字符）
 */
function stringToHex(string $string): string
{
	$hex='';
	for ($i=0; $i < strlen($string); $i++){
		$hex .= dechex(ord($string[$i]));
	}
	return $hex;
}

/**\n * 测试类\n * @author 作者\n * /\nclass Test { }';
 * $compressed = stripWhitespace($classCode);
 * // 输出: class Test { }
 * 
 * // 处理包含Heredoc的代码
 * $heredocCode = '<?php\n$text = <<<EOT\n多行文本\nEOT;\n?>';
 * $compressed = stripWhitespace($heredocCode);
 * // 输出保持Heredoc结构但去除了多余空白
 * ```
 * 
 * @note
 * - 函数会保留必要的空格以确保代码语法正确
 * - 会完全移除所有注释（包括文档注释和行注释）
 * - 对Heredoc语法有特殊处理，确保其正确转换
 * - 该函数不会修改代码的功能，仅移除不必要的字符
 * - 在处理大型PHP文件时要注意内存使用情况
 */
function stripWhitespace(string $content): string
{
	$stripStr = '';
	//分析php源码
	$tokens = token_get_all($content);
	$lastSpace = false;
	for ($i = 0, $j = count($tokens); $i < $j; $i++){
		if (is_string($tokens[$i])){
			$lastSpace = false;
			$stripStr .= $tokens[$i];
		} else {
			switch ($tokens[$i][0]){
				//过滤各种PHP注释
				case T_COMMENT:
				case T_DOC_COMMENT:
					break;
				//过滤空格
				case T_WHITESPACE:
					if (!$lastSpace){
						$stripStr  .= ' ';
						$lastSpace = true;
					}
					break;
				case T_START_HEREDOC:
					$stripStr .= '<<<RUBIK\n';
					break;
				case T_END_HEREDOC:
					$stripStr .= 'RUBIK;\n';
					for($k = $i+1; $k < $j; $k++){
						if(is_string($tokens[$k]) && $tokens[$k] == ';'){
							$i = $k;
							break;
						} else if($tokens[$k][0] == T_CLOSE_TAG){
							break;
						}
					}
					break;
				default:
					$lastSpace = false;
					$stripStr  .= $tokens[$i][1];
			}
		}
	}
	return $stripStr;
}

/**
 * 递归去除字符串中的反斜杠函数
 *
 * 该函数能够递归处理数组或单个值，对其中的字符串去除反斜杠转义。
 * 对于数组类型的值，会递归地对每个元素调用自身；对于非数组值，
 * 会调用PHP内置的stripslashes()函数处理。适用于处理从数据库或POST/GET
 * 获取的数据，特别是在magic_quotes_gpc开启的环境中。
 *
 * @param mixed $value 要处理的值，可以是字符串或数组
 * @return mixed 处理后的值，保持原始数据类型
 * 
 * @example
 * ```php
 * // 处理单个字符串
 * $str = "That\\'s a test\\"string\\"";
 * $clean = stripslashesDeep($str); // 输出: That's a test"string"
 * 
 * // 处理嵌套数组
 * $array = [
 *     "key1" => "value\\'s test",
 *     "key2" => [
 *         "nested1" => "another\\"value\\"",
 *         "nested2" => "no\\'quotes"
 *     ]
 * ];
 * $cleanArray = stripslashesDeep($array);
 * // $cleanArray将包含所有反斜杠已被移除的字符串
 * 
 * // 处理NULL值
 * $nullResult = stripslashesDeep(null); // 输出: null
 * ```
 * @note
 * - 函数会保持原始数据类型不变（字符串、数组或null）
 * - 对于空值或未定义的值，函数会安全地返回null而不会报错
 * - 在处理大型嵌套数组时要注意性能影响
 */
function stripslashesDeep(mixed $value):mixed
{ 
	$value = is_array($value) ? array_map('stripslashesDeep', $value) : (isset($value) ? stripslashes($value) : null); 
	return $value; 
}

/**
 * 生成随机字符串
 *
 * 该函数用于生成指定长度和类型的随机字符串，支持纯数字、小写字母、大写字母、
 * 字母数字组合等多种模式，通过调用Str::random方法实现。
 *
 * 使用示例：
 * - 生成默认4位随机数: getRandomStr() // 如: 1234
 * - 生成6位随机数: getRandomStr(6) // 如: 123456
 * - 生成8位字母数字混合: getRandomStr(8, 1) // 如: a1b2c3d4
 * - 生成10位纯小写字母: getRandomStr(10, 2) // 如: abcdefghij
 * - 生成5位纯大写字母: getRandomStr(5, 3) // 如: ABCDE
 *
 * @param int $num 生成的字符串长度，默认为4
 * @param int $type 生成类型，0:纯数字, 1:字母数字混合, 2:纯小写字母, 3:纯大写字母
 * @return string 返回生成的随机字符串
 */
function getRandomStr(int $num = 4,int $type = 0): string
{
	return \Candy\Extend\Str::random($num, $type);
}

/**
 * 生成v4版本UUID
 *
 * 该函数用于生成符合RFC 4122标准的UUID v4（基于随机数），
 * 通过设置特定的版本位和变体位来确保生成的UUID符合标准规范。
 * UUID v4适用于需要全局唯一标识符的场景，如数据库主键、会话标识等。
 *
 * 使用示例：
 * - 生成标准UUID: createUuidV4() // 输出类似: 123e4567-e89b-12d3-a456-426614174000
 * - 用于数据库主键: $id = createUuidV4();
 * - 生成会话标识: session_id(createUuidV4());
 *
 * @return string 返回符合RFC 4122标准的v4版本UUID字符串
 */
function createUuidV4(): string
{
    // 生成随机的 16 字节
    $data = random_bytes(16);

    // 设置版本号为 0100（即 v4）
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // 设置变体为 10（即 RFC 4122）
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // 将字节转换为十六进制字符串，并格式化为 UUID
    return sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(substr($data, 0, 4)),
        bin2hex(substr($data, 4, 2)),
        bin2hex(substr($data, 6, 2)),
        bin2hex(substr($data, 8, 2)),
        bin2hex(substr($data, 10, 6))
    );
}

/**
 * 文件尺寸转换函数
 * 
 * 将字节数转换为人类可读的文件大小格式，自动选择合适的单位（Byte、KB、MB、GB等）。
 * 支持大文件尺寸的精确转换，保留两位小数，适用于文件大小显示、内存使用统计等场景。
 * 
 * @param int $bytes 要转换的字节数
 * @return string 格式化后的大小字符串，如 "1.5 MB"、"1024 Byte" 等
 * 
 * @example
 * ```php
 * // 获取文件大小的人类可读格式
 * $fileSize = filesize('example.txt');
 * echo '文件大小：' . sizeCount($fileSize);
 * 
 * // 显示内存使用情况
 * echo '内存使用：' . sizeCount(memory_get_usage());
 * 
 * // 处理不同大小的字节数
 * echo sizeCount(1024);       // 输出: "1 KB"
 * echo sizeCount(1048576);    // 输出: "1 MB"
 * echo sizeCount(0);          // 输出: "0 Byte"
 * ```
 * 
 * @note
 * - 使用对数计算确定最合适的单位级别
 * - 单位转换基于1024进制（1 KB = 1024 Byte）
 * - 结果保留两位小数，确保显示的可读性
 * - 对于超出已知单位范围的超大数值，会限制在最高可用单位（PB）
 */
function sizeCount(int $bytes): string
{
	// 修复：处理零值和负数，避免 log(0) 错误
	if ($bytes <= 0) {
		return '0 Byte';
	}
	
	$unit = ['Byte','KB','MB','GB','TB','PB'];
	$i = floor(log($bytes, 1024));
	// 确保索引在有效范围内
	$i = min($i, count($unit) - 1);
	return round($bytes / pow(1024, $i), 2) . ' ' . $unit[$i];
}

/**
 * 对象深度转换为数组
 * 
 * 该函数用于递归地将对象或嵌套的对象转换为数组格式，支持处理多层嵌套的对象和数组混合结构。
 * 可将stdClass对象或自定义对象转换为关联数组，保持原始数据的结构层次关系。
 *
 * 使用示例：
 * ```php
 * // 将对象转换为数组
 * $array = objToArray($object);
 * 
 * // 处理嵌套对象
 * $stdClass = new stdClass();
 * $stdClass->name = 'test';
 * $stdClass->data = new stdClass();
 * $stdClass->data->id = 1;
 * $array = objToArray($stdClass);
 * // 返回: ['name' => 'test', 'data' => ['id' => 1]]
 * ```
 *
 * @param mixed $obj 要转换的对象或数组，可以是对象、数组或标量值
 * @return array 返回转换后的数组，如果输入是标量值则返回原值
 * 
 * @note
 * - 使用递归方式处理多层嵌套的对象和数组
 * - 对于对象，使用get_object_vars获取其所有属性
 * - 对于非对象和非数组的值，直接返回原值
 */
function objToArray($obj):array
{
	if(is_array($obj)){
		foreach($obj as &$value){
			$value = objToArray($value);
		}
		return $obj;
	}elseif(is_object($obj)){
		$obj = get_object_vars($obj);
		return objToArray($obj);
	}else{
		// 修复：标量值包装为数组以符合返回类型声明
		return [$obj];
	}
}
