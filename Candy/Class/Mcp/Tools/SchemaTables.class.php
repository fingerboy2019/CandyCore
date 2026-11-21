<?php
/***
 * MCP Tool：库表结构只读摘要
 *
 * 业务说明：强制 grant_token；经 SchemaMeta 列表示名；单表列/索引/估算行数
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:58:21 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\DB\SchemaMeta;
use Candy\Extend\Mcp\Grant;
use Candy\Extend\Mcp\QueryForm\Runner;
use Candy\Extend\Mcp\ToolInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class SchemaTables implements ToolInterface
{
	public function name(): string
	{
		return 'schema_tables';
	}

	public function description(): string
	{
		return 'List database tables (readonly). Requires grant_token (tid=0 platform / tenant id). Optionally columns, indexes, approx rows.';
	}

	public function risk(): string
	{
		return 'readonly';
	}

	public function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'grant_token' => [
					'type'        => 'string',
					'description' => 'MCP DB grant token (Mcrypt). tid=0 → platform DB; else tenant DB. Required.',
				],
				'keyword'     => [
					'type'        => 'string',
					'description' => 'Optional table name keyword filter',
				],
				'table'       => [
					'type'        => 'string',
					'description' => 'If set, also return columns/indexes/approx rows',
				],
				'limit'       => [
					'type'        => 'integer',
					'description' => 'Max tables (default 100, max 500)',
					'default'     => 100,
				],
			],
			'required'             => ['grant_token'],
			'additionalProperties' => false,
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function outputSchema(): ?array
	{
		return null;
	}

	public function handle(array $args): array
	{
		return Runner::withGrant($args, static function (array $a): array {
			$keyword = isset($a['keyword']) ? trim((string) $a['keyword']) : null;
			if ($keyword === '') {
				$keyword = null;
			}

			$tables = SchemaMeta::listTables($keyword);
			$total = count($tables);
			$limit = (int) ($a['limit'] ?? 100);
			if ($limit < 1) {
				$limit = 100;
			}
			if ($limit > 500) {
				$limit = 500;
			}
			$slice = array_slice($tables, 0, $limit);

			$data = [
				'schema'    => SchemaMeta::schemaName(),
				'total'     => $total,
				'returned'  => count($slice),
				'tables'    => $slice,
				'truncated' => $total > $limit,
				'grant_tid' => Grant::boundTid(),
			];

			$table = strtolower(trim((string) ($a['table'] ?? '')));
			if ($table !== '') {
				if (!SchemaMeta::tableExists($table)) {
					return ['ok' => false, 'error' => 'table not found: ' . $table];
				}
				$cols = SchemaMeta::listColumns($table);
				$colSummary = [];
				foreach ($cols as $col) {
					$colSummary[] = [
						'name'    => (string) ($col['Field'] ?? $col['field'] ?? $col['name'] ?? $col['column_name'] ?? ''),
						'type'    => (string) ($col['Type'] ?? $col['type'] ?? $col['data_type'] ?? ''),
						'null'    => (string) ($col['Null'] ?? $col['null'] ?? ''),
						'key'     => (string) ($col['Key'] ?? $col['key'] ?? ''),
						'default' => $col['Default'] ?? $col['default'] ?? null,
						'comment' => (string) ($col['Comment'] ?? $col['comment'] ?? ''),
					];
				}

				$indexes = [];
				try {
					$groups = SchemaMeta::getTableIndexGroups($table);
					foreach ($groups as $g) {
						if (!is_array($g)) {
							continue;
						}
						$indexes[] = [
							'name'    => (string) ($g['key_name'] ?? ''),
							'unique'  => (int) ($g['unique'] ?? 0),
							'primary' => (int) ($g['primary'] ?? 0),
							'columns' => $g['columns'] ?? [],
						];
					}
				} catch (\Throwable) {
					$indexes = [];
				}

				$approxRows = null;
				try {
					$adminRows = SchemaMeta::listTablesForAdmin($table);
					foreach ($adminRows as $ar) {
						if (!is_array($ar)) {
							continue;
						}
						$name = strtolower((string) ($ar['name'] ?? $ar['TABLE_NAME'] ?? ''));
						if ($name === $table) {
							$approxRows = $ar['rows_count'] ?? $ar['TABLE_ROWS'] ?? $ar['rows'] ?? null;
							if ($approxRows !== null) {
								$approxRows = (int) $approxRows;
							}
							break;
						}
					}
				} catch (\Throwable) {
					$approxRows = null;
				}

				$data['table'] = $table;
				$data['columns'] = $colSummary;
				$data['column_count'] = count($colSummary);
				$data['indexes'] = $indexes;
				$data['approx_rows'] = $approxRows;
			}

			return ['ok' => true, 'data' => $data];
		});
	}
}
