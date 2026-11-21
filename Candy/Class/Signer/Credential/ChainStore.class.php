<?php
/***
 * 链式凭证仓库
 *
 * 业务说明：按顺序查询；后期「库优先、配置回退」用此组合
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
 * 多 Store 串联
 */
final class ChainStore implements CredentialStoreInterface
{
	/** @var list<CredentialStoreInterface> */
	private array $stores;

	/**
	 * @param CredentialStoreInterface ...$stores 优先级从高到低
	 */
	public function __construct(CredentialStoreInterface ...$stores)
	{
		$this->stores = array_values($stores);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get(?string $appId): ?Credential
	{
		foreach ($this->stores as $store) {
			$cred = $store->get($appId);
			if ($cred !== null && $cred->canVerify()) {
				return $cred;
			}
		}
		return null;
	}
}
