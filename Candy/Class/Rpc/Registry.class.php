<?php
/***
 * Rpc 服务注册表
 *
 * 业务说明：配置白名单优先，扫描 Application/{App}/Rpc；可按 methods / #[Expose] 限制可调方法
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:34:53 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 服务名 → 类名 / 可选方法白名单
 *
 * @phpstan-type Entry array{class:class-string, methods:?list<string>}
 */
final class Registry
{
	/** @var array<string,Entry>|null */
	private static ?array $map = null;

	/** @var array<string,object> */
	private static array $instances = [];

	/**
	 * 解析服务实例
	 *
	 * @param string $name 服务名，如 Freight 或 Home/Freight
	 * @return object
	 * @throws \RuntimeException 未注册时抛出
	 */
	public static function resolve(string $name): object
	{
		$entry = self::entry($name);
		$fqcn = $entry['class'];
		if (!isset(self::$instances[$fqcn])) {
			if (!class_exists($fqcn)) {
				throw new \RuntimeException("RPC service class not found: {$fqcn}", -32001);
			}
			self::$instances[$fqcn] = new $fqcn();
		}

		return self::$instances[$fqcn];
	}

	/**
	 * 取注册项（类 + 可选配置方法白名单）
	 *
	 * @param string $name 服务名
	 * @return Entry
	 * @throws \RuntimeException 未注册时抛出
	 */
	public static function entry(string $name): array
	{
		$map = self::map();
		$key = self::normalizeName($name);
		if (!isset($map[$key])) {
			throw new \RuntimeException("RPC service not registered: {$name}", -32001);
		}
		return $map[$key];
	}

	/**
	 * 是否已注册
	 *
	 * @param string $name 服务名
	 * @return bool
	 */
	public static function has(string $name): bool
	{
		return isset(self::map()[self::normalizeName($name)]);
	}

	/**
	 * 判断方法是否允许远程调用
	 *
	 * 优先级：配置 methods > 类上 #[Expose] 集合 > 全部公开实例方法
	 *
	 * @param string             $service 服务名
	 * @param string             $method  方法名
	 * @param \ReflectionMethod  $ref     方法反射
	 * @return bool
	 */
	public static function isMethodExposed(string $service, string $method, \ReflectionMethod $ref): bool
	{
		if (!$ref->isPublic() || $ref->isStatic() || str_starts_with($method, '__')) {
			return false;
		}

		$entry = self::entry($service);
		$configMethods = $entry['methods'];
		if (is_array($configMethods)) {
			return in_array($method, $configMethods, true);
		}

		$exposed = self::attributeExposedMethods($entry['class']);
		if ($exposed !== null) {
			return in_array($method, $exposed, true);
		}

		return true;
	}

	/**
	 * 列出某服务对外可调方法名
	 *
	 * @param string $service 服务名
	 * @return list<string>
	 */
	public static function exportedMethodNames(string $service): array
	{
		$entry = self::entry($service);
		$fqcn = $entry['class'];
		$names = [];

		try {
			$ref = new \ReflectionClass($fqcn);
			foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				$name = $method->getName();
				if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
					continue;
				}
				if (!self::isMethodExposed($service, $name, $method)) {
					continue;
				}
				$names[] = $name;
			}
		} catch (\Throwable) {
			return [];
		}

		sort($names);
		return $names;
	}

	/**
	 * 获取完整映射
	 *
	 * @return array<string,Entry>
	 */
	public static function map(): array
	{
		if (self::$map !== null) {
			return self::$map;
		}

		$merged = [];
		if (Config::scanEnabled()) {
			foreach (self::scanApplicationRpc() as $name => $fqcn) {
				$merged[$name] = ['class' => $fqcn, 'methods' => null];
			}
		}

		foreach (Config::services() as $name => $def) {
			$parsed = self::parseServiceDefinition($def);
			if ($parsed === null) {
				continue;
			}
			$merged[self::normalizeName((string)$name)] = $parsed;
		}

		$merged['System'] = [
			'class'   => System::class,
			'methods' => ['health', 'listLibs', 'fetchLib'],
		];

		self::$map = $merged;
		return self::$map;
	}

	/**
	 * 重置注册表（测试 / reload）
	 *
	 * @return void
	 */
	public static function reset(): void
	{
		self::$map = null;
		self::$instances = [];
	}

	/**
	 * 解析配置中的服务定义
	 *
	 * 支持：
	 * - FQCN 字符串 → 全部公开方法（或类上 #[Expose]）
	 * - ['class' => FQCN, 'methods' => ['a','b']] → 仅白名单方法
	 *
	 * @param mixed $def 配置值
	 * @return Entry|null
	 */
	private static function parseServiceDefinition(mixed $def): ?array
	{
		if (is_string($def) && $def !== '') {
			return ['class' => $def, 'methods' => null];
		}
		if (!is_array($def)) {
			return null;
		}
		$class = $def['class'] ?? null;
		if (!is_string($class) || $class === '') {
			return null;
		}
		$methods = null;
		if (isset($def['methods']) && is_array($def['methods'])) {
			$methods = [];
			foreach ($def['methods'] as $m) {
				if (is_string($m) && $m !== '') {
					$methods[] = $m;
				}
			}
			$methods = array_values(array_unique($methods));
		}
		return ['class' => $class, 'methods' => $methods];
	}

	/**
	 * 读取类上带 #[Expose] 的方法名；若一个都没有则返回 null（表示未启用属性模式）
	 *
	 * @param class-string $fqcn 类名
	 * @return list<string>|null
	 */
	private static function attributeExposedMethods(string $fqcn): ?array
	{
		static $cache = [];
		if (array_key_exists($fqcn, $cache)) {
			return $cache[$fqcn];
		}

		try {
			$ref = new \ReflectionClass($fqcn);
		} catch (\Throwable) {
			return $cache[$fqcn] = null;
		}

		$found = [];
		$hasAny = false;
		foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$attrs = $method->getAttributes(Expose::class);
			if ($attrs === []) {
				continue;
			}
			$hasAny = true;
			if (!$method->isStatic() && !str_starts_with($method->getName(), '__')) {
				$found[] = $method->getName();
			}
		}

		return $cache[$fqcn] = $hasAny ? array_values(array_unique($found)) : null;
	}

	/**
	 * 扫描 Application/{App}/Rpc/*.class.php
	 *
	 * @return array<string,class-string>
	 */
	private static function scanApplicationRpc(): array
	{
		$root = defined('CANDYPATH') ? CANDYPATH : '';
		$pattern = $root . 'Application/*/Rpc/*.class.php';
		$files = glob($pattern) ?: [];
		$map = [];

		foreach ($files as $file) {
			$app = basename(dirname(dirname($file)));
			$name = basename($file, '.class.php');
			if ($app === '' || $name === '') {
				continue;
			}
			$fqcn = 'APP\\Rpc\\' . $app . '\\' . $name;
			$fullKey = self::normalizeName($app . '/' . $name);
			$map[$fullKey] = $fqcn;
			$short = self::normalizeName($name);
			if (!isset($map[$short])) {
				$map[$short] = $fqcn;
			}
		}

		return $map;
	}

	/**
	 * 规范化服务名
	 *
	 * @param string $name 原始名
	 * @return string
	 */
	private static function normalizeName(string $name): string
	{
		$name = str_replace('\\', '/', trim($name));
		return trim($name, '/');
	}
}

