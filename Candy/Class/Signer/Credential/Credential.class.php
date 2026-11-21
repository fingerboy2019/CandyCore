<?php
/***
 * 凭证 DTO
 *
 * 业务说明：hmac / rsa / ecdsa 统一载体
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Credential
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Credential;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 应用凭证
 */
final class Credential
{
	/**
	 * @param string $appId 应用 ID（single 下可为 default）
	 * @param string $signType hmac|rsa|ecdsa
	 * @param string $secret HMAC 密钥
	 * @param string $publicKey PEM 公钥
	 * @param string $privateKey PEM 私钥
	 * @param bool $enable 是否启用
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $signType,
		public readonly string $secret = '',
		public readonly string $publicKey = '',
		public readonly string $privateKey = '',
		public readonly bool $enable = true,
	) {
	}

	/**
	 * 从配置数组构建
	 *
	 * @param string $appId 应用 ID
	 * @param array<string,mixed> $row 配置行
	 * @return self
	 */
	public static function fromArray(string $appId, array $row): self
	{
		$type = strtolower((string)($row['sign_type'] ?? 'hmac'));
		if (!in_array($type, ['hmac', 'rsa', 'ecdsa'], true)) {
			$type = 'hmac';
		}
		return new self(
			$appId,
			$type,
			(string)($row['secret'] ?? ''),
			(string)($row['public_key'] ?? ''),
			(string)($row['private_key'] ?? ''),
			!isset($row['enable']) || (bool)$row['enable'],
		);
	}

	/**
	 * 是否可用于验签
	 *
	 * @return bool
	 */
	public function canVerify(): bool
	{
		if (!$this->enable) {
			return false;
		}
		return match ($this->signType) {
			'hmac' => $this->secret !== '',
			'rsa', 'ecdsa' => $this->publicKey !== '',
			default => false,
		};
	}

	/**
	 * 是否可用于签发
	 *
	 * @return bool
	 */
	public function canSign(): bool
	{
		if (!$this->enable) {
			return false;
		}
		return match ($this->signType) {
			'hmac' => $this->secret !== '',
			'rsa', 'ecdsa' => $this->privateKey !== '',
			default => false,
		};
	}
}
