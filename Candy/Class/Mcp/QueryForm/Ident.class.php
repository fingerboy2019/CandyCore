<?php
/***
 * MCP 查数标识符与表名工具
 *
 * 业务说明：校验表/列/别名；物理表与 D() 逻辑表转换；方言 quote
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:32:59 $
 *
 * @package Candy\Extend\Mcp\QueryForm
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\QueryForm;

use Candy\Extend\DB\DBServer;
use Candy\Extend\DB\SchemaMeta;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Ident
{
	/**
	 * 简单标识符（表名/列名/别名）
	 *
	 * @param string $name 名称
	 * @return bool
	 */
	public static function isSimple(string $name): bool
	{
		return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
	}

	/**
	 * 列引用：col 或 alias.col
	 *
	 * @param string $ref 引用
	 * @return array{alias:?string,col:string}|null
	 */
	public static function parseColumnRef(string $ref): ?array
	{
		$ref = trim($ref);
		if ($ref === '') {
			return null;
		}
		if (str_contains($ref, '.')) {
			$parts = explode('.', $ref, 2);
			$alias = trim($parts[0]);
			$col = trim($parts[1] ?? '');
			if (!self::isSimple($alias) || !self::isSimple($col)) {
				return null;
			}

			return ['alias' => $alias, 'col' => $col];
		}
		if (!self::isSimple($ref)) {
			return null;
		}

		return ['alias' => null, 'col' => $ref];
	}

	/**
	 * 方言 quote 标识符
	 *
	 * @param string $ident 标识符（不含点）
	 * @return string
	 */
	public static function quote(string $ident): string
	{
		/** @var class-string $dialect */
		$dialect = DBServer::resolveDialectClass();

		return $dialect::quoteIdent($ident);
	}

	/**
	 * 引用列：可选别名.列
	 *
	 * @param string      $col 列名
	 * @param string|null $alias 表别名
	 * @return string
	 */
	public static function quoteColumn(string $col, ?string $alias = null): string
	{
		$q = self::quote($col);
		if ($alias !== null && $alias !== '') {
			return self::quote($alias) . '.' . $q;
		}

		return $q;
	}

	/**
	 * 物理表名规范化（小写）并校验格式
	 *
	 * @param string $table 物理表名
	 * @return string|null 失败返回 null
	 */
	public static function normalizeTable(string $table): ?string
	{
		$table = strtolower(trim($table));
		if ($table === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
			return null;
		}

		return $table;
	}

	/**
	 * 物理表是否允许（存在且未 deny）
	 *
	 * @param string $physical 物理表名（已规范化）
	 * @return string|null 错误信息；null 表示通过
	 */
	public static function assertTableAllowed(string $physical): ?string
	{
		$cfg = Config::dbQuery();
		if (in_array($physical, $cfg['deny_tables'], true)) {
			return 'table denied: ' . $physical;
		}
		if (!SchemaMeta::tableExists($physical)) {
			return 'table not found: ' . $physical;
		}

		return null;
	}

	/**
	 * 读取表列名集合（小写 => 原始名）
	 *
	 * @param string $physical 物理表名
	 * @return array<string, string>
	 */
	public static function columnMap(string $physical): array
	{
		$cols = SchemaMeta::listColumns($physical);
		$map = [];
		foreach ($cols as $col) {
			$name = (string) (
				$col['Field']
				?? $col['field']
				?? $col['name']
				?? $col['column_name']
				?? ''
			);
			if ($name === '') {
				continue;
			}
			$map[strtolower($name)] = $name;
		}

		return $map;
	}

	/**
	 * 物理表名 → D() 逻辑表名（剥 TABPREFIX）
	 *
	 * @param string $physical 物理表名
	 * @return string
	 */
	public static function toLogicalTable(string $physical): string
	{
		$prefix = strtolower((string) (C('TABPREFIX') ?? ''));
		if ($prefix !== '' && str_starts_with($physical, $prefix)) {
			$logical = substr($physical, strlen($prefix));

			return $logical !== '' ? $logical : $physical;
		}

		return $physical;
	}

	/**
	 * LIMIT 子句（方言）
	 *
	 * @param int $limit 条数
	 * @param int $offset 偏移
	 * @return string
	 */
	public static function limitSql(int $limit, int $offset = 0): string
	{
		/** @var class-string $dialect */
		$dialect = DBServer::resolveDialectClass();

		return $dialect::limitSql($limit, $offset);
	}
}
