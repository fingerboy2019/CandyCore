<?php
/***
 * 数据库凭证仓库（占位）
 *
 * 业务说明：后期接 sa_signer_app；本版未实现
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
 * 库表凭证（待实现）
 */
final class DatabaseStore implements CredentialStoreInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function get(?string $appId): ?Credential
	{
		return null;
	}
}
