<?php
/***
 * Catlog 可选启用入口（包内）
 *
 * 业务说明：Hook 合并、配置应用、报告鉴权；非框架 Extend，随 Vendor/Catlog 分发
 * 启用：php candy catlog:init
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:51:43 $
 *
 * @package Catlog
 */

namespace Catlog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 性能分析可选挂载（默认不采样）
 */
final class Bootstrap
{
	/** @var bool 配置是否已应用到 Xhprof 静态属性 */
	private static bool $configApplied = false;

	/**
	 * 供 Application/tags.php 合并的 Hook 表
	 *
	 * @return array<string,list<string>>
	 */
	public static function hooks(): array
	{
		if (!self::enabled()) {
			return [];
		}

		return [
			'app_begin'   => [
				Xhprof::class . '::start',
			],
			'app_end'     => [
				Xhprof::class . '::stop',
			],
			'app_stop'    => [
				Xhprof::class . '::stop',
			],
			'view_xhprof' => [
				Xhprof::class . '::showResult',
			],
		];
	}

	/**
	 * 是否启用（读 Source/Inc/Catlog.inc.php）
	 *
	 * @return bool
	 */
	public static function enabled(): bool
	{
		$config = self::config();
		return $config !== [] && !empty($config['enable']);
	}

	/**
	 * 读取项目配置
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array
	{
		$config = function_exists('loadConfig') ? loadConfig('Catlog') : [];
		return is_array($config) ? $config : [];
	}

	/**
	 * 合并项目 tags 与本包 hooks
	 *
	 * @param array<string,mixed> $tags 项目已有标签
	 * @return array<string,mixed>
	 */
	public static function mergeTags(array $tags = []): array
	{
		$hooks = self::hooks();
		if ($hooks === []) {
			return $tags;
		}
		foreach ($hooks as $name => $list) {
			$existing = $tags[$name] ?? [];
			if (!is_array($existing)) {
				$existing = [$existing];
			}
			$tags[$name] = array_values(array_unique(array_merge($existing, $list)));
		}
		return $tags;
	}

	/**
	 * 将 Inc 配置同步到 Xhprof 静态属性
	 *
	 * @return void
	 */
	public static function applyConfig(): void
	{
		if (self::$configApplied) {
			return;
		}
		self::$configApplied = true;

		$config = self::config();
		if ($config === []) {
			return;
		}

		if (isset($config['time_limit'])) {
			Xhprof::$time_limit = (float)$config['time_limit'];
		}
		if (isset($config['ignore_url_arr']) && is_array($config['ignore_url_arr'])) {
			Xhprof::$ignore_url_arr = $config['ignore_url_arr'];
		}
		if (isset($config['key_prefix']) && is_string($config['key_prefix']) && $config['key_prefix'] !== '') {
			Xhprof::$key_prefix = $config['key_prefix'];
		}
		if (isset($config['log_num'])) {
			Xhprof::$log_num = max(1, (int)$config['log_num']);
		}
		if (isset($config['view_wtred'])) {
			Xhprof::$view_wtred = (float)$config['view_wtred'];
		}
		if (isset($config['ui_html']) && is_string($config['ui_html']) && $config['ui_html'] !== '') {
			Xhprof::$ui_html = $config['ui_html'];
		} else {
			Xhprof::$ui_html = '/Candy/Vendor/Catlog/static';
		}
		if (isset($config['symbol_lookup_url']) && is_string($config['symbol_lookup_url'])) {
			Xhprof::$symbol_lookup_url = $config['symbol_lookup_url'];
		}
	}

	/**
	 * 是否要求 DEBUG 才采样
	 *
	 * @return bool
	 */
	public static function requireDebug(): bool
	{
		$config = self::config();
		return !array_key_exists('require_debug', $config) || !empty($config['require_debug']);
	}

	/**
	 * Cache 是否可用于写入性能日志
	 *
	 * @return bool
	 */
	public static function cacheReady(): bool
	{
		try {
			if (!class_exists(\Candy\Core\Cache::class)) {
				return false;
			}
			$cache = \Candy\Core\Cache::store();
			return is_object($cache) && method_exists($cache, 'lPush') && method_exists($cache, 'set');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * 报告页鉴权
	 *
	 * @return bool
	 */
	public static function authorizeReport(): bool
	{
		$config = self::config();
		$auth = $config['auth'] ?? [];
		if (!is_array($auth) || empty($auth['enable'])) {
			return true;
		}

		$password = (string)($auth['password'] ?? '');
		if ($password === '') {
			return false;
		}

		if (function_exists('session')) {
			$flag = session('catlog_authed');
			if (!empty($flag)) {
				return true;
			}
		} elseif (!empty($_SESSION['catlog_authed'])) {
			return true;
		}

		$input = '';
		if (function_exists('I')) {
			$input = (string)(I('request.password') ?: I('request.catlog_password') ?: '');
		}
		if ($input === '' && isset($_REQUEST['password'])) {
			$input = (string)$_REQUEST['password'];
		}

		if ($input !== '' && hash_equals($password, $input)) {
			if (function_exists('session')) {
				session('catlog_authed', 1);
			} else {
				$_SESSION['catlog_authed'] = 1;
			}
			return true;
		}

		return false;
	}
}
