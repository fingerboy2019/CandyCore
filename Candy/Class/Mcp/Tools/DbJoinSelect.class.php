<?php
/***
 * MCP Tool：受限多表等值 JOIN 查明细
 *
 * 业务说明：最多 3 表 INNER/LEFT 等值 JOIN；须显式列；禁止任意 SQL
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

final class DbJoinSelect implements ToolInterface
{
	public function name(): string
	{
		return 'db_join_select';
	}

	public function description(): string
	{
		return 'Readonly join select. Requires grant_token. Max 2 joins, left|inner, equality ON. columns must be alias.col; main alias t0.';
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
					'description' => 'MCP DB grant token. Required.',
				],
				'table'   => [
					'type'        => 'string',
					'description' => 'Main physical table (alias t0)',
				],
				'from'    => ['type' => 'string'],
				'joins'   => [
					'type'        => 'array',
					'description' => '[{type,table,alias,on:[{left,right}]}]',
					'items'       => ['type' => 'object'],
				],
				'columns' => [
					'type'        => 'array',
					'items'       => ['type' => 'string'],
					'description' => 'Required: ["t0.id","a.name",...]',
				],
				'where'   => ['description' => 'where with alias.col'],
				'order'   => ['description' => 'order with alias.col'],
				'limit'   => ['type' => 'integer', 'default' => 20],
				'offset'  => ['type' => 'integer', 'default' => 0],
			],
			'required'             => ['grant_token', 'joins', 'columns'],
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

		return Runner::joinSelect($args);
	}
}
