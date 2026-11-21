<?php
/***
 * 验签异常
 *
 * 业务说明：统一错误码，供 API / 中间件返回
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer
 */

declare(strict_types=1);

namespace Candy\Extend\Signer;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Signer 业务异常
 */
class SignerException extends \RuntimeException
{
	public const ERR_DISABLED = 'signer_disabled';
	public const ERR_MISSING_APP = 'missing_app_id';
	public const ERR_UNKNOWN_APP = 'unknown_app_id';
	public const ERR_EXPIRED = 'timestamp_expired';
	public const ERR_SIGNATURE = 'invalid_signature';
	public const ERR_NONCE = 'nonce_replay';
	public const ERR_CREDENTIAL = 'invalid_credential';
	public const ERR_PARAM = 'invalid_param';

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
		protected int $httpStatus = 403
	) {
		$this->errorCode = $errorCode;
		parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
	}

	/**
	 * 错误码
	 *
	 * @return string
	 */
	public function errorCode(): string
	{
		return $this->errorCode;
	}

	/**
	 * HTTP 状态
	 *
	 * @return int
	 */
	public function httpStatus(): int
	{
		return $this->httpStatus;
	}
}

