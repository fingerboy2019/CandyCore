<?php
/***
 * ECDSA P-256 + SHA256 引擎
 *
 * 业务说明：仅接受 prime256v1（P-256）曲线；私钥签发、公钥验签
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:47:57 $
 *
 * @package Candy\Extend\Signer\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Kernel;

use Candy\Extend\Signer\Credential\Credential;
use Candy\Extend\Signer\SignerException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * ECDSA 非对称签名（P-256）
 */
final class EcdsaEngine implements SignEngineInterface
{
	/** OpenSSL 曲线名 */
	private const CURVE = 'prime256v1';

	/**
	 * {@inheritdoc}
	 */
	public function sign(string $canonical, Credential $credential): string
	{
		if ($credential->privateKey === '') {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'ECDSA private key empty');
		}
		$key = openssl_pkey_get_private($credential->privateKey);
		if ($key === false) {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'ECDSA private key invalid');
		}
		self::assertP256($key, true);
		$ok = openssl_sign($canonical, $raw, $key, OPENSSL_ALGO_SHA256);
		if (!$ok) {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'ECDSA sign failed');
		}
		return SignatureCodec::encode($raw);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify(string $canonical, string $signature, Credential $credential): bool
	{
		if ($credential->publicKey === '') {
			return false;
		}
		$key = openssl_pkey_get_public($credential->publicKey);
		if ($key === false) {
			return false;
		}
		try {
			self::assertP256($key, false);
		} catch (SignerException) {
			return false;
		}
		$raw = SignatureCodec::decode($signature);
		if ($raw === false) {
			return false;
		}
		return openssl_verify($canonical, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
	}

	/**
	 * 断言密钥为 P-256（prime256v1）
	 *
	 * @param \OpenSSLAsymmetricKey $key 密钥
	 * @param bool $throw 是否抛异常（签发时 true）
	 * @return void
	 */
	private static function assertP256(\OpenSSLAsymmetricKey $key, bool $throw): void
	{
		$details = openssl_pkey_get_details($key);
		if (!is_array($details) || (int)($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) {
			if ($throw) {
				throw new SignerException(SignerException::ERR_CREDENTIAL, 'ECDSA key must be EC type');
			}
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'not ec');
		}
		$curve = (string)($details['ec']['curve_name'] ?? '');
		if ($curve !== '' && $curve !== self::CURVE) {
			if ($throw) {
				throw new SignerException(
					SignerException::ERR_CREDENTIAL,
					'ECDSA only supports ' . self::CURVE . ' (P-256), got: ' . $curve
				);
			}
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'bad curve');
		}
		// 部分 PHP/OpenSSL 构建不回传 curve_name：用位数兜底（P-256 ≈ 256 bit）
		$bits = (int)($details['bits'] ?? 0);
		if ($curve === '' && $bits > 0 && $bits !== 256) {
			if ($throw) {
				throw new SignerException(
					SignerException::ERR_CREDENTIAL,
					'ECDSA key bits must be 256 (P-256), got: ' . $bits
				);
			}
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'bad bits');
		}
	}
}
