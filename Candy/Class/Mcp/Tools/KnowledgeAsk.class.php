<?php
/***
 * MCP Tool：知识库问答
 *
 * 业务说明：封装 Rag::ask（doc/attr/attr_enrich/auto）；调用 LLM，不写业务表
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 07:09:18 $
 *
 * @package Candy\Extend\Mcp\Tools
 */

declare(strict_types=1);

namespace Candy\Extend\Mcp\Tools;

use Candy\Extend\Mcp\ToolInterface;
use Candy\Extend\Rag\Rag;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class KnowledgeAsk implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'knowledge_ask';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Ask Candy RAG (modes: auto|doc|attr|attr_enrich). Returns polished answer, route, optional citations. Uses configured LLM; does not mutate DB.';
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
			'type'                 => 'object',
			'properties'           => [
				'question' => [
					'type'        => 'string',
					'description' => 'User question',
				],
				'mode' => [
					'type'        => 'string',
					'description' => 'auto (default) | doc | attr | attr_enrich',
					'enum'        => ['auto', 'doc', 'attr', 'attr_enrich'],
					'default'     => 'auto',
				],
				'namespace' => [
					'type'        => 'string',
					'description' => 'Optional same-DB partition',
				],
				'top_k' => [
					'type'        => 'integer',
					'description' => 'Retrieve top_k override',
					'minimum'     => 1,
					'maximum'     => 30,
				],
			],
			'required'             => ['question'],
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
		$question = trim((string)($args['question'] ?? ''));
		if ($question === '') {
			return ['ok' => false, 'error' => 'question is required'];
		}

		$mode = trim((string)($args['mode'] ?? 'auto'));
		if ($mode === '') {
			$mode = 'auto';
		}
		$allowed = ['auto', 'doc', 'attr', 'attr_enrich'];
		if (!in_array($mode, $allowed, true)) {
			return ['ok' => false, 'error' => 'invalid mode'];
		}

		$opts = ['mode' => $mode];
		$ns = trim((string)($args['namespace'] ?? ''));
		if ($ns !== '') {
			$opts['namespace'] = $ns;
		}
		if (isset($args['top_k']) && (int)$args['top_k'] > 0) {
			$opts['top_k'] = min(30, (int)$args['top_k']);
		}

		try {
			$res = Rag::ask($question, $opts);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		}

		if (($res['code'] ?? 1) !== 0) {
			return ['ok' => false, 'error' => (string)($res['message'] ?? 'ask failed')];
		}

		$data = is_array($res['data'] ?? null) ? $res['data'] : $res;
		return [
			'ok'   => true,
			'data' => [
				'question'          => $question,
				'mode'              => $mode,
				'answer'            => (string)($data['answer'] ?? ''),
				'canonical_answer'  => $data['canonical_answer'] ?? null,
				'route'             => $data['route'] ?? null,
				'attr_hit'          => $data['attr_hit'] ?? null,
				'enriched'          => $data['enriched'] ?? null,
				'citations'         => $data['citations'] ?? ($data['doc_hits'] ?? null),
			],
		];
	}
}
