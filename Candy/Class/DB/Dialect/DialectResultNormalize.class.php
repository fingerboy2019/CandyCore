<?php
/***
 * 方言结果键规范化 Trait
 *
 * 业务说明：主库统一小写列名/行键/查询结果键，供 Mysql/Pgsql/Sqlite/Sqlsrv 复用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 17:55:00 $
 *
 * @package Candy\Extend\DB\Dialect
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Dialect;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

trait DialectResultNormalize
{
	/**
	 * 逻辑列名规范化（统一小写）
	 *
	 * @param string $name 物理或逻辑列名
	 * @return string
	 */
	public static function normalizeColumnName(string $name): string
	{
		return strtolower(trim($name));
	}

	/**
	 * 关联数组键名统一为小写
	 *
	 * @param array<string, mixed>|null $row 单行数据
	 * @return array<string, mixed>|null
	 */
	public static function normalizeRowKeys(?array $row): ?array
	{
		if ($row === null) {
			return null;
		}

		return array_change_key_case($row, CASE_LOWER);
	}

	/**
	 * 将查询结果字段名统一为小写
	 *
	 * @param mixed $result query 返回值
	 * @param string $method select|find|total|insert|update|delete|other
	 * @return mixed
	 */
	public static function normalizeResultKeys(mixed $result, string $method): mixed
	{
		if ($method === 'find' && is_array($result)) {
			return self::normalizeRowKeys($result);
		}
		if ($method === 'select' && is_array($result)) {
			return array_map(
				static fn ($row) => is_array($row) ? (self::normalizeRowKeys($row) ?? []) : $row,
				$result
			);
		}

		return $result;
	}
}
