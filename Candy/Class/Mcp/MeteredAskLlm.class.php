<?php
/***
 * 平台通道计量包装（AskLlmPort）
 *
 * 业务说明：每次 chat 成功后经 TenantLlmGateway 记用量并扣积分
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 16:30:12 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class MeteredAskLlm implements AskLlmPort
{
	/**
	 * @param AskLlmPort $inner 内层 LLM
	 * @param string $tenantId 租户 ID
	 * @param array $meta 计量元数据（scene、provider_id、model_id、倍率等）
	 */
	public function __construct(
		private AskLlmPort $inner,
		private string $tenantId,
		private array $meta,
	) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat(array $messages, array $opts = []): array
	{
		$res = $this->inner->chat($messages, $opts);
		if (($res['code'] ?? 1) !== 0) {
			return $res;
		}
		$usage = is_array($res['data']['usage'] ?? null) ? $res['data']['usage'] : null;
		$bill = S('Portal://TenantLlmGateway')->recordPlatformUsage($this->tenantId, $usage, $this->meta);
		if (($bill['code'] ?? 1) !== 0) {
			return [
				'code' => 1,
				'message' => (string)($bill['message'] ?? L('portal_llm_points_exhausted')),
				'data' => $res['data'] ?? null,
			];
		}
		return $res;
	}
}
