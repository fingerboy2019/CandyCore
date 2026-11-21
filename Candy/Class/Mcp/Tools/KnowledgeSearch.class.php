<?php
/***
 * MCP Tool：知识库文档检索
 *
 * 业务说明：封装 Rag::retrieve；可选 namespace / top_k / alias；只读不改库
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

final class KnowledgeSearch implements ToolInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function name(): string
	{
		return 'knowledge_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function description(): string
	{
		return 'Search Candy RAG document index (hybrid retrieve). Returns top chunks with score/text/payload. Optional namespace filter (same-DB partition, not tenancy).';
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
					'description' => 'Search query text',
				],
				'top_k' => [
					'type'        => 'integer',
					'description' => 'Max hits (default from Rag.inc)',
					'minimum'     => 1,
					'maximum'     => 50,
				],
				'namespace' => [
					'type'        => 'string',
					'description' => 'Optional same-DB partition filter',
				],
				'alias' => [
					'type'        => 'string',
					'description' => 'Vector alias override (default rag)',
				],
				'mode' => [
					'type'        => 'string',
					'description' => 'hybrid | vector | text',
					'enum'        => ['hybrid', 'vector', 'text'],
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
			$opts['top_k'] = min(50, (int)$args['top_k']);
		}
		$ns = trim((string)($args['namespace'] ?? ''));
		if ($ns !== '') {
			$opts['namespace'] = $ns;
		}
		$alias = trim((string)($args['alias'] ?? ''));
		if ($alias !== '') {
			$opts['alias'] = $alias;
		}
		$mode = trim((string)($args['mode'] ?? ''));
		if ($mode !== '') {
			$opts['mode'] = $mode;
		}

		try {
			$res = Rag::retrieve($query, $opts);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		}

		if (($res['code'] ?? 1) !== 0) {
			return ['ok' => false, 'error' => (string)($res['message'] ?? 'retrieve failed')];
		}

		$hits = [];
		foreach ($res['data'] ?? [] as $hit) {
			if (!is_array($hit)) {
				continue;
			}
			$payload = is_array($hit['payload'] ?? null) ? $hit['payload'] : [];
			$hits[] = [
				'id'      => (string)($hit['id'] ?? ''),
				'score'   => (float)($hit['score'] ?? 0),
				'text'    => (string)($payload['text'] ?? $hit['text'] ?? ''),
				'doc_id'  => (string)($payload['doc_id'] ?? ''),
				'payload' => $payload,
			];
		}

		return [
			'ok'   => true,
			'data' => [
				'query' => $query,
				'count' => count($hits),
				'hits'  => $hits,
			],
		];
	}
}
