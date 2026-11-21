<?php
/***
 * 密文信封编解码
 *
 * 业务说明：version(1)+nonce(12)+ciphertext+tag(16)；支持 base64 / raw
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 17:48:19 $
 *
 * @package Candy\Extend\PayloadCrypto\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\PayloadCrypto\Kernel;

use Candy\Extend\PayloadCrypto\Bootstrap;
use Candy\Extend\PayloadCrypto\PayloadCryptoException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 载荷信封
 */
final class Envelope
{
	public const VERSION = "\x01";

	/**
	 * 密封为传输形态（base64 或 raw）
	 *
	 * @param string $plain 明文
	 * @param string $key 32 字节密钥
	 * @param string $aad AAD
	 * @param string|null $encoding 覆盖配置；null 跟配置
	 * @return string
	 */
	public static function pack(string $plain, string $key, string $aad = '', ?string $encoding = null): string
	{
		$nonce = random_bytes(AesGcmEngine::NONCE_LEN);
		$sealed = AesGcmEngine::seal($plain, $key, $nonce, $aad);
		$binary = self::VERSION . $nonce . $sealed['ciphertext'] . $sealed['tag'];
		return self::encode($binary, $encoding);
	}

	/**
	 * 打开传输形态
	 *
	 * @param string $wire 线上数据
	 * @param string $key 32 字节密钥
	 * @param string $aad AAD
	 * @param string|null $encoding 覆盖配置
	 * @return string 明文
	 */
	public static function unpack(string $wire, string $key, string $aad = '', ?string $encoding = null): string
	{
		$binary = self::decode($wire, $encoding);
		$min = 1 + AesGcmEngine::NONCE_LEN + AesGcmEngine::TAG_LEN;
		if (strlen($binary) < $min) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'envelope too short');
		}
		if ($binary[0] !== self::VERSION[0]) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'unsupported envelope version');
		}

		$nonce = substr($binary, 1, AesGcmEngine::NONCE_LEN);
		$tag = substr($binary, -AesGcmEngine::TAG_LEN);
		$ciphertext = substr($binary, 1 + AesGcmEngine::NONCE_LEN, -AesGcmEngine::TAG_LEN);

		return AesGcmEngine::open($ciphertext, $key, $nonce, $tag, $aad);
	}

	/**
	 * @param string $binary 原始信封
	 * @param string|null $encoding 编码
	 * @return string
	 */
	public static function encode(string $binary, ?string $encoding = null): string
	{
		$enc = $encoding ?? Bootstrap::encoding();
		if ($enc === 'raw') {
			return $binary;
		}
		return base64_encode($binary);
	}

	/**
	 * @param string $wire 线上
	 * @param string|null $encoding 编码
	 * @return string
	 */
	public static function decode(string $wire, ?string $encoding = null): string
	{
		$enc = $encoding ?? Bootstrap::encoding();
		if ($enc === 'raw') {
			return $wire;
		}
		$binary = base64_decode($wire, true);
		if ($binary === false) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'envelope base64 invalid');
		}
		return $binary;
	}
}
