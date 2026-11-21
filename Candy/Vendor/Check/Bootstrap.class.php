<?php
/***
 * Check 可选启用入口（包内）
 *
 * 业务说明：读取 Inc 开关；默认不挂 Hook；未 init 前不可用
 * 启用：php candy check:init
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 12:31:43 $
 *
 * @package Check
 */

declare(strict_types=1);

namespace Check;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 敏感词可选启用（无请求级 Hook，按需调用）
 */
final class Bootstrap
{
	/** @var bool helpers 是否已加载 */
	private static bool $helpersLoaded = false;

	/**
	 * 是否启用（Source/Inc/Sensitive.inc.php 存在且 enabled=true）
	 *
	 * @return bool
	 */
	public static function enabled(): bool
	{
		$config = self::config();
		return $config !== [] && !empty($config['enabled']);
	}

	/**
	 * 读取项目配置
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array
	{
		// init 同进程覆盖（loadConfig 静态缓存可能仍是旧值）
		if (isset($GLOBALS['__CHECK_SENSITIVE_CONFIG__']) && is_array($GLOBALS['__CHECK_SENSITIVE_CONFIG__'])) {
			return $GLOBALS['__CHECK_SENSITIVE_CONFIG__'];
		}
		$config = function_exists('loadConfig') ? loadConfig('Sensitive') : [];
		return is_array($config) ? $config : [];
	}

	/**
	 * Check 不注册请求 Hook（保持 tags 干净）
	 *
	 * @return array<string,list<string>>
	 */
	public static function hooks(): array
	{
		return [];
	}

	/**
	 * 合并 tags（恒等：不注入任何 Hook）
	 *
	 * @param array<string,mixed> $tags 项目已有标签
	 * @return array<string,mixed>
	 */
	public static function mergeTags(array $tags = []): array
	{
		return $tags;
	}

	/**
	 * 加载 helpers.php（幂等）
	 *
	 * @return void
	 */
	public static function loadHelpers(): void
	{
		if (self::$helpersLoaded) {
			return;
		}
		$path = __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';
		if (is_file($path)) {
			require_once $path;
		}
		self::$helpersLoaded = true;
	}

	/**
	 * 默认种子词库路径
	 *
	 * @return string
	 */
	public static function defaultWordFile(): string
	{
		return __DIR__ . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'SensitiveWord.txt';
	}
}
