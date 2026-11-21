<?php
/***
 * MCP Tool：表数据采样预览
 *
 * 业务说明：返回表头 N 行，供 Agent 理解字段取值；脱敏；无 where
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

final class DbSample implements ToolInterface
{
	public function name(): string
	{
		return 'db_sample';
	}

	public function description(): string
	{
		return 'Readonly sample first N rows. Requires grant_token. Sensitive columns masked.';
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
					'description' => 'Physical table name, e.g. sa_article',
				],
				'columns' => [
					'type'        => 'array',
					'items'       => ['type' => 'string'],
					'description' => 'Optional column list',
				],
				'limit'   => [
					'type'        => 'integer',
					'description' => 'Rows to sample (default 20)',
					'default'     => 20,
				],
			],
			'required'             => ['grant_token', 'table'],
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
		return Runner::sample($args);
	}
}
