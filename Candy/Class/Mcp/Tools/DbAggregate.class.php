<?php
/***
 * MCP Tool：受控聚合分析
 *
 * 业务说明：COUNT/SUM/AVG/MIN/MAX + GROUP BY + HAVING；禁止任意 SQL
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

final class DbAggregate implements ToolInterface
{
	public function name(): string
	{
		return 'db_aggregate';
	}

	public function description(): string
	{
		return 'Readonly aggregate QueryForm. Requires grant_token. metrics COUNT/SUM/AVG/MIN/MAX, group_by, having.';
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
				'table'    => [
					'type'        => 'string',
					'description' => 'Physical table (or use from)',
				],
				'from'     => ['type' => 'string'],
				'metrics'  => [
					'type'        => 'array',
					'description' => 'e.g. [{"fn":"count","col":"*","as":"cnt"}]',
					'items'       => ['type' => 'object'],
				],
				'group_by' => [
					'type'        => 'array',
					'items'       => ['type' => 'string'],
				],
				'having'   => [
					'description' => '[{col,op,val}] on metric alias or group col',
				],
				'where'    => [
					'description' => 'Same as db_select where',
				],
				'order'    => [
					'description' => 'Order by group col or metric alias',
				],
				'limit'    => [
					'type'    => 'integer',
					'default' => 100,
				],
				'offset'   => [
					'type'    => 'integer',
					'default' => 0,
				],
			],
			'required'             => ['grant_token', 'metrics'],
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

		return Runner::aggregate($args);
	}
}
