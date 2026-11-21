<?php
/***
 * Apidoc 字段到 OpenAPI Schema 映射
 *
 * 业务说明：将 query/param/responseSuccess 等 Apidoc 字段结构转为 OAS 3 片段
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 23:07:48 $
 *
 * @package Candy\Extend\OpenApi
 */

declare(strict_types=1);

namespace Candy\Extend\OpenApi;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 字段映射辅助
 */
final class FieldMapper
{
	/**
	 * Apidoc 类型名 → OAS type
	 *
	 * @param string $type Apidoc 类型
	 * @return array{type:string,format?:string}
	 */
	public static function mapType(string $type): array
	{
		$t = strtolower(trim($type));
		return match ($t) {
			'int', 'integer' => ['type' => 'integer'],
			'float', 'double', 'number' => ['type' => 'number'],
			'bool', 'boolean' => ['type' => 'boolean'],
			'array' => ['type' => 'array', 'items' => ['type' => 'string']],
			'object' => ['type' => 'object'],
			'file' => ['type' => 'string', 'format' => 'binary'],
			default => ['type' => 'string'],
		};
	}

	/**
	 * 将 Apidoc 字段列表转为 OAS parameters（query/header/path）
	 *
	 * @param list<array<string,mixed>> $fields 字段
	 * @param string $in query|header|path
	 * @return list<array<string,mixed>>
	 */
	public static function toParameters(array $fields, string $in): array
	{
		$out = [];
		foreach ($fields as $field) {
			if (!is_array($field) || empty($field['name'])) {
				continue;
			}
			$name = (string)$field['name'];
			$schema = self::mapType((string)($field['type'] ?? 'string'));
			// array 在 query 时常为多值
			if (($schema['type'] ?? '') === 'array' && empty($schema['items'])) {
				$schema['items'] = ['type' => 'string'];
			}
			$param = [
				'name'     => $name,
				'in'       => $in,
				'required' => self::isRequired($field),
				'schema'   => $schema,
			];
			$desc = trim((string)($field['desc'] ?? ''));
			if ($desc !== '') {
				$param['description'] = $desc;
			}
			$out[] = $param;
		}
		return $out;
	}

	/**
	 * 将 Apidoc body 字段转为 requestBody schema
	 *
	 * @param list<array<string,mixed>> $fields 字段
	 * @return array<string,mixed>
	 */
	public static function toObjectSchema(array $fields): array
	{
		$properties = [];
		$required = [];
		foreach ($fields as $field) {
			if (!is_array($field) || empty($field['name'])) {
				continue;
			}
			$name = (string)$field['name'];
			$prop = self::fieldToSchema($field);
			$properties[$name] = $prop;
			if (self::isRequired($field)) {
				$required[] = $name;
			}
		}
		$schema = [
			'type'       => 'object',
			'properties' => $properties,
		];
		if ($required !== []) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * 单字段 → schema（含 children）
	 *
	 * @param array<string,mixed> $field 字段
	 * @return array<string,mixed>
	 */
	public static function fieldToSchema(array $field): array
	{
		$type = strtolower((string)($field['type'] ?? 'string'));
		$schema = self::mapType($type);
		$desc = trim((string)($field['desc'] ?? ''));
		if ($desc !== '') {
			$schema['description'] = $desc;
		}

		$children = $field['children'] ?? null;
		if (is_array($children) && $children !== []) {
			if ($type === 'array') {
				$schema['type'] = 'array';
				$schema['items'] = self::toObjectSchema($children);
			} else {
				$schema = array_merge($schema, self::toObjectSchema($children));
				$schema['type'] = 'object';
			}
		} elseif ($type === 'array' && empty($schema['items'])) {
			$schema['items'] = ['type' => 'string'];
		}

		return $schema;
	}

	/**
	 * responseSuccess 字段列表 → 响应 object schema
	 *
	 * @param list<array<string,mixed>> $fields 成功响应字段
	 * @return array<string,mixed>
	 */
	public static function responseFieldsToSchema(array $fields): array
	{
		if ($fields === []) {
			return [
				'type' => 'object',
				'additionalProperties' => true,
			];
		}
		return self::toObjectSchema($fields);
	}

	/**
	 * @param array<string,mixed> $field 字段
	 * @return bool
	 */
	private static function isRequired(array $field): bool
	{
		$r = $field['required'] ?? false;
		if (is_bool($r)) {
			return $r;
		}
		if (is_int($r) || is_float($r)) {
			return (int)$r === 1;
		}
		$s = strtolower((string)$r);
		return in_array($s, ['1', 'true', 'yes', 'require'], true);
	}
}

