<?php
/***
 * OpenAPI 风格 Schema 构造助手
 *
 * 业务说明：拼装 properties / format；供 SchemaProvider 使用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 18:43:40 $
 *
 * @package Candy\Extend\Schema
 */

declare(strict_types=1);

namespace Candy\Extend\Schema;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Schema 片段工厂
 */
final class Schema
{
	/**
	 * @param string $description 说明
	 * @return array<string,mixed>
	 */
	public static function string(string $description = ''): array
	{
		return self::typed('string', $description);
	}

	/**
	 * @param string $description 说明
	 * @return array<string,mixed>
	 */
	public static function integer(string $description = ''): array
	{
		return self::typed('integer', $description);
	}

	/**
	 * @param string $description 说明
	 * @return array<string,mixed>
	 */
	public static function number(string $description = ''): array
	{
		return self::typed('number', $description);
	}

	/**
	 * @param string $description 说明
	 * @return array<string,mixed>
	 */
	public static function boolean(string $description = ''): array
	{
		return self::typed('boolean', $description);
	}

	/**
	 * @param array<string,mixed> $schema schema
	 * @param string $format format
	 * @return array<string,mixed>
	 */
	public static function format(array $schema, string $format): array
	{
		$schema['format'] = $format;
		return $schema;
	}

	/**
	 * @param array<string,mixed> $schema schema
	 * @param mixed $example 示例
	 * @return array<string,mixed>
	 */
	public static function example(array $schema, mixed $example): array
	{
		$schema['example'] = $example;
		return $schema;
	}

	/**
	 * 仅透传 x-* 扩展
	 *
	 * @param array<string,mixed> $schema schema
	 * @param array<string,mixed> $ext 扩展
	 * @return array<string,mixed>
	 */
	public static function with(array $schema, array $ext): array
	{
		foreach ($ext as $k => $v) {
			if (is_string($k) && str_starts_with($k, 'x-')) {
				$schema[$k] = $v;
			}
		}
		return $schema;
	}

	/**
	 * @param string $type 类型
	 * @param string $description 说明
	 * @return array<string,mixed>
	 */
	private static function typed(string $type, string $description): array
	{
		$s = ['type' => $type];
		if ($description !== '') {
			$s['description'] = $description;
		}
		return $s;
	}
}

