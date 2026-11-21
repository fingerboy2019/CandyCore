<?php
/***
 * AES-256-GCM 引擎
 *
 * 业务说明：openssl 密封/打开；tag 长度固定 16 字节；nonce 12 字节
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 17:48:19 $
 *
 * @package Candy\Extend\PayloadCrypto\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\PayloadCrypto\Kernel;

use Candy\Extend\PayloadCrypto\PayloadCryptoException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * AES-256-GCM 加解密
 */
final class AesGcmEngine
{
	public const CIPHER = 'aes-256-gcm';
	public const NONCE_LEN = 12;
	public const TAG_LEN = 16;
	public const KEY_LEN = 32;

	/**
	 * 加密
	 *
	 * @param string $plain 明文
	 * @param string $key 32 字节密钥
	 * @param string $nonce 12 字节 nonce
	 * @param string $aad 附加认证数据
	 * @return array{ciphertext:string,tag:string}
	 */
	public static function seal(string $plain, string $key, string $nonce, string $aad = ''): array
	{
		self::assertOpenssl();
		self::assertKey($key);
		if (strlen($nonce) !== self::NONCE_LEN) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'GCM nonce must be 12 bytes');
		}

		$tag = '';
		$ciphertext = openssl_encrypt(
			$plain,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			$aad,
			self::TAG_LEN
		);
		if ($ciphertext === false || strlen($tag) !== self::TAG_LEN) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'AES-GCM encrypt failed');
		}

		return ['ciphertext' => $ciphertext, 'tag' => $tag];
	}

	/**
	 * 解密
	 *
	 * @param string $ciphertext 密文
	 * @param string $key 32 字节密钥
	 * @param string $nonce 12 字节 nonce
	 * @param string $tag 16 字节 tag
	 * @param string $aad 附加认证数据
	 * @return string 明文
	 */
	public static function open(
		string $ciphertext,
		string $key,
		string $nonce,
		string $tag,
		string $aad = ''
	): string {
		self::assertOpenssl();
		self::assertKey($key);
		if (strlen($nonce) !== self::NONCE_LEN || strlen($tag) !== self::TAG_LEN) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'GCM nonce/tag length invalid');
		}

		$plain = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			$aad
		);
		if ($plain === false) {
			throw new PayloadCryptoException(PayloadCryptoException::ERR_INVALID, 'AES-GCM decrypt failed');
		}

		return $plain;
	}

	/**
	 * @return void
	 */
	private static function assertOpenssl(): void
	{
		if (!extension_loaded('openssl')) {
			throw new PayloadCryptoException(
				PayloadCryptoException::ERR_MISCONFIGURED,
				'openssl extension required',
				500
			);
		}
	}

	/**
	 * @param string $key 密钥
	 * @return void
	 */
	private static function assertKey(string $key): void
	{
		if (strlen($key) !== self::KEY_LEN) {
			throw new PayloadCryptoException(
				PayloadCryptoException::ERR_MISCONFIGURED,
				'AES key must be 32 bytes',
				500
			);
		}
	}
}
