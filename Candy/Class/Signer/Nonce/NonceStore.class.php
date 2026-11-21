<?php
/***
 * Nonce 一次性存储
 *
 * 业务说明：Cache 防重放；可配置关闭
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer\Nonce
 */

declare(strict_types=1);

namespace Candy\Extend\Signer\Nonce;

use Candy\Extend\Signer\Bootstrap;
use Candy\Extend\Signer\SignerException;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Nonce store
 */
final class NonceStore
{
	/**
	 * 是否启用
	 *
	 * @return bool
	 */
	public static function enabled(): bool
	{
		$cfg = Bootstrap::config()['nonce'] ?? [];
		if (!is_array($cfg)) {
			return true;
		}
		return !isset($cfg['enabled']) || (bool)$cfg['enabled'];
	}

	/**
	 * 占用 nonce；已存在则抛重放
	 *
	 * @param string $nonce 一次性串
	 * @param string|null $appId 应用维度
	 * @return void
	 */
	public static function consume(string $nonce, ?string $appId = null): void
	{
		if (!self::enabled()) {
			return;
		}
		$nonce = trim($nonce);
		if ($nonce === '') {
			throw new SignerException(SignerException::ERR_PARAM, 'Nonce required');
		}
		$prefix = (string)((Bootstrap::config()['nonce']['prefix'] ?? null) ?: 'signer:nonce:');
		$key = $prefix . ($appId ?: 'default') . ':' . hash('sha256', $nonce);
		$cache = \Candy\Core\Cache::store();
		if ($cache->has($key)) {
			throw new SignerException(SignerException::ERR_NONCE, 'Nonce already used');
		}
		$ttl = Bootstrap::skew() * 2;
		$cache->set($key, 1, $ttl);
	}
}
