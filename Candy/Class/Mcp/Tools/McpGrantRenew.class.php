<?php
/***
 * MCP Tool：库访问授权续签延期
 *
 * 业务说明：Agent 在 grant_should_renew 时主动调用；同 tid 换新 Token；支持宽限与后台 rearm
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-23 16:17:12 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\AdminContext;
use Candy\Extend\Mcp\Grant;
use Candy\Extend\Mcp\ToolInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class McpGrantRenew implements ToolInterface
{
	public function name(): string
	{
		return 'mcp_grant_renew';
	}

	public function description(): string
	{
		return 'Proactively renew/extend MCP DB grant_token BEFORE it expires when grant_should_renew=true '
			. '(or within grace after expiry / after admin rearm). Same tid only. Replace your stored grant_token with the new one. '
			. 'Do NOT wait for db_* failures — renew early.';
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
					'description' => 'Current (or recently expired) grant_token to renew',
				],
				'ttl'         => [
					'type'        => 'integer',
					'description' => 'Optional new TTL seconds (default from Mcp.inc)',
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
		AdminContext::boot('Admin');
		$token = trim((string) ($args['grant_token'] ?? ''));
		$ttl = isset($args['ttl']) ? (int) $args['ttl'] : null;
		$result = Grant::renew($token, $ttl);
		if (!($result['ok'] ?? false)) {
			return ['ok' => false, 'error' => (string) ($result['error'] ?? 'renew failed')];
		}

		return [
			'ok'   => true,
			'data' => [
				'grant_token'         => $result['token'],
				'tid'                 => $result['tid'],
				'exp'                 => $result['exp'],
				'jti'                 => $result['jti'],
				'ttl'                 => $result['ttl'],
				'reason'              => $result['reason'] ?? 'proactive',
				'grant_expires_in'    => max(0, (int) $result['exp'] - time()),
				'grant_should_renew'  => false,
				'note'                => $result['note'] ?? 'Use this new grant_token for subsequent tools.',
			],
		];
	}
}
