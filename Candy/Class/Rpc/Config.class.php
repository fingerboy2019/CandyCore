<?php
/***
 * Rpc 配置读取
 *
 * 业务说明：加载 Source/Inc/Rpc.inc.php，提供协议默认值与服务白名单
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:20:44 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Rpc 配置访问
 */
final class Config
{
	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	/**
	 * 读取完整 Rpc 配置
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array
	{
		if (self::$cache !== null) {
			return self::$cache;
		}

		$path = (defined('CANDY') && function_exists('C') ? C('INCPATH') : '') . 'Rpc.inc.php';
		if ($path !== 'Rpc.inc.php' && is_file($path) && function_exists('loadConfig')) {
			self::$cache = loadConfig('Rpc') ?: [];
		} elseif (is_file((defined('CANDYPATH') ? CANDYPATH : '') . 'Source/Inc/Rpc.inc.php')) {
			self::$cache = include (CANDYPATH . 'Source/Inc/Rpc.inc.php');
		} else {
			self::$cache = [];
		}

		return self::$cache;
	}

	/**
	 * 重置配置缓存（测试 / reload）
	 *
	 * @return void
	 */
	public static function reset(): void
	{
		self::$cache = null;
	}

	/**
	 * 默认协议：auto|json|bin（auto 在客户端按 DEBUG 选择）
	 *
	 * @return string
	 */
	public static function protocol(): string
	{
		$p = (string)(self::all()['protocol'] ?? 'auto');
		return in_array($p, ['auto', 'json', 'bin'], true) ? $p : 'auto';
	}

	/**
	 * 解析实际编解码（auto → DEBUG 用 json，否则 bin）
	 *
	 * @param string|null $override 调用方覆盖
	 * @return string json|bin
	 */
	public static function resolveCodec(?string $override = null): string
	{
		$p = $override ?: self::protocol();
		if ($p === 'json' || $p === 'bin') {
			return $p;
		}
		$debug = defined('DEBUG') ? (int)DEBUG : 0;
		if (function_exists('C')) {
			$cDebug = C('DEBUG');
			if ($cDebug !== '' && $cDebug !== null) {
				$debug = (int)$cDebug;
			}
		}
		return $debug ? 'json' : 'bin';
	}

	/**
	 * 监听地址 host:port
	 *
	 * @return string
	 */
	public static function listen(): string
	{
		return (string)(self::all()['listen'] ?? '0.0.0.0:2020');
	}

	/**
	 * 配置中的服务白名单（优先于目录扫描）
	 *
	 * @return array<string,class-string>
	 */
	public static function services(): array
	{
		$services = self::all()['services'] ?? [];
		return is_array($services) ? $services : [];
	}

	/**
	 * 是否启用 Application/{App}/Rpc 目录扫描
	 *
	 * @return bool
	 */
	public static function scanEnabled(): bool
	{
		return (bool)(self::all()['scan'] ?? true);
	}

	/**
	 * 客户端收到带 ops 的信封时是否自动 OpRunner::apply
	 *
	 * @return bool
	 */
	public static function autoApply(): bool
	{
		return (bool)(self::all()['auto_apply'] ?? false);
	}

	/**
	 * Op / Query 允许的 Model 名白名单；空数组表示不限制（仅信任内网时使用）
	 *
	 * @return list<string>
	 */
	public static function opModels(): array
	{
		$models = self::all()['op_models'] ?? [];
		if (!is_array($models)) {
			return [];
		}
		$out = [];
		foreach ($models as $m) {
			if (is_string($m) && $m !== '') {
				$out[] = $m;
			}
		}
		return $out;
	}

	/**
	 * 查询方案 Model 白名单；未配置时回退 op_models
	 *
	 * @return list<string>
	 */
	public static function queryModels(): array
	{
		if (!array_key_exists('query_models', self::all())) {
			return self::opModels();
		}
		$models = self::all()['query_models'];
		if (!is_array($models)) {
			return [];
		}
		$out = [];
		foreach ($models as $m) {
			if (is_string($m) && $m !== '') {
				$out[] = $m;
			}
		}
		return $out;
	}

	/**
	 * 多条 op 是否包在数据库事务中执行
	 *
	 * @return bool
	 */
	public static function opTransaction(): bool
	{
		return (bool)(self::all()['op_transaction'] ?? true);
	}

	/**
	 * LocalApply 默认顺序：query_op（先查后写）| op_query（先写后查）
	 *
	 * @return string
	 */
	public static function applyOrder(): string
	{
		$order = strtolower(trim((string)(self::all()['apply_order'] ?? LocalApply::ORDER_QUERY_OP)));
		return $order === LocalApply::ORDER_OP_QUERY
			? LocalApply::ORDER_OP_QUERY
			: LocalApply::ORDER_QUERY_OP;
	}

	/**
	 * 含 ops/queries 落地时是否强制验权（普通 result 永不验权）
	 *
	 * @return bool
	 */
	public static function applyAuth(): bool
	{
		return (bool)(self::all()['apply_auth'] ?? true);
	}

	/**
	 * A/B 共享的 apply 签名密钥
	 *
	 * @return string
	 */
	public static function applySecret(): string
	{
		return (string)(self::all()['apply_secret'] ?? '');
	}

	/**
	 * 签名时间窗（秒）
	 *
	 * @return int
	 */
	public static function applySkew(): int
	{
		$skew = (int)(self::all()['apply_skew'] ?? 300);
		return $skew > 0 ? $skew : 300;
	}

	/**
	 * 是否启用远程类库导出/安装
	 *
	 * @return bool
	 */
	public static function remoteLibEnabled(): bool
	{
		$cfg = self::all()['remote_lib'] ?? [];
		return is_array($cfg) && !empty($cfg['enabled']);
	}

	/**
	 * 远程类库密钥（默认回退 apply_secret）
	 *
	 * @return string
	 */
	public static function remoteLibSecret(): string
	{
		$cfg = self::all()['remote_lib'] ?? [];
		if (is_array($cfg) && isset($cfg['secret']) && is_string($cfg['secret']) && $cfg['secret'] !== '') {
			return $cfg['secret'];
		}
		return self::applySecret();
	}

	/**
	 * 远程类库包时间窗
	 *
	 * @return int
	 */
	public static function remoteLibSkew(): int
	{
		$cfg = self::all()['remote_lib'] ?? [];
		if (is_array($cfg) && isset($cfg['skew'])) {
			$skew = (int)$cfg['skew'];
			return $skew > 0 ? $skew : 300;
		}
		return self::applySkew();
	}

	/**
	 * 允许导出的类库：逻辑名 => 绝对路径或 FQCN
	 *
	 * @return array<string,string>
	 */
	public static function remoteLibAllow(): array
	{
		$cfg = self::all()['remote_lib'] ?? [];
		$allow = is_array($cfg) ? ($cfg['allow'] ?? []) : [];
		if (!is_array($allow)) {
			return [];
		}
		$out = [];
		foreach ($allow as $name => $def) {
			if (is_string($name) && $name !== '' && is_string($def) && $def !== '') {
				$out[$name] = $def;
			}
		}
		return $out;
	}

	/**
	 * 远程类库落盘目录
	 *
	 * @return string
	 */
	public static function remoteLibStorage(): string
	{
		$cfg = self::all()['remote_lib'] ?? [];
		if (is_array($cfg) && isset($cfg['storage']) && is_string($cfg['storage']) && $cfg['storage'] !== '') {
			return rtrim($cfg['storage'], "/\\");
		}
		$root = defined('CANDYPATH') ? CANDYPATH : (defined('CANDYROOT') ? CANDYROOT : sys_get_temp_dir() . '/');
		return rtrim($root, "/\\") . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'remote_lib';
	}
}

