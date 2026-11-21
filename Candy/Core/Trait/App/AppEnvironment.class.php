<?php
declare(strict_types=1);
namespace Candy\Core\Trait\App;

use Candy\Core\Debug;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 应用环境路径与全局资源初始化
 */
final class AppEnvironment
{
	public static function loading(): void
	{
		$rootpath = preg_replace("/[\/\\\\]{1,}/", '/', getcwd() . '/');
		$GLOBALS['root'] = $lang_path = str_replace(C('CANDYROOT'), '/', $rootpath);
		$c_root = rtrim($GLOBALS['root'], '/');
		$c_addon = $c_root . '/Addons/';
		G(['ROOTPATH' => $rootpath, 'C_ROOT' => $c_root, 'C_ADDON' => $c_addon]);
		$candy_path = rtrim(str_replace(C('CANDYROOT'), '/', CANDYPATH . 'Candy/'), '/');

		Debug::addMsg('[<b>Candy</b>] 框架所在位置：' . $candy_path, 3);

		if (!isWebServer()) {
		$scriptname = ltrim((string)I('server.SCRIPT_NAME', '', ''), '/');
			Debug::addMsg('[<b>Main file</b>] 入口文件位置：' . $scriptname, 3);
		}

		$c_public = G('C_PUBLIC');
		if (is_null($c_public)) {
			$GLOBALS['public'] = $GLOBALS['root'] . 'Public/';
			$c_public = rtrim($GLOBALS['public'], '/');
			Debug::addMsg('[<b>Public</b>] 公共资源请求位置（默认）：' . $c_public, 3);
		} else {
			Debug::addMsg('[<b>Public</b>] 公共资源请求位置：' . $c_public, 3);
		}

		initTplPath();

		// 仅缓存「基路径」到 G（进程级）；有效路径按当前 TENANT_ID 注入，写入 C（请求级）防串租户
		$c_up_base = G('C_UP_PATH_BASE');
		if (is_null($c_up_base)) {
			$legacyUp = G('C_UP_PATH');
			$c_up_base = (is_string($legacyUp) && $legacyUp !== '')
				? $legacyUp
				: (C('CANDYROOT') . 'Public/uploads');
			// 若历史 G 误写了租户段，剥掉末尾 t/{id} 当作基路径
			$c_up_base = preg_replace('#/t/[A-Za-z0-9]{6}$#', '', rtrim(str_replace('\\', '/', (string) $c_up_base), '/')) ?: $c_up_base;
			Debug::addMsg('[<b>Upload path</b>] 上传基路径：' . str_replace(C('CANDYROOT'), '/', $c_up_base), 3);
		} else {
			Debug::addMsg('[<b>Upload path</b>] 上传基路径（已配置）：' . str_replace(C('CANDYROOT'), '/', $c_up_base), 3);
		}
		$c_up_path = rtrim(\Candy\Core\Bootstrap::resolveTenantDataPath((string) $c_up_base), '/');
		Debug::addMsg('[<b>Upload path</b>] 当前请求上传目录：' . str_replace(C('CANDYROOT'), '/', $c_up_path), 3);

		$c_upcw_base = G('C_UPCW_PATH_BASE');
		if (is_null($c_upcw_base)) {
			$legacyCw = G('C_UPCW_PATH');
			$c_upcw_base = (is_string($legacyCw) && $legacyCw !== '')
				? $legacyCw
				: (C('CANDYROOT') . 'Public/thumbs');
			$c_upcw_base = preg_replace('#/t/[A-Za-z0-9]{6}$#', '', rtrim(str_replace('\\', '/', (string) $c_upcw_base), '/')) ?: $c_upcw_base;
			Debug::addMsg('[<b>Thumb path</b>] 缩略图基路径：' . str_replace(C('CANDYROOT'), '/', $c_upcw_base), 3);
		} else {
			Debug::addMsg('[<b>Thumb path</b>] 缩略图基路径（已配置）：' . str_replace(C('CANDYROOT'), '/', $c_upcw_base), 3);
		}
		$c_upcw_path = rtrim(\Candy\Core\Bootstrap::resolveTenantDataPath((string) $c_upcw_base), '/');
		Debug::addMsg('[<b>Thumb path</b>] 当前请求缩略图目录：' . str_replace(C('CANDYROOT'), '/', $c_upcw_path), 3);

		if (defined('C_RUN_PATH')) {
			Debug::addMsg('[<b>Runtime</b>] 运行时生成的缓存runtime目录位置：' . C('C_RUN_PATH'), 3);
		} else {
			Debug::addMsg('[<b>Runtime</b>] 运行时生成的缓存runtime目录位置(默认)：' . str_replace(C('CANDYROOT'), '/', C('RUNTIME')), 3);
		}

		// 基路径进 G；有效路径进 C（每请求随 TENANT_ID 变化）
		G(['C_PUBLIC' => $c_public, 'C_UP_PATH_BASE' => $c_up_base, 'C_UPCW_PATH_BASE' => $c_upcw_base]);
		C([
			'C_UP_PATH' => $c_up_path,
			'C_UPCW_PATH' => $c_upcw_path,
		]);
	}
}
