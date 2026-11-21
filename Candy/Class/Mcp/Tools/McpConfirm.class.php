<?php
/***
 * MCP Tool：确认类 MRTR 示范
 *
 * 业务说明：首次调用返回 input_required；带 inputResponses.confirm 后完成。供 Agent/冒烟对照
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

final class McpConfirm implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'mcp_confirm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'MRTR demo: first call returns input_required (elicitation confirm); retry with inputResponses.confirm to finish.';
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
				'action' => [
					'type'        => 'string',
					'description' => 'Human-readable action label to confirm (optional)',
				],
				'message' => [
					'type'        => 'string',
					'description' => 'Override confirmation prompt text',
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
				'confirmed' => ['type' => 'boolean'],
				'action'    => ['type' => 'string'],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function handle(array $args): array
	{
		$action = trim((string) ($args['action'] ?? 'demo-action'));
		if ($action === '') {
			$action = 'demo-action';
		}
		$message = trim((string) ($args['message'] ?? ''));
		if ($message === '') {
			$message = 'Confirm action: ' . $action . ' ?';
		}

		$responses = $args['inputResponses'] ?? null;
		if (!is_array($responses) || !array_key_exists('confirm', $responses)) {
			// 无确认答复 → MRTR；requestState 携带 action 便于重试对照
			return [
				'ok'   => true,
				'mrtr' => [
					'inputRequests' => [
						'confirm' => [
							'method' => 'elicitation/create',
							'params' => [
								'message'         => $message,
								'requestedSchema' => [
									'type'       => 'object',
									'properties' => [
										'ok' => [
											'type'        => 'boolean',
											'description' => 'true to confirm',
										],
									],
									'required' => ['ok'],
								],
							],
						],
					],
					'requestState' => 'mcp_confirm:' . $action,
				],
			];
		}

		$confirm = $responses['confirm'];
		$ok = false;
		if (is_array($confirm)) {
			$content = $confirm['content'] ?? $confirm;
			if (is_array($content)) {
				$ok = !empty($content['ok']) || (($content['ok'] ?? null) === true);
			} elseif (is_bool($confirm)) {
				$ok = $confirm;
			} elseif (is_string($confirm)) {
				$ok = in_array(strtolower($confirm), ['1', 'true', 'yes', 'y', 'ok'], true);
			}
		} elseif (is_bool($confirm)) {
			$ok = $confirm;
		}

		if (!$ok) {
			return [
				'ok'    => false,
				'error' => 'confirmation rejected or missing ok=true',
			];
		}

		return [
			'ok'   => true,
			'data' => [
				'confirmed'    => true,
				'action'       => $action,
				'requestState' => (string) ($args['requestState'] ?? ''),
			],
		];
	}
}
