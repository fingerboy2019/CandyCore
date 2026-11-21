<?php
/***
 * 签名引擎工厂
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Kernel
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Kernel;

use Candy\Extend\Signer\SignerException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 按 sign_type 取引擎
 */
final class EngineFactory
{
	/**
	 * @param string $signType hmac|rsa|ecdsa
	 * @return SignEngineInterface
	 */
	public static function make(string $signType): SignEngineInterface
	{
		return match (strtolower($signType)) {
			'rsa' => new RsaEngine(),
			'ecdsa' => new EcdsaEngine(),
			'hmac' => new HmacEngine(),
			default => throw new SignerException(
				SignerException::ERR_CREDENTIAL,
				'Unsupported sign_type: ' . $signType
			),
		};
	}
}
