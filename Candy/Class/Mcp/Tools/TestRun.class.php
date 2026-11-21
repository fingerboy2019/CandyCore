<?php
/***
 * MCP Tool：执行测试单元（ops，仅 *_smoke）
 *
 * 业务说明：仅允许 Mcp.inc test_run_id_pattern 匹配的 handler；禁止全量 runAll
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-20 20:36:57 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\AdminContext;
use Candy\Extend\Mcp\Bootstrap;
use Candy\Extend\Mcp\ToolInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class TestRun implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'test_run';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Run a single Admin test unit by id. Only ids matching test_run_id_pattern (default *_smoke) are allowed. Use list=true to list allowed handlers.';
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
				'id' => [
					'type'        => 'string',
					'description' => 'HANDLER_ID, e.g. cache_semantics_smoke',
				],
				'list' => [
					'type'        => 'boolean',
					'description' => 'If true, only list allowed smoke handlers (default false)',
					'default'     => false,
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
		AdminContext::boot('Admin');
		AdminContext::loadLang('Testlib');

		$pattern = (string) (Bootstrap::config()['test_run_id_pattern'] ?? '/_smoke$/');
		if ($pattern === '' || @preg_match($pattern, '') === false) {
			return ['ok' => false, 'error' => 'invalid test_run_id_pattern in Mcp.inc'];
		}

		if (!empty($args['list'])) {
			return $this->listAllowed($pattern);
		}

		$id = trim((string) ($args['id'] ?? ''));
		if ($id === '') {
			return ['ok' => false, 'error' => 'id is required (or set list=true)'];
		}
		if ($id === 'all') {
			return ['ok' => false, 'error' => 'runAll is not allowed via MCP; pass a single *_smoke id'];
		}
		if (preg_match($pattern, $id) !== 1) {
			return [
				'ok'    => false,
				'error' => 'id not allowed by pattern ' . $pattern . ': ' . $id,
			];
		}

		$result = S('TestLibrary')->runTest($id);
		$code = (int) ($result['code'] ?? 1);

		// 测试失败仍算 Tool 调用成功，结果写在 data 里供 Agent 阅读
		return [
			'ok'   => true,
			'data' => [
				'id'      => (string) ($result['id'] ?? $id),
				'title'   => (string) ($result['title'] ?? ''),
				'passed_suite' => $code === 0,
				'code'    => $code,
				'message' => (string) ($result['message'] ?? ''),
				'passed'  => (int) ($result['passed'] ?? 0),
				'failed'  => (int) ($result['failed'] ?? 0),
				'cases'   => $result['cases'] ?? [],
			],
		];
	}

	/**
	 * 列出符合 pattern 的 handler
	 *
	 * @param string $pattern 正则
	 * @return array{ok:bool,data?:array,error?:string}
	 */
	private function listAllowed(string $pattern): array
	{
		$list = S('TestLibrary')->listHandlers([]);
		$rows = $list['data'] ?? [];
		if (!is_array($rows)) {
			$rows = [];
		}

		$allowed = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = (string) ($row['id'] ?? '');
			if ($id === '' || preg_match($pattern, $id) !== 1) {
				continue;
			}
			$allowed[] = [
				'id'    => $id,
				'title' => (string) ($row['title'] ?? ''),
				'file'  => (string) ($row['file'] ?? ''),
			];
		}

		return [
			'ok'   => true,
			'data' => [
				'pattern'  => $pattern,
				'total'    => count($allowed),
				'handlers' => $allowed,
			],
		];
	}
}
