<?php
/***
 * 凭证存储接口
 *
 * 业务说明：本版 ConfigStore；后期 DatabaseStore + ChainStore
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
 * 凭证仓库
 */
interface CredentialStoreInterface
{
	/**
	 * 按 AppId 取凭证；null 表示取 default
	 *
	 * @param string|null $appId 应用 ID
	 * @return Credential|null
	 */
	public function get(?string $appId): ?Credential;
}
