<?php
/***
 * Check 初始化安装器（包内）
 *
 * 业务说明：发布 Sensitive.inc.php、灌 Cache 词库；不改 Application/tags.php
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
 * Check 一键初始化（由 Console 薄命令调用）
 */
final class Installer
{
	/**
	 * 本包目录
	 *
	 * @return string
	 */
	public static function vendorDir(): string
	{
		return rtrim((string)CANDYPATH, '/\\') . DIRECTORY_SEPARATOR
			. 'Candy' . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR . 'Check' . DIRECTORY_SEPARATOR;
	}

	/**
	 * 配置模板
	 *
	 * @return string
	 */
	public static function templateConfigPath(): string
	{
		return self::vendorDir() . 'Sensitive.inc.php';
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
		return rtrim($inc, '/\\') . DIRECTORY_SEPARATOR . 'Sensitive.inc.php';
	}

	/**
	 * 执行初始化
	 *
	 * @param bool $force 覆盖配置 / 强制重灌词库
	 * @return array{code:int,message:string,data:array<string,mixed>}
	 */
	public static function install(bool $force = false): array
	{
		$steps = [];
		$steps['config'] = self::publishConfig($force);
		if ((int)($steps['config']['code'] ?? 1) !== 0 && empty($steps['config']['skipped'])) {
			return ['code' => 1, 'message' => (string)$steps['config']['message'], 'data' => ['steps' => $steps]];
		}

		$steps['cache'] = self::checkCache();
		if ((int)($steps['cache']['code'] ?? 1) !== 0) {
			return ['code' => 1, 'message' => (string)$steps['cache']['message'], 'data' => ['steps' => $steps]];
		}

		// 发布后 loadConfig 可能已缓存空结果，清静态需重新 include
		self::reloadSensitiveConfig();

		$steps['seed'] = WordStore::seedFromFile($force);
		if ((int)($steps['seed']['code'] ?? 1) !== 0) {
			return ['code' => 1, 'message' => (string)$steps['seed']['message'], 'data' => ['steps' => $steps]];
		}

		$steps['tags'] = [
			'code'    => 0,
			'skipped' => true,
			'message' => 'Check 不注册 tags Hook（按需调用 Sensitive / helpers）',
		];

		return [
			'code'    => 0,
			'message' => 'Check initialized',
			'data'    => [
				'steps'  => $steps,
				'config' => self::projectConfigPath(),
				'words'  => $steps['seed']['count'] ?? null,
				'ver'    => $steps['seed']['ver'] ?? null,
				'hint'   => '默认不挂 tags；业务直接 \\Check\\Sensitive::instance()->is($text) 或 sensitive_is()',
			],
		];
	}

	/**
	 * 发布配置（enabled 写为 true）
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
		$raw = preg_replace("/('enabled'\\s*=>\\s*)false/", '${1}true', $raw, 1) ?? $raw;
		$header = "<?php\n/**\n * Check 敏感词项目配置（php candy check:init）\n * 模板：Candy/Vendor/Check/Sensitive.inc.php\n */\n";
		if (str_starts_with(ltrim($raw), '<?php')) {
			$raw = preg_replace('/^<\?php\s*/', '', $raw, 1) ?? $raw;
		}
		if (@file_put_contents($dst, $header . ltrim($raw)) === false) {
			return ['code' => 1, 'message' => 'cannot write: ' . $dst];
		}
		return ['code' => 0, 'message' => 'config published: ' . $dst, 'path' => $dst];
	}

	/**
	 * 检查 Cache
	 *
	 * @return array{code:int,message:string}
	 */
	public static function checkCache(): array
	{
		try {
			if (!class_exists(\Candy\Core\Cache::class)) {
				return ['code' => 1, 'message' => 'Candy\\Core\\Cache missing'];
			}
			$cache = \Candy\Core\Cache::store();
			if (!is_object($cache) || !method_exists($cache, 'set') || !method_exists($cache, 'get')) {
				return ['code' => 1, 'message' => 'Cache store not ready'];
			}
			$probe = 'check:init:probe:' . getmypid();
			$cache->set($probe, 1, 60);
			$ok = (int)$cache->get($probe, 0) === 1;
			$cache->delete($probe);
			if (!$ok) {
				return ['code' => 1, 'message' => 'Cache get/set probe failed'];
			}
			return ['code' => 0, 'message' => 'Cache ready'];
		} catch (\Throwable $e) {
			return ['code' => 1, 'message' => 'Cache error: ' . $e->getMessage()];
		}
	}

	/**
	 * 重新加载 Sensitive 配置（同进程 init 后立即可用）
	 *
	 * @return void
	 */
	private static function reloadSensitiveConfig(): void
	{
		$path = self::projectConfigPath();
		if (!is_file($path)) {
			return;
		}
		clearstatcache(true, $path);
		$cfg = include $path;
		if (is_array($cfg)) {
			$GLOBALS['__CHECK_SENSITIVE_CONFIG__'] = $cfg;
		}
	}
}
