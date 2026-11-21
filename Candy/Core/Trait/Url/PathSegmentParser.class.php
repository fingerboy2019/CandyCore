<?php
/**
 * PATH 段解析器
 *
 * 将规范化后的 PATH_INFO 路径段解析为 $_GET 路由参数（g/m/a 及键值对）。
 *
 * @package Candy\Core\Trait\Url
 */

declare(strict_types=1);

namespace Candy\Core\Trait\Url;

use Candy\Core\Request;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class PathSegmentParser
{
	/** @var list<string> 路径段允许的内置过滤函数 */
	private const ALLOWED_PATH_FUNCS = [
		'intval', 'floatval', 'trim', 'strip_tags', 'htmlspecialchars',
		'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode',
	];

	/**
	 * 将 PATH_INFO 路径解析为 $_GET 参数
	 */
	public function parseToGet(string $pathInfoStr): void
	{
		$inCoroutine = Request::inCoroutine();
		$get = I('get.', [], '');
		$segments = explode('/', $pathInfoStr);

		if (isset($segments[0]) && stripos($segments[0], '.') !== false && stripos($segments[0], '.') > 0) {
			array_shift($segments);
		}
		if (isset($segments[0]) && $segments[0] === 'Home') {
			array_shift($segments);
		}

		foreach ($segments as $index => $segment) {
			if (str_contains($segment, '|')) {
				[$rawValue, $filterFunc] = explode('|', $segment);
				if (in_array($filterFunc, self::ALLOWED_PATH_FUNCS, true)) {
					$segments[$index] = $filterFunc($rawValue);
				}
			}
		}

		$get['g'] = is_null(C('APP'))
			? (!empty($get['g']) ? ucfirst($get['g']) : (G('default.app') ?: 'Home'))
			: C('APP');

		// 始终从 PATH 段解析 m/a。Workerman 长驻时若沿用 C('CONTROL'/'ACTION')，
		// 会把上一请求的控制器/操作写回 $_GET，导致 Article/read 串到后续首页等请求。
		$rawControl = !empty($segments[0])
			? (stristr($segments[0], '.php') ? (G('default.control') ?: 'Index') : $segments[0])
			: (G('default.control') ?: 'Index');
		$get['m'] = \Candy\Extend\Str::controlName((string) $rawControl);
		array_shift($segments);

		$get['a'] = !empty($segments[0])
			? (stristr($segments[0], '.php') ? (G('default.action') ?: 'index') : $segments[0])
			: (G('default.action') ?: 'index');
		array_shift($segments);

		for ($i = 0, $segmentCount = count($segments); $i < $segmentCount; $i += 2) {
			$get[$segments[$i]] = $segments[$i + 1] ??= '';
		}

		// PATH 段已完整灌入 g/m/a 与业务参数后统一同步两套上下文。
		// 不在解析中途同步，避免应用判定仍使用旧 PATH_INFO。
		if ($inCoroutine) {
			R()->replaceGet($get);
		} else {
			$_GET = $get;
		}
	}
}
