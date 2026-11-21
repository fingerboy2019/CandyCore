<?php
/***
 * HMAC-SHA256 引擎
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
 * HMAC 签名
 */
final class HmacEngine implements SignEngineInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function sign(string $canonical, Credential $credential): string
	{
		if ($credential->secret === '') {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'HMAC secret empty');
		}
		$raw = hash_hmac('sha256', $canonical, $credential->secret, true);
		return SignatureCodec::encode($raw);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify(string $canonical, string $signature, Credential $credential): bool
	{
		if ($credential->secret === '') {
			return false;
		}
		$expected = hash_hmac('sha256', $canonical, $credential->secret, true);
		$given = SignatureCodec::decode($signature);
		if ($given === false) {
			return false;
		}
		return hash_equals($expected, $given);
	}
}
