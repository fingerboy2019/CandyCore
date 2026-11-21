<?php
/***
 * Signer 安装器
 *
 * 业务说明：发布 Source/Inc/Signer.inc.php；不改 Middleware
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 13:18:53 $
 *
 * @package Candy\Extend\Signer
 */

declare(strict_types=1);

namespace Candy\Extend\Signer;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * signer:init 安装逻辑
 */
final class Installer
{
	/**
	 * 模板路径
	 *
	 * @return string
	 */
	public static function templateConfigPath(): string
	{
		return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Signer.inc.php';
	}

	/**
	 * 项目配置路径
	 *
	 * @return string
	 */
	public static function projectConfigPath(): string
	{
		$inc = function_exists('C') ? (string)C('INCPATH') : '';
		if ($inc === '' && defined('INCPATH')) {
			$inc = (string)INCPATH;
		}
		if ($inc === '' && defined('CANDYROOT')) {
			$inc = rtrim((string)CANDYROOT, '/\\') . DIRECTORY_SEPARATOR . 'Source' . DIRECTORY_SEPARATOR . 'Inc' . DIRECTORY_SEPARATOR;
		}
		return rtrim($inc, '/\\') . DIRECTORY_SEPARATOR . 'Signer.inc.php';
	}

	/**
	 * 执行安装
	 *
	 * @param bool $force 覆盖
	 * @return array{code:int,message:string,data:array<string,mixed>}
	 */
	public static function install(bool $force = false): array
	{
		$steps = [];
		$steps['config'] = self::publishConfig($force);
		if ((int)($steps['config']['code'] ?? 1) !== 0 && empty($steps['config']['skipped'])) {
			return ['code' => 1, 'message' => (string)$steps['config']['message'], 'data' => ['steps' => $steps]];
		}

		self::reloadConfig();

		$steps['openssl'] = extension_loaded('openssl')
			? ['code' => 0, 'message' => 'openssl ready']
			: ['code' => 1, 'message' => 'openssl extension missing'];
		if ((int)$steps['openssl']['code'] !== 0) {
			return ['code' => 1, 'message' => $steps['openssl']['message'], 'data' => ['steps' => $steps]];
		}

		$steps['cache'] = self::probeCache();
		$steps['middleware'] = [
			'code'    => 0,
			'skipped' => true,
			'message' => '不自动改 Middleware.inc.php；见 Doc/Candy/扩展类库/Signer使用文档.md',
		];

		return [
			'code'    => 0,
			'message' => 'Signer initialized',
			'data'    => [
				'steps'  => $steps,
				'config' => self::projectConfigPath(),
				'hint'   => 'mode=single 填 default.secret 即可；API 用 Candy\\Extend\\Signer\\Api\\ApiSigner',
			],
		];
	}

	/**
	 * 发布配置
	 *
	 * @param bool $force 覆盖
	 * @return array{code:int,message:string,skipped?:bool,path?:string}
	 */
	public static function publishConfig(bool $force = false): array
	{
		$src = self::templateConfigPath();
		$dst = self::projectConfigPath();
		if (!is_file($src)) {
			return ['code' => 1, 'message' => 'template missing: ' . $src];
		}
		if (is_file($dst) && !$force) {
			return ['code' => 0, 'skipped' => true, 'message' => 'config exists: ' . $dst, 'path' => $dst];
		}
		$dir = dirname($dst);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return ['code' => 1, 'message' => 'cannot create Inc: ' . $dir];
		}

		$raw = (string)file_get_contents($src);
		$secret = bin2hex(random_bytes(32));
		$aes = bin2hex(random_bytes(16));
		$raw = preg_replace("/('enabled'\\s*=>\\s*)false/", '${1}true', $raw, 1) ?? $raw;
		$raw = preg_replace(
			"/('secret'\\s*=>\\s*)''/",
			'${1}\'' . $secret . '\'',
			$raw,
			1
		) ?? $raw;
		$raw = preg_replace(
			"/('aes_key'\\s*=>\\s*)''/",
			'${1}\'' . $aes . '\'',
			$raw,
			1
		) ?? $raw;

		$header = "<?php\n/**\n * Signer 项目配置（php candy signer:init）\n * 模板：Candy/Class/Signer/Signer.inc.php\n */\n";
		if (str_starts_with(ltrim($raw), '<?php')) {
			$raw = preg_replace('/^<\?php\s*/', '', $raw, 1) ?? $raw;
		}
		// 去掉模板自带的文档块，避免与项目头重复
		$raw = preg_replace('/^\s*\/\*\*.*?\*\/\s*/s', '', ltrim($raw), 1) ?? ltrim($raw);
		if (@file_put_contents($dst, $header . ltrim($raw)) === false) {
			return ['code' => 1, 'message' => 'cannot write: ' . $dst];
		}
		return ['code' => 0, 'message' => 'config published (secret/aes_key auto-generated): ' . $dst, 'path' => $dst];
	}

	/**
	 * Cache 探测（nonce）
	 *
	 * @return array{code:int,message:string,skipped?:bool}
	 */
	private static function probeCache(): array
	{
		try {
			$cache = \Candy\Core\Cache::store();
			$k = 'signer:init:probe:' . getmypid();
			$cache->set($k, 1, 60);
			$ok = (int)$cache->get($k, 0) === 1;
			$cache->delete($k);
			return $ok
				? ['code' => 0, 'message' => 'Cache ready (nonce)']
				: ['code' => 0, 'skipped' => true, 'message' => 'Cache probe weak; nonce may fail'];
		} catch (\Throwable $e) {
			return ['code' => 0, 'skipped' => true, 'message' => 'Cache unavailable: ' . $e->getMessage() . '（可关 nonce.enabled）'];
		}
	}

	/**
	 * 同进程重载配置
	 *
	 * @return void
	 */
	private static function reloadConfig(): void
	{
		$path = self::projectConfigPath();
		if (!is_file($path)) {
			return;
		}
		clearstatcache(true, $path);
		$cfg = include $path;
		if (is_array($cfg)) {
			$GLOBALS['__CANDY_SIGNER_CONFIG__'] = $cfg;
		}
	}
}

