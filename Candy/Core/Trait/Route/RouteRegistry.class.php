<?php
declare(strict_types=1);
namespace Candy\Core\Trait\Route;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 进程内路由规则注册表与 URI 解析缓存
 */
final class RouteRegistry
{
	protected static array $rules = [];

	/** @var array<string, array{0: string, 1: string}> */
	private static array $parseRouteCache = [];

	protected static array $methodRules = ['*', 'get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

	/** @var mixed 兜底路由（未命中任何规则时使用） */
	protected static $missRoute = null;

	/**
	 * 设置兜底路由
	 * @param mixed $route
	 * @return void
	 */
	public static function miss($route): void
	{
		self::$missRoute = $route;
	}

	/**
	 * 取兜底路由
	 * @return mixed
	 */
	public static function getMiss()
	{
		return self::$missRoute;
	}

	/**
	 * 取全部已注册路由（展平前原始结构，供 sys/url 端点列举）
	 *
	 * @return array<string, array<string, array<string, array<string, mixed>>>>
	 */
	public static function all(): array
	{
		return self::$rules;
	}

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
		[$group, $rulex] = self::parseRoute($rule);

		// 路由串可能不含 '@'（如 'Controller/index'），此时 explode 只有 1 段，
		// 直接取下标 1 会报 Undefined array key。用 ?? '' 兜底再回退整串。
		$routex = explode('@', $route, 2)[1] ?? '';
		$routex = $routex !== '' ? $routex : $route;

		if (!isset(self::$rules[$group])) {
			self::$rules[$group] = [];
		}

		foreach (self::$methodRules as $m) {
			if ($method === $m || $method === '*') {
				self::$rules[$group][$m][$rulex] = [
					'uri' => $rule,
					'callback' => $route,
					'route' => $routex,
					'option' => $option,
					'pattern' => $pattern,
				];
			}
		}
	}

	/**
	 * Reset
	 */
	public static function reset(): void
	{
		self::$rules = [];
		self::$parseRouteCache = [];
		self::$missRoute = null;
	}

	/**
	 * On Worker Reload
	 * @param mixed $worker
	 */
	public static function onWorkerReload(mixed $worker = null): void
	{
		self::reset();
		\Candy\Core\Trait\Func\FuncLoader::reset();
		if (class_exists(\Candy\Extend\DB\WorkerRuntime::class)) {
			\Candy\Extend\DB\WorkerRuntime::onWorkerReload($worker);
		}

		// 代码热更新：注解/节点派生缓存必须随 reload 一起失效。
		// 否则 reload 后新 worker 仍会从 G()/共享缓存读回旧的节点与注解数据，
		// 表现为「改了注解/新增了控制器，reload 之后仍然看不到」。
		if (class_exists(\Candy\Extend\Attribute\utils\Tool::class)) {
			\Candy\Extend\Attribute\utils\Tool::resetScanCache();
		}
		if (class_exists(\Candy\Core\Node::class)) {
			\Candy\Core\Node::clearNodeCache();
		}
	}

	/**
	 * Bind Worker Reload
	 * @param mixed $worker
	 */
	public static function bindWorkerReload(object $worker): void
	{
		$previous = $worker->onWorkerReload ?? null;
		$worker->onWorkerReload = static function ($w) use ($previous) {
			self::onWorkerReload($w);
			if (is_callable($previous)) {
				call_user_func($previous, $w);
			}
		};

		// Worker 子进程启动：fork 后重置 DB/Cache，避免继承 Master 坏连接
		$prevStart = $worker->onWorkerStart ?? null;
		$worker->onWorkerStart = static function ($w) use ($prevStart) {
			if (class_exists(\Candy\Extend\DB\WorkerRuntime::class)) {
				\Candy\Extend\DB\WorkerRuntime::onWorkerStart($w);
			}
			if (is_callable($prevStart)) {
				call_user_func($prevStart, $w);
			}
		};
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
		return self::$rules[$group][$method][$rule] ?? (self::$rules[$group]['*'][$rule] ?? []);
	}

	/**
	 * Parse Route
	 * @param mixed $uri
	 * @return array
	 */
	public static function parseRoute(string $uri): array
	{
		if (isset(self::$parseRouteCache[$uri])) {
			return self::$parseRouteCache[$uri];
		}

		[$uri] = explode('?', $uri, 2);
		$uris = explode('/', trim($uri, '/'), 2);
		$result = count($uris) > 1 ? $uris : [$uris[0], ''];

		self::$parseRouteCache[$uri] = $result;
		return $result;
	}
}
