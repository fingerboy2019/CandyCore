<?php
/**
 * Security — XSS HTML 清理
 *
 * 移除危险标签、事件处理器与 javascript: 协议。
 * 对外通过 Security::removeXss() / Security::_removeXss() 调用。
 *
 * @package Candy\Core\Trait\Security
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Security;

trait XssSanitizer
{
	/**
	 * Remove Xss
	 * @param mixed $string
	 * @return string
	 */
	public static function removeXss($string): string
	{
		return self::_removeXss($string);
	}

	/** @deprecated 请使用 removeXss() */
	public static function _removeXss($string): string
	{
		if (empty($string)) {
			return '';
		}
		// 非标量（数组/对象）与数字：数字直接转字符串，数组交给调用方递归处理。
		// PHP 8 的 preg_replace() 只接受 array|string，int 会抛 TypeError（strict_types 下更严格）。
		if (is_array($string) || is_object($string)) {
			return '';
		}
		$string = (string) $string;

		$string = preg_replace('/%0[0-8bcef]/', '', $string);
		$string = preg_replace('/%1[0-9a-f]/', '', $string);
		$string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S', '', $string);

		$string = preg_replace('/<meta.+?>/is', '', $string);
		$string = preg_replace('/<link.+?>/is', '', $string);
		$string = preg_replace('/<script.+?<\/script>/is', '', $string);
		$string = preg_replace('/<style.+?<\/style>/is', '', $string);
		$string = preg_replace('/<iframe.+?<\/iframe>/is', '', $string);
		$string = preg_replace('/<iframe.+?>/is', '', $string);

		$string = preg_replace_callback('/(\<\w+\s)(.+?)(?=( \/)?\>)/is', static function (array $m): string {
			$m[2] = preg_replace('/\son[a-z]+\s*\=.+?(\s\w+\s*\=|$)/is', '\1', $m[2]);
			if (strtolower($m[1]) === '<a ') {
				$m[2] = preg_replace('/href\s*=["\'\s]*javascript\s*:.+?(\s\w+\s*\=|$)/is', 'href="#"\1', $m[2]);
			}
			return $m[1] . $m[2];
		}, $string);

		$string = preg_replace('/(<\w+)\s+/is', '\1 ', $string);
		$string = preg_replace('/(<\w+.*?)\s*?( \/>|>)/is', '\1\2', $string);

		return $string;
	}
}
