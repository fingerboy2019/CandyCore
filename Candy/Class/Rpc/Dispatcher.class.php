<?php
/***
 * Rpc 方法调度器
 *
 * 业务说明：解析注册对象，仅调用已暴露方法（配置 methods / #[Expose] / 默认公开），返回 return 值
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:34:53 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 远程方法调度
 */
final class Dispatcher
{
	/**
	 * 调用已注册且已暴露的服务方法
	 *
	 * @param string $service 服务名
	 * @param string $method  方法名
	 * @param array  $params  位置参数或关联参数
	 * @return mixed 方法返回值
	 * @throws \Throwable 调用失败时抛出
	 */
	public static function call(string $service, string $method, array $params = []): mixed
	{
		if ($method === '' || str_starts_with($method, '__')) {
			throw new \RuntimeException("RPC method not allowed: {$method}", -32601);
		}

		$object = Registry::resolve($service);
		if (!method_exists($object, $method)) {
			throw new \RuntimeException("RPC method not found: {$service}::{$method}", -32601);
		}

		$ref = new \ReflectionMethod($object, $method);
		if (!Registry::isMethodExposed($service, $method, $ref)) {
			throw new \RuntimeException("RPC method not exposed: {$service}::{$method}", -32601);
		}

		$args = self::bindParams($ref, $params);
		return $ref->invokeArgs($object, $args);
	}

	/**
	 * 将 params 绑定到方法形参
	 *
	 * @param \ReflectionMethod $ref    方法反射
	 * @param array             $params 入参
	 * @return list<mixed>
	 */
	private static function bindParams(\ReflectionMethod $ref, array $params): array
	{
		if ($params === []) {
			return [];
		}

		if (array_is_list($params)) {
			return $params;
		}

		$args = [];
		foreach ($ref->getParameters() as $parameter) {
			$name = $parameter->getName();
			if (array_key_exists($name, $params)) {
				$args[] = $params[$name];
				continue;
			}
			if ($parameter->isDefaultValueAvailable()) {
				$args[] = $parameter->getDefaultValue();
				continue;
			}
			if ($parameter->allowsNull()) {
				$args[] = null;
				continue;
			}
			throw new \InvalidArgumentException("Missing RPC param: {$name}", -32602);
		}

		return $args;
	}
}

