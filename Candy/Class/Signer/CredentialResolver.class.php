<?php
/***
 * 凭证解析器
 *
 * 业务说明：single/multi + ConfigStore（本版）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer
 */

declare(strict_types=1);

namespace Candy\Extend\Signer;

use Candy\Extend\Signer\Credential\ConfigStore;
use Candy\Extend\Signer\Credential\Credential;
use Candy\Extend\Signer\Credential\CredentialStoreInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 解析当前请求应用凭证
 */
final class CredentialResolver
{
	private static ?CredentialStoreInterface $store = null;

	/**
	 * 注入 Store（测试 / 后期 ChainStore）
	 *
	 * @param CredentialStoreInterface|null $store 仓库
	 * @return void
	 */
	public static function setStore(?CredentialStoreInterface $store): void
	{
		self::$store = $store;
	}

	/**
	 * 当前 Store
	 *
	 * @return CredentialStoreInterface
	 */
	public static function store(): CredentialStoreInterface
	{
		return self::$store ??= new ConfigStore();
	}

	/**
	 * 解析凭证
	 *
	 * @param string|null $appId 应用 ID
	 * @return Credential
	 */
	public static function resolve(?string $appId): Credential
	{
		Bootstrap::assertEnabled();
		$appId = $appId !== null ? trim($appId) : null;
		if ($appId === '') {
			$appId = null;
		}

		if (Bootstrap::mode() === 'multi' && ($appId === null || $appId === 'default')) {
			throw new SignerException(SignerException::ERR_MISSING_APP, 'AppId required in multi mode', 401);
		}

		$cred = self::store()->get($appId);
		if ($cred === null || !$cred->canVerify()) {
			throw new SignerException(
				$appId ? SignerException::ERR_UNKNOWN_APP : SignerException::ERR_CREDENTIAL,
				'Credential not found or incomplete',
				401
			);
		}
		return $cred;
	}

	/**
	 * 解析可签发凭证
	 *
	 * @param string|null $appId 应用 ID
	 * @return Credential
	 */
	public static function resolveForSign(?string $appId): Credential
	{
		$cred = self::resolve($appId);
		if (!$cred->canSign()) {
			throw new SignerException(SignerException::ERR_CREDENTIAL, 'Credential cannot sign (missing private key/secret)');
		}
		return $cred;
	}
}

