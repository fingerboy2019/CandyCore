<?php
/***
 * MCP QueryForm 执行器
 *
 * 业务说明：强制 grant_token 绑库 → 编译 SQL → 超时 → 参数化查询 → 脱敏；finally 解绑
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:58:21 $
 *
 * @package Candy\Extend\Mcp\QueryForm
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\QueryForm;

use Candy\Extend\Mcp\AdminContext;
use Candy\Extend\Mcp\Grant;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Runner
{
	/**
	 * 执行明细查询
	 *
	 * @param array<string, mixed> $form
	 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
	 */
	public static function select(array $form): array
	{
		return self::withGrant($form, static function (array $f): array {
			return self::execute(SqlBuilder::select($f));
		});
	}

	/**
	 * 执行头 N 行采样
	 *
	 * @param array<string, mixed> $args
	 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
	 */
	public static function sample(array $args): array
	{
		return self::withGrant($args, static function (array $a): array {
			$table = (string) ($a['table'] ?? $a['from'] ?? '');
			$form = [
				'from'    => $table,
				'columns' => $a['columns'] ?? null,
				'limit'   => isset($a['limit']) ? (int) $a['limit'] : 20,
				'offset'  => 0,
			];

			return self::execute(SqlBuilder::select($form), 'sample');
		});
	}

	/**
	 * 执行聚合
	 *
	 * @param array<string, mixed> $form
	 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
	 */
	public static function aggregate(array $form): array
	{
		return self::withGrant($form, static function (array $f): array {
			return self::execute(SqlBuilder::aggregate($f));
		});
	}

	/**
	 * 执行 JOIN 明细
	 *
	 * @param array<string, mixed> $form
	 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
	 */
	public static function joinSelect(array $form): array
	{
		return self::withGrant($form, static function (array $f): array {
			return self::execute(SqlBuilder::joinSelect($f));
		});
	}

	/**
	 * 强制验 Token → 绑库 → 回调 → 解绑
	 *
	 * @param array<string, mixed> $args
	 * @param callable(array<string,mixed>):array{ok:bool,data?:mixed,error?:string} $fn
	 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
	 */
	public static function withGrant(array $args, callable $fn): array
	{
		if (!Config::isEnabled()) {
			return ['ok' => false, 'error' => 'db_query disabled in Mcp.inc'];
		}

		$token = trim((string) ($args['grant_token'] ?? $args['grantToken'] ?? ''));
		unset($args['grant_token'], $args['grantToken'], $args['tenant_id'], $args['tenantId']);

		AdminContext::boot('Admin');

		$bound = Grant::bindFromToken($token);
		if (!($bound['ok'] ?? false)) {
			Grant::unbind();
			return ['ok' => false, 'error' => (string) ($bound['error'] ?? 'grant_token required')];
		}

		try {
			$result = $fn($args);
			if (($result['ok'] ?? false) && is_array($result['data'] ?? null)) {
				$result['data'] = array_merge($result['data'], Grant::renewHint());
			}

			return $result;
		} finally {
			Grant::unbind();
		}
	}

	/**
	 * 执行已编译 SQL
	 *
	 * @param array{ok:bool,sql?:string,bind?:list<mixed>,meta?:array<string,mixed>,error?:string} $compiled
	 * @param string|null $modeOverride
	 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
	 */
	private static function execute(array $compiled, ?string $modeOverride = null): array
	{
		if (!($compiled['ok'] ?? false)) {
			return ['ok' => false, 'error' => (string) ($compiled['error'] ?? 'compile failed')];
		}

		$sql = (string) ($compiled['sql'] ?? '');
		$bind = $compiled['bind'] ?? [];
		if (!is_array($bind)) {
			$bind = [];
		}
		$meta = is_array($compiled['meta'] ?? null) ? $compiled['meta'] : [];
		if ($modeOverride !== null) {
			$meta['mode'] = $modeOverride;
		}

		try {
			$db = Grant::dbDriver();
			self::applyTimeout($db);
			$rows = $db->query($sql, 'select', $bind);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'query failed: ' . $e->getMessage()];
		}

		if (!is_array($rows)) {
			$rows = [];
		}
		$list = [];
		foreach ($rows as $row) {
			if (is_array($row)) {
				$list[] = $row;
			}
		}
		$masked = Mask::rows($list);

		return [
			'ok'   => true,
			'data' => [
				'table'   => $meta['table'] ?? null,
				'tables'  => $meta['tables'] ?? null,
				'mode'    => $meta['mode'] ?? 'select',
				'count'   => count($masked),
				'limit'   => $meta['limit'] ?? null,
				'offset'  => $meta['offset'] ?? 0,
				'rows'    => $masked,
				'note'    => 'Structured QueryForm only; grant_token required; no raw SQL; sensitive columns masked.',
			],
		];
	}

	/**
	 * 尽力设置语句超时
	 *
	 * @param object $db 驱动
	 * @return void
	 */
	private static function applyTimeout(object $db): void
	{
		$ms = Config::dbQuery()['timeout_ms'];
		if ($ms <= 0) {
			return;
		}
		$driver = strtolower((string) (G('DRIVER') ?: C('DRIVER') ?: ''));
		try {
			if (str_contains($driver, 'mysql')) {
				$db->query('SET SESSION MAX_EXECUTION_TIME = ' . (int) $ms, 'other', []);
			}
		} catch (\Throwable) {
		}
	}
}
