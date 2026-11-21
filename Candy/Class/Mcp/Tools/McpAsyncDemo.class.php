<?php
/***
 * MCP Tool：异步 Task 示范
 *
 * 业务说明：返回 working 任务句柄；客户端用 tasks/get / tasks/update 轮询。供冒烟对照
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 11:04:05 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\ToolInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class McpAsyncDemo implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'mcp_async_demo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Tasks demo: returns a working task handle (taskId). Poll with tasks/get; finish via tasks/update.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function risk(): string
	{
		return 'readonly';
	}

	/**
	 * {@inheritdoc}
	 */
	public function inputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'label' => [
					'type'        => 'string',
					'description' => 'Optional job label stored in task data',
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function outputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'phase'      => ['type' => 'string'],
				'taskId'     => ['type' => 'string'],
				'taskStatus' => ['type' => 'string'],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function handle(array $args): array
	{
		$label = trim((string) ($args['label'] ?? 'async-demo'));
		if ($label === '') {
			$label = 'async-demo';
		}

		return [
			'ok'   => true,
			'data' => [
				'phase' => 'queued',
				'label' => $label,
			],
			'task' => [
				'status'  => 'working',
				'message' => 'queued: ' . $label,
				'ttl_sec' => 600,
			],
		];
	}
}
