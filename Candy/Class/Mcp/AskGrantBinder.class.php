<?php
/***
 * 问数 Agent 租户 grant 持有器
 *
 * 业务说明：按六位 tid 签发/静默续签；token 仅服务端持有
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 11:37:41 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class AskGrantBinder
{
	private string $tenantId;
	private string $token = '';
	private string $jti = '';

	/**
	 * @param string $tenantId 六位租户 ID（V1 拒绝空与非六位）
	 */
	public function __construct(string $tenantId)
	{
		$this->tenantId = trim($tenantId);
	}

	/**
	 * 确保已持有可用 grant_token
	 *
	 * 空或非六位 tid 直接失败；已有 token 则跳过签发
	 *
	 * @return array{ok:bool,token?:string,jti?:string,error?:string}
	 */
	public function ensure(): array
	{
		if ($this->tenantId === '' || !preg_match('/^[A-Za-z0-9]{6}$/', $this->tenantId)) {
			return ['ok' => false, 'error' => 'invalid tenant_id (6-char required)'];
		}
		if ($this->token !== '') {
			return ['ok' => true, 'token' => $this->token, 'jti' => $this->jti];
		}

		$issued = Grant::issue($this->tenantId, ['db_query']);
		if (!($issued['ok'] ?? false)) {
			return ['ok' => false, 'error' => (string)($issued['error'] ?? 'grant issue failed')];
		}
		$this->token = (string)($issued['token'] ?? '');
		$this->jti = (string)($issued['jti'] ?? '');
		if ($this->token === '') {
			return ['ok' => false, 'error' => 'grant issue returned empty token'];
		}

		return ['ok' => true, 'token' => $this->token, 'jti' => $this->jti];
	}

	/**
	 * 若 Tool 结果提示应续签则立刻 renew 并替换内部 token
	 *
	 * @param array<string,mixed> $toolResult Tool.handle 返回结构
	 * @return void
	 */
	public function maybeRenewFromToolResult(array $toolResult): void
	{
		$data = is_array($toolResult['data'] ?? null) ? $toolResult['data'] : $toolResult;
		$should = !empty($data['grant_should_renew']);
		if (!$should || $this->token === '') {
			return;
		}
		$renewed = Grant::renew($this->token);
		if (!($renewed['ok'] ?? false)) {
			return;
		}
		$newToken = (string)($renewed['token'] ?? '');
		if ($newToken === '') {
			return;
		}
		$this->token = $newToken;
		if (!empty($renewed['jti'])) {
			$this->jti = (string)$renewed['jti'];
		}
	}

	/**
	 * 当前 grant_token（可能为空）
	 *
	 * @return string
	 */
	public function token(): string
	{
		return $this->token;
	}

	/**
	 * 当前 jti（审计用，非全文 token）
	 *
	 * @return string
	 */
	public function jti(): string
	{
		return $this->jti;
	}
}
