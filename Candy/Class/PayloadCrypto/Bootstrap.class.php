<?php
/***
 * PayloadCrypto 可选启用入口
 *
 * 业务说明：读取 Source/Inc/PayloadCrypto.inc.php；默认不启用
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
 * 强密载荷模块开关与配置
 */
final class Bootstrap
{
	/**
	 * 是否启用
	 *
	 * @return bool
	 */
	public static function enabled(): bool
	{
		$config = self::config();
		return $config !== [] && !empty($config['enabled']);
	}

	/**
	 * 读取配置
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array
	{
		if (isset($GLOBALS['__CANDY_PAYLOAD_CRYPTO_CONFIG__']) && is_array($GLOBALS['__CANDY_PAYLOAD_CRYPTO_CONFIG__'])) {
			return $GLOBALS['__CANDY_PAYLOAD_CRYPTO_CONFIG__'];
		}
		$config = function_exists('loadConfig') ? loadConfig('PayloadCrypto') : [];
		return is_array($config) ? $config : [];
	}

	/**
	 * 注入配置（测试 / init 同进程重载）
	 *
	 * @param array<string,mixed>|null $config 配置；null 清除注入
	 * @return void
	 */
	public static function setConfig(?array $config): void
	{
		if ($config === null) {
			unset($GLOBALS['__CANDY_PAYLOAD_CRYPTO_CONFIG__']);
			return;
		}
		$GLOBALS['__CANDY_PAYLOAD_CRYPTO_CONFIG__'] = $config;
	}

	/**
	 * 断言已启用
	 *
	 * @return void
	 * @throws PayloadCryptoException
	 */
	public static function assertEnabled(): void
	{
		if (!self::enabled()) {
			throw new PayloadCryptoException(
				PayloadCryptoException::ERR_DISABLED,
				'PayloadCrypto 未启用：请先执行 php candy payload-crypto:init 并设 enabled=true',
				503
			);
		}
	}

	/**
	 * 运行模式 single|multi
	 *
	 * @return string
	 */
	public static function mode(): string
	{
		$mode = (string)(self::config()['mode'] ?? 'single');
		return $mode === 'multi' ? 'multi' : 'single';
	}

	/**
	 * 编码 base64|raw
	 *
	 * @return string
	 */
	public static function encoding(): string
	{
		$enc = strtolower((string)(self::config()['encoding'] ?? 'base64'));
		return $enc === 'raw' ? 'raw' : 'base64';
	}

	/**
	 * 是否启用 AAD
	 *
	 * @return bool
	 */
	public static function aadEnabled(): bool
	{
		$aad = self::config()['aad'] ?? [];
		if (!is_array($aad)) {
			return true;
		}
		return ($aad['enabled'] ?? true) !== false;
	}
}

