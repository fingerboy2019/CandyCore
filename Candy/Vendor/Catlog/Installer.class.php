<?php
/***
 * Catlog 初始化安装器（包内）
 *
 * 业务说明：发布配置、复制静态资源、注册 tags；逻辑留在 Vendor/Catlog
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:51:43 $
 *
 * @package Catlog
 */

namespace Catlog;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Catlog 一键初始化（由 Console 薄命令调用）
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
			. 'Candy' . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR . 'Catlog' . DIRECTORY_SEPARATOR;
	}

	/**
	 * 配置模板
	 *
	 * @return string
	 */
	public static function templateConfigPath(): string
	{
		return self::vendorDir() . 'Catlog.inc.php';
	}

	/**
	 * 项目配置
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
		return rtrim($inc, '/\\') . DIRECTORY_SEPARATOR . 'Catlog.inc.php';
	}

	/**
	 * Vendor 静态资源
	 *
	 * @return string
	 */
	public static function vendorStaticDir(): string
	{
		return self::vendorDir() . 'static' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Public 静态资源
	 *
	 * @return string
	 */
	public static function publicStaticDir(): string
	{
		$root = function_exists('C') ? (string)C('CANDYROOT') : (defined('CANDYROOT') ? (string)CANDYROOT : '');
		return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Public' . DIRECTORY_SEPARATOR . 'catlog' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR;
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

		// UI 留在 Vendor/Catlog/static，页面直接引用 /Candy/Vendor/Catlog/static
		$steps['static'] = [
			'code'    => 0,
			'skipped' => true,
			'message' => 'UI at /Candy/Vendor/Catlog/static (no Public copy)',
			'path'    => self::vendorStaticDir(),
		];

		$steps['tags'] = self::registerServiceTags($force);

		return [
			'code'    => 0,
			'message' => 'Catlog initialized',
			'data'    => [
				'steps'       => $steps,
				'config'      => self::projectConfigPath(),
				'static'      => '/Candy/Vendor/Catlog/static',
				'report_hint' => '打开 /Home/Index/catlog.html；密码见 Inc auth',
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
		$raw = preg_replace("/('enable'\\s*=>\\s*)false/", '$1true', $raw, 1) ?? $raw;
		$header = "<?php\n/**\n * Catlog 项目配置（php candy catlog:init）\n * 模板：Candy/Vendor/Catlog/Catlog.inc.php\n */\n";
		if (str_starts_with(ltrim($raw), '<?php')) {
			$raw = preg_replace('/^<\?php\s*/', '', $raw, 1) ?? $raw;
		}
		if (@file_put_contents($dst, $header . ltrim($raw)) === false) {
			return ['code' => 1, 'message' => 'cannot write: ' . $dst];
		}
		return ['code' => 0, 'message' => 'config published: ' . $dst, 'path' => $dst];
	}

	/**
	 * 复制静态资源到 Public
	 *
	 * @param bool $force 覆盖
	 * @return array{code:int,message:string,skipped?:bool,path?:string}
	 */
	public static function publishStatic(bool $force = false): array
	{
		$src = self::vendorStaticDir();
		$dst = self::publicStaticDir();
		if (!is_dir($src)) {
			return ['code' => 1, 'message' => 'static missing: ' . $src];
		}
		if (is_dir($dst) && !$force && is_file($dst . 'css' . DIRECTORY_SEPARATOR . 'xhprof.css')) {
			return ['code' => 0, 'skipped' => true, 'message' => 'static exists: ' . $dst, 'path' => $dst];
		}
		if (!self::copyDir($src, $dst)) {
			return ['code' => 1, 'message' => 'copy static failed: ' . $dst];
		}
		return ['code' => 0, 'message' => 'static published: ' . $dst, 'path' => $dst];
	}

	/**
	 * 注册可选扩展 tags（与 Apidoc 包约定同一双层 merge 模板）
	 *
	 * @param bool $force 强制重写
	 * @return array{code:int,message:string,skipped?:bool,path?:string}
	 */
	public static function registerServiceTags(bool $force = false): array
	{
		$root = function_exists('C') ? (string)C('CANDYROOT') : (defined('CANDYROOT') ? (string)CANDYROOT : '');
		$path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'Application' . DIRECTORY_SEPARATOR . 'tags.php';
		if (!is_file($path)) {
			return ['code' => 1, 'message' => 'tags.php missing: ' . $path];
		}

		$current = (string)file_get_contents($path);
		$codeOnly = preg_replace('#/\*.*?\*/#s', '', $current) ?? $current;
		$codeOnly = preg_replace('#//[^\n]*#', '', $codeOnly) ?? $codeOnly;

		$hasDual = (bool)preg_match('/\\\\Catlog\\\\Bootstrap::mergeTags\s*\(/', $codeOnly)
			&& (bool)preg_match('/\\\\Apidoc\\\\Bootstrap::mergeTags\s*\(/', $codeOnly);
		$hasLegacy = (bool)preg_match('/Candy\\\\Extend\\\\(Catlog|Apidoc)\\\\Bootstrap::mergeTags/', $codeOnly);
		$isScaffold = (bool)preg_match('/return\s*\[\s*\];/s', $codeOnly);

		if ($hasDual && !$force) {
			return ['code' => 0, 'skipped' => true, 'message' => 'tags already register optional vendors: ' . $path, 'path' => $path];
		}
		if (!$isScaffold && !$hasDual && !$hasLegacy && !$force) {
			return [
				'code'    => 0,
				'skipped' => true,
				'message' => 'tags.php custom; wrap with \\Catlog\\Bootstrap::mergeTags(\\Apidoc\\Bootstrap::mergeTags([...])) manually',
				'path'    => $path,
			];
		}

		$now = date('Y-m-d H:i:s');
		$content = <<<PHP
<?php
/***
 * 全局 Hook 标签注册
 *
 * 仅注册项目显式声明的标签；不自动扫描 Candy/Vendor 下各包的 tags.php
 * 可选扩展：php candy apidoc:init / catlog:init（包内 Bootstrap，enable=false 时 hooks 为空）
 *
 * \$Author: 刘森 (fingerboy@qq.com) \$
 * \$Date: {$now} \$
 */

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

return \\Catlog\\Bootstrap::mergeTags(
	\\Apidoc\\Bootstrap::mergeTags([
		// 其它全局 Hook 在此追加
	])
);

PHP;

		if (@file_put_contents($path, $content) === false) {
			return ['code' => 1, 'message' => 'cannot write tags: ' . $path];
		}
		return ['code' => 0, 'message' => 'optional vendor tags registered: ' . $path, 'path' => $path];
	}

	/**
	 * 递归复制目录
	 *
	 * @param string $src 源
	 * @param string $dst 目标
	 * @return bool
	 */
	private static function copyDir(string $src, string $dst): bool
	{
		$src = rtrim($src, '/\\');
		$dst = rtrim($dst, '/\\');
		if (!is_dir($src)) {
			return false;
		}
		if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
			return false;
		}
		foreach (scandir($src) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$from = $src . DIRECTORY_SEPARATOR . $item;
			$to = $dst . DIRECTORY_SEPARATOR . $item;
			if (is_dir($from)) {
				if (!self::copyDir($from, $to)) {
					return false;
				}
			} else {
				$dir = dirname($to);
				if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
					return false;
				}
				if (!@copy($from, $to)) {
					return false;
				}
			}
		}
		return true;
	}
}
