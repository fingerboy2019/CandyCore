<?php
/***
 * Candy框架 mbstring 函数兼容库
 * 为没有安装 mbstring 扩展的环境提供 mbstring 函数的模拟实现
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2023-6-28 10:14:25 $   
 */

/**
 * 设置/获取内部字符编码
 *
 * @param string $enc 字符编码
 * @return bool
 */
if (!function_exists('mb_internal_encoding'))
{
	function mb_internal_encoding($enc) {return true; }
}

/**
 * 设置/获取正则表达式的字符编码
 *
 * @param string $enc 字符编码
 * @return bool
 */
if (!function_exists('mb_regex_encoding'))
{
	function mb_regex_encoding($enc) {return true; }
}

/**
 * 获取字符串长度
 *
 * @param string $str 要检查长度的字符串
 * @return int 字符串长度
 */
if (!function_exists('mb_strlen'))
{
	function mb_strlen($str)
	{
		return strlen($str);
	}
}

/**
 * 查找字符串在另一个字符串中首次出现的位置
 *
 * @param string $haystack 被搜索的字符串
 * @param string $needle 要搜索的字符串
 * @param int $offset 搜索的起始位置
 * @return int|false 找到返回位置，否则返回false
 */
if (!function_exists('mb_strpos'))
{
	function mb_strpos($haystack, $needle, $offset=0)
	{
		return strpos($haystack, $needle, $offset);
	}
}

/**
 * 不区分大小写地查找字符串在另一个字符串中首次出现的位置
 *
 * @param string $haystack 被搜索的字符串
 * @param string $needle 要搜索的字符串
 * @param int $offset 搜索的起始位置
 * @return int|false 找到返回位置，否则返回false
 */
if (!function_exists('mb_stripos'))
{
	function mb_stripos($haystack, $needle, $offset=0)
	{
		return stripos($haystack, $needle, $offset);
	}
}

/**
 * 获取字符串的子串
 *
 * @param string $str 原始字符串
 * @param int $start 起始位置
 * @param int $length 子串长度，0表示到字符串末尾
 * @return string 子串
 */
if (!function_exists('mb_substr'))
{
	function mb_substr($str, $start, $length=0)
	{
		return substr($str, $start, $length);
	}
}

/**
 * 统计字符串出现的次数
 *
 * @param string $haystack 被搜索的字符串
 * @param string $needle 要搜索的字符串
 * @return int 出现次数
 */
if (!function_exists('mb_substr_count'))
{
	function mb_substr_count($haystack, $needle)
	{
		return substr_count($haystack, $needle);
	}
}

