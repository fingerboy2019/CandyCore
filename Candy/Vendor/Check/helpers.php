<?php
/***
 * Check 敏感词辅助函数
 *
 * 业务说明：薄封装 \\Check\\Sensitive::instance()；需先 php candy check:init
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 12:31:43 $
 *
 * @package Check
 */

declare(strict_types=1);

use Check\Sensitive;

if (!function_exists('sensitive_is')) {
	/**
	 * 是否含敏感词
	 *
	 * @param string $content 文本
	 * @return bool
	 */
	function sensitive_is(string $content): bool
	{
		return Sensitive::instance()->is($content);
	}
}

if (!function_exists('sensitive_replace')) {
	/**
	 * 替换敏感词
	 *
	 * @param string $content 文本
	 * @param string $replaceChar 替换字符
	 * @param bool   $repeat 按词长重复
	 * @return string
	 */
	function sensitive_replace(string $content, string $replaceChar = '', bool $repeat = false): string
	{
		return Sensitive::instance()->replace($content, $replaceChar, $repeat);
	}
}

if (!function_exists('sensitive_mark')) {
	/**
	 * 标记敏感词
	 *
	 * @param string $content 文本
	 * @param string $tag 标签名
	 * @return string
	 */
	function sensitive_mark(string $content, string $tag = ''): string
	{
		return Sensitive::instance()->mark($content, $tag);
	}
}

if (!function_exists('sensitive_get')) {
	/**
	 * 提取敏感词
	 *
	 * @param string $content 文本
	 * @param int    $matchType 匹配类型（与 Sensitive::get 一致，默认最小匹配）
	 * @param int    $wordNum 数量限制，0=全部
	 * @return array
	 */
	function sensitive_get(string $content, $matchType = 1, $wordNum = 0): array
	{
		return Sensitive::instance()->get($content, $matchType, $wordNum);
	}
}

if (!function_exists('sensitive_custom')) {
	/**
	 * 自定义词库并持久化
	 *
	 * @param string|array $custom 文件路径或词数组
	 * @return Sensitive
	 */
	function sensitive_custom(string|array $custom): Sensitive
	{
		return Sensitive::instance()->custom($custom);
	}
}

if (!function_exists('sensitive_add')) {
	/**
	 * 添加敏感词并持久化
	 *
	 * @param string|array $words 词
	 * @return Sensitive
	 */
	function sensitive_add(string|array $words): Sensitive
	{
		return Sensitive::instance()->add($words);
	}
}

if (!function_exists('sensitive_remove')) {
	/**
	 * 删除敏感词
	 *
	 * @param string|array $words 词
	 * @param bool         $once 仅临时
	 * @return Sensitive
	 */
	function sensitive_remove(string|array $words, bool $once = false): Sensitive
	{
		return Sensitive::instance()->remove($words, $once);
	}
}

if (!function_exists('sensitive_interference_factor')) {
	/**
	 * 追加干扰因子
	 *
	 * @param array $interferenceFactors 因子
	 * @return Sensitive
	 */
	function sensitive_interference_factor(array $interferenceFactors): Sensitive
	{
		return Sensitive::instance()->interferenceFactor($interferenceFactors);
	}
}

if (!function_exists('sensitive_add_word_to_file')) {
	/**
	 * 添加敏感词并持久化（兼容旧名）
	 *
	 * @param string|array $data 词
	 * @param bool         $append 是否追加合并
	 * @return bool
	 */
	function sensitive_add_word_to_file(string|array $data, bool $append = true): bool
	{
		return Sensitive::instance()->addWordToFile($data, $append);
	}
}

if (!function_exists('sensitive_mb_strlen')) {
	/**
	 * 安全 mb_strlen
	 *
	 * @param string      $str 字符串
	 * @param string|null $encoding 编码
	 * @return int
	 */
	function sensitive_mb_strlen(string $str, string|null $encoding = null): int
	{
		$length = mb_strlen($str, $encoding);
		if ($length === false) {
			\Candy\Core\Debug::showMessage('Invalid encoding', 'Sensitive');
		}
		return $length;
	}
}
