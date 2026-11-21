<?php
/***
 * Payload metadata 预过滤
 *
 * 业务说明：支持等值与 ['$eq'=>x]；嵌套键用点号
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 23:25:52 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * MetadataFilter
 */
final class MetadataFilter
{
	/**
	 * 是否匹配
	 *
	 * @param array<string,mixed>|null $payload
	 * @param array<string,mixed>|null $filter
	 * @return bool
	 */
	public static function match(?array $payload, ?array $filter): bool
	{
		if ($filter === null || $filter === []) {
			return true;
		}
		$payload = $payload ?? [];
		foreach ($filter as $key => $expect) {
			$key = (string)$key;
			$actual = self::getPath($payload, $key);
			if (is_array($expect) && array_key_exists('$eq', $expect)) {
				if (!self::equals($actual, $expect['$eq'])) {
					return false;
				}
				continue;
			}
			if (!self::equals($actual, $expect)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param string $path
	 * @return mixed
	 */
	private static function getPath(array $data, string $path): mixed
	{
		if ($path === '') {
			return null;
		}
		if (!str_contains($path, '.')) {
			return $data[$path] ?? null;
		}
		$cur = $data;
		foreach (explode('.', $path) as $seg) {
			if (!is_array($cur) || !array_key_exists($seg, $cur)) {
				return null;
			}
			$cur = $cur[$seg];
		}
		return $cur;
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 * @return bool
	 */
	private static function equals(mixed $a, mixed $b): bool
	{
		if (is_numeric($a) && is_numeric($b)) {
			return (float)$a === (float)$b;
		}
		return $a == $b;
	}
}
