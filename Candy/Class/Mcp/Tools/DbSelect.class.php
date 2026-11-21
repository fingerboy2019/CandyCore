<?php
/***
 * MCP Tool：受控只读查明细
 *
 * 业务说明：QueryForm 明细查询（过滤/投影/排序/分页）；禁止任意 SQL
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 15:32:59 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\QueryForm\Runner;
use Candy\Extend\Mcp\ToolInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class DbSelect implements ToolInterface
{
	public function name(): string
	{
		return 'db_select';
	}

	public function description(): string
	{
		return 'Readonly structured row select (QueryForm). Requires grant_token. Supports where ops eq/in/like/between; order; limit/offset. No raw SQL.';
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
					'description' => 'MCP DB grant token. tid=0 platform; else tenant. Required.',
				],
				'table'   => [
					'type'        => 'string',
					'description' => 'Physical table name, e.g. sa_article (alias: from)',
				],
				'from'    => [
					'type'        => 'string',
					'description' => 'Same as table',
				],
				'columns' => [
					'type'        => 'array',
					'items'       => ['type' => 'string'],
					'description' => 'Column names; omit for *',
				],
				'where'   => [
					'description' => 'List of {col,op,val} or legacy equality object {"status":1}',
				],
				'order'   => [
					'description' => '[{col,dir}] or "id DESC"',
				],
				'limit'   => [
					'type'        => 'integer',
					'description' => 'Max rows (default 20)',
					'default'     => 20,
				],
				'offset'  => [
					'type'        => 'integer',
					'description' => 'Offset (default 0)',
					'default'     => 0,
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
		if (!isset($args['from']) && isset($args['table'])) {
			$args['from'] = $args['table'];
		}

		return Runner::select($args);
	}
}
