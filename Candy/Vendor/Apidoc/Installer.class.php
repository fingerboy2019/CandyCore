<?php
/***
 * Apidoc 初始化安装器（包内）
 *
 * 业务说明：发布配置、解压前端到站点根 /Apidoc/、注册 tags
 * 前端包：Candy/Vendor/Apidoc/apidoc.zip（顶层目录名 Apidoc）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 21:12:46 $
 *
 * @package Apidoc
 */

namespace Apidoc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Apidoc 一键初始化（由 Console 薄命令调用）
 */
final class Installer
{
	/** 站点根下前端目录名（与 zip 顶层、route_prefix 对齐，大小写敏感） */
	public const FRONTEND_DIR = 'Apidoc';

	/**
	 * 本包目录
	 *
	 * @return string
	 */
	public static function vendorDir(): string
	{
		return rtrim((string)CANDYPATH, '/\\') . DIRECTORY_SEPARATOR
			. 'Candy' . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR . 'Apidoc' . DIRECTORY_SEPARATOR;
	}

	/**
	 * 配置模板
	 *
	 * @return string
	 */
	public static function templateConfigPath(): string
	{
		return self::vendorDir() . 'Apidoc.inc.php';
	}

	/**
	 * 项目配置
	 *
	 * @return string
	 */
	public static function projectConfigPath(): string
	{
		$inc = function_exists('C') ? (string)C('INCPATH') : (defined('INCPATH') ? (string)INCPATH : '');
		return rtrim($inc, '/\\') . DIRECTORY_SEPARATOR . 'Apidoc.inc.php';
	}

	/**
	 * 站点根目录下前端绝对路径
	 *
	 * @return string
	 */
	public static function frontendPath(): string
	{
		$root = rtrim((string)(function_exists('C') ? C('CANDYROOT') : CANDYROOT), '/\\');
		return $root . DIRECTORY_SEPARATOR . self::FRONTEND_DIR . DIRECTORY_SEPARATOR;
	}

	/**
	 * 执行初始化
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

		$steps['frontend'] = self::extractFrontend($force);
		if ((int)($steps['frontend']['code'] ?? 1) !== 0 && empty($steps['frontend']['skipped'])) {
			return ['code' => 1, 'message' => (string)$steps['frontend']['message'], 'data' => ['steps' => $steps]];
		}

		$steps['tags'] = self::registerServiceTags($force);

		return [
			'code'    => 0,
			'message' => 'Apidoc initialized',
			'data'    => [
				'steps'    => $steps,
				'config'   => self::projectConfigPath(),
				'frontend' => self::frontendPath(),
			],
		];
	}

	/**
	 * 发布配置到 Source/Inc
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
			return ['code' => 1, 'message' => 'cannot create: ' . $dir];
		}
		$raw = (string)file_get_contents($src);
		$raw = preg_replace("/('enable'\\s*=>\\s*)false/", '$1true', $raw, 1) ?? $raw;
		$header = "<?php\n/**\n * Apidoc 项目配置（php candy apidoc:init）\n * 模板：Candy/Vendor/Apidoc/Apidoc.inc.php\n */\n";
		if (str_starts_with(ltrim($raw), '<?php')) {
			$raw = preg_replace('/^<\?php\s*/', '', $raw, 1) ?? $raw;
		}
		if (@file_put_contents($dst, $header . ltrim($raw)) === false) {
			return ['code' => 1, 'message' => 'cannot write: ' . $dst];
		}
		return ['code' => 0, 'message' => 'config published: ' . $dst, 'path' => $dst];
	}

	/**
	 * 解压前端到站点根目录 /Apidoc/
	 *
	 * zip 顶层必须为 Apidoc/，extractTo(CANDYROOT) 后得到 CANDYROOT/Apidoc/
	 * 兼容清理历史小写 apidoc/ 目录（force 时一并删除）
	 *
	 * @param bool $force 覆盖
	 * @return array{code:int,message:string,skipped?:bool,path?:string}
	 */
	public static function extractFrontend(bool $force = false): array
	{
		$zipPath = self::vendorDir() . 'apidoc.zip';
		$root = rtrim((string)(function_exists('C') ? C('CANDYROOT') : CANDYROOT), '/\\') . DIRECTORY_SEPARATOR;
		$target = self::frontendPath();
		$legacy = $root . 'apidoc' . DIRECTORY_SEPARATOR;

		if (!is_file($zipPath)) {
			return ['code' => 1, 'message' => 'zip missing: ' . $zipPath];
		}
		if (is_dir($target) && !$force) {
			return ['code' => 0, 'skipped' => true, 'message' => 'frontend exists: ' . $target, 'path' => $target];
		}
		if (!class_exists(\ZipArchive::class)) {
			return ['code' => 1, 'message' => 'ZipArchive required'];
		}
		if ($force) {
			if (is_dir($target)) {
				self::removeDir($target);
			}
			// 历史解压目录（小写）一并清理，避免与 /Apidoc 混用
			if (is_dir($legacy) && realpath($legacy) !== realpath($target)) {
				self::removeDir($legacy);
			}
		}
		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			return ['code' => 1, 'message' => 'cannot open zip'];
		}
		$ok = $zip->extractTo($root);
		$zip->close();
		if (!$ok || !is_dir($target)) {
			return ['code' => 1, 'message' => 'extract failed（zip 顶层须为 ' . self::FRONTEND_DIR . '/）'];
		}
		return ['code' => 0, 'message' => 'frontend extracted: ' . $target, 'path' => $target];
	}

	/**
	 * 注册可选扩展 tags（与 Catlog 约定同一双层 merge 模板）
	 *
	 * @param bool $force 强制重写
	 * @return array{code:int,message:string,skipped?:bool,path?:string}
	 */
	public static function registerServiceTags(bool $force = false): array
	{
		// 与 Catlog\Installer::registerServiceTags 模板保持一致，包间无硬依赖
		if (class_exists(\Catlog\Installer::class)) {
			return \Catlog\Installer::registerServiceTags($force);
		}

		$root = function_exists('C') ? (string)C('CANDYROOT') : (defined('CANDYROOT') ? (string)CANDYROOT : '');
		$path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Application' . DIRECTORY_SEPARATOR . 'tags.php';
		if (!is_file($path)) {
			return ['code' => 1, 'message' => 'tags.php missing: ' . $path];
		}

		$now = date('Y-m-d H:i:s');
		$content = <<<PHP
<?php
/***
 * 全局 Hook 标签注册
 *
 * 可选扩展：php candy apidoc:init / catlog:init
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$now} \$
 */

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

return \\Apidoc\\Bootstrap::mergeTags([
	// 其它全局 Hook 在此追加
]);

PHP;
		if (@file_put_contents($path, $content) === false) {
			return ['code' => 1, 'message' => 'cannot write tags: ' . $path];
		}
		return ['code' => 0, 'message' => 'Apidoc tags registered: ' . $path, 'path' => $path];
	}

	/**
	 * 递归删除目录
	 *
	 * @param string $dir 目录
	 * @return void
	 */
	private static function removeDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			is_dir($path) ? self::removeDir($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
