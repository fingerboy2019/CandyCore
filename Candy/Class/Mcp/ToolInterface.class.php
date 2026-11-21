<?php
/***
 * MCP Tool 契约接口
 *
 * 业务说明：项目内 Tool 须实现本接口；由 Registry 按白名单注册
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 10:54:51 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface ToolInterface
{
	/**
	 * Tool 唯一名称（与 Mcp.inc 白名单一致）
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * 人类可读说明（给 Agent）
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * 风险分级
	 *
	 * readonly：DEBUG=0 亦可注册；ops：需 DEBUG=1 或 Mcp.inc allow_ops=true
	 *
	 * @return string readonly|ops
	 */
	public function risk(): string;

	/**
	 * JSON Schema（object）描述入参
	 *
	 * @return array<string, mixed>
	 */
	public function inputSchema(): array;

	/**
	 * JSON Schema 描述成功时 data 形状（可选）
	 *
	 * 返回 null 时 tools/list 不上报 outputSchema，保持与旧客户端兼容
	 *
	 * @return array<string, mixed>|null
	 */
	public function outputSchema(): ?array;

	/**
	 * 执行 Tool
	 *
	 * 返回统一结构：
	 * - ['ok' => true, 'data' => mixed]
	 * - ['ok' => true, 'mrtr' => ['inputRequests'=>..., 'requestState'=>?]]（MRTR）
	 * - ['ok' => true, 'data'=>?, 'task' => ['status'=>...]]（Tasks）
	 * - ['ok' => false, 'error' => string]
	 *
	 * @param array<string, mixed> $args 已解析参数（可含 inputResponses/requestState）
	 * @return array{ok: bool, data?: mixed, error?: string, mrtr?: array, task?: array}
	 */
	public function handle(array $args): array;
}

