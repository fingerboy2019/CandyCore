<?php
/**
 * Security — SQL / 代码注入特征检测
 *
 * 十六进制特征规则匹配、严格模式递归过滤（securityRank）。
 * 由 InputFilter 组合；检测到违规时由编排层记录日志并阻断。
 *
 * @package Candy\Core\Trait\Security
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Security;

trait SqlInjectionGuard
{
	/** @var string 特征规则量词占位，由 InputFilter::_getInfo() 写入 */
	protected static string $injectionRank = '';

	private static array $injectionPreProcess = ['"."', "'.'"];

	/** @var list<string> PHP/JS/SQL/LDAP/XXE/SSRF 等注入特征（#%# 替换为 $injectionRank） */
	private static array $injectionCodePreg = [
		'63#%#68#%#6f#%#77#%#6e.{0,20}(22|27|28)',/*chown*/
		'63#%#68#%#6d#%#6f#%#64.{0,20}(22|27|28)',/*chmod*/
		'65#%#78#%#65#%#63.{0,20}(22|27|28)',/*exec*/
		'65#%#76#%#61#%#6C.{0,20}(22|27|28)',/*eval*/
		'73#%#79#%#73#%#74#%#65#%#6d.{0,20}(22|27|28)',/*system*/
		'63#%#68#%#67#%#72#%#70.{0,20}(22|27|28)',/*chgrp*/
		'73#%#74#%#72#%#5f#%#72#%#6f#%#74#%#31#%#33.{0,20}(22|27|28)',/*str_rot13*/
		'67#%#7A#%#75#%#6E#%#63#%#6F#%#6D#%#70#%#72#%#65#%#73#%#73.{0,20}(22|27|28)',/*gzuncompress*/
		'62#%#61#%#73#%#65#%#36#%#34#%#5f#%#64#%#65#%#63#%#6F#%#64#%#65.{0,20}(22|27|28)',/*base64_decode*/
		'63#%#68#%#72#%#(22|27|28)',/*chr(*/
		'70#%#72#%#69#%#6e#%#74.{0,20}(22|27|28)',/*print(*/
		'3c#%#3f#%#70#%#68#%#70.*3f#%#3e',/*<?php?>*/
		'3c#%#3f.*3f#%#3e',/*<??>*/
		'3c#%#25.*25#%#3e',/*<%%>*/
		'24#%#7b#%#7d',/*${}*/
		'7b7c.*7d',/*{|}*/
		'5c#%#78.*5c#%#78.*5c#%#78.*5c#%#78',/**/
		'26.*5b.*5d.*3d.*\d+.*26',/*&...[]...=...\d...&*/
		'24.{0,40}7b.{0,40}24.{0,40}7b',/*${${*/
		'61#%#73#%#73#%#65#%#72#%#74.{0,20}(22|27|28)',/*assert*/
		'72#%#65#%#67#%#5f#%#72#%#65#%#70#%#6c#%#61#%#63#%#65.{0,20}(22|27|28)',/*reg_replace*/
		'63#%#72#%#65#%#61#%#74#%#65#%#5f#%#66#%#75#%#6e#%#63#%#74#%#69#%#6f#%#6e.{0,20}(22|27|28)',/*create_function*/
		'66#%#69#%#6c#%#65#%#20#%#63#%#6f#%#6e#%#74#%#65#%#6e#%#74#%#73.{0,20}(22|27|28)',/*file_put_contents*/
		'3c#%#69#%#66#%#72#%#61#%#6d#%#65.*3c#%#2f#%#69#%#66#%#72#%#61#%#6d#%#65',/*<iframe></iframe>*/
		'3c#%#69#%#6d#%#67.*6f#%#6e#%#65#%#72#%#72#%%#72',/*<img onerror*/
		'3c#%#73#%#63#%#72#%#69#%#70#%#74.*6a#%#61#%#76#%#61#%#73#%#63#%#72#%#69#%#70#%#74',/*<script javascript*/
		'3c#%#73#%#74#%#79#%#6c#%#65.*3c#%#2f#%#73#%#74#%#79#%#6c#%#65',/*<style></style>*/
		'3c#%#6c#%#69#%#6e#%#6b.*6a#%#61#%#76#%#61#%#73#%#63#%#72#%#69#%#70#%#74',/*<link javascript*/
		'3c#%#66#%#6f#%#72#%#6d.*6f#%#6e#%#73#%#75#%#62#%#6d#%#69#%#74',/*<form onsubmit*/
		'3c#%#62#%#6f#%#64#%#79.*6f#%#6e#%#6c#%#6f#%#61#%#64',/*<body onload*/
		'3c#%#61#%#6e#%#63#%#68#%#6f#%#72.*6a#%#61#%#76#%#61#%#73#%#63#%#72#%#69#%#70#%#74',/*<a javascript*/
		'3c#%#6d#%#65#%#74#%#61.*68#%#74#%#74#%#70#%#2d#%#65#%#71#%#75#%#69#%#76',/*<meta http-equiv*/
		'3c#%#6f#%#62#%#6a#%#65#%#63#%#74.*3c#%#2f#%#6f#%#62#%#6a#%#65#%#63#%#74',/*<object></object>*/
		'3c#%#65#%#6d#%#62#%#65#%#64.*3c#%#2f#%#65#%#6d#%#62#%#65#%#64',/*<embed></embed>*/
		'3c#%#73#%#63#%#72#%#69#%#70#%#74.*3c#%#2f#%#73#%#63#%#72#%#69#%#70#%#74',/*<script\x00</script*/
		'6a#%#61#%#76#%#61#%#73#%#63#%#72#%#69#%#70#%#74#%#3a',/*javascript:*/
		'3c.*6f#%#6e#%#65#%#72#%#72#%#6f#%#72',/*onerror*/
		'3c.*6f#%#6e#%#6c#%#6f#%#61#%#64',/*onload*/
		'3c.*6f#%#6e#%#62#%#6c#%#75#%#72',/*onblur*/
		'3c.*6f#%#6e#%#63#%#68#%#61#%#6e#%#67#%#65',/*onchange*/
		'3c.*6f#%#6e#%#63#%#6c#%#69#%#63#%#6b',/*onclick*/
		'3c.*6f#%#6e#%#64#%#62#%#6c#%#63#%#6c#%#69#%#63#%#6b',/*ondblclick*/
		'3c.*6f#%#6e#%#66#%#6f#%#63#%#75#%#73',/*onfocus*/
		'3c.*6f#%#6e#%#6b#%#65#%#79#%#64#%#6f#%#77#%#6e',/*onkeydown*/
		'3c.*6f#%#6e#%#6b#%#65#%#79#%#70#%#72#%#65#%#73#%#73',/*onkeypress*/
		'3c.*6f#%#6e#%#6b#%#65#%#79#%#75#%#70',/*onkeyup*/
		'3c.*6f#%#6e#%#6d#%#6f#%#75#%#73#%#65#%#64#%#6f#%#77#%#6e',/*onmousedown*/
		'3c.*6f#%#6e#%#6d#%#6f#%#75#%#73#%#65#%#6d#%#6f#%#76#%#65',/*onmousemove*/
		'3c.*6f#%#6e#%#6d#%#6f#%#75#%#73#%#65#%#6f#%#75#%#74',/*onmouseout*/
		'3c.*6f#%#6e#%#6d#%#6f#%#75#%#73#%#65#%#6f#%#76#%#65#%#72',/*onmouseover*/
		'3c.*6f#%#6e#%#6d#%#6f#%#75#%#73#%#65#%#75#%#70',/*onmouseup*/
		'3c.*6f#%#6e#%#72#%#65#%#73#%#65#%#74',/*onreset*/
		'3c.*6f#%#6e#%#72#%#65#%#73#%#69#%#7a#%#65',/*onresize*/
		'3c.*6f#%#6e#%#73#%#65#%#6c#%#65#%#63#%#74',/*onselect*/
		'3c.*6f#%#6e#%#73#%#75#%#62#%#6d#%#69#%#74',/*onsubmit*/
		'3c.*6f#%#6e#%#75#%#6e#%#6c#%#6f#%#61#%#64',/*onunload*/
		'2e#%#63#%#6f#%#6f#%#6b#%#69#%#65.*3d',/*cookie...=*/
		'73#%#65#%#6c#%#65#%#63#%#74.*66#%#72#%#6f#%#6d',/*select...from*/
		'75#%#70#%#64#%#61#%#74#%#65.*73#%#65#%#74',/*update...set*/
		'69#%#6e#%#73#%#65#%#72#%#74.*69#%#6e#%#74#%#6f',/*insert...into*/
		'64#%#65#%#6c#%#65#%#74#%#65.*66#%#72#%#6f#%#6d',/*delete...from*/
		'64#%#72#%#6f#%#70.*74#%#61#%#62#%#6c#%#65',/*drop...table*/
		'72#%#65#%#70#%#6c#%#61#%#63#%#65.*69#%#6e#%#74#%#6f',/*replace...into*/
		'74#%#72#%#75#%#6e#%#63#%#61#%#74#%#65.*74#%#61#%#62#%#6c#%#65',/*truncate...table*/
		'73#%#65#%#6c#%#65#%#63#%#74.{0,100}69#%#6e#%#74#%#6f.*6f#%#75#%#74#%#66#%#69#%#6c#%#65',/*select...into...outfile*/
		'6c#%#6f#%#61#%#64.{0,20}64#%#61#%#74#%#61.{0,20}69#%#6e#%#66#%#69#%#6c#%#65',/*load...data...infile*/
		'61#%#6c#%#74#%#65#%#72.*74#%#61#%#62#%#6c#%#65',/*alter...table*/
		'75#%#6e#%#69#%#6f#%#6e.*73#%#65#%#6c#%#65#%#63#%#74',/*union select*/
		'75#%#73#%#65#%#72#%#28.{0,40}29',/*user()*/
		'64#%#61#%#74#%#61#%#62#%#61#%#73#%#65#%#28.{0,40}29',/*database()*/
		'76#%#65#%#72#%#73#%#69#%#6f#%#6e#%#28.{0,40}29',/*user()*/
		'(40)+#%#76#%#65#%#72#%#73#%#69#%#6f#%#6e#%#5f#%#63#%#6f#%#6d#%#70#%#69#%#6c#%#65#%#5f#%#6f#%#73',/*@@version_compile_os*/
		'67#%#72#%#6f#%#75#%#70#%#5f#%#63#%#6f#%#6e#%#63#%#61#%#74.*28',/*group_concat(*/
		'63#%#6f#%#6e#%#63#%#61#%#74#%#5f#%#77#%#73.*28',/*concat_ws(*/
		'73#%#63#%#68#%#65#%#6d#%#61#%#5f#%#6e#%#61#%#6d#%#65',/*schema_name*/
		'74#%#61#%#62#%#6c#%#65#%#5f#%#6e#%#61#%#6d#%#65#%#73',/*table_names*/
		'63#%#6f#%#6c#%#75#%#6d#%#6e#%#5f#%#6e#%#61#%#6d#%#65',/*column_name*/
		'20.{0,4}6c#%#69#%#6b#%#65.{0,4}20.*(22|27)',/* like ("|')*/
		'20.{0,4}69#%#6e.{0,4}20#%#28',/* in (*/
		'(22|27|29|3d|3e|3c|3e3d|3c3d|213d).{0,20}20#%#6f.{0,4}72.{0,4}20',/*('|"|)|=|>|<|>=|<=|!=) or ...*/
		'(22|27|29|3d|3e|3c|3e3d|3c3d|213d).{0,20}20#%#61.{0,6}6e.{0,6}64.{0,4}20',/*('|"|)|=|>|<|>=|<=|!=) and ...*/
		'20.{0,40}62.{0,8}65.{0,8}74.{0,8}77.{0,8}65.{0,8}65.{0,8}6e.{6,80}20#%#61.{0,8}6e.{0,8}64.{0,40}20',/* between..and... */
		'(3e|3c|3e3d|3c3d|3d|213d|29).{0,40}23',/*(>|<|>=|<=|=|!=|))...#*/
		'78#%#70#%#5f#%#63#%#6d#%#64#%#73#%#68#%#65#%#6c#%#6c',/*xp_cmdshell*/
		'62#%#69#%#6e#%#64.{0,20}(22|27|28)',/*bind*/
		'73#%#65#%#61#%#72#%#63#%#68.{0,20}(22|27|28)',/*search*/
		'61#%#64#%#64.{0,20}(22|27|28)',/*add*/
		'64#%#65#%#6c#%#65#%#74#%#65.{0,20}(22|27|28)',/*delete*/
		'6d#%#6f#%#64#%#69#%#66#%#79.{0,20}(22|27|28)',/*modify*/
		'2a.{0,10}\).{0,10}\(&\)',/**)(&) LDAP注入模式*/
		'2a.{0,10}\).{0,10}\(|\).{0,10}\(uid\=\*\)\)',/**)(|(uid=*)) LDAP注入模式*/
		'3c#%#21#%#45#%#4e#%#54#%#49#%#54#%#59',/*<!ENTITY*/
		'53#%#59#%#53#%#54#%#45#%#4d.{0,10}(22|27)',/*SYSTEM "'*/
		'3c#%#21#%#44#%#4f#%#43#%#54#%#59#%#50#%#45',/*<!DOCTYPE*/
		'66#%#69#%#6c#%#65#%#3a#%#2f#%#2f',/*file://*/
		'66#%#69#%#6c#%#65#%#5f#%#67#%#65#%#74#%#5f#%#63#%#6f#%#6e#%#74#%#65#%#6e#%#74#%#73.{0,20}(22|27|28)',/*file_get_contents*/
		'63#%#75#%#72#%#6c#%#5f#%#69#%#6e#%#69#%#74.{0,20}(22|27|28)',/*curl_init*/
		'66#%#6f#%#70#%#65#%#6e.{0,20}(22|27|28)',/*fopen*/
		'68#%#74#%#74#%#70#%#3a#%#2f#%#2f',/*http://*/
		'68#%#74#%#74#%#70#%#73#%#3a#%#2f#%#2f',/*https://*/
		'66#%#74#%#70#%#3a#%#2f#%#2f',/*ftp://*/
		'31#%#32#%#37#%#2e#%#30#%#2e#%#30#%#2e#%#31',/*127.0.0.1*/
		'6c#%#6f#%#63#%#61#%#6c#%#68#%#6f#%#73#%#74',/*localhost*/
		'30#%#2e#%#30#%#2e#%#30#%#2e#%#30',/*0.0.0.0*/
	];

	/**
	 * Set Injection Rank
	 * @param mixed $rank
	 */
	protected static function setInjectionRank(string $rank): void
	{
		self::$injectionRank = $rank;
	}

	/**
	 *  decode For Inspection
	 * @param mixed $str
	 * @return string
	 */
	protected static function _decodeForInspection(string $str): string
	{
		$str = urldecode(urldecode(stripslashes(htmlspecialchars_decode(htmlspecialchars_decode($str)))));
		return trim(trim($str, '"'), "'");
	}

	/**
	 *  hex Encode For Inspection
	 * @param mixed $str
	 * @return string
	 */
	protected static function _hexEncodeForInspection(string $str): string
	{
		$str = str_ireplace(self::$injectionPreProcess, '', $str);
		$str = strtolower($str);
		$revStr = '';
		for ($i = 0, $len = strlen($str); $i < $len; $i++) {
			$revStr .= 'x' . bin2hex($str[$i]);
		}
		return $revStr;
	}

	/**
	 *  recursive
	 * @param mixed $varPara
	 * @return mixed
	 */
	private static function _recursive(mixed $varPara): mixed
	{
		if (empty($varPara)) {
			return null;
		}
		if (is_array($varPara)) {
			return array_walk($varPara, 'Candy\Core\Security::_recursive');
		}
		if (is_string($varPara) && self::_matchesInjectionPattern($varPara)) {
			showExceptionMsg('firewall', "违规字符串:{$varPara},修改后刷新即可...");
		}
		return $varPara;
	}

	/**
	 *  matches Injection Pattern
	 * @param mixed $str
	 * @return bool
	 */
	private static function _matchesInjectionPattern(string $str): bool
	{
		$codeHtml = self::_decodeForInspection($str);
		$codeHtml = self::_hexEncodeForInspection($codeHtml);
		return self::_pregInjectionCode($codeHtml);
	}

	/**
	 *  preg Injection Code
	 * @param mixed $hexStr
	 * @return bool
	 */
	private static function _pregInjectionCode(string $hexStr): bool
	{
		foreach (self::$injectionCodePreg as $value) {
			$value = str_replace('#%#', self::$injectionRank, $value);
			$preg = "/(.*){$value}.*/is";
			$revStr = [];
			if (preg_match($preg, $hexStr, $revStr)) {
				if (self::_injectionPrefixIsEvenHex($revStr)) {
					continue;
				}
				return true;
			}
		}
		return false;
	}

	/**
	 *  injection Prefix Is Even Hex
	 * @param mixed $pregRevArr
	 * @return bool
	 */
	private static function _injectionPrefixIsEvenHex(array $pregRevArr): bool
	{
		return !isset($pregRevArr[1]);
	}
}
