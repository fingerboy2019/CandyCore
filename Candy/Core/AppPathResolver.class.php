<?php
/***
 * 应用 / 扩展 / 插件驼峰磁盘路径探测
 *
 * 业务说明：逻辑名与磁盘路径分离；根探测拆段优先、扁平兜底。
 * 请求内以控制器源文件主导锁定 APP_PATH，供同应用 M/V/S 共用；兼容 Hallmark/Tenant 覆写。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-04 18:37:32 $
 *
 * @package Candy\Core
 */
declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class AppPathResolver
{
	/**
	 * 将多驼峰逻辑名拆成斜杠相对路径
	 *
	 * CreatorHub → Creator/Hub；单段名不变。
	 *
	 * @param string $name 逻辑名或已含斜杠的相对路径
	 * @return string 拆段后的相对路径
	 * @static
	 */
	public static function camelSplitRel(string $name): string
	{
		$name = str_replace('\\', '/', trim($name));
		$name = trim($name, '/');
		if ($name === '' || str_contains($name, '/')) {
			return $name;
		}
		return (string) preg_replace('/(.)(?=[A-Z])/u', '$1/', $name);
	}

	/**
	 * 由任意相对路径或逻辑名得到扁平逻辑名（去斜杠）
	 *
	 * Creator/Hub → CreatorHub；供 APPNAME / FQCN / Node / Runtime 缓存键。
	 *
	 * @param string $nameOrRel 逻辑名或磁盘相对路径
	 * @return string 扁平逻辑名
	 * @static
	 */
	public static function logicalName(string $nameOrRel): string
	{
		$nameOrRel = str_replace('\\', '/', trim($nameOrRel));
		return str_replace('/', '', trim($nameOrRel, '/'));
	}

	/**
	 * 在指定根目录下探测驼峰布局相对路径
	 *
	 * 顺序：
	 * 1) 入参已含 `/` → 原样，再试去斜杠扁平
	 * 2) CamelCase 拆段（CreatorHub → Creator/Hub）
	 * 3) 扁平原名
	 * 4) 根下一级目录大小写不敏感匹配（仅扁平）
	 *
	 * @param string $baseDir 绝对根目录（建议尾随 /）
	 * @param string $name 逻辑名或相对路径候选
	 * @param callable|null $isValid function(string $absDir): bool；null 时仅要求 is_dir
	 * @return string|null 命中的相对路径；未找到返回 null
	 * @static
	 */
	public static function probeFsRel(string $baseDir, string $name, ?callable $isValid = null): ?string
	{
		$baseDir = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
		$name = str_replace('\\', '/', trim($name));
		$name = trim($name, '/');
		if ($name === '' || $baseDir === '/' || !is_dir($baseDir)) {
			return null;
		}

		$isValid ??= static fn(string $absDir): bool => is_dir($absDir);

		$candidates = [];
		if (str_contains($name, '/')) {
			$candidates[] = $name;
			$flat = str_replace('/', '', $name);
			if ($flat !== '') {
				$candidates[] = $flat;
			}
		} else {
			$split = self::camelSplitRel($name);
			if ($split !== $name) {
				$candidates[] = $split;
			}
			$candidates[] = $name;
		}

		foreach (array_values(array_unique($candidates)) as $rel) {
			$abs = $baseDir . $rel;
			if ($isValid($abs)) {
				return $rel;
			}
		}

		$needle = strtolower(self::logicalName($name));
		foreach (scandir($baseDir) ?: [] as $dir) {
			if ($dir === '.' || $dir === '..' || str_contains($dir, '/')) {
				continue;
			}
			$abs = $baseDir . $dir;
			if (!is_dir($abs)) {
				continue;
			}
			if (strtolower($dir) === $needle && $isValid($abs)) {
				return $dir;
			}
		}

		return null;
	}

	/**
	 * 按控制器源文件探测应用磁盘布局（方案 A）
	 *
	 * 候选根顺序同 probeFsRel；根下命中 Controls 公共 / Hallmark / Tenant 任一即成功。
	 * Addon 须同时存在 conf.php。
	 *
	 * @param string $kind application|addon
	 * @param string $app 逻辑名
	 * @param string $control 控制器短名（多驼峰保留）
	 * @param string|null $hallmark HALLMARK 值
	 * @param string|null $tenantId 租户 ID
	 * @return array{fsRel:string,root:string,controlFile:string,via:string,logical:string}|null
	 * @static
	 */
	public static function probeByControl(
		string $kind,
		string $app,
		string $control,
		?string $hallmark = null,
		?string $tenantId = null
	): ?array {
		$kind = strtolower(trim($kind));
		$app = trim($app);
		$control = \Candy\Extend\Str::controlName($control);
		if ($app === '' || $control === '' || !in_array($kind, ['application', 'addon'], true)) {
			return null;
		}

		$base = $kind === 'addon'
			? (rtrim((string) C('ADDONPATH'), '/\\') . '/')
			: (rtrim((string) C('CANDYROOT'), '/\\') . '/Application/');
		if ($base === '/' || !is_dir($base)) {
			return null;
		}

		$hallmark = ($hallmark !== null && $hallmark !== '') ? (string) $hallmark : null;
		$tenantId = ($tenantId !== null && $tenantId !== '') ? (string) $tenantId : null;
		$hitFile = null;
		$hitVia = null;

		$fsRel = self::probeFsRel(
			$base,
			$app,
			static function (string $absDir) use ($kind, $control, $hallmark, $tenantId, &$hitFile, &$hitVia): bool {
				if (!is_dir($absDir)) {
					return false;
				}
				$root = rtrim(str_replace('\\', '/', $absDir), '/') . '/';
				if ($kind === 'addon' && !is_file($root . 'conf.php')) {
					return false;
				}
				$found = self::findControlFile($root, $control, $hallmark, $tenantId);
				if ($found === null) {
					return false;
				}
				$hitFile = $found['file'];
				$hitVia = $found['via'];
				return true;
			}
		);

		if ($fsRel === null || $hitFile === null || $hitVia === null) {
			return null;
		}

		$root = $base . $fsRel . '/';
		return [
			'fsRel' => $fsRel,
			'root' => $root,
			'controlFile' => $hitFile,
			'via' => $hitVia,
			'logical' => self::logicalName($fsRel),
		];
	}

	/**
	 * 在应用根下查找控制器源文件（Hallmark → Tenant → 公共）
	 *
	 * @param string $root 应用绝对根（尾随 /）
	 * @param string $control 控制器短名
	 * @param string|null $hallmark
	 * @param string|null $tenantId
	 * @return array{file:string,via:string}|null
	 * @access private
	 * @static
	 */
	private static function findControlFile(
		string $root,
		string $control,
		?string $hallmark,
		?string $tenantId
	): ?array {
		$file = $control . '.class.php';
		if ($hallmark !== null) {
			$p = $root . 'Controls/Hallmark/' . $hallmark . '/' . $file;
			if (is_file($p) || (function_exists('fileExistsCase') && fileExistsCase($p))) {
				return ['file' => $p, 'via' => 'hallmark'];
			}
		}
		if ($tenantId !== null) {
			$p = $root . 'Controls/Tenant/' . $tenantId . '/' . $file;
			if (is_file($p) || (function_exists('fileExistsCase') && fileExistsCase($p))) {
				return ['file' => $p, 'via' => 'tenant'];
			}
		}
		$p = $root . 'Controls/' . $file;
		if (is_file($p) || (function_exists('fileExistsCase') && fileExistsCase($p))) {
			return ['file' => $p, 'via' => 'public'];
		}
		return null;
	}

	/**
	 * 按控制器探测并锁定本请求应用磁盘根
	 *
	 * 成功后写入 APP_PATH_LOCKED / APP_FS_* / APP_PATH / APPNAME，供同应用 MVS 共用。
	 *
	 * @param string $app 逻辑名
	 * @param string $control 控制器短名
	 * @param bool $isAddon 是否扩展
	 * @return bool 是否锁定成功
	 * @static
	 */
	public static function lockByControl(string $app, string $control, bool $isAddon = false): bool
	{
		$hallmark = C('HALLMARK');
		$hallmark = ($hallmark !== null && $hallmark !== '') ? (string) $hallmark : null;
		$tenantId = Bootstrap::tenantId();

		$hit = self::probeByControl(
			$isAddon ? 'addon' : 'application',
			$app,
			$control,
			$hallmark,
			$tenantId
		);
		if ($hit === null) {
			return false;
		}

		C('APPNAME', $hit['logical']);
		C('APP_FS_REL', $hit['fsRel']);
		C('APP_FS_ROOT', $hit['root']);
		C('APP_PATH', $hit['root']);
		C('APP_PATH_LOCKED', true);
		C('APP_CONTROL_FILE', $hit['controlFile']);
		C('APP_CONTROL_VIA', $hit['via']);
		return true;
	}

	/**
	 * 当前请求已锁定且目标应用为当前 APPNAME 时返回锁定根
	 *
	 * @param string $app 目标应用逻辑名；空则用 APPNAME
	 * @return string|null 尾随 / 的绝对根
	 * @static
	 */
	public static function lockedRootFor(string $app = ''): ?string
	{
		if (C('APP_PATH_LOCKED') !== true) {
			return null;
		}
		$current = trim((string) C('APPNAME'));
		$app = trim($app !== '' ? $app : $current);
		if ($app === '' || $current === '' || strcasecmp($app, $current) !== 0) {
			return null;
		}
		$root = C('APP_FS_ROOT');
		if (!is_string($root) || $root === '') {
			$root = C('APP_PATH');
		}
		if (!is_string($root) || $root === '') {
			return null;
		}
		return rtrim(str_replace('\\', '/', $root), '/') . '/';
	}

	/**
	 * 清除本请求路径锁（Workerman / Swoole 请求末调用）
	 *
	 * @return void
	 * @static
	 */
	public static function clearRequestLock(): void
	{
		foreach (['APP_PATH_LOCKED', 'APP_FS_REL', 'APP_FS_ROOT', 'APP_CONTROL_FILE', 'APP_CONTROL_VIA'] as $key) {
			C($key, null);
		}
	}

	/**
	 * 解析 Application 下磁盘相对路径
	 *
	 * @param string $app 应用逻辑名
	 * @return string|null 如 Creator/Hub 或 Portal
	 * @static
	 */
	public static function resolveApplicationFsRel(string $app): ?string
	{
		$base = rtrim((string) C('CANDYROOT'), '/\\') . '/Application/';
		return self::probeFsRel($base, $app);
	}

	/**
	 * 解析 Application 绝对根（尾随 /）
	 *
	 * @param string $app 应用逻辑名
	 * @return string|null
	 * @static
	 */
	public static function resolveApplicationRoot(string $app): ?string
	{
		$rel = self::resolveApplicationFsRel($app);
		if ($rel === null) {
			return null;
		}
		return rtrim((string) C('CANDYROOT'), '/\\') . '/Application/' . $rel . '/';
	}

	/**
	 * 解析 Plugins 下磁盘相对路径
	 *
	 * @param string $name 插件逻辑名
	 * @return string|null
	 * @static
	 */
	public static function resolvePluginFsRel(string $name): ?string
	{
		$base = rtrim((string) C('PLUGPATH'), '/\\') . '/';
		if ($base === '/' || !is_dir($base)) {
			return null;
		}
		return self::probeFsRel($base, $name);
	}
}
