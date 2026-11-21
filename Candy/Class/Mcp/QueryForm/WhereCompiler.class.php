<?php
/***
 * MCP QueryForm WHERE 编译
 *
 * 业务说明：将结构化 where 列表编译为参数化 SQL；禁止 EXP/裸表达式
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:32:59 $
 *
 * @package Candy\Extend\Mcp\QueryForm
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\QueryForm;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class WhereCompiler
{
	/** @var array<string, string> op → SQL */
	private const OPS = [
		'eq'       => '=',
		'neq'      => '<>',
		'gt'       => '>',
		'gte'      => '>=',
		'lt'       => '<',
		'lte'       => '<=',
		'in'       => 'IN',
		'not_in'   => 'NOT IN',
		'like'     => 'LIKE',
		'not_like' => 'NOT LIKE',
		'between'  => 'BETWEEN',
		'is_null'  => 'IS NULL',
		'not_null' => 'IS NOT NULL',
	];

	/**
	 * 编译 where 列表
	 *
	 * 每项：{col, op, val?}；col 可为 alias.col
	 *
	 * @param mixed                $whereIn 入参
	 * @param array<string, array<string, string>> $aliasColumns alias => columnMap
	 * @param string|null          $defaultAlias 无前缀列默认别名
	 * @return array{ok:bool,sql?:string,bind?:list<mixed>,error?:string}
	 */
	public static function compile(mixed $whereIn, array $aliasColumns, ?string $defaultAlias = null): array
	{
		if ($whereIn === null || $whereIn === [] || $whereIn === '') {
			return ['ok' => true, 'sql' => '', 'bind' => []];
		}
		if (!is_array($whereIn)) {
			return ['ok' => false, 'error' => 'where must be an array'];
		}

		// 兼容旧 db_select：关联数组等值
		if (self::isAssoc($whereIn)) {
			$list = [];
			foreach ($whereIn as $k => $v) {
				$list[] = ['col' => (string) $k, 'op' => 'eq', 'val' => $v];
			}
			$whereIn = $list;
		}

		$parts = [];
		$bind = [];
		foreach ($whereIn as $i => $item) {
			if (!is_array($item)) {
				return ['ok' => false, 'error' => 'where[' . $i . '] must be object'];
			}
			$colRaw = trim((string) ($item['col'] ?? ''));
			$opRaw = strtolower(trim((string) ($item['op'] ?? 'eq')));
			if ($colRaw === '') {
				return ['ok' => false, 'error' => 'where[' . $i . '].col required'];
			}
			if (!isset(self::OPS[$opRaw])) {
				return ['ok' => false, 'error' => 'where[' . $i . '] unsupported op: ' . $opRaw];
			}

			$parsed = Ident::parseColumnRef($colRaw);
			if ($parsed === null) {
				return ['ok' => false, 'error' => 'where[' . $i . '] invalid col: ' . $colRaw];
			}
			$alias = $parsed['alias'] ?? $defaultAlias;
			$col = $parsed['col'];
			$err = self::assertColumn($aliasColumns, $alias, $col, 'where[' . $i . ']');
			if ($err !== null) {
				return ['ok' => false, 'error' => $err];
			}

			$sqlOp = self::OPS[$opRaw];
			$left = Ident::quoteColumn($col, $alias);

			if ($opRaw === 'is_null' || $opRaw === 'not_null') {
				$parts[] = $left . ' ' . $sqlOp;
				continue;
			}

			$val = $item['val'] ?? null;
			if ($opRaw === 'in' || $opRaw === 'not_in') {
				$list = self::normalizeList($val);
				if ($list === null || $list === []) {
					return ['ok' => false, 'error' => 'where[' . $i . '] ' . $opRaw . ' needs non-empty list'];
				}
				if (count($list) > 200) {
					return ['ok' => false, 'error' => 'where[' . $i . '] list too large (max 200)'];
				}
				$ph = implode(', ', array_fill(0, count($list), '?'));
				$parts[] = $left . ' ' . $sqlOp . ' (' . $ph . ')';
				array_push($bind, ...$list);
				continue;
			}

			if ($opRaw === 'between') {
				$pair = self::normalizeBetween($val);
				if ($pair === null) {
					return ['ok' => false, 'error' => 'where[' . $i . '] between needs [min,max]'];
				}
				$parts[] = $left . ' BETWEEN ? AND ?';
				$bind[] = $pair[0];
				$bind[] = $pair[1];
				continue;
			}

			if (is_array($val) || is_object($val)) {
				return ['ok' => false, 'error' => 'where[' . $i . '] val must be scalar'];
			}
			$parts[] = $left . ' ' . $sqlOp . ' ?';
			$bind[] = $val;
		}

		return [
			'ok'   => true,
			'sql'  => $parts === [] ? '' : implode(' AND ', $parts),
			'bind' => $bind,
		];
	}

	/**
	 * 编译 HAVING（列可为指标别名或 group 列，不做表列存在性强制）
	 *
	 * @param mixed        $havingIn 入参
	 * @param list<string> $allowedAliases 允许的标识（小写）
	 * @return array{ok:bool,sql?:string,bind?:list<mixed>,error?:string}
	 */
	public static function compileHaving(mixed $havingIn, array $allowedAliases): array
	{
		if ($havingIn === null || $havingIn === [] || $havingIn === '') {
			return ['ok' => true, 'sql' => '', 'bind' => []];
		}
		if (!is_array($havingIn)) {
			return ['ok' => false, 'error' => 'having must be an array'];
		}

		$allowed = [];
		foreach ($allowedAliases as $a) {
			$allowed[strtolower((string) $a)] = true;
		}

		$parts = [];
		$bind = [];
		foreach ($havingIn as $i => $item) {
			if (!is_array($item)) {
				return ['ok' => false, 'error' => 'having[' . $i . '] must be object'];
			}
			$col = trim((string) ($item['col'] ?? ''));
			$opRaw = strtolower(trim((string) ($item['op'] ?? 'gte')));
			if ($col === '' || !Ident::isSimple($col)) {
				return ['ok' => false, 'error' => 'having[' . $i . '] invalid col'];
			}
			if (!isset($allowed[strtolower($col)])) {
				return ['ok' => false, 'error' => 'having[' . $i . '] col not in group_by/metrics alias: ' . $col];
			}
			if (!isset(self::OPS[$opRaw]) || in_array($opRaw, ['in', 'not_in', 'like', 'not_like', 'between', 'is_null', 'not_null'], true)) {
				// HAVING 仅比较类，避免复杂列表；仍允许 eq/neq/gt/...
				if (!in_array($opRaw, ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'], true)) {
					return ['ok' => false, 'error' => 'having[' . $i . '] unsupported op: ' . $opRaw];
				}
			}
			$val = $item['val'] ?? null;
			if (is_array($val) || is_object($val)) {
				return ['ok' => false, 'error' => 'having[' . $i . '] val must be scalar'];
			}
			$parts[] = Ident::quote($col) . ' ' . self::OPS[$opRaw] . ' ?';
			$bind[] = $val;
		}

		return [
			'ok'   => true,
			'sql'  => $parts === [] ? '' : implode(' AND ', $parts),
			'bind' => $bind,
		];
	}

	/**
	 * 校验列属于某 alias 的表
	 *
	 * @param array<string, array<string, string>> $aliasColumns
	 * @param string|null $alias
	 * @param string $col
	 * @param string $ctx
	 * @return string|null
	 */
	public static function assertColumn(array $aliasColumns, ?string $alias, string $col, string $ctx): ?string
	{
		if ($alias === null || $alias === '') {
			// 无别名：在所有表列并集中查找（单表场景只有一张）
			foreach ($aliasColumns as $map) {
				if (isset($map[strtolower($col)])) {
					return null;
				}
			}

			return $ctx . ' unknown column: ' . $col;
		}
		if (!isset($aliasColumns[$alias])) {
			return $ctx . ' unknown table alias: ' . $alias;
		}
		if (!isset($aliasColumns[$alias][strtolower($col)])) {
			return $ctx . ' unknown column: ' . $alias . '.' . $col;
		}

		return null;
	}

	/**
	 * @param mixed $val
	 * @return list<mixed>|null
	 */
	private static function normalizeList(mixed $val): ?array
	{
		if (is_string($val)) {
			$parts = array_map('trim', explode(',', $val));
			$parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

			return $parts;
		}
		if (!is_array($val)) {
			return null;
		}
		$out = [];
		foreach ($val as $v) {
			if (is_array($v) || is_object($v)) {
				return null;
			}
			$out[] = $v;
		}

		return $out;
	}

	/**
	 * @param mixed $val
	 * @return array{0:mixed,1:mixed}|null
	 */
	private static function normalizeBetween(mixed $val): ?array
	{
		if (is_string($val)) {
			$parts = array_map('trim', explode(',', $val, 2));
			if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
				return null;
			}

			return [$parts[0], $parts[1]];
		}
		if (!is_array($val) || count($val) < 2) {
			return null;
		}
		$vals = array_values($val);
		if (is_array($vals[0]) || is_object($vals[0]) || is_array($vals[1]) || is_object($vals[1])) {
			return null;
		}

		return [$vals[0], $vals[1]];
	}

	/**
	 * @param array<mixed> $arr
	 * @return bool
	 */
	private static function isAssoc(array $arr): bool
	{
		if ($arr === []) {
			return false;
		}

		return array_keys($arr) !== range(0, count($arr) - 1);
	}
}
