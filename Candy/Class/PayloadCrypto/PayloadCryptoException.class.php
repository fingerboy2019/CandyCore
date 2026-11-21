<?php
/***
 * PayloadCrypto 业务异常
 *
 * 业务说明：统一错误码，供 HTTP 中间件与 Rpc 映射 -3202x
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 17:48:19 $
 *
 * @package Candy\Extend\PayloadCrypto
 */

declare(strict_types=1);

namespace Candy\Extend\PayloadCrypto;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 载荷加解密异常
 */
class PayloadCryptoException extends \RuntimeException
{
	public const ERR_MISCONFIGURED = 'payload_crypto_misconfigured';
	public const ERR_REQUIRED = 'payload_crypto_required';
	public const ERR_INVALID = 'payload_crypto_invalid';
	public const ERR_DISABLED = 'payload_crypto_disabled';

	/** RPC：要求密文却未带 */
	public const RPC_REQUIRED = -32020;

	/** RPC：解密/AAD/版本失败 */
	public const RPC_INVALID = -32021;

	/** RPC：enabled 但密钥未配置 */
	public const RPC_MISCONFIGURED = -32022;

	/** @var string */
	protected string $errorCode;

	/**
	 * @param string $errorCode 错误码
	 * @param string $message 说明
	 * @param int $httpStatus HTTP 状态
	 */
	public function __construct(
		string $errorCode,
		string $message = '',
		protected int $httpStatus = 400
	) {
		$this->errorCode = $errorCode;
		parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
	}

	/**
	 * 业务错误码
	 *
	 * @return string
	 */
	public function errorCode(): string
	{
		return $this->errorCode;
	}

	/**
	 * HTTP 状态码
	 *
	 * @return int
	 */
	public function httpStatus(): int
	{
		return $this->httpStatus;
	}

	/**
	 * 映射为 Rpc 数字错误码
	 *
	 * @return int
	 */
	public function rpcCode(): int
	{
		return match ($this->errorCode) {
			self::ERR_REQUIRED => self::RPC_REQUIRED,
			self::ERR_MISCONFIGURED => self::RPC_MISCONFIGURED,
			default => self::RPC_INVALID,
		};
	}
}

