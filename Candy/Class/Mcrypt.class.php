<?php
/***
 * 字符串加解密
 *
 * 业务说明：Discuz 风格 code()、轻量 symmetry()、AES-256-CBC des()；算法与历史密文兼容
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-17 21:40:00 $
 *
 * @package Candy\Extend
 */

declare(strict_types=1);
namespace Candy\Extend;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Mcrypt {

	public static string $defaultKey = 'c,n=X!OjvpiBbw(QC0ma';

	private const CIPHER = 'AES-256-CBC';

	/**
	 * 高性能字符加密解密（RC4 变体 + 完整性校验 + 可选过期）
	 *
	 * 密文为 URL 安全 Base64；解密失败或过期返回空字符串
	 * $allowExpired=true 时仍校验完整性，但忽略过期（仅供 MCP grant 续签 peek）
	 *
	 * @param string $string 原文或密文
	 * @param string $key 密钥，空则用默认密钥
	 * @param string $type en|de
	 * @param int $expiry 加密时有效秒数，0 为永久
	 * @param bool $allowExpired 解密时是否允许已过期密文
	 * @return string
	 */
	public static function code(string $string, string $key = '', string $type = 'en', int $expiry = 0, bool $allowExpired = false): string
	{
		$type = strtolower($type);
		if ($type !== 'en' && $type !== 'de') {
			return '';
		}

		$ckeyLength = 4;
		$digestKey = md5($key !== '' ? $key : self::$defaultKey);
		$keya = md5(substr($digestKey, 0, 16));
		$keyb = md5(substr($digestKey, 16, 16));
		$keyc = $type === 'en'
			? substr(md5((string) microtime(true)), -$ckeyLength)
			: substr($string, 0, $ckeyLength);

		$cryptkey = $keya . md5($keya . $keyc);
		$keyLength = strlen($cryptkey);

		if ($type === 'en') {
			$payload = sprintf('%010d', $expiry !== 0 ? $expiry + time() : 0)
				. substr(md5($string . $keyb), 0, 16)
				. $string;
		} else {
			$decoded = self::urlSafeB64Decode(substr($string, $ckeyLength));
			if ($decoded === false) {
				return '';
			}
			$payload = $decoded;
		}

		$payloadLength = strlen($payload);
		$rndkey = [];
		for ($i = 0; $i < 256; $i++) {
			$rndkey[$i] = ord($cryptkey[$i % $keyLength]);
		}

		$box = range(0, 255);
		for ($j = $i = 0; $i < 256; $i++) {
			$j = ($j + $box[$i] + $rndkey[$i]) % 256;
			[$box[$i], $box[$j]] = [$box[$j], $box[$i]];
		}

		$result = '';
		for ($a = $j = $i = 0; $i < $payloadLength; $i++) {
			$a = ($a + 1) % 256;
			$j = ($j + $box[$a]) % 256;
			[$box[$a], $box[$j]] = [$box[$j], $box[$a]];
			$result .= chr(ord($payload[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
		}

		if ($type === 'en') {
			return self::urlSafeB64Encode($keyc . str_replace('=', '', base64_encode($result)));
		}

		$expireAt = (int) substr($result, 0, 10);
		$hash = substr($result, 10, 16);
		$plain = substr($result, 26);

		if (!$allowExpired && $expireAt !== 0 && $expireAt < time()) {
			return '';
		}
		if ($hash !== substr(md5($plain . $keyb), 0, 16)) {
			return '';
		}

		return $plain;
	}

	/**
	 * 简单对称混淆（非敏感数据，安全性较低）
	 *
	 * 对原文按密钥循环 XOR 后做 URL 安全 Base64，与旧版插字符算法不兼容
	 *
	 * @param string $string 原文或密文
	 * @param string $key 密钥
	 * @param string $type en|de
	 * @return string
	 */
	public static function symmetry(string $string = '', string $key = '', string $type = 'en'): string
	{
		$type = strtolower($type);
		$skey = $key !== '' ? $key : self::$defaultKey;
		$keyLen = strlen($skey);
		if ($keyLen === 0) {
			return '';
		}

		if ($type === 'en') {
			$bin = '';
			$len = strlen($string);
			for ($i = 0; $i < $len; $i++) {
				$bin .= chr(ord($string[$i]) ^ ord($skey[$i % $keyLen]));
			}
			return self::urlSafeB64Encode(base64_encode($bin));
		}

		if ($type !== 'de') {
			return '';
		}

		$bin = self::urlSafeB64Decode($string);
		if ($bin === false) {
			return '';
		}

		$out = '';
		$len = strlen($bin);
		for ($i = 0; $i < $len; $i++) {
			$out .= chr(ord($bin[$i]) ^ ord($skey[$i % $keyLen]));
		}
		return $out;
	}

	/**
	 * AES-256-CBC 加解密（历史方法名 des，算法为 AES）
	 *
	 * 使用全零 IV 保持与旧版 openssl 空 IV 行为一致
	 *
	 * @param string $string 原文或密文
	 * @param string $key 密钥
	 * @param string $type en|de
	 * @return string
	 */
	public static function des(string $string = '', string $key = '', string $type = 'en'): string
	{
		return self::aes($string, $key, $type);
	}

	/**
	 * AES-256-CBC 加解密
	 *
	 * @param string $string 原文或密文
	 * @param string $key 密钥
	 * @param string $type en|de
	 * @return string
	 */
	public static function aes(string $string = '', string $key = '', string $type = 'en'): string
	{
		if (!extension_loaded('openssl')) {
			return '';
		}

		$type = strtolower($type);
		$secret = $key !== '' ? $key : self::$defaultKey;
		$iv = self::aesIv();

		if ($type === 'en') {
			$encrypted = openssl_encrypt($string, self::CIPHER, $secret, OPENSSL_RAW_DATA, $iv);
			return $encrypted === false ? '' : base64_encode($encrypted);
		}

		if ($type !== 'de' || $string === '') {
			return '';
		}

		$raw = base64_decode($string, true);
		if ($raw === false) {
			return '';
		}

		$decrypted = openssl_decrypt($raw, self::CIPHER, $secret, OPENSSL_RAW_DATA, $iv);
		return $decrypted === false ? '' : $decrypted;
	}

	/**
	 * 与历史 des() 兼容的固定 IV（全零）
	 *
	 * @return string
	 */
	private static function aesIv(): string
	{
		$len = openssl_cipher_iv_length(self::CIPHER);
		return $len > 0 ? str_repeat("\0", $len) : '';
	}

	/**
	 * URL 安全 Base64 编码
	 *
	 * @param string $data 二进制或文本
	 * @return string
	 */
	private static function urlSafeB64Encode(string $data): string
	{
		return str_replace(['+', '/', '='], ['-', '_', '.'], $data);
	}

	/**
	 * URL 安全 Base64 解码为字符串
	 *
	 * @param string $data 密文
	 * @return string|false
	 */
	private static function urlSafeB64Decode(string $data): string|false
	{
		$normalized = str_replace(['-', '_', '.'], ['+', '/', '='], $data);
		return base64_decode($normalized, true);
	}
}

