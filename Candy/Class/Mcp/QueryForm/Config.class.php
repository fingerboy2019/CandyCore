<?php
/***
 * MCP 查数配置门面
 *
 * 业务说明：读取 Mcp.inc db_query；提供启停、行数上限、超时、拒绝表/列
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:32:59 $
 *
 * @package Candy\Extend\Mcp\QueryForm
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\QueryForm;

use Candy\Extend\Mcp\Bootstrap;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Config
{
	/**
	 * 读取 db_query 段（缺省安全默认值）
	 *
	 * @return array{
	 *   enable:bool,
	 *   max_rows:int,
	 *   timeout_ms:int,
	 *   deny_tables:list<string>,
	 *   deny_columns:list<string>
	 * }
	 */
	public static function dbQuery(): array
	{
		$cfg = Bootstrap::config();
		$dq = is_array($cfg['db_query'] ?? null) ? $cfg['db_query'] : [];

		$enable = !array_key_exists('enable', $dq) || !empty($dq['enable']);

		$maxRows = (int) ($dq['max_rows'] ?? 100);
		if ($maxRows < 1) {
			$maxRows = 100;
		}
		if ($maxRows > 500) {
			$maxRows = 500;
		}

		$timeoutMs = (int) ($dq['timeout_ms'] ?? 10000);
		if ($timeoutMs < 0) {
			$timeoutMs = 0;
		}
		if ($timeoutMs > 120000) {
			$timeoutMs = 120000;
		}

		$denyTables = $dq['deny_tables'] ?? [];
		if (!is_array($denyTables)) {
			$denyTables = [];
		}
		$denyTables = array_values(array_map(
			static fn ($t) => strtolower((string) $t),
			$denyTables
		));

		$denyColumns = $dq['deny_columns'] ?? [];
		if (!is_array($denyColumns)) {
			$denyColumns = [];
		}
		$denyColumns = array_values(array_map(
			static fn ($c) => strtolower((string) $c),
			$denyColumns
		));

		return [
			'enable'       => $enable,
			'max_rows'     => $maxRows,
			'timeout_ms'   => $timeoutMs,
			'deny_tables'  => $denyTables,
			'deny_columns' => $denyColumns,
		];
	}

	/**
	 * 查数总开关是否开启
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool
	{
		return self::dbQuery()['enable'];
	}

	/**
	 * 夹紧 limit（默认 20）
	 *
	 * @param int|null $limit 入参
	 * @param int      $default 默认
	 * @return int
	 */
	public static function clampLimit(?int $limit, int $default = 20): int
	{
		$max = self::dbQuery()['max_rows'];
		$n = $limit ?? $default;
		if ($n < 1) {
			$n = $default;
		}
		if ($n > $max) {
			$n = $max;
		}

		return $n;
	}
}
