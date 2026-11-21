<?php
/***
 * RSA-SHA256 引擎
 *
 * 业务说明：私钥签发、公钥验签
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Kernel;

use Candy\Extend\Signer\Credential\Credential;
use Candy\Extend\Signer\SignerException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * RSA 非对称签名
 */
final class RsaEngine implements SignEngineInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function sign(string $canonical, Credential $credential): string
	{
		if ($credential->privateKey === '') {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'RSA private key empty');
		}
		$key = openssl_pkey_get_private($credential->privateKey);
		if ($key === false) {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'RSA private key invalid');
		}
		$ok = openssl_sign($canonical, $raw, $key, OPENSSL_ALGO_SHA256);
		if (!$ok) {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'RSA sign failed');
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
		$raw = SignatureCodec::decode($signature);
		if ($raw === false) {
			return false;
		}
		return openssl_verify($canonical, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
	}
}
