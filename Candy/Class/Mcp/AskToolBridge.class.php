<?php
/***
 * 问数 Agent Tool 桥接
 *
 * 业务说明：白名单五查数 Tool；对模型隐藏 grant_token；invoke 强制注入
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-31 11:37:41 $
 *
 * @package Candy\Extend\Mcp
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class AskToolBridge implements AskToolPort
{
	/** @var list<string> 问数允许的 Tool 名 */
	public const WHITELIST = [
		'schema_tables',
		'db_select',
		'db_sample',
		'db_aggregate',
		'db_join_select',
	];

	private const CONTENT_MAX = 16384;

	private AskGrantBinder $grant;
	private ?Registry $registry = null;

	/**
	 * @param AskGrantBinder $grant 服务端 grant 持有器
	 */
	public function __construct(AskGrantBinder $grant)
	{
		$this->grant = $grant;
	}

	/**
	 * 转为 OpenAI tools[]（参数 Schema 去掉 grant_token）
	 *
	 * @return list<array<string,mixed>>
	 */
	public function openaiTools(): array
	{
		$registry = $this->registry();
		$out = [];
		foreach (self::WHITELIST as $name) {
			$tool = $registry->get($name);
			if ($tool === null) {
				continue;
			}
			$schema = $tool->inputSchema();
			$props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
			unset($props['grant_token']);
			$required = [];
			if (isset($schema['required']) && is_array($schema['required'])) {
				foreach ($schema['required'] as $req) {
					$r = (string)$req;
					if ($r !== '' && $r !== 'grant_token') {
						$required[] = $r;
					}
				}
			}
			$parameters = [
				'type' => 'object',
				'properties' => $props,
				'additionalProperties' => false,
			];
			if ($required !== []) {
				$parameters['required'] = $required;
			}
			$out[] = [
				'type' => 'function',
				'function' => [
					'name' => $tool->name(),
					'description' => $tool->description(),
					'parameters' => $parameters,
				],
			];
		}

		return $out;
	}

	/**
	 * 调用白名单 Tool（强制注入 grant_token）
	 *
	 * @param string $name Tool 名
	 * @param array<string,mixed> $arguments 模型给出的参数
	 * @return array{ok:bool,name:string,result:array,content_for_llm:string,error?:string}
	 */
	public function invoke(string $name, array $arguments): array
	{
		$name = trim($name);
		if ($name === '' || !in_array($name, self::WHITELIST, true)) {
			return [
				'ok' => false,
				'name' => $name,
				'result' => ['ok' => false, 'error' => 'tool not allowed'],
				'content_for_llm' => '{"ok":false,"error":"tool not allowed"}',
				'error' => 'tool not allowed',
			];
		}

		$ensure = $this->grant->ensure();
		if (!($ensure['ok'] ?? false)) {
			$err = (string)($ensure['error'] ?? 'grant unavailable');
			return [
				'ok' => false,
				'name' => $name,
				'result' => ['ok' => false, 'error' => $err],
				'content_for_llm' => json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE) ?: '{"ok":false}',
				'error' => $err,
			];
		}

		$tool = $this->registry()->get($name);
		if ($tool === null) {
			return [
				'ok' => false,
				'name' => $name,
				'result' => ['ok' => false, 'error' => 'tool not registered'],
				'content_for_llm' => '{"ok":false,"error":"tool not registered"}',
				'error' => 'tool not registered',
			];
		}

		$arguments['grant_token'] = $this->grant->token();
		try {
			$result = $tool->handle($arguments);
		} catch (\Throwable $e) {
			$result = ['ok' => false, 'error' => $e->getMessage()];
		}
		if (!is_array($result)) {
			$result = ['ok' => false, 'error' => 'tool returned non-array'];
		}

		$this->grant->maybeRenewFromToolResult($result);

		$forLlm = $this->sanitizeForLlm($result);
		$encoded = json_encode($forLlm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			$encoded = '{"ok":false,"error":"encode failed"}';
		}
		if (strlen($encoded) > self::CONTENT_MAX) {
			$encoded = substr($encoded, 0, self::CONTENT_MAX) . '…[truncated]';
		}

		return [
			'ok' => (bool)($result['ok'] ?? false),
			'name' => $name,
			'result' => $result,
			'content_for_llm' => $encoded,
		];
	}

	/**
	 * 懒加载 MCP Registry（default server）
	 *
	 * @return Registry
	 * @access private
	 */
	private function registry(): Registry
	{
		if ($this->registry === null) {
			$this->registry = Bootstrap::buildRegistry('default');
		}

		return $this->registry;
	}

	/**
	 * 供模型阅读的结果：去掉 grant_token，并把内部绑库错误改成业务表述
	 *
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 * @access private
	 */
	private function sanitizeForLlm(array $result): array
	{
		$copy = $result;
		unset($copy['grant_token']);
		if (isset($copy['data']) && is_array($copy['data'])) {
			unset($copy['data']['grant_token']);
		}
		$err = (string)($copy['error'] ?? '');
		if ($err !== '') {
			if (str_contains($err, 'tenant db config not found')
				|| str_contains($err, 'grant_token')
				|| str_contains($err, 'invalid grant')
			) {
				$copy['error'] = 'tenant_database_unavailable';
				$copy['error_hint'] = '当前租户库连接暂不可用，请稍后重试或联系管理员检查租户库配置；不要向用户索要令牌。';
			}
		}

		return $copy;
	}
}
