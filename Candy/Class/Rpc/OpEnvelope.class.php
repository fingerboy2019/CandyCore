<?php
/***
 * Rpc 远端结果信封（data + queries + ops）
 *
 * 业务说明：B 可返回查询方案 queries（A 本地查库）与写库 ops（A 本地落库）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 09:54:32 $
 *
 * @package Candy\Extend\Rpc
 */

namespace Candy\Extend\Rpc;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * 标准返回：['data'=>mixed, 'queries'=>[], 'ops'=>[], 'apply_order'=>?string]
 */
final class OpEnvelope
{
	public const KEY_DATA        = 'data';
	public const KEY_OPS         = 'ops';
	public const KEY_QUERIES     = 'queries';
	public const KEY_APPLY_ORDER = 'apply_order';

	/**
	 * 打包信封；若含 ops/queries 且开启 apply_auth，则自动签名
	 *
	 * @param mixed        $data       业务种子数据（可为 null）
	 * @param list<array>  $ops        写库命令
	 * @param list<array>  $queries    查询方案
	 * @param string|null  $applyOrder query_op|op_query，null 表示用配置默认
	 * @param bool|null    $sign       true 强制签；false 不签；null 跟配置
	 * @return array<string,mixed>
	 */
	public static function pack(
		mixed $data,
		array $ops = [],
		array $queries = [],
		?string $applyOrder = null,
		?bool $sign = null,
	): array {
		$out = [
			self::KEY_DATA    => $data,
			self::KEY_QUERIES => array_values($queries),
			self::KEY_OPS     => array_values($ops),
		];
		if ($applyOrder !== null && $applyOrder !== '') {
			$out[self::KEY_APPLY_ORDER] = $applyOrder;
		}

		$doSign = $sign;
		if ($doSign === null) {
			$doSign = Config::applyAuth() && ApplyAuth::requiresAuth($out) && Config::applySecret() !== '';
		}
		if ($doSign) {
			$out = ApplyAuth::sign($out);
		}
		return $out;
	}

	/**
	 * 是否为信封（含 ops 或 queries 键）
	 *
	 * @param mixed $result Rpc result
	 * @return bool
	 */
	public static function isEnvelope(mixed $result): bool
	{
		if (!is_array($result)) {
			return false;
		}
		$hasOps = array_key_exists(self::KEY_OPS, $result) && is_array($result[self::KEY_OPS]);
		$hasQueries = array_key_exists(self::KEY_QUERIES, $result) && is_array($result[self::KEY_QUERIES]);
		return $hasOps || $hasQueries;
	}

	/**
	 * 规范化信封
	 *
	 * @param mixed $result Rpc result
	 * @return array<string,mixed>
	 */
	public static function normalize(mixed $result): array
	{
		if (!self::isEnvelope($result)) {
			return [
				self::KEY_DATA        => $result,
				self::KEY_QUERIES     => [],
				self::KEY_OPS         => [],
				self::KEY_APPLY_ORDER => null,
			];
		}

		$order = $result[self::KEY_APPLY_ORDER] ?? null;

		return [
			self::KEY_DATA        => $result[self::KEY_DATA] ?? null,
			self::KEY_QUERIES     => self::listOfArrays($result[self::KEY_QUERIES] ?? []),
			self::KEY_OPS         => self::listOfArrays($result[self::KEY_OPS] ?? []),
			self::KEY_APPLY_ORDER => is_string($order) ? $order : null,
		];
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public static function data(mixed $result): mixed
	{
		return self::normalize($result)[self::KEY_DATA];
	}

	/**
	 * @param mixed $result
	 * @return list<array>
	 */
	public static function ops(mixed $result): array
	{
		return self::normalize($result)[self::KEY_OPS];
	}

	/**
	 * @param mixed $result
	 * @return list<array>
	 */
	public static function queries(mixed $result): array
	{
		return self::normalize($result)[self::KEY_QUERIES];
	}

	/**
	 * @param mixed $items
	 * @return list<array>
	 */
	private static function listOfArrays(mixed $items): array
	{
		if (!is_array($items)) {
			return [];
		}
		$out = [];
		foreach ($items as $item) {
			if (is_array($item)) {
				$out[] = $item;
			}
		}
		return $out;
	}
}

