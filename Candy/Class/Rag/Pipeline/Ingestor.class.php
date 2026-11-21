<?php
/***
 * RAG 文本入库流水线
 *
 * 业务说明：分片 → 嵌入 → V()->upsert；向量主键为 {doc_id}:{seq}
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Pipeline
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Pipeline;

use Candy\Extend\Rag\Chunk\TextChunker;
use Candy\Extend\Rag\Config;
use Candy\Extend\Rag\Embedding\EmbeddingFactory;
use Candy\Extend\Rag\Embedding\EmbeddingInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Ingestor
{
	private Config $config;
	private EmbeddingInterface $embedding;
	private TextChunker $chunker;

	/**
	 * @param Config $config
	 * @param EmbeddingInterface|null $embedding 可注入测试驱动
	 * @param TextChunker|null $chunker
	 */
	public function __construct(
		Config $config,
		?EmbeddingInterface $embedding = null,
		?TextChunker $chunker = null
	) {
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
		$this->chunker = $chunker ?? new TextChunker(
			$config->chunkSize,
			$config->chunkOverlap,
			$config->splitByHeading
		);
	}

	/**
	 * 入库纯文本 / Markdown
	 *
	 * opts：
	 * - doc_id（必填）
	 * - alias / namespace / payload（基底元数据）
	 * - chunk_size / overlap / split_by_heading 可覆盖分片
	 *
	 * @param string $text
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:array}
	 */
	public function ingestText(string $text, array $opts = []): array
	{
		$aligned = $this->config->assertDimensionsAligned();
		if (!$aligned['ok']) {
			return ['code' => 1, 'message' => $aligned['message']];
		}
		if ($this->embedding->dimensions() !== $this->config->dimensions) {
			return [
				'code' => 1,
				'message' => '嵌入驱动维度与 Rag 配置不一致',
			];
		}

		$docId = trim((string)($opts['doc_id'] ?? ''));
		if ($docId === '') {
			return ['code' => 1, 'message' => 'doc_id 必填'];
		}
		if (str_contains($docId, ':')) {
			return ['code' => 1, 'message' => 'doc_id 禁止包含冒号'];
		}

		$chunker = $this->chunker;
		if (isset($opts['chunk_size']) || isset($opts['overlap']) || isset($opts['split_by_heading'])) {
			$chunker = new TextChunker(
				(int)($opts['chunk_size'] ?? $this->config->chunkSize),
				(int)($opts['overlap'] ?? $this->config->chunkOverlap),
				(bool)($opts['split_by_heading'] ?? $this->config->splitByHeading)
			);
		}

		$chunks = $chunker->chunk($text);
		if ($chunks === []) {
			return ['code' => 1, 'message' => '文本为空，无分片'];
		}

		$texts = array_map(static fn(array $c): string => $c['text'], $chunks);
		$emb = $this->embedding->embedBatch($texts);
		if (($emb['code'] ?? 1) !== 0) {
			return ['code' => (int)$emb['code'], 'message' => (string)($emb['message'] ?? 'embed failed')];
		}
		/** @var list<list<float>> $vectors */
		$vectors = $emb['data'] ?? [];
		if (count($vectors) !== count($chunks)) {
			return ['code' => 1, 'message' => '嵌入结果与分片数量不一致'];
		}

		$alias = isset($opts['alias']) && trim((string)$opts['alias']) !== ''
			? (string)$opts['alias']
			: $this->config->alias;
		$namespace = isset($opts['namespace']) ? (string)$opts['namespace'] : null;
		$basePayload = is_array($opts['payload'] ?? null) ? $opts['payload'] : [];

		$vectorOpts = $this->config->vectorOptions;
		if (isset($opts['vector']) && is_array($opts['vector'])) {
			$vectorOpts = array_merge($vectorOpts, $opts['vector']);
		}
		$vectorOpts['dimensions'] = $this->config->dimensions;

		$ids = [];
		$server = \V($alias, $vectorOpts);
		foreach ($chunks as $i => $chunk) {
			$id = $docId . ':' . $chunk['seq'];
			$payload = array_merge($basePayload, [
				'doc_id' => $docId,
				'seq' => $chunk['seq'],
				'char_offset' => $chunk['char_offset'],
				'text' => $chunk['text'],
			]);
			if ($namespace !== null && $namespace !== '') {
				$payload['namespace'] = $namespace;
			}
			$up = $server->upsert($id, $vectors[$i], $payload);
			if (($up['code'] ?? 1) !== 0) {
				return [
					'code' => 1,
					'message' => 'upsert 失败 ' . $id . ': ' . (string)($up['message'] ?? ''),
					'data' => [
						'doc_id' => $docId,
						'failed_id' => $id,
						'ingested' => $ids,
					],
				];
			}
			$ids[] = $id;
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'data' => [
				'doc_id' => $docId,
				'alias' => $alias,
				'chunk_count' => count($ids),
				'ids' => $ids,
				'dimensions' => $this->config->dimensions,
			],
		];
	}
}
