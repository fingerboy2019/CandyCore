<?php

namespace Candy\Core;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/** Candy 请求协程上下文适配器，统一 Swoole 与 Workerman Fiber/Swow。 */
final class CoroutineContext
{
	/** 防止首次加载 Workerman Context 时其初始化过程递归回 Candy 配置读取。 */
	private static bool $loadingContext = false;
	private static \ArrayObject $loadingFallback;

	public static function active(): bool
	{
		return function_exists('isRequestCoroutine') && isRequestCoroutine();
	}

	public static function getContext(): mixed
	{
		if (extension_loaded('swoole') && class_exists('Swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
			return \Swoole\Coroutine::getContext();
		}
		// Candy 的早期自动加载可能先于 Workerman 自带 loader 执行；Fiber
		// 模式直接使用具体上下文实现，避免加载 Context 包装类时递归触发 C()。
		if (!class_exists('Workerman\\Coroutine\\Context\\Fiber', false)) {
			if (self::$loadingContext) {
				return self::$loadingFallback ??= new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);
			}
			$file = defined('CANDYPATH') ? CANDYPATH . 'Candy/Vendor/Workerman/Coroutine/Context/Fiber.php' : '';
			if ($file !== '' && is_file($file)) {
				self::$loadingContext = true;
				try {
					require_once $file;
				} finally {
					self::$loadingContext = false;
				}
			}
		}
		return \Workerman\Coroutine\Context\Fiber::get();
	}
}
