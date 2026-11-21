<?php
/**
 * Candy框架 应用启动管道
 *
 * 可扩展的五个阶段，按序完成请求生命周期初始化
 *
 * boot → security → route → dispatch → respond
 *
 * WebSocket 入口：wsRun() 规范化消息后执行同一管道
 * route 阶段在 App::loading 前解析可选租户钩子 getTenantID()
 * 非 HTTP（CLI / Process Worker / MCP）：bootRuntime() 注入 Config.inc 与应用路径
 *
 * @package Candy\Core
 * @author 刘森 (fingerboy@qq.com)
	 * @date 2026-08-04 17:41:26
	 * @final 禁止被继承
	 */

declare(strict_types=1);

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Bootstrap
{
	/**
	 * 是否文档中心模式
	 * @var bool
	 */
	private static bool $docType = false;

	/**
	 * 执行完整启动管道
	 *
	 * @param bool $docType 是否文档中心模式（Doc/index.php）
	 * @return void
	 * @static
	 * @access public
	 */
	public static function run(bool $docType = false): void
	{
		self::$docType = $docType;

		// 常驻运行时（Workerman / Swoole）进程复用，全局请求体缓存必须每请求重置：
		// 否则 I('put') / I('patch') 会沿用上一个请求的 body（表现为「提交了但参数是上一次的」），
		// 且 $_RAW 会累积上一请求的原始参数。
		$GLOBALS['_PUT'] = null;
		$GLOBALS['_PATCH'] = null;
		$GLOBALS['_RAW'] = [];

		self::boot();
		self::security();
		self::route();
		self::dispatch();
		self::respond();
	}

	/**
	 * 阶段一：环境与基础引导
	 *
	 * PHP 版本、调试、配置/数据库/缓存驱动加载、全局函数库
	 *
	 * @return void
	 * @static
	 * @access public
	 */
	public static function boot(): void
	{
		// 长驻进程下先清请求级租户，避免 route 前误读上一请求的 TENANT_ID
		C('TENANT_ID', null);

		if (version_compare(PHP_VERSION, '8.4', '<')) {
			showExceptionMsg('shutdown', 'PHP版本不能低于8.4', '', '', true);
		}

		// 运行时标识（开发/调试）：配置驱动，不再写死在 Source/Inc/Init.inc.php
		self::applyRuntimeFlags();

		RuntimeGuard::verify();

		C('MARKDOWNREAD', null);
		if (self::$docType) {
			C('MARKDOWNREAD', true);
			Build::start();
		}

		Debug::start();
		self::loadDbConfig();

		if (!Cache::isFileDriver()) {
			listen('cache_loading');
		}

		if (defined('INIT') === false) {
			$funcList = glob(C('SOURCEPATH') . 'Func/*.func.php');
			foreach ($funcList as $funcPath) {
				isDebug() ? includeCache($funcPath) : requireCache($funcPath);
			}
			define('INIT', true);
		}

		Service::register();
	}

	/**
	 * 阶段二：安全与 Session
	 *
	 * 中间件注册、IP 黑名单、Init 中间件、Session 初始化、Before 中间件
	 *
	 * @return void
	 * @static
	 * @access public
	 */
	public static function security(): void
	{
		Security::run();

		C('startMemory', memory_get_usage());

		if (isDebug() === false && Security::check()) {
			showExceptionMsg('shutdown', '因非法操作您已经被禁止访问，请联系管理员！', '', '', true);
		}

		Security::runMiddlewares();
		self::loadSession();

		if (isWebServer() && !isSwooleCoroutine()) {
			$_SESSION = session();
		}

		Security::runMiddlewares('Before');
	}

	/**
	 * 阶段三：路由与应用环境
	 *
	 * URL 解析、End 中间件、系统/模板配置、租户上下文、路径初始化、语言包
	 *
	 * @return void
	 * @static
	 * @access public
	 */
	/**
	 * 应用运行时标识（DEBUG / DEV）
	 *
	 * 优先级：.env 的 APP_DEBUG / APP_DEV > Sys.runtime.debug / .dev > 默认 false。
	 * 用 include 直读配置而不是 loadConfig()：此刻框架函数还没加载完。
	 *
	 * @return void
	 */
	/**
	 * 应用运行时标识（DEBUG / DEV）
	 *
	 * 原为 protected：只有 Bootstrap::boot() 内部能用。但 Console 命令（如 `candy server start`）
	 * 在 Bootstrap::boot() **之前**执行，那时 DEV/DEBUG 还没定义，导致
	 * `Server` 里 `$devMode = defined('DEV') || DEBUG==1` 恒为 false → FileMonitor 热更新永不启用。
	 * 开放为 public，供命令层在需要时提前应用一次（幂等，defined() OR define）。
	 *
	 * @return void
	 */
	public static function applyRuntimeFlags(): void
	{
		$env = self::readDotEnv();

		$sys = [];

		// ① 框架默认：随框架发布、一定存在 —— 直接 include，省掉一次 stat（约 0.9μs）
		$framework = CANDYPATH . 'Candy/Class/Tools/Inc/Sys.inc.php';
		$base = include $framework;
		if (is_array($base)) {
			$sys = $base;
		}

		// ② 项目覆盖：可能不存在，仍需 is_file 守卫
		$project = (C('INCPATH') ?: '') . 'Sys.inc.php';
		if ($project !== '' && is_file($project)) {
			$one = include $project;
			if (is_array($one)) {
				$sys = array_replace_recursive($sys, $one);
			}
		}
		$runtime = is_array($sys['runtime'] ?? null) ? $sys['runtime'] : [];

		$debug = self::flagValue($env['APP_DEBUG'] ?? null, $runtime['debug'] ?? false);
		$dev   = self::flagValue($env['APP_DEV'] ?? null, $runtime['dev'] ?? false);

		defined('DEBUG') OR define('DEBUG', $debug ? 1 : 0);
		defined('DEV')   OR define('DEV', $dev ? 1 : 0);
	}

	/**
	 * 解析一个布尔标识：显式环境变量优先，否则用配置值
	 *
	 * @param mixed $env     环境变量原始值（null = 未设置）
	 * @param mixed $default 配置值
	 * @return bool
	 */
	protected static function flagValue(mixed $env, mixed $default): bool
	{
		if ($env !== null && $env !== '') {
			$v = strtolower(trim((string) $env));
			if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
				return true;
			}
			if (in_array($v, ['0', 'false', 'off', 'no'], true)) {
				return false;
			}
		}
		return (bool) $default;
	}

	/**
	 * 读取站点根目录下的 .env（只取 KEY=VALUE，够用且不依赖其它函数）
	 *
	 * @return array<string, string>
	 */
	protected static function readDotEnv(): array
	{
		$file = (defined('CANDYROOT') ? CANDYROOT : '') . '.env';
		if (!is_file($file)) {
			return [];
		}
		$out = [];
		foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
				continue;
			}
			[$k, $v] = explode('=', $line, 2);
			$out[trim($k)] = trim(trim($v), '"\'');
		}
		return $out;
	}

	public static function route(): void
	{
		// 项目基础配置提前：System.inc.php 里定义的常量/共享配置（如 PROCESS_ENABLE、appNode）
		// 必须在路由与端点派发之前生效，否则 sys/* 端点读到的是未定义值。
		if (fileExistsCase(C('INCPATH') . 'System.inc.php')) {
			include C('INCPATH') . 'System.inc.php';
		}

		// __ENDPOINT_BOOT__ 内置端点注册（学 CLI 的两层注册：框架内置 + Source/Inc/Endpoint.inc.php 扩展）。
		// 必须早于 UrlParser::parseUrl()：它内部调 RouteContextInit::init()，
		// 其中 dispatchRegisteredHttpRoute() 会查 Route::rule 注册表并直接派发（send + stop）。
		\Candy\Extend\Tools\Endpoint\Registry::boot();

		listen('route_start');
		UrlParser::parseUrl();
		listen('route_end');

		Security::runMiddlewares('End');


		isset($separate) OR $separate = '/';
		isset($tplprefix) OR $tplprefix = '.html';
		G(['SEPARATE' => $separate, 'TPLPREFIX' => $tplprefix]);

		$tplstyle = C('TPLSTYLE');
		is_null($tplstyle) && $tplstyle = 'default';
		$addons = G('Addons') ?? false;
		C(['TPLSTYLE' => $tplstyle, 'Addons' => $addons]);
		if (isset($tplpath)) {
			C('APP_TPL_PATH', $tplpath);
		}

		if (defined('ALLINONE')) {
			if (is_null(C('APP_INIT_STATUS')) || (isDebug() && I('get.init', null) !== null)) {
				Build::start();
			}
		} else {
			if (is_null(C('APP_INIT_STATUS')) || (isDebug() && in_array(I('get.add', null), ['', 'addon', 'plug'], true))) {
				Build::start();
			}
		}

		// 须在 App::loading / loadLocale / dispatch 之前完成，供后续 MVCS 路径与编译名读取
		self::resolveTenantContext();

		App::loading();
		self::loadLocale();
	}

	/**
	 * 当前请求的租户 ID
	 *
	 * 未启用租户或钩子未返回时为 null（平台 / 公共 MVCS）
	 *
	 * @return string|null 六位租户 ID，非租户为 null
	 * @static
	 * @access public
	 */
	public static function tenantId(): ?string
	{
		$id = C('TENANT_ID');
		if ($id === null || $id === '') {
			return null;
		}
		return (string) $id;
	}

	/**
	 * 租户旁路路径段（与业务 Cache 键段一致）
	 *
	 * 有 TENANT_ID 时为 t/{id}/；无租户返回空串（回退默认空间）
	 * 用于上传、缩略图、向量 Data/vector、以及 Cache::prefix() 等旁路隔离
	 *
	 * @return string 空串或 t/{六位ID}/
	 * @static
	 * @access public
	 */
	public static function tenantPathSegment(): string
	{
		$tenantId = self::tenantId();
		if ($tenantId === null || $tenantId === '') {
			return '';
		}
		return 't/' . $tenantId . '/';
	}

	/**
	 * 在基础目录后注入租户段
	 *
	 * 统一 / 分隔并保证尾随 /；$shared=true 时强制默认空间（不注入）
	 *
	 * @param string $basePath 绝对或相对基路径（如 Public/uploads、Data/vector）
	 * @param bool $shared 是否平台共享（跨租户同一目录）
	 * @return string 尾随 /
	 * @static
	 * @access public
	 */
	public static function resolveTenantDataPath(string $basePath, bool $shared = false): string
	{
		$base = rtrim(str_replace('\\', '/', $basePath), '/') . '/';
		if ($shared) {
			return $base;
		}
		$seg = self::tenantPathSegment();
		return $seg === '' ? $base : $base . $seg;
	}

	/**
	 * 本地上传根目录（绝对路径，无尾斜杠）
	 *
	 * 无租户：{CANDYROOT}/Public/uploads
	 * 有租户：{CANDYROOT}/Public/uploads/t/{id}
	 * 安全区：…/Public/uploads/safefile[/t/{id}]
	 *
	 * @param bool $safe 是否 safefile 子树
	 * @param bool $shared 强制默认空间
	 * @return string
	 * @static
	 * @access public
	 */
	public static function uploadRoot(bool $safe = false, bool $shared = false): string
	{
		$root = rtrim((string) C('CANDYROOT'), '/\\');
		$base = $root . '/Public/uploads' . ($safe ? '/safefile' : '');
		return rtrim(self::resolveTenantDataPath($base, $shared), '/');
	}

	/**
	 * 缩略图缓存根目录（绝对路径，无尾斜杠）
	 *
	 * @param bool $shared 强制默认空间
	 * @return string
	 * @static
	 * @access public
	 */
	public static function thumbRoot(bool $shared = false): string
	{
		$base = rtrim((string) C('CANDYROOT'), '/\\') . '/Public/thumbs';
		return rtrim(self::resolveTenantDataPath($base, $shared), '/');
	}

	/**
	 * 上传文件的 Web 访问前缀（以 / 开头、尾随 /）
	 *
	 * 无租户：/Public/uploads/
	 * 有租户：/Public/uploads/t/{id}/
	 *
	 * @param bool $safe 是否 safefile
	 * @param bool $shared 强制默认空间
	 * @return string
	 * @static
	 * @access public
	 */
	public static function uploadWebPrefix(bool $safe = false, bool $shared = false): string
	{
		$base = $safe ? '/Public/uploads/safefile' : '/Public/uploads';
		return self::resolveTenantDataPath($base, $shared);
	}

	/**
	 * 缩略图 Web 访问前缀（以 / 开头、尾随 /）
	 *
	 * 无租户：/Public/thumbs/
	 * 有租户：/Public/thumbs/t/{id}/
	 *
	 * @param bool $shared 强制默认空间
	 * @return string
	 * @static
	 * @access public
	 */
	public static function thumbWebPrefix(bool $shared = false): string
	{
		return self::resolveTenantDataPath('/Public/thumbs', $shared);
	}

	/**
	 * 向量旁路数据目录（绝对路径，无尾斜杠）
	 *
	 * 无租户：{CANDYROOT}/Data/vector/{alias}
	 * 有租户：{CANDYROOT}/Data/vector/t/{id}/{alias}
	 *
	 * @param string $alias 向量库别名
	 * @param string $baseRel 相对 CANDYROOT 的根（默认 Data/vector），或绝对路径
	 * @param bool $shared 强制默认空间
	 * @return string
	 * @static
	 * @access public
	 */
	public static function vectorDataDir(string $alias = 'default', string $baseRel = 'Data/vector', bool $shared = false): string
	{
		$alias = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($alias)) ?: 'default';
		$baseRel = str_replace('\\', '/', $baseRel);
		if (str_contains($baseRel, 'backend/')) {
			$baseRel = preg_replace('#(^|/)backend/#', '$1', $baseRel) ?? $baseRel;
		}
		$root = rtrim((string) C('CANDYROOT'), '/\\');
		if ($baseRel !== '' && ($baseRel[0] === '/' || preg_match('#^[A-Za-z]:/#', $baseRel))) {
			$base = rtrim($baseRel, '/');
		} else {
			$base = $root . '/' . trim($baseRel, '/');
		}
		return rtrim(self::resolveTenantDataPath($base, $shared), '/') . '/' . $alias;
	}

	/**
	 * 拼装层内租户覆写路径（不检查是否存在）
	 *
	 * 统一约定：{baseRoot}/{layer}/Tenant/{id}/{relative}
	 * 适用于 Application / Plugins / Addons / Template 等根
	 *
	 * @param string $baseRoot 根目录（应用、插件、扩展或模板根）
	 * @param string $layer 层名（Controls、Views、Hook、Languages 等）
	 * @param string $relative 层内相对路径（如 Index.class.php、zh-cn/Common.lang.php）
	 * @return string|null 无租户时为 null
	 * @static
	 * @access public
	 */
	public static function tenantOverlayPath(string $baseRoot, string $layer, string $relative = ''): ?string
	{
		$tenantId = self::tenantId();
		if ($tenantId === null) {
			return null;
		}

		$baseRoot = rtrim(str_replace('\\', '/', $baseRoot), '/') . '/';
		$layer = trim(str_replace('\\', '/', $layer), '/');
		$relative = ltrim(str_replace('\\', '/', $relative), '/');

		$path = $baseRoot . $layer . '/Tenant/' . $tenantId . '/';
		if ($relative !== '') {
			$path .= $relative;
		}
		return $path;
	}

	/**
	 * 若层内租户覆写文件存在则返回绝对路径，否则 null
	 *
	 * @param string $baseRoot 根目录
	 * @param string $layer 层名
	 * @param string $relative 相对路径
	 * @return string|null
	 * @static
	 * @access public
	 */
	public static function tenantOverlayFile(string $baseRoot, string $layer, string $relative = ''): ?string
	{
		$path = self::tenantOverlayPath($baseRoot, $layer, $relative);
		if ($path === null) {
			return null;
		}
		return (fileExistsCase($path) || is_file($path)) ? $path : null;
	}

	/**
	 * 解析应用 / 扩展根路径
	 *
	 * 规则：
	 * 1) Addons 命中（拆段优先、扁平兜底，见 AppPathResolver / Addon::resolveRoot）
	 * 2) Application 同序探测
	 * 3) 禁止用「当前请求 ISADDON」把 Admin 等普通应用指到 Addons/Admin
	 *
	 * @param string $app 应用或扩展名
	 * @return string 尾随 /
	 * @static
	 * @access public
	 */
	public static function resolveAppRoot(string $app = ''): string
	{
		$app = $app !== '' ? $app : (string) C('APPNAME');

		// 本请求控制器已锁定：同应用 M/V/S 一律跟锁（跨应用不命中）
		$locked = AppPathResolver::lockedRootFor($app);
		if ($locked !== null) {
			return $locked;
		}

		// Addon 优先：探测 Creator/Hub 拆段布局，再回退扁平 CreatorHub
		$addonRoot = Addon::resolveRoot($app);
		if ($addonRoot !== null) {
			return $addonRoot;
		}

		$applicationRoot = AppPathResolver::resolveApplicationRoot($app);
		if ($applicationRoot !== null) {
			return $applicationRoot;
		}

		// 当前请求 APP_PATH——仅当查询的就是当前应用
		$current = C('APP_PATH');
		if (is_string($current) && $current !== '' && ($app === '' || strcasecmp($app, (string) C('APPNAME')) === 0)) {
			return rtrim(str_replace('\\', '/', $current), '/') . '/';
		}

		return rtrim((string) C('CANDYROOT'), '/\\') . '/Application/' . AppPathResolver::logicalName($app) . '/';
	}

	/**
	 * 解析当前请求的租户上下文（可选钩子）
	 *
	 * 与 getUserRole / locale_check 相同：不强制 listen，由业务可选实现全局函数
	 * 开发者在 Source/Func/*.func.php（boot 已加载）实现 getTenantID() 即可
	 * 未实现或返回空时 TENANT_ID 为 null，不改变现有单库/平台行为
	 *
	 * 时序保证：security（Session/JWT）与路由已完成，MVCS / 语言包尚未加载
	 * 每请求覆盖写入 C('TENANT_ID')，避免 Workerman 长驻沿用上一请求租户
	 *
	 * @return void
	 * @static
	 * @access private
	 */
	private static function resolveTenantContext(): void
	{
		$tenantId = null;

		if (function_exists('getTenantID')) {
			$raw = getTenantID();
			if ($raw !== null && $raw !== '') {
				$tenantId = self::normalizeTenantId($raw);
			}
		}

		C('TENANT_ID', $tenantId);
	}

	/**
	 * 规范化租户 ID
	 *
	 * 仅接受六位字母数字；非法值返回 null（注册库配置 / 解析上下文共用）
	 *
	 * @param mixed $raw 原始值
	 * @return string|null 合法 ID 或 null
	 * @static
	 * @access public
	 */
	public static function normalizeTenantId(mixed $raw): ?string
	{
		if (!is_scalar($raw)) {
			Debug::addMsg('[<b>Tenant</b>] 租户 ID 非标量，已忽略', 2);
			return null;
		}

		$id = trim((string) $raw);
		if ($id === '') {
			return null;
		}

		// 约定：租户代码隔离目录各层下 Tenant/{id}/ 使用六位 [A-Za-z0-9]
		if (!preg_match('/^[A-Za-z0-9]{6}$/', $id)) {
			Debug::addMsg('[<b>Tenant</b>] 非法租户 ID（须为六位字母数字）：' . htmlspecialchars($id), 2);
			return null;
		}

		return $id;
	}

	/**
	 * 阶段四：控制器分发
	 *
	 * @return void
	 * @static
	 * @access public
	 */
	public static function dispatch(): void
	{
		App::dispatch();
	}

	/**
	 * 阶段五：响应收尾
	 *
	 * 主体输出由 dispatch 内 Action::display() 完成；此处触发 respond_start / respond_end 钩子
	 *
	 * @return void
	 * @static
	 * @access public
	 */
	public static function respond(): void
	{
		listen('respond_start');
		listen('respond_end');
	}

	/**
	 * 加载项目与控制器语言包
	 *
	 * @return void
	 * @static
	 * @access private
	 */
	private static function loadLocale(): void
	{
		$lg = null;

		if (function_exists('locale_check')) {
			$lg = locale_check();
		}

		if (empty($lg)) {
			$lg = cookie('locale');
		}

		$allowedLocales = ['zh-cn', 'en-us'];
		$defaultLocale = 'zh-cn';
		$langConfigFile = C('CANDYROOT') . 'Source/Inc/Lang.inc.php';
		if (fileExistsCase($langConfigFile)) {
			$langConfig = include $langConfigFile;
			if (!empty($langConfig['locales']) && is_array($langConfig['locales'])) {
				$allowedLocales = array_keys($langConfig['locales']);
			}
			if (!empty($langConfig['default'])) {
				$defaultLocale = $langConfig['default'];
			}
		}
		if (empty($lg) || !in_array($lg, $allowedLocales, true)) {
			$lg = $defaultLocale;
		}

		G('LANG', $lg);

		$LANG = [];
		$clangfile = C('APP_PATH') . 'Languages/' . $lg . '/Common.lang.php';
		if (fileExistsCase($clangfile)) {
			$LANG += include $clangfile;
		}

		// 与 Languages/{Control}.lang.php 文件名一致，保留多驼峰
		$controller = \Candy\Extend\Str::controlName((string)I('get.m', '', ''));
		$langfile = C('APP_PATH') . 'Languages/' . $lg . '/' . $controller . '.lang.php';
		if (fileExistsCase($langfile)) {
			$LANG += include $langfile;
		}

		$plangfile = C('PLUGPATH') . C('RAPPNAME') . '/Languages/' . $lg . '/' . $controller . '.lang.php';
		if (fileExistsCase($plangfile)) {
			$LANG += include $plangfile;
			$langfile = $plangfile;
		}

		// 租户语言包覆盖同名键（应用 / 扩展 / 插件，层内 Tenant/{id}/）
		$tenantId = self::tenantId();
		if ($tenantId !== null) {
			$langFiles = [];
			$appRoot = self::resolveAppRoot((string) C('APPNAME'));
			foreach (['Common.lang.php', $controller . '.lang.php'] as $tenantLangName) {
				if ($tenantLangName === '.lang.php') {
					continue;
				}
				$langFiles[] = self::tenantOverlayPath($appRoot, 'Languages', $lg . '/' . $tenantLangName);
			}
			$plugRoot = C('PLUGPATH') . C('RAPPNAME') . '/';
			foreach (['Common.lang.php', $controller . '.lang.php'] as $tenantLangName) {
				if ($tenantLangName === '.lang.php') {
					continue;
				}
				$langFiles[] = self::tenantOverlayPath($plugRoot, 'Languages', $lg . '/' . $tenantLangName);
			}
			foreach ($langFiles as $tenantLangFile) {
				if ($tenantLangFile === null || !fileExistsCase($tenantLangFile)) {
					continue;
				}
				$chunk = include $tenantLangFile;
				if (is_array($chunk)) {
					$LANG = array_merge($LANG, $chunk);
					$langfile = $tenantLangFile;
				}
			}
		}

		Debug::addMsg('[<b>Languages file</b>] 当前项目语言库路径：' . str_replace(C('CANDYROOT'), '/', $langfile), 3);

		C('LANGINFO', $LANG);
	}

	/**
	 * WebSocket 长连接请求入口
	 *
	 * 将 WebSocket 消息转换为 HTTP 请求环境后执行标准启动管道。
	 *
	 * @param string $client_id WebSocket 客户端连接 ID
	 * @param string $message WebSocket 消息内容（通常为 JSON）
	 * @return void
	 */
	public static function wsRun(string $client_id, string $message): void
	{
		$data = json($message, 'de');

		if (function_exists('\wsInitServer')) {
			$data = \wsInitServer($data);
		}

		if (empty($data['api'])) {
			\GatewayWorker\Lib\Gateway::sendToClient($_SERVER['GATEWAY_CLIENT_ID'], json(['']));
			stop();
		}

		$method = isset($data['method']) ? strtoupper($data['method']) : 'GET';

		if (isset($data['data'])) {
			$pathInfo = str_ends_with($data['api'], '.json') ? str_replace('.json', '', $data['api']) : rtrim($data['api'], '/');
			if ($method === 'GET') {
				foreach ($data['data'] as $key => $value) {
					$pathInfo .= '/' . $key . '/' . $value;
				}
			} else {
				$_POST = $data['data'];
			}
			$pathInfo .= '.json';
		} else {
			$pathInfo = rtrim($data['api'], '/') . (str_ends_with($data['api'], '.json') ? '' : '.json');
		}

		$extendArgs = [];
		foreach ($data as $key => $value) {
			if ($key !== 'api' && $key !== 'data') {
				$extendArgs[$key] = $value;
			}
		}

		$_SERVER = array_merge($_SERVER, [
			'REQUEST_METHOD'     => $method,
			'PATH_INFO'          => $pathInfo,
			'REQUEST_URI'        => $pathInfo,
			'SCRIPT_NAME'        => '/index.php',
			'QUERY_STRING'       => '',
			'SERVER_NAME'        => '',
			'HTTP_HOST'          => '',
			'HTTP_SOCKET_EXTEND' => $extendArgs,
		]);

		if (isset($data['servers']) && is_array($data['servers'])) {
			$_SERVER = array_merge($_SERVER, $data['servers']);
		}

		self::run();
	}

	/**
	 * 加载 Source/Inc/Config.inc.php（DB / G / 缓存驱动）
	 *
	 * 配置文件固定位于 INCPATH，Web boot、CLI、Process Worker、MCP 共用本入口
	 * 未调用时访问 M()/S() 会提示「请先配置数据库」；CLI 下 stop() 会 exit 整个进程
	 *
	 * @return void
	 * @static
	 * @access public
	 */
	public static function loadDbConfig(): void
	{
		$config = loadConfig('Config');

		if (empty($config) || !is_array($config)) {
			return;
		}

		if (isset($config['Servers']['open']) && $config['Servers']['open'] == true) {
			$svtype = $config['Servers']['type'] ?? 'Memcache';
			$serverConfig = $config['Servers']['config'] ?? $config['Servers']['confing'] ?? [];
			G('cacheDriver', ['type' => $svtype, 'config' => $serverConfig, 'confing' => $serverConfig]);
		}

		unset($config['Servers']);

		isset($config['CSTART']) OR $config['CSTART'] = false;
		if (function_exists('cacheLoading')) {
			cacheLoading((bool)$config['CSTART']);
		}

		$dbconfig = is_array($config['DB'] ?? null) ? $config['DB'] : [];
		unset($config['DB']);

		G($config);

		$defaultDb = is_array($dbconfig['default'] ?? null) ? $dbconfig['default'] : [];
		if ($defaultDb !== []) {
			if (\Candy\Extend\DB\DBServer::isRoleSplitConfig($defaultDb)) {
				// 默认库读写分库：C() 仍注入 write 节点，完整配置保留在 DBLIST
				C(\Candy\Extend\DB\DBServer::extractWriteNode($defaultDb));
			} else {
				C($defaultDb);
				unset($dbconfig['default']);
			}
		}

		if (count($dbconfig) > 0) {
			C('DBLIST', $dbconfig);
		}
	}

	/**
	 * 绑定应用路径（APPNAME / RAPPNAME / APP_PATH）
	 *
	 * 供 M()/S() 解析 Application/{App}，不加载数据库
	 *
	 * @param string $app 应用目录名（Admin / Home）
	 * @return void
	 * @static
	 * @access public
	 */
	public static function bindApp(string $app = 'Admin'): void
	{
		$app = $app !== '' ? $app : 'Admin';
		$root = rtrim((string)C('CANDYROOT'), '/\\') . '/';
		$fsRel = AppPathResolver::resolveApplicationFsRel($app) ?? $app;
		$logical = AppPathResolver::logicalName($fsRel !== '' ? $fsRel : $app) ?: $app;
		C('APPNAME', $logical);
		C('RAPPNAME', AppPathResolver::camelSplitRel($logical));
		C('APP_PATH', $root . 'Application/' . $fsRel . '/');
	}

	/**
	 * 非 HTTP 运行时引导（CLI / Process Worker / MCP）
	 *
	 * 注入 Config.inc 的 DB 与全局项；可选绑定应用路径以便 S()/M()
	 * Process 子进程由 startProcess 自动调用，业务 Worker 一般无需再调
	 *
	 * @param string|null|false $app 应用名；null/false 只加载配置不绑应用；默认 Admin
	 * @return void
	 * @static
	 * @access public
	 */
	public static function bootRuntime(string|null|false $app = 'Admin'): void
	{
		self::loadDbConfig();
		if ($app === null || $app === false) {
			return;
		}
		self::bindApp((string)$app);
	}

	/**
	 * security 阶段：初始化 Session（HTTP / Workerman / 缓存驱动）
	 *
	 * @return void
	 */
	private static function loadSession(): void
	{
		$sessionid = '';
		$inSwoole = isSwooleCoroutine();
		$requestData = I('request');
		if (is_array($requestData) && array_key_exists('sessionid', $requestData)) {
			$sessionid = (string)$requestData['sessionid'];
			// FPM/Workerman 仍需移除已消费参数，避免后续路由把它当业务参数。
			if (!$inSwoole) {
				unset($_GET['sessionid'], $_POST['sessionid']);
			}
		}

		if (isWebServer() && !$inSwoole) {
			$request = null;
			try {
				$container = Container::getInstance();
				if ($container->has('Request')) {
					$request = $container->get('Request');
				}
			} catch (\Exception) {
			}
			// 仅 Workerman（Container 已绑定 Request）走 Http Session；php -S 等内置服务器继续走下方原生 Session
			if ($request !== null && method_exists($request, 'session')) {
				if ($sessionid !== '') {
					try {
						$request->session($sessionid);
						Debug::addMsg('<font color=\'red\'>Workerman 模式：使用客户端提供的 SessionID</font>（' . $sessionid . '）');
					} catch (\Exception) {
					}
				}
				return;
			}
		}

		// Swoole 协程使用请求适配器持有的缓存 Session；绝不能调用 PHP 原生 session_start()。
		if ($inSwoole) {
			$request ??= Container::getInstance()->get('Request');
			if ($request !== null && method_exists($request, 'session')) {
				$request->session($sessionid !== '' ? $sessionid : null);
				Debug::addMsg('<font color=\'green\'>Swoole 协程缓存 Session（请求上下文隔离）</font>');
			}
			return;
		}

		if (!Cache::isFileDriver()) {
			try {
				$cache = Cache::store();
				\Candy\Extend\Session::start($cache, $sessionid);
				Debug::addMsg('<font color=\'green\'>' . ($inSwoole ? 'Swoole 协程' : '') . '缓存 Session（使用缓存驱动保存会话信息）</font>');
			} catch (\Exception) {
				if ($sessionid !== '') {
					\session_id($sessionid);
				}
				\session_start();
				Debug::addMsg('<font color=\'green\'>开启会话 Session（缓存驱动不可用，默认方式）</font>');
			}
			return;
		}

		if ($sessionid !== '') {
			\session_id($sessionid);
		}
		\session_start();
		Debug::addMsg('<font color=\'green\'>开启会话 Session（默认方式）</font>');
	}
}
