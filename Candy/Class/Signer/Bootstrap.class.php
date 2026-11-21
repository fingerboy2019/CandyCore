<?php
/***
 * Signer 可选启用入口
 *
 * 业务说明：读取 Source/Inc/Signer.inc.php；默认不启用、不挂中间件
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:47:57 $
 *
 * @package Candy\Extend\Signer
 */

declare(strict_types=1);

namespace Candy\Extend\Signer;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 验签模块开关与配置
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
		if (isset($GLOBALS['__CANDY_SIGNER_CONFIG__']) && is_array($GLOBALS['__CANDY_SIGNER_CONFIG__'])) {
			return $GLOBALS['__CANDY_SIGNER_CONFIG__'];
		}
		$config = function_exists('loadConfig') ? loadConfig('Signer') : [];
		return is_array($config) ? $config : [];
	}

	/**
	 * 断言已启用
	 *
	 * @return void
	 * @throws SignerException
	 */
	public static function assertEnabled(): void
	{
		if (!self::enabled()) {
			throw new SignerException(
				SignerException::ERR_DISABLED,
				'Signer 未启用：请先执行 php candy signer:init',
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
	 * 时间窗秒数
	 *
	 * @return int
	 */
	public static function skew(): int
	{
		return max(1, (int)(self::config()['skew'] ?? 300));
	}

	/**
	 * 签名编码 hex|base64
	 *
	 * @return string
	 */
	public static function signatureEncoding(): string
	{
		$enc = strtolower((string)(self::config()['signature_encoding'] ?? 'hex'));
		return $enc === 'base64' ? 'base64' : 'hex';
	}
}

