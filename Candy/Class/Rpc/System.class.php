<?php
/***
 * Rpc 内置系统服务
 *
 * 业务说明：health 探活；listLibs/fetchLib 远程类库（加密验签）；接口清单
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 10:15:24 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 内置 System 服务（始终注册为 System）
 */
class System
{
	/**
	 * 健康检查与基本配置
	 *
	 * @param bool $detail true=带方法形参；false=仅服务名与方法名
	 * @return array<string,mixed>
	 */
	public function health(bool $detail = true): array
	{
		return [
			'ok'            => true,
			'service'       => 'CandyRpc',
			'time'          => time(),
			'listen'        => Config::listen(),
			'protocol'      => Config::protocol(),
			'codec_default' => Config::resolveCodec(),
			'scan'          => Config::scanEnabled(),
			'pid'           => getmypid(),
			'php'           => PHP_VERSION,
			'remote_lib'    => Config::remoteLibEnabled(),
			'interfaces'    => self::collectInterfaces($detail),
		];
	}

	/**
	 * 列出本机允许远程导出的类库名（不含路径）
	 *
	 * @return array{ok:bool,libs:list<string>}
	 */
	public function listLibs(): array
	{
		if (!Config::remoteLibEnabled()) {
			throw new \RuntimeException('RemoteLib disabled', -32013);
		}
		return [
			'ok'   => true,
			'libs' => array_keys(Config::remoteLibAllow()),
		];
	}

	/**
	 * 导出白名单类库（加密+签名包，供 A 安装）
	 *
	 * @param string $name 白名单逻辑名
	 * @return array<string,mixed> RemoteLib 数据包
	 */
	public function fetchLib(string $name): array
	{
		if (!Config::remoteLibEnabled()) {
			throw new \RuntimeException('RemoteLib disabled', -32013);
		}
		$allow = Config::remoteLibAllow();
		if (!isset($allow[$name]) || !is_string($allow[$name]) || $allow[$name] === '') {
			throw new \RuntimeException("RemoteLib not allowed: {$name}", -32014);
		}
		$resolved = RemoteLib::resolveAllowEntry($name, $allow[$name]);
		return RemoteLib::packFile($name, $resolved['path'], $resolved['fqcn']);
	}

	/**
	 * 汇总已注册服务的「已暴露」方法（配置白名单 / #[Expose] / 默认公开）
	 *
	 * 按 FQCN 去重；names 列出全部别名（如 Ping 与 Home/Ping）
	 *
	 * @param bool $detail 是否包含参数/返回类型信息
	 * @return list<array<string,mixed>>
	 */
	private static function collectInterfaces(bool $detail): array
	{
		/** @var array<string,array{names:list<string>,class:string}> $byClass */
		$byClass = [];
		foreach (Registry::map() as $alias => $entry) {
			$fqcn = $entry['class'];
			if (!isset($byClass[$fqcn])) {
				$byClass[$fqcn] = [
					'names' => [],
					'class' => $fqcn,
				];
			}
			$byClass[$fqcn]['names'][] = $alias;
		}

		$list = [];
		foreach ($byClass as $item) {
			sort($item['names']);
			$primary = $item['names'][0];
			$exported = Registry::exportedMethodNames($primary);
			$methods = [];
			try {
				$ref = new \ReflectionClass($item['class']);
				foreach ($exported as $name) {
					if (!$ref->hasMethod($name)) {
						continue;
					}
					$method = $ref->getMethod($name);
					if ($detail) {
						$methods[$name] = [
							'params' => self::describeParams($method),
							'return' => self::describeReturn($method),
						];
					} else {
						$methods[] = $name;
					}
				}
			} catch (\Throwable) {
				$methods = $detail ? [] : [];
			}

			$list[] = [
				'names'   => $item['names'],
				'class'   => $item['class'],
				'methods' => $methods,
			];
		}

		usort($list, static fn(array $a, array $b): int => strcmp($a['names'][0] ?? '', $b['names'][0] ?? ''));
		return $list;
	}

	/**
	 * 描述方法参数
	 *
	 * @param \ReflectionMethod $method 方法反射
	 * @return list<array{name:string,type:?string,optional:bool,default:mixed}>
	 */
	private static function describeParams(\ReflectionMethod $method): array
	{
		$params = [];
		foreach ($method->getParameters() as $parameter) {
			$type = $parameter->getType();
			$typeName = null;
			if ($type instanceof \ReflectionNamedType) {
				$typeName = $type->getName();
			} elseif ($type instanceof \ReflectionUnionType) {
				$typeName = implode('|', array_map(
					static fn(\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : (string)$t,
					$type->getTypes()
				));
			}

			$default = null;
			$hasDefault = $parameter->isDefaultValueAvailable();
			if ($hasDefault) {
				try {
					$default = $parameter->getDefaultValue();
				} catch (\Throwable) {
					$default = null;
				}
			}

			$params[] = [
				'name'     => $parameter->getName(),
				'type'     => $typeName,
				'optional' => $parameter->isOptional(),
				'default'  => $hasDefault ? $default : null,
			];
		}
		return $params;
	}

	/**
	 * 描述返回类型
	 *
	 * @param \ReflectionMethod $method 方法反射
	 * @return string|null
	 */
	private static function describeReturn(\ReflectionMethod $method): ?string
	{
		$type = $method->getReturnType();
		if ($type instanceof \ReflectionNamedType) {
			return $type->getName();
		}
		if ($type instanceof \ReflectionUnionType) {
			return implode('|', array_map(
				static fn(\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : (string)$t,
				$type->getTypes()
			));
		}
		return null;
	}
}

