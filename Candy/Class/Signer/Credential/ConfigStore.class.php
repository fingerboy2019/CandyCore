<?php
/***
 * 配置凭证仓库
 *
 * 业务说明：读取 Signer.inc.php 的 default / apps（本版唯一实现）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Credential
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Credential;

use Candy\Extend\Signer\Bootstrap;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 从 Inc 配置解析凭证
 */
final class ConfigStore implements CredentialStoreInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function get(?string $appId): ?Credential
	{
		$config = Bootstrap::config();
		$appId = $appId !== null ? trim($appId) : null;

		if ($appId !== null && $appId !== '' && $appId !== 'default') {
			$apps = $config['apps'] ?? [];
			if (is_array($apps) && isset($apps[$appId]) && is_array($apps[$appId])) {
				$cred = Credential::fromArray($appId, $apps[$appId]);
				return $cred->enable ? $cred : null;
			}
			// 允许回退 default
			if (!empty($config['allow_default_fallback']) || Bootstrap::mode() === 'single') {
				return $this->defaultCredential();
			}
			return null;
		}

		return $this->defaultCredential();
	}

	/**
	 * 默认凭证
	 *
	 * @return Credential|null
	 */
	private function defaultCredential(): ?Credential
	{
		$config = Bootstrap::config();
		$row = $config['default'] ?? [];
		if (!is_array($row)) {
			return null;
		}
		$cred = Credential::fromArray('default', $row);
		return $cred->enable ? $cred : null;
	}
}
