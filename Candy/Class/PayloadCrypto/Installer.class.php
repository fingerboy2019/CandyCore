<?php
/***
 * PayloadCrypto 安装器
 *
 * 业务说明：发布 Source/Inc/PayloadCrypto.inc.php；不改 Middleware
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
 * payload-crypto:init 安装逻辑
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
		return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'PayloadCrypto.inc.php';
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
		return rtrim($inc, '/\\') . DIRECTORY_SEPARATOR . 'PayloadCrypto.inc.php';
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
			? ['code' => 0, 'message' => 'openssl ready (aes-256-gcm)']
			: ['code' => 1, 'message' => 'openssl extension missing'];
		if ((int)$steps['openssl']['code'] !== 0) {
			return ['code' => 1, 'message' => $steps['openssl']['message'], 'data' => ['steps' => $steps]];
		}

		$steps['middleware'] = [
			'code'    => 0,
			'skipped' => true,
			'message' => '不自动改 Middleware.inc.php；见 Doc/Candy/扩展类库/PayloadCrypto使用文档.md',
		];

		return [
			'code'    => 0,
			'message' => 'PayloadCrypto initialized',
			'data'    => [
				'steps'  => $steps,
				'config' => self::projectConfigPath(),
				'hint'   => '强密环境设 enabled=true；HTTP 挂 PayloadCryptoMiddleware；Rpc 自动加解密',
			],
		];
	}

	/**
	 * 发布配置并自动生成 32 字节密钥（hex）
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
		$keyHex = bin2hex(random_bytes(32));
		$raw = preg_replace("/('enabled'\\s*=>\\s*)false/", '${1}true', $raw, 1) ?? $raw;
		$raw = preg_replace(
			"/('key'\\s*=>\\s*)''/",
			'${1}\'' . $keyHex . '\'',
			$raw,
			1
		) ?? $raw;

		$header = "<?php\n/**\n * PayloadCrypto 项目配置（php candy payload-crypto:init）\n * 模板：Candy/Class/PayloadCrypto/PayloadCrypto.inc.php\n */\n";
		if (str_starts_with(ltrim($raw), '<?php')) {
			$raw = preg_replace('/^<\?php\s*/', '', $raw, 1) ?? $raw;
		}
		$raw = preg_replace('/^\s*\/\*\*.*?\*\/\s*/s', '', ltrim($raw), 1) ?? ltrim($raw);
		if (@file_put_contents($dst, $header . ltrim($raw)) === false) {
			return ['code' => 1, 'message' => 'cannot write: ' . $dst];
		}
		return ['code' => 0, 'message' => 'config published (key auto-generated): ' . $dst, 'path' => $dst];
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
			Bootstrap::setConfig($cfg);
		}
	}
}

