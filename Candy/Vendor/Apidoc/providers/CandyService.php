<?php
/***
 * Apidoc Candy 框架适配服务
 *
 * 业务说明：route_start 注册文档路由；对接 Route / D() / L()；不默认开启自动业务路由扫描
 * 应用展示名优先取 App.inc.php 的 name，避免直白 Admin/Home
 * auto_url 默认 /api/v1 + .json，与 Route.inc.php API_URL 对齐；Portal/Home 省略应用段
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-29 10:41:46 $
 *
 * @package Apidoc\providers
 */

namespace Apidoc\providers;

use Apidoc\utils\Helper;
use Candy\Core\Route;
use Apidoc\Bootstrap;

class CandyService
{
	use BaseService;

	/**
	 * Hook 入口：仅当配置 enable=true 时注册文档路由
	 *
	 * @return void
	 */
	public function run(): void
	{
		if (!Bootstrap::enabled()) {
			return;
		}

		$this->initConfig();
		self::registerApidocRoutes();

		// 仅当配置显式打开时，才把业务注解 URL 扫进路由（默认关闭）
		self::autoRegisterRoutes(function ($routeData) {
			foreach ($routeData as $controller) {
				if (empty($controller['methods']) || !is_array($controller['methods'])) {
					continue;
				}
				$controller_namespace = $controller['name'] . '@';
				foreach ($controller['methods'] as $method) {
					$apiMethods = Helper::handleApiMethod($method['method'] ?? 'GET');
					$apiMethods = implode('|', $apiMethods);
					$route = [
						'uri'      => $method['url'] ?? '',
						'callback' => $controller_namespace . ($method['name'] ?? ''),
						'route'    => $controller['name'],
						'method'   => $apiMethods,
					];
					if ($route['uri'] === '' || $route['callback'] === $controller_namespace) {
						continue;
					}
					self::registerRoute($route);
				}
			}
		});
	}

	/**
	 * 读取并规范化 Apidoc 配置
	 *
	 * @return array<string,mixed>
	 */
	public static function getApidocConfig(): array
	{
		$config = loadConfig('Apidoc');
		if (!is_array($config)) {
			$config = [];
		}

		if (!is_array($config['auto_url'] ?? null)) {
			$config['auto_url'] = [];
		}
		$config['auto_url'] = self::normalizeAutoUrl($config['auto_url']);
		if (!isset($config['enable'])) {
			$config['enable'] = false;
		}
		if (empty($config['apps']) || !is_array($config['apps'])) {
			$config['apps'] = [
				[
					'title' => '管理中心',
					'path'  => 'Application/Admin/Controls',
					'host'  => '',
					'key'   => 'admin',
				],
			];
		}
		$config['apps'] = self::decorateAppTitles($config['apps']);
		if (!isset($config['auto_register_routes'])) {
			$config['auto_register_routes'] = false;
		}
		if (empty($config['ignored_annitation']) || !is_array($config['ignored_annitation'])) {
			$config['ignored_annitation'] = ['Auth', 'Menu', 'Log', 'Annotation', 'RateLimiter'];
		} else {
			foreach (['Annotation', 'RateLimiter'] as $ignore) {
				if (!in_array($ignore, $config['ignored_annitation'], true)) {
					$config['ignored_annitation'][] = $ignore;
				}
			}
		}

		$config['app_frame'] = 'Candy';
		return $config;
	}

	/**
	 * 规范化 auto_url：与 Route API_URL 对齐为 /api/v1 + .json
	 *
	 * 未配置 prefix 时读取 Route.inc.php 的 API_URL.prefix
	 * 未配置 suffix 时默认 .json
	 * 未配置 custom 时：Portal/Home 绑定域省略应用段，避免文档写成 /api/v1/Portal/...
	 *
	 * @param array<string,mixed> $autoUrl 原始 auto_url
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function normalizeAutoUrl(array $autoUrl): array
	{
		if (empty($autoUrl['filter_keys']) || !is_array($autoUrl['filter_keys'])) {
			$autoUrl['filter_keys'] = ['app', 'controller'];
		}

		$suffix = trim((string)($autoUrl['suffix'] ?? ''));
		if ($suffix === '') {
			$autoUrl['suffix'] = '.json';
		} elseif ($suffix[0] !== '.') {
			$autoUrl['suffix'] = '.' . $suffix;
		}

		$prefix = trim((string)($autoUrl['prefix'] ?? ''), '/');
		if ($prefix === '') {
			$prefix = self::resolveRouteApiPrefix();
		}
		$autoUrl['prefix'] = '/' . $prefix;

		if (empty($autoUrl['custom']) || !is_callable($autoUrl['custom'])) {
			$autoUrl['custom'] = static function (string $className, string $methodName, string $url): string {
				unset($className, $methodName);
				// 租户域绑 Portal、默认站绑 Home：对外路径省略应用名
				if (preg_match('#^(/api/v\d+)/(Portal|Home)/(.+)$#i', $url, $m) === 1) {
					return $m[1] . '/' . $m[3];
				}
				return $url;
			};
		}

		return $autoUrl;
	}

	/**
	 * 从全局路由配置读取 API 路径前缀
	 *
	 * 与 Source/Inc/Route.inc.php 的 API_URL.prefix 保持一致，缺省 api/v1
	 *
	 * @return string 不含首尾斜杠，如 api/v1
	 * @access private
	 * @static
	 */
	private static function resolveRouteApiPrefix(): string
	{
		$route = function_exists('loadConfig') ? loadConfig('Route') : null;
		if (!is_array($route)) {
			$route = [];
		}
		$api = $route['API_URL'] ?? null;
		if (is_array($api) && !empty($api['prefix'])) {
			return trim((string)$api['prefix'], '/');
		}
		return 'api/v1';
	}

	/**
	 * 用 App.inc.php 的应用中文名修饰 Apidoc apps.title
	 *
	 * 当 title 为空，或仍为目录名/key（Admin、admin、Home）时，替换为项目配置的 name
	 * 已手写过的展示名（如「管理中心」）保持不变
	 *
	 * @param list<array<string,mixed>> $apps Apidoc apps
	 * @return list<array<string,mixed>>
	 * @access private
	 * @static
	 */
	private static function decorateAppTitles(array $apps): array
	{
		$appNames = function_exists('loadConfig') ? loadConfig('App') : [];
		if (!is_array($appNames)) {
			$appNames = [];
		}

		foreach ($apps as &$app) {
			if (!is_array($app)) {
				continue;
			}
			$folder = '';
			$path = (string)($app['path'] ?? '');
			if (preg_match('#Application[/\\\\]([^/\\\\]+)[/\\\\]Controls#', $path, $m)) {
				$folder = $m[1];
			} elseif (!empty($app['key']) && is_string($app['key'])) {
				$folder = ucfirst($app['key']);
			}
			if ($folder === '') {
				continue;
			}

			$configured = trim((string)($appNames[$folder]['name'] ?? ''));
			if ($configured === '') {
				continue;
			}

			$title = trim((string)($app['title'] ?? ''));
			$key = (string)($app['key'] ?? '');
			$plainTitles = [
				$folder,
				strtolower($folder),
				ucfirst(strtolower($folder)),
				$key,
				strtolower($key),
				ucfirst(strtolower($key)),
			];
			if ($title === '' || in_array($title, $plainTitles, true)) {
				$app['title'] = $configured;
			}
		}
		unset($app);

		return $apps;
	}

	/**
	 * 注册单条路由
	 *
	 * @param array<string,mixed> $route uri/callback/method
	 * @return mixed
	 */
	public static function registerRoute($route)
	{
		$config = self::getApidocConfig();
		$registerRoute = Route::rule(
			(string)($route['uri'] ?? ''),
			(string)($route['callback'] ?? ''),
			(string)($route['method'] ?? '*')
		);
		if (!empty($config['allowCrossDomain']) && is_object($registerRoute) && method_exists($registerRoute, 'allowCrossDomain')) {
			$registerRoute->allowCrossDomain();
		}
		return $registerRoute;
	}

	/**
	 * 执行 SQL（文档生成器读表结构用）
	 *
	 * @param string $sql SQL
	 * @return mixed
	 */
	public static function databaseQuery($sql)
	{
		if (!function_exists('D')) {
			return [];
		}
		try {
			return D()->query($sql);
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * 表前缀
	 *
	 * @return string
	 */
	public static function getTablePrefix()
	{
		$prefix = function_exists('C') ? C('TABPREFIX') : '';
		return is_string($prefix) ? $prefix : '';
	}

	/**
	 * 项目根路径
	 *
	 * @return string
	 */
	public static function getRootPath()
	{
		if (function_exists('C')) {
			$root = C('CANDYROOT');
			if (is_string($root) && $root !== '') {
				return $root;
			}
		}
		return defined('CANDYPATH') ? CANDYPATH : '';
	}

	/**
	 * 运行时目录
	 *
	 * @return string
	 */
	public static function getRuntimePath()
	{
		if (function_exists('C')) {
			$runtime = C('RUNTIME');
			if (is_string($runtime) && $runtime !== '') {
				return $runtime;
			}
		}
		return self::getRootPath() . 'Runtime/';
	}

	/**
	 * 切换语言（对接框架 C('LANG')）
	 *
	 * @param string $locale 语言标识
	 * @return void
	 */
	public static function setLang($locale)
	{
		if (function_exists('C') && is_string($locale) && $locale !== '') {
			C('LANG', $locale);
		}
	}

	/**
	 * 读取语言包键（对接 L()）
	 *
	 * @param string $lang 语言键
	 * @return string
	 */
	public static function getLang($lang)
	{
		if (!is_string($lang) || $lang === '') {
			return (string)$lang;
		}
		if (function_exists('L')) {
			$translated = L($lang);
			return is_string($translated) ? $translated : $lang;
		}
		return $lang;
	}

	/**
	 * JSON 响应
	 *
	 * @param mixed $res 数据
	 * @return mixed
	 */
	public static function handleResponseJson($res)
	{
		return function_exists('json') ? json($res) : json_encode($res, JSON_UNESCAPED_UNICODE);
	}
}
