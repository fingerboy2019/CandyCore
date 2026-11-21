<?php
/***
 * MCP Tool：归因答案卡检索
 *
 * 业务说明：封装 Rag::retrieveAnswer；返回聚合后的答案卡命中
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

final class KnowledgeAnswerSearch implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'knowledge_answer_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Search Candy RAG attribution answer cards (triggers/answer embeddings). Returns aggregated answer_id hits with scores.';
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
				'query' => [
					'type'        => 'string',
					'description' => 'User utterance / trigger-like query',
				],
				'top_k' => [
					'type'        => 'integer',
					'description' => 'Max answer cards',
					'minimum'     => 1,
					'maximum'     => 20,
				],
				'namespace' => [
					'type'        => 'string',
					'description' => 'Optional same-DB partition',
				],
				'answer_alias' => [
					'type'        => 'string',
					'description' => 'Answer vector alias override',
				],
			],
			'required'             => ['query'],
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
		$query = trim((string)($args['query'] ?? ''));
		if ($query === '') {
			return ['ok' => false, 'error' => 'query is required'];
		}

		$opts = [];
		if (isset($args['top_k']) && (int)$args['top_k'] > 0) {
			$opts['top_k'] = min(20, (int)$args['top_k']);
		}
		$ns = trim((string)($args['namespace'] ?? ''));
		if ($ns !== '') {
			$opts['namespace'] = $ns;
		}
		$alias = trim((string)($args['answer_alias'] ?? ''));
		if ($alias !== '') {
			$opts['answer_alias'] = $alias;
		}

		try {
			$res = Rag::retrieveAnswer($query, $opts);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		}

		if (($res['code'] ?? 1) !== 0) {
			return ['ok' => false, 'error' => (string)($res['message'] ?? 'retrieveAnswer failed')];
		}

		return [
			'ok'   => true,
			'data' => [
				'query'   => $query,
				'count'   => count($res['data'] ?? []),
				'answers' => $res['data'] ?? [],
			],
		];
	}
}
