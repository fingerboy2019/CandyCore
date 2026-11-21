<?php
/***
 * MCP QueryForm SQL 组装
 *
 * 业务说明：由已校验的表单生成只读 SELECT（明细/采样/聚合/JOIN）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:32:59 $
 *
 * @package Candy\Extend\Mcp\QueryForm
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\QueryForm;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SqlBuilder
{
	/** @var list<string> */
	private const AGG_FNS = ['count', 'sum', 'avg', 'min', 'max'];

	/**
	 * 编译明细 / 采样 SELECT
	 *
	 * @param array<string, mixed> $form
	 * @return array{ok:bool,sql?:string,bind?:list<mixed>,meta?:array<string,mixed>,error?:string}
	 */
	public static function select(array $form): array
	{
		$from = Ident::normalizeTable((string) ($form['from'] ?? $form['table'] ?? ''));
		if ($from === null) {
			return ['ok' => false, 'error' => 'invalid table name'];
		}
		$err = Ident::assertTableAllowed($from);
		if ($err !== null) {
			return ['ok' => false, 'error' => $err];
		}

		$colMap = Ident::columnMap($from);
		$aliasColumns = ['' => $colMap];

		$fields = self::compileProjection($form['columns'] ?? null, $aliasColumns, null);
		if (!$fields['ok']) {
			return $fields;
		}

		$where = WhereCompiler::compile($form['where'] ?? null, $aliasColumns, null);
		if (!$where['ok']) {
			return $where;
		}

		$order = self::compileOrder($form['order'] ?? null, $aliasColumns, null);
		if (!$order['ok']) {
			return $order;
		}

		$limit = Config::clampLimit(isset($form['limit']) ? (int) $form['limit'] : null);
		$offset = max(0, (int) ($form['offset'] ?? 0));
		if ($offset > 100000) {
			return ['ok' => false, 'error' => 'offset too large'];
		}

		$sql = 'SELECT ' . $fields['sql']
			. ' FROM ' . Ident::quote($from);
		$bind = [];
		if (($where['sql'] ?? '') !== '') {
			$sql .= ' WHERE ' . $where['sql'];
			$bind = array_merge($bind, $where['bind'] ?? []);
		}
		if (($order['sql'] ?? '') !== '') {
			$sql .= ' ORDER BY ' . $order['sql'];
		}
		$sql .= Ident::limitSql($limit, $offset);

		return [
			'ok'   => true,
			'sql'  => $sql,
			'bind' => $bind,
			'meta' => [
				'table'  => $from,
				'limit'  => $limit,
				'offset' => $offset,
				'mode'   => 'select',
			],
		];
	}

	/**
	 * 编译聚合 SELECT
	 *
	 * @param array<string, mixed> $form
	 * @return array{ok:bool,sql?:string,bind?:list<mixed>,meta?:array<string,mixed>,error?:string}
	 */
	public static function aggregate(array $form): array
	{
		$from = Ident::normalizeTable((string) ($form['from'] ?? $form['table'] ?? ''));
		if ($from === null) {
			return ['ok' => false, 'error' => 'invalid table name'];
		}
		$err = Ident::assertTableAllowed($from);
		if ($err !== null) {
			return ['ok' => false, 'error' => $err];
		}

		$colMap = Ident::columnMap($from);
		$aliasColumns = ['' => $colMap];

		$metrics = $form['metrics'] ?? null;
		if (!is_array($metrics) || $metrics === []) {
			return ['ok' => false, 'error' => 'metrics required (e.g. [{"fn":"count","col":"*","as":"cnt"}])'];
		}

		$selectParts = [];
		$metricAliases = [];
		foreach ($metrics as $i => $m) {
			if (!is_array($m)) {
				return ['ok' => false, 'error' => 'metrics[' . $i . '] must be object'];
			}
			$fn = strtolower(trim((string) ($m['fn'] ?? '')));
			if (!in_array($fn, self::AGG_FNS, true)) {
				return ['ok' => false, 'error' => 'metrics[' . $i . '] unsupported fn: ' . $fn];
			}
			$col = trim((string) ($m['col'] ?? '*'));
			$as = trim((string) ($m['as'] ?? ($fn . '_' . $i)));
			if (!Ident::isSimple($as)) {
				return ['ok' => false, 'error' => 'metrics[' . $i . '] invalid as'];
			}
			$metricAliases[] = $as;

			if ($fn === 'count' && ($col === '*' || $col === '')) {
				$expr = 'COUNT(*)';
			} else {
				$parsed = Ident::parseColumnRef($col);
				if ($parsed === null || $parsed['alias'] !== null) {
					return ['ok' => false, 'error' => 'metrics[' . $i . '] invalid col'];
				}
				$cerr = WhereCompiler::assertColumn($aliasColumns, null, $parsed['col'], 'metrics[' . $i . ']');
				if ($cerr !== null) {
					return ['ok' => false, 'error' => $cerr];
				}
				$expr = strtoupper($fn) . '(' . Ident::quote($parsed['col']) . ')';
			}
			$selectParts[] = $expr . ' AS ' . Ident::quote($as);
		}

		$groupBy = $form['group_by'] ?? [];
		if ($groupBy !== null && $groupBy !== '' && !is_array($groupBy)) {
			return ['ok' => false, 'error' => 'group_by must be array of columns'];
		}
		if (!is_array($groupBy)) {
			$groupBy = [];
		}
		$groupSql = [];
		$groupAliases = [];
		foreach ($groupBy as $gi => $gcol) {
			$gcol = trim((string) $gcol);
			$parsed = Ident::parseColumnRef($gcol);
			if ($parsed === null || $parsed['alias'] !== null) {
				return ['ok' => false, 'error' => 'group_by[' . $gi . '] invalid'];
			}
			$cerr = WhereCompiler::assertColumn($aliasColumns, null, $parsed['col'], 'group_by[' . $gi . ']');
			if ($cerr !== null) {
				return ['ok' => false, 'error' => $cerr];
			}
			$q = Ident::quote($parsed['col']);
			$groupSql[] = $q;
			$selectParts[] = $q;
			$groupAliases[] = $parsed['col'];
		}

		$where = WhereCompiler::compile($form['where'] ?? null, $aliasColumns, null);
		if (!$where['ok']) {
			return $where;
		}

		$allowedHaving = array_merge($metricAliases, $groupAliases);
		$having = WhereCompiler::compileHaving($form['having'] ?? null, $allowedHaving);
		if (!$having['ok']) {
			return $having;
		}

		$order = self::compileOrder($form['order'] ?? null, $aliasColumns, null, $allowedHaving);
		if (!$order['ok']) {
			return $order;
		}

		$limit = Config::clampLimit(isset($form['limit']) ? (int) $form['limit'] : null, 100);
		$offset = max(0, (int) ($form['offset'] ?? 0));

		$sql = 'SELECT ' . implode(', ', $selectParts)
			. ' FROM ' . Ident::quote($from);
		$bind = [];
		if (($where['sql'] ?? '') !== '') {
			$sql .= ' WHERE ' . $where['sql'];
			$bind = array_merge($bind, $where['bind'] ?? []);
		}
		if ($groupSql !== []) {
			$sql .= ' GROUP BY ' . implode(', ', $groupSql);
		}
		if (($having['sql'] ?? '') !== '') {
			$sql .= ' HAVING ' . $having['sql'];
			$bind = array_merge($bind, $having['bind'] ?? []);
		}
		if (($order['sql'] ?? '') !== '') {
			$sql .= ' ORDER BY ' . $order['sql'];
		}
		$sql .= Ident::limitSql($limit, $offset);

		return [
			'ok'   => true,
			'sql'  => $sql,
			'bind' => $bind,
			'meta' => [
				'table'  => $from,
				'limit'  => $limit,
				'offset' => $offset,
				'mode'   => 'aggregate',
			],
		];
	}

	/**
	 * 编译受限 JOIN SELECT
	 *
	 * @param array<string, mixed> $form
	 * @return array{ok:bool,sql?:string,bind?:list<mixed>,meta?:array<string,mixed>,error?:string}
	 */
	public static function joinSelect(array $form): array
	{
		$from = Ident::normalizeTable((string) ($form['from'] ?? $form['table'] ?? ''));
		if ($from === null) {
			return ['ok' => false, 'error' => 'invalid table name'];
		}
		$err = Ident::assertTableAllowed($from);
		if ($err !== null) {
			return ['ok' => false, 'error' => $err];
		}

		$joins = $form['joins'] ?? [];
		if (!is_array($joins) || $joins === []) {
			return ['ok' => false, 'error' => 'joins required'];
		}
		if (count($joins) > 2) {
			return ['ok' => false, 'error' => 'max 2 joins (3 tables total)'];
		}

		$mainAlias = 't0';
		$aliasColumns = [
			$mainAlias => Ident::columnMap($from),
		];
		$tableByAlias = [$mainAlias => $from];

		$joinSql = [];
		foreach ($joins as $ji => $j) {
			if (!is_array($j)) {
				return ['ok' => false, 'error' => 'joins[' . $ji . '] must be object'];
			}
			$type = strtolower(trim((string) ($j['type'] ?? 'left')));
			if (!in_array($type, ['left', 'inner'], true)) {
				return ['ok' => false, 'error' => 'joins[' . $ji . '] type must be left|inner'];
			}
			$jt = Ident::normalizeTable((string) ($j['table'] ?? ''));
			if ($jt === null) {
				return ['ok' => false, 'error' => 'joins[' . $ji . '] invalid table'];
			}
			$jerr = Ident::assertTableAllowed($jt);
			if ($jerr !== null) {
				return ['ok' => false, 'error' => $jerr];
			}
			$alias = trim((string) ($j['alias'] ?? ('t' . ($ji + 1))));
			if (!Ident::isSimple($alias) || isset($aliasColumns[$alias])) {
				return ['ok' => false, 'error' => 'joins[' . $ji . '] invalid or duplicate alias'];
			}
			if ($alias === $mainAlias) {
				return ['ok' => false, 'error' => 'joins[' . $ji . '] alias reserved: t0'];
			}

			$on = $j['on'] ?? null;
			if (!is_array($on) || $on === []) {
				return ['ok' => false, 'error' => 'joins[' . $ji . '].on required'];
			}
			if (count($on) > 5) {
				return ['ok' => false, 'error' => 'joins[' . $ji . '].on too many pairs'];
			}

			$aliasColumns[$alias] = Ident::columnMap($jt);
			$tableByAlias[$alias] = $jt;

			$onParts = [];
			foreach ($on as $oi => $pair) {
				if (!is_array($pair)) {
					return ['ok' => false, 'error' => 'joins[' . $ji . '].on[' . $oi . '] must be object'];
				}
				$leftRaw = trim((string) ($pair['left'] ?? ''));
				$rightRaw = trim((string) ($pair['right'] ?? ''));
				$lp = Ident::parseColumnRef($leftRaw);
				$rp = Ident::parseColumnRef($rightRaw);
				if ($lp === null || $rp === null || $lp['alias'] === null || $rp['alias'] === null) {
					return ['ok' => false, 'error' => 'joins on must be alias.col = alias.col'];
				}
				// 左右别名须已声明（含主表与当前/先前 join）
				foreach ([$lp, $rp] as $side => $p) {
					$a = $p['alias'];
					if (!isset($aliasColumns[$a])) {
						return ['ok' => false, 'error' => 'joins[' . $ji . '].on unknown alias: ' . $a];
					}
					$cerr = WhereCompiler::assertColumn($aliasColumns, $a, $p['col'], 'joins[' . $ji . '].on');
					if ($cerr !== null) {
						return ['ok' => false, 'error' => $cerr];
					}
				}
				$onParts[] = Ident::quoteColumn($lp['col'], $lp['alias'])
					. ' = '
					. Ident::quoteColumn($rp['col'], $rp['alias']);
			}

			$joinSql[] = strtoupper($type) . ' JOIN ' . Ident::quote($jt)
				. ' AS ' . Ident::quote($alias)
				. ' ON ' . implode(' AND ', $onParts);
		}

		$fields = self::compileProjection($form['columns'] ?? null, $aliasColumns, $mainAlias, true);
		if (!$fields['ok']) {
			return $fields;
		}

		$where = WhereCompiler::compile($form['where'] ?? null, $aliasColumns, $mainAlias);
		if (!$where['ok']) {
			return $where;
		}

		$order = self::compileOrder($form['order'] ?? null, $aliasColumns, $mainAlias);
		if (!$order['ok']) {
			return $order;
		}

		$limit = Config::clampLimit(isset($form['limit']) ? (int) $form['limit'] : null);
		$offset = max(0, (int) ($form['offset'] ?? 0));

		$sql = 'SELECT ' . $fields['sql']
			. ' FROM ' . Ident::quote($from) . ' AS ' . Ident::quote($mainAlias)
			. ' ' . implode(' ', $joinSql);
		$bind = [];
		if (($where['sql'] ?? '') !== '') {
			$sql .= ' WHERE ' . $where['sql'];
			$bind = array_merge($bind, $where['bind'] ?? []);
		}
		if (($order['sql'] ?? '') !== '') {
			$sql .= ' ORDER BY ' . $order['sql'];
		}
		$sql .= Ident::limitSql($limit, $offset);

		return [
			'ok'   => true,
			'sql'  => $sql,
			'bind' => $bind,
			'meta' => [
				'table'   => $from,
				'tables'  => array_values($tableByAlias),
				'limit'   => $limit,
				'offset'  => $offset,
				'mode'    => 'join_select',
			],
		];
	}

	/**
	 * 投影列
	 *
	 * @param mixed $columns
	 * @param array<string, array<string, string>> $aliasColumns
	 * @param string|null $defaultAlias
	 * @param bool $requireQualified 多表时建议带别名；缺省则补 defaultAlias
	 * @return array{ok:bool,sql?:string,error?:string}
	 */
	private static function compileProjection(
		mixed $columns,
		array $aliasColumns,
		?string $defaultAlias,
		bool $requireQualified = false
	): array {
		if ($columns === null || $columns === '*') {
			if ($requireQualified && $defaultAlias !== null) {
				// 多表禁止 *，强制列清单
				return ['ok' => false, 'error' => 'join_select requires explicit columns (alias.col)'];
			}

			return ['ok' => true, 'sql' => '*'];
		}
		if ($columns === []) {
			return ['ok' => false, 'error' => 'columns required (omit key for * on single-table; join needs alias.col list)'];
		}
		if (!is_array($columns)) {
			return ['ok' => false, 'error' => 'columns must be array'];
		}

		$parts = [];
		foreach ($columns as $i => $c) {
			$c = trim((string) $c);
			$parsed = Ident::parseColumnRef($c);
			if ($parsed === null) {
				return ['ok' => false, 'error' => 'invalid column: ' . $c];
			}
			$alias = $parsed['alias'] ?? $defaultAlias;
			if ($requireQualified && $parsed['alias'] === null && $defaultAlias === null) {
				return ['ok' => false, 'error' => 'columns[' . $i . '] must be alias.col'];
			}
			$cerr = WhereCompiler::assertColumn($aliasColumns, $alias, $parsed['col'], 'columns[' . $i . ']');
			if ($cerr !== null) {
				return ['ok' => false, 'error' => $cerr];
			}
			$parts[] = Ident::quoteColumn($parsed['col'], $alias);
		}

		return ['ok' => true, 'sql' => implode(', ', $parts)];
	}

	/**
	 * ORDER BY
	 *
	 * @param mixed $order
	 * @param array<string, array<string, string>> $aliasColumns
	 * @param string|null $defaultAlias
	 * @param list<string>|null $extraAllowed 聚合别名等额外可排序标识
	 * @return array{ok:bool,sql?:string,error?:string}
	 */
	private static function compileOrder(
		mixed $order,
		array $aliasColumns,
		?string $defaultAlias,
		?array $extraAllowed = null
	): array {
		if ($order === null || $order === [] || $order === '') {
			return ['ok' => true, 'sql' => ''];
		}

		// 兼容 "id DESC"
		if (is_string($order)) {
			if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?)\s+(ASC|DESC)$/i', trim($order), $m) !== 1) {
				return ['ok' => false, 'error' => 'order must look like "id DESC" or [{col,dir}]'];
			}
			$order = [['col' => $m[1], 'dir' => strtoupper($m[2])]];
		}
		if (!is_array($order)) {
			return ['ok' => false, 'error' => 'order invalid'];
		}

		$extra = [];
		if (is_array($extraAllowed)) {
			foreach ($extraAllowed as $a) {
				$extra[strtolower((string) $a)] = true;
			}
		}

		$parts = [];
		foreach ($order as $i => $item) {
			if (is_string($item)) {
				if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?)\s+(ASC|DESC)$/i', trim($item), $m) !== 1) {
					return ['ok' => false, 'error' => 'order[' . $i . '] invalid'];
				}
				$item = ['col' => $m[1], 'dir' => strtoupper($m[2])];
			}
			if (!is_array($item)) {
				return ['ok' => false, 'error' => 'order[' . $i . '] must be object'];
			}
			$colRaw = trim((string) ($item['col'] ?? ''));
			$dir = strtoupper(trim((string) ($item['dir'] ?? 'ASC')));
			if ($dir !== 'ASC' && $dir !== 'DESC') {
				return ['ok' => false, 'error' => 'order[' . $i . '] dir must be ASC|DESC'];
			}
			$parsed = Ident::parseColumnRef($colRaw);
			if ($parsed === null) {
				return ['ok' => false, 'error' => 'order[' . $i . '] invalid col'];
			}
			// 聚合别名（无表前缀）
			if ($parsed['alias'] === null && isset($extra[strtolower($parsed['col'])])) {
				$parts[] = Ident::quote($parsed['col']) . ' ' . $dir;
				continue;
			}
			$alias = $parsed['alias'] ?? $defaultAlias;
			$cerr = WhereCompiler::assertColumn($aliasColumns, $alias, $parsed['col'], 'order[' . $i . ']');
			if ($cerr !== null) {
				return ['ok' => false, 'error' => $cerr];
			}
			$parts[] = Ident::quoteColumn($parsed['col'], $alias) . ' ' . $dir;
		}

		return ['ok' => true, 'sql' => implode(', ', $parts)];
	}
}
