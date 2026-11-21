<?php
/***
 * MCP Tool：清理 Runtime（ops）
 *
 * 业务说明：保留 init.lock，清缓存/编译产物，并清 Node；提示 chown 给 www
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 20:36:57 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\ToolInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class RuntimeClear implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'runtime_clear';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Clear backend/Runtime (keep init.lock) and Node annotation cache; returns permission hint for www user.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function risk(): string
	{
		return 'ops';
	}

	/**
	 * {@inheritdoc}
	 */
	public function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'clear_node' => [
					'type'        => 'boolean',
					'description' => 'Also clear Node annotation cache (default true)',
					'default'     => true,
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
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function handle(array $args): array
	{
		$clearNode = !array_key_exists('clear_node', $args) || !empty($args['clear_node']);

		if (!function_exists('candy_clear_runtime')) {
			$projectFunc = C('CANDYROOT') . 'Source/Func/global.func.php';
			if (is_file($projectFunc)) {
				require_once $projectFunc;
			}
		}

		if (!function_exists('candy_clear_runtime')) {
			return ['ok' => false, 'error' => 'candy_clear_runtime() not available'];
		}

		$result = candy_clear_runtime($clearNode);
		$code = (int) ($result['code'] ?? 1);
		$runtime = rtrim((string) C('RUNTIME'), '/\\');
		$hint = 'chown -R www:www ' . $runtime . ' && chmod -R 775 ' . $runtime;

		if ($code !== 0) {
			return [
				'ok'    => false,
				'error' => (string) ($result['message'] ?? 'runtime clear failed'),
			];
		}

		$lockOk = is_file($runtime . DIRECTORY_SEPARATOR . 'init.lock');

		return [
			'ok'   => true,
			'data' => [
				'message'          => (string) ($result['message'] ?? 'ok'),
				'runtime'          => $runtime,
				'init_lock_kept'   => $lockOk,
				'node_cache_clear' => $clearNode,
				'permission_hint'  => $hint,
				'note'             => 'If Web process is www, re-run permission_hint after clear (Agent often runs as root).',
			],
		];
	}
}
