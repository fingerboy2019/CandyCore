<?php
declare(strict_types=1);
namespace Candy\Core\Trait\Route;

use Candy\Core\Addon;
use Candy\Core\AppPathResolver;
use Candy\Core\Container;
use Candy\Core\Request;
use Candy\Core\Respond;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 请求上下文初始化：XSS 过滤、应用/插件解析、路由配置合并
 *
 * 路由配置层级：全局 Route.inc.php（兜底）→ 窥探到的 {App}Route.inc.php（覆盖/叠加）→
 * 应用名确定后再次合并（与窥探一致，保证 C() 最终为当前应用口径）。
 */
final class RouteContextInit
{
	public static function init(): void
	{
		$pathSeparatorInfo = [];
		$inCoroutine = Request::inCoroutine();
		$server = self::requestServer();
		$get = self::requestGet();
		$post = self::requestPost();

		$filterParams = function (&$arr) use (&$_RAW, &$filterParams) {
			foreach ($arr as $k => $v) {
				if (is_array($v)) {
					$filterParams($v);
				} elseif (is_string($v)) {
					// 只对字符串做 trim + XSS 转义
					$_RAW[$k] = $v;
					$arr[$k] = htmlspecialchars(trim($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				} else {
					// int / float / bool / null 原样保留：
					// PHP 8 起 trim() 只接受 string（strict_types 下 int 直接 TypeError），
					// 之前对 JSON 里的 id / project_id 这类数字一律 trim，导致整请求 500/空响应。
					$_RAW[$k] = $v;
				}
			}
		};
		$filterParams($get);
		$filterParams($post);
		if ($inCoroutine) {
			R()->replaceGet($get);
			R()->replacePost($post);
		} else {
			$_GET = $get;
			$_POST = $post;
		}

		// Route::rule 注册的回调（如 /Apidoc/config）须优先于域名绑 Home/Portal 后的 MVC 解析
		// 否则 C('APP')=Home 时会把 Apidoc 当成控制器名，永远进不了文档站接口
		self::dispatchRegisteredHttpRoute($server);

		// 全局路由配置始终加载（兜底，含 API_URL）；无 PATH_INFO 时跳过路径改写
		$globRoute = C('INCPATH') . 'Route.inc.php';
		$globConfig = is_file($globRoute) ? include($globRoute) : [];

		if (empty($server['PATH_INFO'])) {
			// 仅保留配置，不做 PATH 解析
		} else {
			if (isset($globConfig['URL_DEPR']) && $globConfig['URL_DEPR'] == '.') {
				showExceptionMsg('halt', '<b>.</b>符号不能做分隔符。');
			}

			$protectSeparator = isset($globConfig['PROTECT_SEP']) ? explode(',', $globConfig['PROTECT_SEP']) : [];
			$pathSeparatorInfo = self::detectPathSeparator((string)$server['PATH_INFO'], $protectSeparator);

			// 路由匹配前先窥探应用并合并 {App}Route，使后缀/规则按应用生效、互不冲掉
			$peekApp = self::peekApplicationName((string)$server['PATH_INFO'], $pathSeparatorInfo);
			if ($peekApp !== null) {
				$globConfig = self::mergeApplicationRouteConfig($globConfig, $peekApp, false);
			}
			// DomainRouteMiddleware（Before）可能已按 Host 绑定 APP（如 portal→Portal、
			// www→Home）。CMS 路径首段不是应用名，窥探为 null；若不再按绑定应用合并，
			// Home 的 :mark/:filename 只能放全局，又会误伤 Portal 的 Control/Action。
			$boundApp = trim((string)C('APP', ''));
			if ($boundApp !== '' && strcasecmp($boundApp, (string)$peekApp) !== 0) {
				$globConfig = self::mergeApplicationRouteConfig($globConfig, $boundApp, false);
			}

			if (empty($globConfig)) {
			} else {
				if (!empty($globConfig['URL_ROUTER_ON'])) {
					if (isset($globConfig['URL_DEPR'])) {
						$pathinfo = str_replace($globConfig['URL_DEPR'], '/', (string)$server['PATH_INFO']);
					} else {
						if (empty($pathSeparatorInfo)) {
							$pathinfo = (string)$server['PATH_INFO'];
						} else {
							$pathinfo = $pathSeparatorInfo['separator'] != '/' ? str_replace($pathSeparatorInfo['separator'], '/', (string)$server['PATH_INFO']) : (string)$server['PATH_INFO'];
						}
					}
					$suffix = '';
					if (stripos($pathinfo, '.') != false) {
						[$pathinfo, $suffix] = explode('.', $pathinfo, 2);
					}
					// PATH_INFO 常带前导 /（如 /sitemap）；不剥除会导致 sitemap$ 等精确规则失配，
					// 进而被 :mark/:filename 等双段规则误匹配（mark 为空串）。
					$pathinfo = ltrim(str_replace('\\', '/', (string)$pathinfo), '/');

					// 入站带 api/v{n}/ 前缀时标记 Api 模式，供 U() 出站自动回加前缀/专用域名
					$pathForApiMode = $pathinfo;
					if (preg_match('#^api/v\d+(/|$)#i', $pathForApiMode)) {
						$globConfig['API_URL_MODE'] = true;
					}

					$routed = RouteMatcher::contrastRoute($pathinfo, $globConfig);
					// 已改写则锁定，禁止 UrlParser 二次 contrastRoute（否则 Seo/sitemap
					// 会被 :mark/:filename 误收成 Cms/resolve）。
					if ($routed !== $pathinfo) {
						C('PATH_INFO_ROUTE_LOCKED', true);
					}
					// 拼回页面与 API 后缀。
					// 已改写为「控制器/操作」两段且无附加参数时禁止拼回，避免带后缀再次误匹配。
					$restoreSuffix = self::shouldRestorePathSuffix($suffix, $globConfig);
					if (
						$restoreSuffix
						&& $routed !== $pathinfo
						&& substr_count($routed, '/') === 1
					) {
						$restoreSuffix = false;
						if ($suffix !== '') {
							// PATH_INFO 不再带后缀时，留给 API 模式等后续识别
							C('PATH_INFO_ORIG_SUFFIX', $suffix);
						}
					}
					if ($restoreSuffix) {
						$server['PATH_INFO'] = $routed . '.' . $suffix;
					} else {
						$server['PATH_INFO'] = $routed;
					}
					if (isset($globConfig['URL_DEPR']) || (isset($pathSeparatorInfo['separator']) && $pathSeparatorInfo['separator'] != '/')) {
						$server['PATH_INFO'] = isset($globConfig['URL_DEPR']) ? str_replace('/', $globConfig['URL_DEPR'], (string)$server['PATH_INFO']) : str_replace('/', $pathSeparatorInfo['separator'], (string)$server['PATH_INFO']);
					}
				}
			}
		}

		// PATH 路由改写完成后再一次性提交，后续应用/域名判定仍可按原顺序执行。
		if ($inCoroutine) {
			R()->replaceServer($server);
		} else {
			$_SERVER = $server;
		}

		if (C('APP')) {
			$appName = C('APP');
			// 路由前由域名中间件绑定应用时，同样要准备应用目录标识；
			// 否则语言包会错误落到 Application//Languages。
			$tmpPath = \Candy\Extend\Str::snake(ucfirst($appName), 'upper', '/');
			if (empty($pathSeparatorInfo)) {
				$className = isset($get['m']) ? trim($get['m']) : G('default.control', '', 'Index');
			} else {
				[$className] = explode($pathSeparatorInfo['separator'], (string)$server['PATH_INFO'], 2);
			}
			$defaultPath = C('CANDYROOT') . 'Application/' . $appName . '/Controls/' . $className . '.class.php';
			$defaultExtendPath = C('PLUGPATH') . C('RAPPNAME') . $defaultAppName . '/Controls/' . $className . '.class.php';
			if (!is_file($defaultPath) && !is_file($defaultExtendPath)) {
				$isAddon = Addon::checkAddon($className);
				if ($isAddon) {
					C('ISADDON', true);
					self::guardAddonTenantAccess($className);
					if (!empty($pathSeparatorInfo)) {
						if ($pathSeparatorInfo['separator'] == '.') {
							$server['PATH_INFO'] = '';
						} else {
							$pathInfoTrimmed = rtrim((string)$server['PATH_INFO'], $pathSeparatorInfo['separator']);
							$parts = explode($pathSeparatorInfo['separator'], $pathInfoTrimmed, 2);
							// 保留磁盘真实目录名（CreatorHub）；禁止 ucfirst(strtolower) 破坏多驼峰
							$rawAddon = (string)($parts[0] ?? '');
							$appName = Addon::resolveDirName($rawAddon) ?? $rawAddon;
							$server['PATH_INFO'] = $parts[1] ?? '';
						}
					}
				}
			}
		} else {
			$globalBindings = G('bindDomainList') ?: [];
			$bindDomainList = T('bindDomainList', '', is_array($globalBindings) ? $globalBindings : []);
			$bindAppList = empty($bindDomainList) ? [] : array_flip($bindDomainList);
			empty($bindAppList) OR T('bindAppList', $bindAppList);
			if (!empty($bindDomainList) && in_array($server['HTTP_HOST'] ?? '', $bindDomainList)) {
				$boundApp = $bindAppList[$server['HTTP_HOST']];
				// 默认站点域名仍可用 /Admin/、/Community/ 等显式应用路径访问。
				// C('APP') 的平台/租户中间件绑定已在上方优先处理，不受此分支影响。
				// 默认站点的显式应用路径必须优先于 Host 的默认 Home 绑定。
				// 这里不能使用 peekApplicationName()，其会先返回当前 Host 的绑定应用，
				// 从而把 /Admin/ 错误解释为 Home/Admin。
				$pathApp = self::peekPathApplicationName($server['PATH_INFO'] ?? '', $pathSeparatorInfo);
				if ($pathApp === null || strcasecmp($pathApp, (string) $boundApp) === 0) {
					$appName = $boundApp;
				}
			}

			$s = '';
			if (empty($appName)) {
				if (empty($pathSeparatorInfo)) {
					// /Admin/ 这类仅含应用名的根路径没有可检测的分隔符；
					// 若首段是实际应用目录，须先识别为应用而非默认 Home 的控制器。
					$peekApp = self::peekApplicationName((string) ($server['PATH_INFO'] ?? ''), ['separator' => '/']);
					if ($peekApp !== null) {
						$appName = $peekApp;
						$server['PATH_INFO'] = '';
					} else {
						$appName = isset($get['g']) ? trim($get['g']) : G('default.app', '', 'Home');
					}
				} else {
					if ($pathSeparatorInfo['separator'] == '.') {
						[$appName, $s] = explode('.', (string)$server['PATH_INFO'], 2);
						$server['PATH_INFO'] = '';
					} else {
						$pathInfoTrimmed = rtrim((string)$server['PATH_INFO'], $pathSeparatorInfo['separator']);
						$parts = explode($pathSeparatorInfo['separator'], $pathInfoTrimmed, 2);
						$appName = $parts[0];
						$server['PATH_INFO'] = $parts[1] ?? '';
					}
				}
			}

			if (!is_null(G('default.reset')) && $appName == 'Home') {
				$appName = G('default.reset');
			}

			$appName = ucfirst($appName);
			$tmpPath = \Candy\Extend\Str::snake(ucfirst($appName), 'upper', '/');
		}

		// 未被中间件显式绑定时，URL 首段的真实应用目录优先于默认域名绑定。
		// 例如 www.candy.com/Admin/ 应进入 Admin，而不是 Home/Admin。
		if (C('APP') === null || C('APP') === '') {
			$rawPath = ltrim((string) ($server['PATH_INFO'] ?? ''), '/');
			$pathParts = explode('/', $rawPath, 2);
			$pathApp = ucfirst((string) ($pathParts[0] ?? ''));
			if ($pathApp !== '' && is_dir(C('CANDYROOT') . 'Application/' . $pathApp . '/')) {
				$appName = $pathApp;
				$tmpPath = \Candy\Extend\Str::snake($appName, 'upper', '/');
				if (strcasecmp((string) ($pathParts[0] ?? ''), $pathApp) === 0) {
					$server['PATH_INFO'] = $pathParts[1] ?? '';
				}
			}
		}

		$appPath = C('CANDYROOT') . 'Application/' . $tmpPath . '/';

		if (is_dir($appPath) === false) {
			// 拆段路径未命中时，按框架约定再探扁平 / 大小写不敏感布局
			$probedApp = AppPathResolver::resolveApplicationFsRel($appName);
			if ($probedApp !== null) {
				$appPath = C('CANDYROOT') . 'Application/' . $probedApp . '/';
			}
		}

		if (is_dir($appPath) === false) {
			$changDefault = true;

			if (empty($pathSeparatorInfo)) {
				$className = isset($get['m']) ? trim($get['m']) : G('default.control', '', 'Index');
				$pathSeparatorInfo['separator'] = '/';
			} else {
				if (stripos((string)$server['PATH_INFO'], $pathSeparatorInfo['separator']) != false) {
					[, $className] = explode($pathSeparatorInfo['separator'], (string)$server['PATH_INFO'], 2);
				} else {
					$className = isset($get['m']) ? trim($get['m']) : G('default.control', '', 'Index');
				}
			}

		if (is_dir(C('CANDYROOT') . 'Application/Home/' . $appName)) {
				$appPath = C('CANDYROOT') . 'Application/Home/' . $appName . '/';
				$tmpPath = 'Home/' . $appName;
				$appName = 'Home' . $appName;
			} else {
				$appExtendPath = C('PLUGPATH') . $appName . '/';
				if (is_dir($appExtendPath)) {
					$extendPath = $appExtendPath . 'Controls/' . $className . '.class.php';
					if (is_file($extendPath)) {
						$appPath = $appExtendPath;
						$changDefault = false;
					}
				} else {
					$addonName = empty($tmpPath) ? $appName : explode('/', $tmpPath)[0];
					$resolvedAddon = Addon::resolveDirName((string)$addonName);
					$isAddon = $resolvedAddon !== null && Addon::checkAddon($resolvedAddon);
					if ($isAddon) {
						C('ISADDON', true);
						$appName = $resolvedAddon;
						self::guardAddonTenantAccess($resolvedAddon);
						$changDefault = false;
					}
				}

				if ($changDefault) {
					[$group, $rule] = RouteRegistry::parseRoute((string)($server['REQUEST_URI'] ?? '/'));
					$route = RouteRegistry::getRule($rule, $group, strtolower((string)($server['REQUEST_METHOD'] ?? 'GET')));

							if (empty($route)) {
						$defaultAppName = 'Home';
						is_null(G('default.reset')) OR $defaultAppName = G('default.reset');
						$defaultPath = C('CANDYROOT') . 'Application/' . $defaultAppName . '/Controls/' . $className . '.class.php';
						$defaultExtendPath = C('PLUGPATH') . C('RAPPNAME') . $defaultAppName . '/Controls/' . $className . '.class.php';
						if ((is_file($defaultPath) || !empty($defaultExtendPath)) && !(isDebug() && (isset($get['add']) && in_array($get['add'], ['', 'addon', 'plug'])))) {
							if (is_null(C('APP'))) {
								$server['PATH_INFO'] = $appName . $pathSeparatorInfo['separator'] . (empty($server['PATH_INFO']) ? $s : $server['PATH_INFO']);
							}
							$appName = $defaultAppName;
							$appPath = C('CANDYROOT') . 'Application/' . $appName . '/';
						}
					} else {
						if (stripos($route['callback'], '::')) {
							$call = $route['callback'];
						} else {
							$call = explode('@', $route['callback'], 2);
						}
						$res = Container::getInstance()->invoke($call, []);
						$type = (is_null(json_decode($res)) && json_last_error() === JSON_ERROR_NONE) ? 'html' : 'json';
						if ($type == 'json') {
							debug(0);
						}
						Respond::create($res, $type, 200)->send();
						stop();
					}
				}
			}
		}

		// HTTP Host 在非标准端口（如 Swoole :8008）会携带端口；域名绑定表
		// 使用规范化主机名。比较前统一剥离端口，避免同一域名被误判为跨域。
		$currentHost = strtolower((string) preg_replace('/:\\d+$/', '', (string) ($server['HTTP_HOST'] ?? '')));
		$boundHost = strtolower((string) ($bindDomainList[$appName] ?? ''));
            if ($appName !== 'Admin' && $boundHost !== '' && $boundHost !== $currentHost) {
			C('S404', '请使用正确的域名访问.');
		}

		$get['g'] = $appName;
		// 应用、域名与路由参数已全部确定，统一提交最终请求状态。
		if ($inCoroutine) {
			R()->replaceServer($server);
			R()->replaceGet($get);
		} else {
			$_SERVER = $server;
			$_GET = $get;
		}
		$AppName = preg_replace('/(.)(?=[A-Z])/u', '$1/', $appName);
		C(['APPNAME' => $appName, 'TMPPATH' => $AppName, 'LAPPNAME' => preg_replace('/(.)(?=[A-Z])/u', '$1\\', $appName), 'RAPPNAME' => $AppName]);

		// 控制器主导：尽早按 Controls（含 Hallmark/Tenant）锁定本请求磁盘根
		$controlForLock = \Candy\Extend\Str::controlName((string) ($get['m'] ?? ''));
		if ($controlForLock === '') {
			$controlForLock = (string) (G('default.control') ?: 'Index');
		}
		$isAddonReq = !is_null(C('ISADDON'));
		if (!AppPathResolver::lockByControl($appName, $controlForLock, $isAddonReq)) {
			// 无控制器文件时回退根探测（兼容缺省页 / CLI 过渡）
			if ($isAddonReq) {
				$addonRoot = Addon::resolveRoot((string) C('APPNAME'));
				$appPath = $addonRoot ?? (C('CANDYROOT') . 'Addons/' . C('APPNAME') . '/');
			}
			// Application：$appPath 已在上文按 tmpPath/探测赋值
		} else {
			$appPath = (string) C('APP_PATH');
			$appName = (string) C('APPNAME');
		}

		if (file_exists($appPath . 'function.php')) {
			requireCache($appPath . 'function.php');
		}

		C('APP_PATH', $appPath);

		if (function_exists('setUrlParserConfig')) {
			$configs = setUrlParserConfig();
			if (is_array($configs)) {
				$globConfig = array_merge($globConfig, $configs);
			}
		}

		if (is_null(C('ISADDON'))) {
			$globConfig = self::mergeApplicationRouteConfig($globConfig, $appName, false);
		} else {
			$globConfig = self::mergeApplicationRouteConfig($globConfig, $appName, true);
		}

		C($globConfig);

		// 允许 sitemap.xml / robots.txt / *.json / *.shtml 等非当前 HTML 后缀（否则假「URL不规范」）
		$extraSuffix = ['xml', 'txt', 'json', 'shtml', 'html'];
		if (!empty($s) && C('URL_HTML_SUFFIX') != '.' . $s
			&& !in_array(strtolower((string)$s), $extraSuffix, true)
		) {
			is_null(C('S404')) && C('S404', 'URL不规范,请检查URL格式.');
		}

		// RouteContextInit 是应用识别与 g/m/a 路由参数最终落点。完成后再同步 R()，
		// 使后续 UrlParser / Action 能读取最终应用，而不是 onRequest 的初始 Home 快照。
		if (Request::inCoroutine()) {
			Request::refresh();
		}
	}

	/**
	 * 窥探当前请求所属应用名（供路由匹配前合并应用 Route）
	 *
	 * 优先级：C('APP') 绑定 → 域名绑定 → PATH 首段且存在 Application/{App}
	 * 剥掉 api/v{n}/ 后再取首段；首段不是应用目录时返回 null（沿用全局兜底）
	 *
	 * @param string $pathInfo 原始 PATH_INFO
	 * @param array{separator?: string, position?: int} $pathSeparatorInfo 分隔符信息
	 * @return string|null 应用短名（如 Admin），无法判定时 null
	 * @access private
	 * @static
	 */
	private static function peekApplicationName(string $pathInfo, array $pathSeparatorInfo): ?string
	{
		if (!is_null(C('APP')) && C('APP') !== '') {
			return ucfirst((string)C('APP'));
		}

		$globalBindings = G('bindDomainList') ?: [];
		$bindDomainList = T('bindDomainList', '', is_array($globalBindings) ? $globalBindings : []);
		$host = (string)(self::requestServer()['HTTP_HOST'] ?? '');
		if ($host !== '' && is_array($bindDomainList) && in_array($host, $bindDomainList, true)) {
			$bindAppList = array_flip($bindDomainList);
			if (!empty($bindAppList[$host])) {
				return ucfirst((string)$bindAppList[$host]);
			}
		}

		$separator = $pathSeparatorInfo['separator'] ?? '/';
		$path = str_replace('\\', '/', $pathInfo);
		if ($separator !== '/' && $separator !== '') {
			$path = str_replace($separator, '/', $path);
		}
		$path = ltrim($path, '/');
		if ($path === '') {
			return null;
		}
		if (stripos($path, '.') !== false) {
			$path = explode('.', $path, 2)[0];
		}
		if (preg_match('#^api/v\d+/(.*)$#i', $path, $m)) {
			$path = $m[1];
		}
		$segment = explode('/', $path, 2)[0] ?? '';
		$segment = ucfirst($segment);
		if ($segment === '') {
			return null;
		}

		$appPath = C('CANDYROOT') . 'Application/' . $segment . '/';
		if (is_dir($appPath)) {
			return $segment;
		}

		return null;
	}

	/**
	 * 仅从请求路径识别应用目录，不读取 Host 绑定。
	 *
	 * 用于默认站点兼容 /Admin/、/Community/ 等显式应用入口；
	 * 避免 www.candy.com 的 Home 绑定覆盖 URL 中已指定的应用。
	 *
	 * @param string $pathInfo 原始 PATH_INFO
	 * @param array{separator?: string, position?: int} $pathSeparatorInfo 分隔符信息
	 * @return string|null 应用短名
	 * @access private
	 * @static
	 */
	private static function peekPathApplicationName(string $pathInfo, array $pathSeparatorInfo): ?string
	{
		$separator = $pathSeparatorInfo['separator'] ?? '/';
		$path = str_replace('\\', '/', $pathInfo);
		if ($separator !== '/' && $separator !== '') {
			$path = str_replace($separator, '/', $path);
		}
		$path = ltrim($path, '/');
		if ($path === '') {
			return null;
		}
		if (stripos($path, '.') !== false) {
			$path = explode('.', $path, 2)[0];
		}
		if (preg_match('#^api/v\d+/(.*)$#i', $path, $matches)) {
			$path = $matches[1];
		}
		$segment = ucfirst((string)(explode('/', $path, 2)[0] ?? ''));
		return $segment !== '' && is_dir(C('CANDYROOT') . 'Application/' . $segment . '/')
			? $segment
			: null;
	}

	/**
	 * 合并应用（或 Addon）路由配置到全局兜底配置
	 *
	 * 标量项（如 URL_HTML_SUFFIX）由应用覆盖；URL_MAP_RULES / URL_ROUTE_RULES 为数组合并叠加
	 *
	 * @param array $globConfig 当前全局配置
	 * @param string $appName 应用或 Addon 名
	 * @param bool $isAddon 是否 Addon 目录下的 route.php
	 * @return array 合并后配置
	 * @access private
	 * @static
	 */
	private static function mergeApplicationRouteConfig(array $globConfig, string $appName, bool $isAddon): array
	{
		if ($isAddon) {
			$configfile = C('CANDYROOT') . 'Addons/' . $appName . '/route.php';
		} else {
			$configfile = C('INCPATH') . $appName . 'Route.inc.php';
		}

		if (!is_file($configfile)) {
			return $globConfig;
		}

		$appConfig = include($configfile);
		if (!is_array($appConfig)) {
			return $globConfig;
		}

		if (isset($appConfig['URL_MAP_RULES']) && is_array($appConfig['URL_MAP_RULES'])) {
			isset($globConfig['URL_MAP_RULES']) OR $globConfig['URL_MAP_RULES'] = [];
			$globConfig['URL_MAP_RULES'] = array_merge($globConfig['URL_MAP_RULES'], $appConfig['URL_MAP_RULES']);
			unset($appConfig['URL_MAP_RULES']);
		}
		if (isset($appConfig['URL_ROUTE_RULES']) && is_array($appConfig['URL_ROUTE_RULES'])) {
			isset($globConfig['URL_ROUTE_RULES']) OR $globConfig['URL_ROUTE_RULES'] = [];
			$globConfig['URL_ROUTE_RULES'] = array_merge($globConfig['URL_ROUTE_RULES'], $appConfig['URL_ROUTE_RULES']);
			unset($appConfig['URL_ROUTE_RULES']);
		}

		return array_merge($globConfig, $appConfig);
	}

	/**
	 * 路由改写后是否把原后缀拼回 PATH_INFO
	 *
	 * 注意：调用方还须排除「已改写为两段 MVC」场景，否则会触发 UrlParser 二次误匹配。
	 *
	 * @param string $suffix 剥下的后缀（无点）
	 * @param array $globConfig 已合并应用后的配置
	 * @return bool
	 * @access private
	 * @static
	 */
	private static function shouldRestorePathSuffix(string $suffix, array $globConfig): bool
	{
		if ($suffix === '') {
			return false;
		}
		$suffixLower = strtolower($suffix);
		// 接口与常见页面后缀：避免仅认全局 .html 时把 Admin .shtml / .json 丢掉
		if (in_array($suffixLower, ['json', 'xml', 'html', 'shtml'], true)) {
			return true;
		}
		$htmlSuffix = ltrim((string)($globConfig['URL_HTML_SUFFIX'] ?? C('URL_HTML_SUFFIX') ?? '.html'), '.');
		return $suffixLower === strtolower($htmlSuffix);
	}

	/**
	 * Detect Path Separator
	 * @param mixed $str
	 * @param mixed $protectSeparator
	 * @return array
	 */
	private static function detectPathSeparator(string $str = '', array $protectSeparator = []): array
	{
		if (empty($str)) {
			return [];
		}
		$i = 0;
		while (!empty($str) && (ctype_alnum($str[0]) || in_array($str[0], $protectSeparator))) {
			$str = substr($str, 1);
			$i++;
		}

		if (empty($str)) {
			return [];
		}

		return ['separator' => $str[0], 'position' => $i];
	}

	/**
	 * 命中 Route::rule 注册回调时直接派发并结束请求
	 *
	 * 用于 /Apidoc/* 等非 Application 目录入口：域名已绑 Home/Portal 时
	 * MVC 解析不会再进入「应用目录不存在 → 查注册路由」分支，须在此抢先匹配。
	 *
	 * @param array<string,mixed> $server 当前 SERVER 快照
	 * @return void 命中时 send + stop，不返回
	 * @access private
	 * @static
	 */
	private static function dispatchRegisteredHttpRoute(array $server): void
	{
		$uri = (string)($server['REQUEST_URI'] ?? '');
		if ($uri === '') {
			return;
		}
		[$group, $rule] = RouteRegistry::parseRoute($uri);
		if ($group === '' || $rule === '') {
			return;
		}
		$method = strtolower((string)($server['REQUEST_METHOD'] ?? 'GET'));
		$route = RouteRegistry::getRule($rule, $group, $method);
		if (empty($route['callback']) || !is_string($route['callback'])) {
			return;
		}

		if (stripos($route['callback'], '::') !== false) {
			$call = $route['callback'];
		} else {
			$call = explode('@', $route['callback'], 2);
		}

		// sys/* 统一过 IP 防护：**任何模式都拦**（项目路径仍由 Bootstrap::security 按 isDebug 决定）
		if ($group === 'sys' && class_exists('\\Candy\\Extend\\Tools\\Endpoint\\Guard')) {
			\Candy\Extend\Tools\Endpoint\Guard::ipGuard('sys/' . $rule);
		}

		$res = Container::getInstance()->invoke($call, []);
		// 合法 JSON 才按 json 输出，其余按 html —— 原判断写反了（只有返回字面量 "null" 才判 html），
		// 导致端点返回 HTML 时也会被标成 application/json。
		if (is_string($res) && $res !== '') {
			json_decode($res);
			$type = (json_last_error() === JSON_ERROR_NONE) ? 'json' : 'html';
		} else {
			$type = 'html';
		}
		if ($type === 'json') {
			debug(0);
		}
		Respond::create($res, $type, 200)->send();
		stop();
	}

	/**
	 * 获取当前请求 SERVER 快照
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestServer(): array
	{
		return I('server.', [], '');
	}

	/**
	 * 获取当前请求 GET 快照
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestGet(): array
	{
		return I('get.', [], '');
	}

	/**
	 * 获取当前请求 POST 快照
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function requestPost(): array
	{
		return I('post.', [], '');
	}

	/**
	 * Addon 租户授权门禁（应用市场）
	 *
	 * 规则（R3/R5/R7 修正）：
	 * - 业务平台租户（含 000000）一视同仁校验 sa_tenant_app_license：未购买/停用/过期 → 403；
	 * - **总后台上下文（无租户）**：不拦截（管理安装有权访问），且把默认库切到「后台应用独立库」，
	 *   使 Addon 的建表/数据写入独立测试库，上架前测试不污染主库与真实租户库；
	 * - 市场未启用或异常时放行，避免误伤（授权权威在 StoreLicense）。
	 *
	 * @param string $appName Addon 代码（目录名）
	 * @return void
	 * @access private
	 * @static
	 */
	private static function guardAddonTenantAccess(string $appName): void
	{
		$tenantId = \Candy\Core\Bootstrap::tenantId();
		if ($tenantId === null || $tenantId === '') {
			// 总后台上下文：放行 + 默认库切到后台应用独立库（上架前测试，防数据污染）
			self::routeAddonDefaultDbToTest();
			return;
		}
		try {
			if (!class_exists('\\APP\\Service\\Admin\\StoreLicense')) {
				return; // 市场服务未部署：放行
			}
			$res = (new \APP\Service\Admin\StoreLicense())->check($tenantId, $appName);
			if (empty($res['ok'])) {
				http_response_code(403);
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode([
					'code' => 403,
					'message' => '应用未授权：' . (string)($res['reason'] ?? 'not_licensed'),
				], JSON_UNESCAPED_UNICODE);
				exit;
			}
		} catch (\Throwable) {
			// 市场表未迁移 / 租户库不可用等异常：放行，避免误伤正常访问
		}
	}

	/**
	 * 总后台访问 Addon 时，把请求级默认库切到「后台应用独立库」
	 *
	 * 覆盖 DBLIST['default']（M() 无第二参时经 peekDefaultBundle 命中），
	 * Addon 的 Model 查询自动落到独立测试库；未配置 test_db 时不改动。
	 *
	 * @return void
	 * @access private
	 * @static
	 */
	private static function routeAddonDefaultDbToTest(): void
	{
		try {
			$store = (array)loadConfig('Store');
			$test = is_array($store['test_db'] ?? null) ? $store['test_db'] : [];
			if (empty($test['enabled']) || empty($test['DBNAME'])) {
				return;
			}
			$test['DRIVER'] = (string)($test['DRIVER'] ?? 'pdopgsql');
			$test['TABPREFIX'] = (string)($test['TABPREFIX'] ?? 'sa_');
			$list = G('DBLIST');
			if (!is_array($list)) {
				$list = C('DBLIST');
			}
			if (!is_array($list)) {
				$list = [];
			}
			$list['default'] = $test;
			G('DBLIST', $list);
			C('DBLIST', $list);
		} catch (\Throwable) {
			// 配置缺失：保持默认库
		}
	}
}
