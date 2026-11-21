<?php
/***
 * RAG 检索流水线
 *
 * 业务说明：查询嵌入后按 hybrid/vector/text 模式检索，归一化为统一 hit 结构
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Pipeline
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Pipeline;

use Candy\Extend\Rag\Config;
use Candy\Extend\Rag\Embedding\EmbeddingFactory;
use Candy\Extend\Rag\Embedding\EmbeddingInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Retriever
{
	private Config $config;
	private EmbeddingInterface $embedding;

	/**
	 * @param Config $config
	 * @param EmbeddingInterface|null $embedding
	 */
	public function __construct(Config $config, ?EmbeddingInterface $embedding = null)
	{
		$this->config = $config;
		if ($embedding !== null) {
			$this->embedding = $embedding;
		} else {
			$made = EmbeddingFactory::make($config);
			if (($made['code'] ?? 1) !== 0 || !isset($made['data'])) {
				throw new \InvalidArgumentException((string)($made['message'] ?? 'embedding 初始化失败'));
			}
			$this->embedding = $made['data'];
		}
	}

	/**
	 * 检索
	 *
	 * opts：alias、top_k、mode、filter、namespace（并入 filter）、vector 覆盖
	 *
	 * @param string $query
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:list<array{id:string,score:float,text:string,payload:array}>}
	 */
	public function retrieve(string $query, array $opts = []): array
	{
		$aligned = $this->config->assertDimensionsAligned();
		if (!$aligned['ok']) {
			return ['code' => 1, 'message' => $aligned['message']];
		}
		$query = trim($query);
		if ($query === '') {
			return ['code' => 1, 'message' => 'query 为空'];
		}

		$alias = isset($opts['alias']) && trim((string)$opts['alias']) !== ''
			? (string)$opts['alias']
			: $this->config->alias;
		$topK = max(1, (int)($opts['top_k'] ?? $this->config->topK));
		$mode = strtolower((string)($opts['mode'] ?? $this->config->retrieveMode));
		if (!in_array($mode, ['hybrid', 'vector', 'text'], true)) {
			$mode = 'hybrid';
		}

		$filter = is_array($opts['filter'] ?? null) ? $opts['filter'] : [];
		if (isset($opts['namespace']) && (string)$opts['namespace'] !== '') {
			$filter['namespace'] = (string)$opts['namespace'];
		}
		$filterOrNull = $filter !== [] ? $filter : null;

		$vectorOpts = $this->config->vectorOptions;
		if (isset($opts['vector']) && is_array($opts['vector'])) {
			$vectorOpts = array_merge($vectorOpts, $opts['vector']);
		}
		$vectorOpts['dimensions'] = $this->config->dimensions;

		$server = \V($alias, $vectorOpts);
		$raw = null;

		if ($mode === 'text') {
			$raw = $server->textSearch($query, $topK, $filterOrNull);
		} else {
			$emb = $this->embedding->embed($query);
			if (($emb['code'] ?? 1) !== 0) {
				return ['code' => (int)$emb['code'], 'message' => (string)($emb['message'] ?? 'embed failed')];
			}
			/** @var list<float> $vec */
			$vec = $emb['data'] ?? [];
			if ($mode === 'vector') {
				$raw = $server->search($vec, $topK, null, $filterOrNull);
			} else {
				$raw = $server->hybridSearch(
					$vec,
					$query,
					$topK,
					(string)($opts['hybrid_mode'] ?? $this->config->hybridMode),
					(float)($opts['vector_weight'] ?? $this->config->vectorWeight),
					(float)($opts['text_weight'] ?? $this->config->textWeight),
					null,
					$filterOrNull
				);
			}
		}

		if (($raw['code'] ?? 1) !== 0) {
			return [
				'code' => (int)($raw['code'] ?? 1),
				'message' => (string)($raw['message'] ?? 'retrieve failed'),
			];
		}

		$hits = [];
		foreach ($raw['data'] ?? [] as $row) {
			if (!is_array($row)) {
				continue;
			}
			$payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
			$text = '';
			if (isset($payload['text']) && is_string($payload['text'])) {
				$text = $payload['text'];
			} elseif (isset($payload['content']) && is_string($payload['content'])) {
				$text = $payload['content'];
			}
			$hits[] = [
				'id' => (string)($row['id'] ?? ''),
				'score' => (float)($row['score'] ?? 0),
				'text' => $text,
				'payload' => $payload,
			];
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'data' => $hits,
		];
	}
}
