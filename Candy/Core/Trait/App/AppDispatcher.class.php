<?php
declare(strict_types=1);
namespace Candy\Core\Trait\App;

use Candy\Core\Container;
use Candy\Core\Debug;
use Candy\Core\Hook;
use Candy\Core\Request;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 控制器实例化与请求分发
 */
final class AppDispatcher
{
	public static function dispatch(): void
	{
		$inCoroutine = Request::inCoroutine();
		$route = I('get.', [], '');
		// Node 菜单可能写成 Demo_Item；还原为 DemoItem 以便加载类与模板目录
		$control = \Candy\Extend\Str::controlName((string) ($route['m'] ?? ''));
		if ($control === '') {
			$control = (string)(G('default.control') ?: 'Index');
			$route['m'] = $control;
		}
		if (!isset($route['a']) || $route['a'] === '' || $route['a'] === null) {
			$route['a'] = (string)(G('default.action') ?: 'index');
		}
		\C('CONTROL', $control);
		\C('ACTION', (string)$route['a']);
		if ($inCoroutine) {
			R()->mergeGet(['m' => $control, 'a' => $route['a']]);
		} else {
			$_GET['m'] = $control;
			$_GET['a'] = $route['a'];
		}

		// 控制器主导二次锁定（以最终 control 名为准；兼容路由改写 m）
		$appName = (string) C('APPNAME');
		$isAddonReq = !is_null(C('ISADDON'));
		if (!\Candy\Core\AppPathResolver::lockByControl($appName, $control, $isAddonReq)) {
			// 保持 RouteContextInit 已写入的 APP_PATH
		}

		$scfile = C('APP_PATH') . 'Controls/' . $control . '.class.php';

		// Hallmark 覆写优先：相对锁定根 {root}/Controls/Hallmark/{val}/X.class.php
		$hallmark = C('HALLMARK');
		$appRoot = \Candy\Core\Bootstrap::resolveAppRoot((string) C('APPNAME'));
		if ($hallmark !== null && $hallmark !== '') {
			$hmFile = $appRoot . 'Controls/Hallmark/' . $hallmark . '/' . $control . '.class.php';
			if (file_exists($hmFile)) {
				$scfile = $hmFile;
			}
		}

		// 再试 Tenant 覆写（相对同一锁定根）
		$tenantCtrl = \Candy\Core\Bootstrap::tenantOverlayFile(
			$appRoot,
			'Controls',
			$control . '.class.php'
		);
		if ($tenantCtrl !== null) {
			$scfile = $tenantCtrl;
		}
		$scfile = str_replace(C('CANDYROOT'), '/', $scfile);
		Debug::addMsg("当前控制器类的路径: <b>$scfile</b> 文件！", 6);

		if (isDebug()) {
			headers('Cache-Control: no-cache,must-revalidate');
			headers('Pragma: no-cache');
			headers("Expires: -1");
		}

		$tagsPath = C('APP_PATH') . 'tags.php';
		$tagConfig = is_file($tagsPath) ? include($tagsPath) : [];
		if (empty($tagConfig) === false) {
			Hook::import($tagConfig);
		}

		listen('app_begin');
		// 租户有 Controls 覆写时走 Tenant\{id}\…Action；Addon 走 Addon\{App}\…Action
		$actionType = in_array($control, ['Base', 'Common', 'CommonExtend'], true) ? 'common' : 'action';
		$runtimeClass = $actionType === 'common' ? $control : ($control . 'Action');
		$appName = (string) C('APPNAME');
		$fqcn = RuntimeClassLoader::resolveFqcn($actionType, $appName, $runtimeClass);
		// 兜底：扩展请求下若仍解析成 Control，强制改回 Addon（防止误判导致类库不存在）
		if ($fqcn !== '' && !is_null(C('ISADDON')) && str_starts_with($fqcn, 'APP\\Control\\')) {
			$fqcn = 'APP\\Addon\\' . substr($fqcn, strlen('APP\\Control\\'));
		}
		$className = $fqcn !== ''
			? ('\\' . $fqcn)
			: ('\\APP\\' . (is_null(C('ISADDON')) ? 'Control\\' : 'Addon\\') . $appName . '\\' . $control . 'Action');
		$className = class_exists($className) ? $className : '\\Candy\\Core\\Action';
		(Container::getInstance()->make($className, [], isDebug()))->working();
	}
}
