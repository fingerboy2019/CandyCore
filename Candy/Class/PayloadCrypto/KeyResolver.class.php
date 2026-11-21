<?php
/***
 * 载荷密钥解析
 *
 * 业务说明：single/multi；配置 default.key / apps.*.key；支持 hex/base64/裸 32 字节
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 17:48:19 $
 *
 * @package Candy\Extend\PayloadCrypto
 */

declare(strict_types=1);

namespace Candy\Extend\PayloadCrypto;

use Candy\Extend\PayloadCrypto\Kernel\AesGcmEngine;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 解析 AES 密钥
 */
final class KeyResolver
{
	/**
	 * 解析密钥原始字节
	 *
	 * @param string|null $appId 应用 ID（single 可空）
	 * @return string 32 字节
	 */
	public static function resolve(?string $appId = null): string
	{
		Bootstrap::assertEnabled();
		$appId = $appId !== null ? trim($appId) : null;
		if ($appId === '') {
			$appId = null;
		}

		$cfg = Bootstrap::config();
		$mode = Bootstrap::mode();

		if ($mode === 'multi' && ($appId === null || $appId === 'default')) {
			throw new PayloadCryptoException(
				PayloadCryptoException::ERR_REQUIRED,
				'AppId required in PayloadCrypto multi mode',
				401
			);
		}

		$entry = null;
		if ($appId !== null && $appId !== 'default') {
			$apps = $cfg['apps'] ?? [];
			if (is_array($apps) && isset($apps[$appId]) && is_array($apps[$appId])) {
				$entry = $apps[$appId];
			} elseif (empty($cfg['allow_default_fallback'])) {
				throw new PayloadCryptoException(
					PayloadCryptoException::ERR_MISCONFIGURED,
					'PayloadCrypto app key not found: ' . $appId,
					500
				);
			}
		}

		if ($entry === null) {
			$entry = is_array($cfg['default'] ?? null) ? $cfg['default'] : [];
		}

		if (isset($entry['enable']) && $entry['enable'] === false) {
			throw new PayloadCryptoException(
				PayloadCryptoException::ERR_MISCONFIGURED,
				'PayloadCrypto key disabled for app',
				500
			);
		}

		$raw = (string)($entry['key'] ?? '');
		$key = self::normalizeKey($raw);
		if ($key === null) {
			throw new PayloadCryptoException(
				PayloadCryptoException::ERR_MISCONFIGURED,
				'PayloadCrypto key empty or invalid (need 32 bytes hex/base64)',
				500
			);
		}

		return $key;
	}

	/**
	 * 将配置中的 key 规范为 32 字节
	 *
	 * @param string $raw 配置值
	 * @return string|null
	 */
	public static function normalizeKey(string $raw): ?string
	{
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		if (strlen($raw) === AesGcmEngine::KEY_LEN) {
			return $raw;
		}
		if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
			$bin = hex2bin($raw);
			return $bin !== false && strlen($bin) === AesGcmEngine::KEY_LEN ? $bin : null;
		}
		$bin = base64_decode($raw, true);
		if ($bin !== false && strlen($bin) === AesGcmEngine::KEY_LEN) {
			return $bin;
		}
		return null;
	}
}

