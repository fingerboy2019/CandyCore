<?php
/**
 * 汉字拼音转换工具类
 * 
 * 提供将中文汉字转换为拼音的功能，支持多种转换模式，包括：
 * - 完整拼音转换
 * - 拼音首字母提取
 * - 自定义分隔符
 * - 首字母大写转换
 * - 多字符编码支持
 * 
 * 该类采用查表方式实现汉字到拼音的高效转换，支持所有常用汉字。
 * 
 * @package Extend
 * @author 刘森 (fingerboy@qq.com)
 * @date 2020-04-11 11:01:08
 * @final 本类为最终类不允许继承
 */

declare(strict_types=1);
namespace Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Pinyin {
    /**
     * 拼音字符串数组的键值
     * 
     * 包含所有支持的拼音字符串，使用竖线(|)分隔，用于构建汉字到拼音的映射表。
     * 这些拼音字符串与$values数组中的数值一一对应。
     * 
     * @var string
     */
	protected static $keys = "a|ai|an|ang|ao|ba|bai|ban|bang|bao|bei|ben|beng|bi|bian|biao|bie|bin|bing|bo|bu|ca|cai|can|cang|cao|ce|ceng|cha|chai|chan|chang|chao|che|chen|cheng|chi|chong|chou|chu|chuai|chuan|chuang|chui|chun|chuo|ci|cong|cou|cu|cuan|cui|cun|cuo|da|dai|dan|dang|dao|de|deng|di|dian|diao|die|ding|diu|dong|dou|du|duan|dui|dun|duo|e|en|er|fa|fan|fang|fei|fen|feng|fo|fou|fu|ga|gai|gan|gang|gao|ge|gei|gen|geng|gong|gou|gu|gua|guai|guan|guang|gui|gun|guo|ha|hai|han|hang|hao|he|hei|hen|heng|hong|hou|hu|hua|huai|huan|huang|hui|hun|huo|ji|jia|jian|jiang|jiao|jie|jin|jing|jiong|jiu|ju|juan|jue|jun|ka|kai|kan|kang|kao|ke|ken|keng|kong|kou|ku|kua|kuai|kuan|kuang|kui|kun|kuo|la|lai|lan|lang|lao|le|lei|leng|li|lia|lian|liang|liao|lie|lin|ling|liu|long|lou|lu|lv|luan|lue|lun|luo|ma|mai|man|mang|mao|me|mei|men|meng|mi|mian|miao|mie|min|ming|miu|mo|mou|mu|na|nai|nan|nang|nao|ne|nei|nen|neng|ni|nian|niang|niao|nie|nin|ning|niu|nong|nu|nv|nuan|nue|nuo|o|ou|pa|pai|pan|pang|pao|pei|pen|peng|pi|pian|piao|pie|pin|ping|po|pu|qi|qia|qian|qiang|qiao|qie|qin|qing|qiong|qiu|qu|quan|que|qun|ran|rang|rao|re|ren|reng|ri|rong|rou|ru|ruan|rui|run|ruo|sa|sai|san|sang|sao|se|sen|seng|sha|shai|shan|shang|shao|she|shen|sheng|shi|shou|shu|shua|shuai|shuan|shuang|shui|shun|shuo|si|song|sou|su|suan|sui|sun|suo|ta|tai|tan|tang|tao|te|teng|ti|tian|tiao|tie|ting|tong|tou|tu|tuan|tui|tun|tuo|wa|wai|wan|wang|wei|wen|weng|wo|wu|xi|xia|xian|xiang|xiao|xie|xin|xing|xiong|xiu|xu|xuan|xue|xun|ya|yan|yang|yao|ye|yi|yin|ying|yo|yong|you|yu|yuan|yue|yun|za|zai|zan|zang|zao|ze|zei|zen|zeng|zha|zhai|zhan|zhang|zhao|zhe|zhen|zheng|zhi|zhong|zhou|zhu|zhua|zhuai|zhuan|zhuang|zhui|zhun|zhuo|zi|zong|zou|zu|zuan|zui|zun|zuo";
    /**
     * 汉字编码值数组
     * 
     * 包含与$keys对应的汉字编码值，使用竖线(|)分隔。
     * 这些数值是汉字的编码值，用于汉字到拼音的映射。
     * 
     * @var string
     */
	protected static $values = "-20319|-20317|-20304|-20295|-20292|-20283|-20265|-20257|-20242|-20230|-20051|-20036|-20032|-20026|-20002|-19990|-19986|-19982|-19976|-19805|-19784|-19775|-19774|-19763|-19756|-19751|-19746|-19741|-19739|-19728|-19725|-19715|-19540|-19531|-19525|-19515|-19500|-19484|-19479|-19467|-19289|-19288|-19281|-19275|-19270|-19263|-19261|-19249|-19243|-19242|-19238|-19235|-19227|-19224|-19218|-19212|-19038|-19023|-19018|-19006|-19003|-18996|-18977|-18961|-18952|-18783|-18774|-18773|-18763|-18756|-18741|-18735|-18731|-18722|-18710|-18697|-18696|-18526|-18518|-18501|-18490|-18478|-18463|-18448|-18447|-18446|-18239|-18237|-18231|-18220|-18211|-18201|-18184|-18183|-18181|-18012|-17997|-17988|-17970|-17964|-17961|-17950|-17947|-17931|-17928|-17922|-17759|-17752|-17733|-17730|-17721|-17703|-17701|-17697|-17692|-17683|-17676|-17496|-17487|-17482|-17468|-17454|-17433|-17427|-17417|-17202|-17185|-16983|-16970|-16942|-16915|-16733|-16708|-16706|-16689|-16664|-16657|-16647|-16474|-16470|-16465|-16459|-16452|-16448|-16433|-16429|-16427|-16423|-16419|-16412|-16407|-16403|-16401|-16393|-16220|-16216|-16212|-16205|-16202|-16187|-16180|-16171|-16169|-16158|-16155|-15959|-15958|-15944|-15933|-15920|-15915|-15903|-15889|-15878|-15707|-15701|-15681|-15667|-15661|-15659|-15652|-15640|-15631|-15625|-15454|-15448|-15436|-15435|-15419|-15416|-15408|-15394|-15385|-15377|-15375|-15369|-15363|-15362|-15183|-15180|-15165|-15158|-15153|-15150|-15149|-15144|-15143|-15141|-15140|-15139|-15128|-15121|-15119|-15117|-15110|-15109|-14941|-14937|-14933|-14930|-14929|-14928|-14926|-14922|-14921|-14914|-14908|-14902|-14894|-14889|-14882|-14873|-14871|-14857|-14678|-14674|-14670|-14668|-14663|-14654|-14645|-14630|-14594|-14429|-14407|-14399|-14384|-14379|-14368|-14355|-14353|-14345|-14170|-14159|-14151|-14149|-14145|-14140|-14137|-14135|-14125|-14123|-14122|-14112|-14109|-14099|-14097|-14094|-14092|-14090|-14087|-14083|-13917|-13914|-13910|-13907|-13906|-13905|-13896|-13894|-13878|-13870|-13859|-13847|-13831|-13658|-13611|-13601|-13406|-13404|-13400|-13398|-13395|-13391|-13387|-13383|-13367|-13359|-13356|-13343|-13340|-13329|-13326|-13318|-13147|-13138|-13120|-13107|-13096|-13095|-13091|-13076|-13068|-13063|-13060|-12888|-12875|-12871|-12860|-12858|-12852|-12849|-12838|-12831|-12829|-12812|-12802|-12607|-12597|-12594|-12585|-12556|-12359|-12346|-12320|-12300|-12120|-12099|-12089|-12074|-12067|-12058|-12039|-11867|-11861|-11847|-11831|-11798|-11781|-11604|-11589|-11536|-11358|-11340|-11339|-11324|-11303|-11097|-11077|-11067|-11055|-11052|-11045|-11041|-11038|-11024|-11020|-11019|-11018|-11014|-10838|-10832|-10815|-10800|-10790|-10780|-10764|-10587|-10544|-10533|-10519|-10331|-10329|-10328|-10322|-10315|-10309|-10307|-10296|-10281|-10274|-10270|-10262|-10260|-10256|-10254";
	
	
    /**
     * 将中文字符串转换为拼音
     * 
     * 提供灵活的汉字到拼音的转换功能，支持多种转换模式和格式选项。
     * 该方法可以处理单个汉字或包含多个汉字的字符串。
     * 
     * @param string $chinese 要转换的中文字符串
     * @param bool $onlyfirst 是否只返回拼音首字母（true:仅返回首字母, false:返回完整拼音）
     * @param string $delimiter 拼音之间的分隔符（默认为空字符串）
     * @param bool $ucfirst 是否将每个拼音的首字母大写
     * @param string $charset 文字编码（默认为'utf-8'）
     * @return mixed 返回转换后的拼音字符串
     * @example 
     * // 返回完整拼音："zhongwen"
     * Pinyin::get('中文');
     * 
     * // 返回拼音首字母："zw"
     * Pinyin::get('中文', true);
     * 
     * // 带分隔符的拼音："zhong-wen"
     * Pinyin::get('中文', false, '-');
     * 
     * // 首字母大写的拼音："ZhongWen"
     * Pinyin::get('中文', false, '', true);
     */
	public static function get(string $chinese = '',bool $onlyfirst = false,string $delimiter = '',bool $ucfirst = false,string $charset = 'utf-8'): mixed
	{
		$keys_a = explode('|', self::$keys);
		$values_a = explode('|', self::$values);
		$data = array_combine($keys_a, $values_a);
		arsort($data);
		reset($data);
		if ($charset != 'gb2312') {
			$chinese = self::_u2_utf8_gb($chinese);
		}
		$result = '';
		for ($i = 0; $i < strlen($chinese); $i++) {
			$_P = ord(substr($chinese, $i, 1));
			if ($_P > 160) {
				$_Q = ord(substr($chinese, ++$i, 1));
				$_P = $_P * 256 + $_Q - 65536;
			}
			$_PY = ($onlyfirst ? substr(self::_pinyin($_P, $data), 0, 1) : self::_pinyin($_P, $data));
			
			$result .= $ucfirst ? ucfirst($_PY) : $_PY;
			$result .= $delimiter;
		}
		if ($delimiter) {
			$result = rtrim($result, $delimiter);
		}
	
		return preg_replace("/[^a-z0-9_\-]*/i", '', $result);
	}
	
	
    /**
     * 根据汉字编码值获取对应的拼音
     * 
     * 内部辅助方法，用于在拼音映射表中查找并返回给定字符编码值对应的拼音字符串。
     * 对于非汉字字符，直接返回其ASCII字符表示。
     * 
     * @param int $num 汉字的编码值
     * @param array $data 拼音映射表，键为拼音字符串，值为对应的编码值
     * @return string|int 返回查找到的拼音字符串或ASCII字符
     * @access private
     */
	private static function _pinyin(int $num,array $data): int|string
	{
		if ($num > 0 && $num < 160) {
			return chr($num);
		} elseif ($num < -20319 || $num > -10247) {
			return '';
		} else {
			foreach ($data as $k => $v) {
				if ($v <= $num) {
					break;
				}
			}
	
			return $k;
		}
	}
	
    /**
     * 将UTF-8编码的字符转换为GB2312编码
     * 
     * 内部辅助方法，用于处理字符编码转换，确保拼音查询的正确性。
     * 当输入字符集不是GB2312时，需要先进行编码转换。
     * 
     * @param string $c 要转换的字符
     * @return string|bool 转换后的GB2312编码字符，失败时返回false
     * @access private
     */
	private static function _u2_utf8_gb(string $c): bool|string
	{
		$string = '';
		if ($c < 0x80) {
			$string .= $c;
		} elseif ($c < 0x800) {
			$string .= chr(0xC0 | $c >> 6);
			$string .= chr(0x80 | $c & 0x3F);
		} elseif ($c < 0x10000) {
			$string .= chr(0xE0 | $c >> 12);
			$string .= chr(0x80 | $c >> 6 & 0x3F);
			$string .= chr(0x80 | $c & 0x3F);
		} elseif ($c < 0x200000) {
			$string .= chr(0xF0 | $c >> 18);
			$string .= chr(0x80 | $c >> 12 & 0x3F);
			$string .= chr(0x80 | $c >> 6 & 0x3F);
			$string .= chr(0x80 | $c & 0x3F);
		}
		return iconv('UTF-8', 'GB2312//IGNORE', $string);
	}
}
