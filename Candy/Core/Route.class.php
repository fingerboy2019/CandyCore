<?php
/**
 * Candy框架 路由处理核心类（门面）
 *
 * @package Candy\Core
 */
declare(strict_types=1);
namespace Candy\Core;

use Candy\Core\Trait\Route\RouteContextInit;
use Candy\Core\Trait\Route\RouteMatcher;
use Candy\Core\Trait\Route\RouteRegistry;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Route
{
	/** @var array 路由组栈（group 嵌套时按层累积前缀与 option） */
	protected static array $groupStack = [];

	/**
	 * Rule
	 * @param mixed $rule
	 * @param mixed $route
	 * @param mixed $method
	 * @param mixed $option
	 * @param mixed $pattern
	 */
	public static function rule($rule, $route, $method = '*', array $option = [], array $pattern = []): void
	{
		[$rule, $option] = self::applyGroup($rule, $option);
		RouteRegistry::rule($rule, $route, $method, $option, $pattern);
	}

	/**
	 * 路由分组（2026-11-13）：组内规则自动加前缀，并继承组 option
	 *
	 * ```php
	 * Route::group('admin', function () {
	 *     Route::get('users', 'Admin/users');        // → admin/users
	 *     Route::group('v1', function () {
	 *         Route::get('list', 'Admin/list');     // → admin/v1/list
	 *     });
	 * }, ['middleware' => 'APP\Middleware\JwtAuth']);
	 * ```
	 *
	 * option 合并顺序：外层组 → 内层组 → 规则自身（后者覆盖前者）。
	 *
	 * @param string   $prefix   前缀（空字符串表示只继承 option 不加前缀）
	 * @param callable $callback 组内注册逻辑
	 * @param array    $option   组级选项
	 * @return void
	 */
	public static function group(string $prefix, callable $callback, array $option = []): void
	{
		self::$groupStack[] = ['prefix' => trim($prefix, '/'), 'option' => $option];
		try {
			$callback();
		} finally {
			array_pop(self::$groupStack);
		}
	}

	/**
	 * 把当前组栈作用到一条规则上
	 *
	 * @param mixed $rule   原始规则
	 * @param array $option 规则自身选项
	 * @return array [拼好前缀的规则, 合并后的选项]
	 */
	private static function applyGroup($rule, array $option): array
	{
		if (self::$groupStack === []) {
			return [$rule, $option];
		}

		$prefixes = [];
		$merged   = [];
		foreach (self::$groupStack as $layer) {
			if ($layer['prefix'] !== '') {
				$prefixes[] = $layer['prefix'];
			}
			$merged = array_merge($merged, $layer['option']);
		}

		$rulePath = is_string($rule) ? trim($rule, '/') : '';
		$parts    = $prefixes;
		if ($rulePath !== '') {
			$parts[] = $rulePath;
		}
		$fullRule = implode('/', $parts);
		if ($fullRule === '') {
			$fullRule = $rule;   // 非字符串规则或空前缀时保持原样
		}

		return [$fullRule, array_merge($merged, $option)];
	}

	/**
	 * 动词快捷方法（2026-11-13）：内部转发到 rule()
	 *
	 * 注意 method 必须传**小写**：RouteRegistry::$methodRules 是小写白名单且用 === 严格比较。
	 *
	 * @param mixed $rule    规则
	 * @param mixed $route   目标（Control/action）
	 * @param array $option  选项（ext/method/callback/middleware 等）
	 * @param array $pattern 变量约束
	 * @return void
	 */
	public static function get($rule, $route, array $option = [], array $pattern = []): void
	{
		self::rule($rule, $route, 'get', $option, $pattern);
	}

	/** @return void */
	public static function post($rule, $route, array $option = [], array $pattern = []): void
	{
		self::rule($rule, $route, 'post', $option, $pattern);
	}

	/** @return void */
	public static function put($rule, $route, array $option = [], array $pattern = []): void
	{
		self::rule($rule, $route, 'put', $option, $pattern);
	}

	/** @return void */
	public static function patch($rule, $route, array $option = [], array $pattern = []): void
	{
		self::rule($rule, $route, 'patch', $option, $pattern);
	}

	/** @return void */
	public static function delete($rule, $route, array $option = [], array $pattern = []): void
	{
		self::rule($rule, $route, 'delete', $option, $pattern);
	}

	/** 任意方法（等价 rule 的默认值） @return void */
	public static function any($rule, $route, array $option = [], array $pattern = []): void
	{
		self::rule($rule, $route, '*', $option, $pattern);
	}

	/**
	 * 资源路由（2026-11-13）：一次注册 REST 七件套
	 *
	 * ```php
	 * Route::resource('articles', 'Articles');
	 * // GET    /articles            → Articles/index
	 * // GET    /articles/create     → Articles/create
	 * // POST   /articles            → Articles/save
	 * // GET    /articles/:id        → Articles/detail
	 * // PUT    /articles/:id        → Articles/update
	 * // DELETE /articles/:id        → Articles/delete
	 * // GET    /articles/:id/edit   → Articles/edit
	 * ```
	 *
	 * @param string $name    资源名（URL 前缀）
	 * @param string $control 控制器（Control/action 的前半段）
	 * @param array  $option  统一的规则选项
	 * @return void
	 */
	public static function resource(string $name, string $control, array $option = []): void
	{
		$name = trim($name, '/');
		$map = [
			['get',    '',          'index'],
			['get',    'create',    'create'],
			['post',   '',          'save'],
			['get',    ':id',       'detail'],
			['put',    ':id',       'update'],
			['delete', ':id',       'delete'],
			['get',    ':id/edit',  'edit'],
		];
		foreach ($map as $item) {
			[$method, $suffix, $action] = $item;
			$uri = $suffix === '' ? $name : $name . '/' . $suffix;
			self::rule($uri, $control . '/' . $action, $method, $option);
		}
	}

	/**
	 * 兜底路由（2026-11-13）：所有规则都没命中时使用
	 *
	 * ```php
	 * Route::miss('Index/notFound');  // 未命中时进入这个控制器
	 * ```
	 *
	 * @param mixed $route 目标（Control/action）
	 * @return void
	 */
	public static function miss($route): void
	{
		RouteRegistry::miss($route);
	}

	/**
	 * Reset
	 */
	public static function reset(): void
	{
		RouteRegistry::reset();
	}

	/**
	 * On Worker Reload
	 * @param mixed $worker
	 */
	public static function onWorkerReload(mixed $worker = null): void
	{
		RouteRegistry::onWorkerReload($worker);
	}

	/**
	 * Bind Worker Reload
	 * @param mixed $worker
	 */
	public static function bindWorkerReload(object $worker): void
	{
		RouteRegistry::bindWorkerReload($worker);
	}

	/**
	 * Get Rule
	 * @param mixed $rule
	 * @param mixed $group
	 * @param mixed $method
	 * @return array
	 */
	public static function getRule($rule, $group, $method): array
	{
		return RouteRegistry::getRule($rule, $group, $method);
	}

	/**
	 * Parse Route
	 * @param mixed $uri
	 * @return array
	 */
	public static function parseRoute(string $uri): array
	{
		return RouteRegistry::parseRoute($uri);
	}

	/**
	 * Init Config
	 */
	public static function initConfig(): void
	{
		RouteContextInit::init();
	}

	/**
	 * Contrast Route
	 * @param mixed $pathinfo
	 * @param mixed $config
	 * @return string
	 */
	public static function contrastRoute(string $pathinfo, array $config = []): string
	{
		return RouteMatcher::contrastRoute($pathinfo, $config);
	}
}
