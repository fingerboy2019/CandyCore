<?php
/***
 * 签名编解码
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Kernel;

use Candy\Extend\Signer\Bootstrap;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * hex / base64 编码
 */
final class SignatureCodec
{
	/**
	 * 原始字节 → 配置编码
	 *
	 * @param string $raw 二进制签名
	 * @return string
	 */
	public static function encode(string $raw): string
	{
		return Bootstrap::signatureEncoding() === 'base64'
			? base64_encode($raw)
			: bin2hex($raw);
	}

	/**
	 * 配置编码 → 原始字节（验签用）
	 *
	 * @param string $encoded 编码串
	 * @return string|false
	 */
	public static function decode(string $encoded): string|false
	{
		$encoded = trim($encoded);
		if ($encoded === '') {
			return false;
		}
		if (Bootstrap::signatureEncoding() === 'base64') {
			$raw = base64_decode($encoded, true);
			return $raw === false ? false : $raw;
		}
		// hex；兼容偶发传入 base64
		if (ctype_xdigit($encoded) && (strlen($encoded) % 2) === 0) {
			$raw = @hex2bin($encoded);
			return $raw === false ? false : $raw;
		}
		$raw = base64_decode($encoded, true);
		return $raw === false ? false : $raw;
	}
}
