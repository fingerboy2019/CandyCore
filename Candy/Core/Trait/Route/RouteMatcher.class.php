<?php
declare(strict_types=1);
namespace Candy\Core\Trait\Route;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 静态/动态/正则路由匹配与解析
 */
final class RouteMatcher
{
	public static function contrastRoute(string $pathinfo, array $config = []): string
	{
		if (function_exists('beforeContrastRoute')) {
			$pathinfo = beforeContrastRoute($pathinfo);
		}

		$maps = empty($config) ? C('URL_MAP_RULES') : $config['URL_MAP_RULES'];
		if (isset($maps[$pathinfo])) {
			return is_string($maps[$pathinfo]) ? $maps[$pathinfo] : '';
		}

		$routes = empty($config) ? C('URL_ROUTE_RULES') : $config['URL_ROUTE_RULES'];
		if (!empty($routes)) {
			foreach ($routes as $rule => $route) {
				if (is_numeric($rule)) {
					$rule = array_shift($route);
				}

				// 每轮重置：避免上一条规则的 option 残留到下一条
				$options = [];

				if (is_array($route) && isset($route[2])) {
					$options = $route[2];

					if (isset($options['ext']) && C('URL_HTML_SUFFIX') != $options['ext']) {
						continue;
					}

					if (isset($options['method']) && C('METHOD') != strtoupper($options['method'])) {
						continue;
					}

					if (!empty($options['callback']) && is_callable($options['callback'])) {
						if (false === call_user_func($options['callback'])) {
							continue;
						}
					}
				}
				if (0 === strpos($rule, '/') && preg_match($rule, $pathinfo, $matches)) {
					self::registerRouteMiddleware($options['middleware'] ?? null);
					if ($route instanceof \Closure) {
						$return = self::invokeRegx($route, $matches);
						return is_string($return) ? $return : '';
					}
					$return = self::parseRegex($matches, $route, $pathinfo);
					return is_string($return) ? $return : '';
				}
				// 以 / 开头的规则按正则处理；未命中须 continue，禁止落入下方斜杠数早退
				if (0 === strpos($rule, '/')) {
					continue;
				}

				$len1 = substr_count($pathinfo, '/');
				$len2 = substr_count($rule, '/');
				if ($len1 >= $len2 || strpos($rule, '[')) {
					if ('$' == substr($rule, -1, 1)) {
						if ($len1 != $len2) {
							continue;
						}
						$rule = substr($rule, 0, -1);
					}
					$match = self::checkUrlMatch($pathinfo, $rule);
					if (false !== $match) {
						self::registerRouteMiddleware($options['middleware'] ?? null);
						if ($route instanceof \Closure) {
							$return = self::invokeRule($route, $match);
							return is_string($return) ? $return : '';
						}
						$return = self::parseRule($rule, $route, $pathinfo);
						return is_string($return) ? $return : '';
					}
				} else {
					return empty($pathinfo) ? '' : $pathinfo;
				}
			}
		}

		$miss = RouteRegistry::getMiss();
		if ($miss !== null && $miss !== '') {
			return is_string($miss) ? $miss : '';
		}

		return empty($pathinfo) ? '' : $pathinfo;
	}

	/**
	 * Check Url Match
	 * @param mixed $regx
	 * @param mixed $rule
	 * @return mixed
	 */
	/**
	 * 按路由规则的 middleware 选项注册中间件（2026-11-13）
	 *
	 * 支持三种写法：
	 *   'middleware' => 'APP\\Middleware\\Foo'
	 *   'middleware' => ['APP\\Middleware\\Foo', 'APP\\Middleware\\Bar']
	 *   'middleware' => [['class' => 'APP\\Middleware\\Foo', 'config' => [...]]]
	 *
	 * 注册到 After 阶段：Before 在路由解析前执行，那时路由还没匹配出来。
	 *
	 * @param mixed $mw middleware 选项值
	 * @return void
	 */
	private static function registerRouteMiddleware($mw): void
	{
		if (empty($mw)) {
			return;
		}
		$items = is_array($mw) ? $mw : [$mw];
		foreach ($items as $item) {
			$cls = '';
			$cfg = [];
			if (is_string($item)) {
				$cls = $item;
			} elseif (is_array($item)) {
				$cls = (string) ($item['class'] ?? $item[0] ?? '');
				$cfg = (array) ($item['config'] ?? $item[1] ?? []);
			}
			if ($cls === '') {
				continue;
			}
\Candy\Core\Security::register($cls, 'After', $cfg);
		}
	}

	private static function checkUrlMatch(string $regx, string $rule)
	{
		$m1 = explode('/', $regx);
		$m2 = explode('/', $rule);
		$var = [];
		foreach ($m2 as $key => $val) {
			if (0 === strpos($val, '[:')) {
				$val = substr($val, 1, -1);
			}

			if (':' == substr($val, 0, 1)) {
				if ($pos = strpos($val, '|')) {
					$val = substr($val, 1, $pos - 1);
				}
				if (strpos($val, '\\')) {
					$type = substr($val, -1);
					if ('d' == $type) {
						if (isset($m1[$key]) && !is_numeric($m1[$key])) {
							return false;
						}
					}
					$name = substr($val, 1, -2);
				} elseif ($pos = strpos($val, '^')) {
					$array = explode('-', substr(strstr($val, '^'), 1));
					if (in_array($m1[$key], $array)) {
						return false;
					}
					$name = substr($val, 1, $pos - 1);
				} else {
					$name = substr($val, 1);
				}
				$var[$name] = isset($m1[$key]) ? $m1[$key] : '';
			} elseif (0 !== strcasecmp($val, $m1[$key])) {
				return false;
			}
		}
		return $var;
	}

	/**
	 * Parse Rule
	 * @param mixed $rule
	 * @param mixed $route
	 * @param mixed $regx
	 * @return string
	 */
	private static function parseRule(string $rule, $route, string $regx): string
	{
		$url = is_array($route) ? $route[0] : $route;
		$paths = explode('/', $regx);
		$matches = [];
		$rule = explode('/', $rule);
		foreach ($rule as $item) {
			$fun = '';
			if (0 === strpos($item, '[:')) {
				$item = substr($item, 1, -1);
			}
			if (0 === strpos($item, ':')) {
				if ($pos = strpos($item, '|')) {
					$fun = substr($item, $pos + 1);
					$item = substr($item, 0, $pos);
				}
				if ($pos = strpos($item, '^')) {
					$var = substr($item, 1, $pos - 1);
				} elseif (strpos($item, '\\')) {
					$var = substr($item, 1, -2);
				} else {
					$var = substr($item, 1);
				}

				$matches[$var] = !empty($fun) ? $fun(array_shift($paths)) : array_shift($paths);
			} else {
				array_shift($paths);
			}
		}
		if (0 === strpos($url, '/') || 0 === strpos($url, 'http')) {
			if (strpos($url, ':')) {
				$values = array_values($matches);
				$url = preg_replace_callback('/:(\d+)/', function ($match) use ($values) {
					return $values[$match[1] - 1];
				}, $url);
			}
			headers("Location: $url");
			stop();
		}

		$var = [];
		$values = array_values($matches);
		foreach ($var as $key => $val) {
			if (0 === strpos($val, ':')) {
				$var[$key] = $values[substr($val, 1) - 1];
			}
		}
		$var = array_merge($matches, $var);
		if (!empty($paths)) {
			preg_match_all('/(\w+)\/([^\/]+)/', implode('/', $paths), $pathMatches);
			foreach ($pathMatches[1] as $pk => $pv) {
				$var[strtolower($pv)] = strip_tags($pathMatches[2][$pk]);
			}
		}
		if (is_array($route) && isset($route[1])) {
			if (is_array($route[1])) {
				$params = $route[1];
			} else {
				parse_str($route[1], $params);
			}
			$var = array_merge($var, $params);
		}
		$varStr = '';
		if (!empty($var)) {
			$varStr = '/';
			foreach ($var as $key => $value) {
				$varStr .= $key . '/' . $value . '/';
			}
			$varStr = rtrim($varStr, '/');
		}
		return $url . $varStr;
	}

	/**
	 * Parse Regex
	 * @param mixed $matches
	 * @param mixed $route
	 * @param mixed $regx
	 * @return string
	 */
	private static function parseRegex(array $matches, $route, string $regx): string
	{
		$url = is_array($route) ? $route[0] : $route;
		$url = preg_replace_callback('/:(\d+)/', function ($match) use ($matches) {
			return $matches[$match[1]];
		}, $url);
		if (0 === strpos($url, '/') || 0 === strpos($url, 'http')) {
			headers("Location: $url");
			stop();
		}

		$var = [];
		$regx = substr_replace($regx, '', 0, strlen($matches[0]));
		if ($regx) {
			preg_match_all('/(\w+)\/([^\/]+)/', $regx, $pathMatches);
			foreach ($pathMatches[1] as $pk => $pv) {
				$var[strtolower($pv)] = strip_tags($pathMatches[2][$pk]);
			}
		}
		if (is_array($route) && isset($route[1])) {
			if (is_array($route[1])) {
				$params = $route[1];
			} else {
				parse_str($route[1], $params);
			}
			$var = array_merge($var, $params);
		}
		$varStr = '';
		if (!empty($var)) {
			$varStr = '/';
			foreach ($var as $key => $value) {
				$varStr .= $key . '/' . $value . '/';
			}
			$varStr = rtrim($varStr, '/');
		}
		return $url . $varStr;
	}

	/**
	 * Invoke Regx
	 * @param mixed $closure
	 * @param mixed $var
	 * @return mixed
	 */
	private static function invokeRegx($closure, array $var = []): mixed
	{
		$reflect = new \ReflectionFunction($closure);
		$params = $reflect->getParameters();
		$args = [];
		array_shift($var);
		foreach ($params as $param) {
			if (!empty($var)) {
				$args[] = array_shift($var);
			} elseif ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
			}
		}
		return $reflect->invokeArgs($args);
	}

	/**
	 * Invoke Rule
	 * @param mixed $closure
	 * @param mixed $var
	 * @return mixed
	 */
	private static function invokeRule($closure, array $var = []): mixed
	{
		$reflect = new \ReflectionFunction($closure);
		$params = $reflect->getParameters();
		$args = [];
		foreach ($params as $param) {
			$name = $param->getName();
			if (isset($var[$name])) {
				$args[] = $var[$name];
			} elseif ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
			}
		}
		return $reflect->invokeArgs($args);
	}
}
