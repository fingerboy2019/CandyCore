<?php
/***
 * 归因答案卡入库与召回
 *
 * 业务说明：将标准答案与触发问法写入独立向量别名；召回后按 answer_id 聚合
 * 向量 ID：{answer_id}:t:{n} 触发问；{answer_id}:a 答案正文
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:41:46 $
 *
 * @package Candy\Extend\Rag\Pipeline
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Pipeline;

use Candy\Extend\Rag\Config;
use Candy\Extend\Rag\Embedding\EmbeddingFactory;
use Candy\Extend\Rag\Embedding\EmbeddingInterface;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class AnswerIndex
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
	 * 入库归因答案卡
	 *
	 * opts：
	 * - answer_id（必填）
	 * - triggers（string 列表，建议 1～N 条同义问法）
	 * - embed_answer（默认 true，额外嵌入答案正文）
	 * - namespace / source / payload / alias / vector
	 *
	 * @param string $answerText 标准答案原文
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:array}
	 */
	public function ingestAnswer(string $answerText, array $opts = []): array
	{
		$aligned = $this->config->assertDimensionsAligned();
		if (!$aligned['ok']) {
			return ['code' => 1, 'message' => $aligned['message']];
		}
		$answerText = trim($answerText);
		if ($answerText === '') {
			return ['code' => 1, 'message' => 'answer 为空'];
		}
		$answerId = trim((string)($opts['answer_id'] ?? ''));
		if ($answerId === '') {
			return ['code' => 1, 'message' => 'answer_id 必填'];
		}
		if (str_contains($answerId, ':')) {
			return ['code' => 1, 'message' => 'answer_id 禁止包含冒号'];
		}

		$triggers = [];
		if (isset($opts['triggers']) && is_array($opts['triggers'])) {
			foreach ($opts['triggers'] as $t) {
				if (!is_string($t)) {
					continue;
				}
				$t = trim($t);
				if ($t !== '') {
					$triggers[] = $t;
				}
			}
		}
		$triggers = array_values(array_unique($triggers));
		$embedAnswer = (bool)($opts['embed_answer'] ?? true);
		if ($triggers === [] && !$embedAnswer) {
			return ['code' => 1, 'message' => 'triggers 与 embed_answer 不能同时为空/关闭'];
		}

		$texts = [];
		$meta = [];
		foreach ($triggers as $i => $trig) {
			$texts[] = $trig;
			$meta[] = ['role' => 'trigger', 'seq' => $i, 'trigger' => $trig];
		}
		if ($embedAnswer) {
			$texts[] = $answerText;
			$meta[] = ['role' => 'answer', 'seq' => -1, 'trigger' => ''];
		}

		$emb = $this->embedding->embedBatch($texts);
		if (($emb['code'] ?? 1) !== 0) {
			return ['code' => (int)$emb['code'], 'message' => (string)($emb['message'] ?? 'embed failed')];
		}
		/** @var list<list<float>> $vectors */
		$vectors = $emb['data'] ?? [];
		if (count($vectors) !== count($texts)) {
			return ['code' => 1, 'message' => '嵌入条数与条目不一致'];
		}

		$alias = $this->resolveAnswerAlias($opts);
		$namespace = isset($opts['namespace']) ? (string)$opts['namespace'] : null;
		$source = isset($opts['source']) ? (string)$opts['source'] : null;
		$basePayload = is_array($opts['payload'] ?? null) ? $opts['payload'] : [];

		$vectorOpts = $this->config->vectorOptions;
		if (isset($opts['vector']) && is_array($opts['vector'])) {
			$vectorOpts = array_merge($vectorOpts, $opts['vector']);
		}
		$vectorOpts['dimensions'] = $this->config->dimensions;
		$server = \V($alias, $vectorOpts);

		$ids = [];
		foreach ($meta as $i => $m) {
			$role = (string)$m['role'];
			$id = $role === 'answer'
				? $answerId . ':a'
				: $answerId . ':t:' . (int)$m['seq'];
			$payload = array_merge($basePayload, [
				'kind' => 'answer',
				'answer_id' => $answerId,
				'answer_text' => $answerText,
				'role' => $role,
				'text' => $role === 'answer' ? $answerText : (string)$m['trigger'],
				'trigger' => (string)$m['trigger'],
			]);
			if ($namespace !== null && $namespace !== '') {
				$payload['namespace'] = $namespace;
			}
			if ($source !== null && $source !== '') {
				$payload['source'] = $source;
			}
			$up = $server->upsert($id, $vectors[$i], $payload);
			if (($up['code'] ?? 1) !== 0) {
				return [
					'code' => 1,
					'message' => 'upsert 失败 ' . $id . ': ' . (string)($up['message'] ?? ''),
					'data' => ['answer_id' => $answerId, 'ingested' => $ids],
				];
			}
			$ids[] = $id;
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'data' => [
				'answer_id' => $answerId,
				'alias' => $alias,
				'vector_ids' => $ids,
				'trigger_count' => count($triggers),
				'embed_answer' => $embedAnswer,
			],
		];
	}

	/**
	 * 召回答案卡并按 answer_id 聚合
	 *
	 * @param string $query
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:list<array{answer_id:string,answer_text:string,score:float,hit_count:int,source:?string,payload:array}>}
	 */
	public function retrieveAnswer(string $query, array $opts = []): array
	{
		$aligned = $this->config->assertDimensionsAligned();
		if (!$aligned['ok']) {
			return ['code' => 1, 'message' => $aligned['message']];
		}
		$query = trim($query);
		if ($query === '') {
			return ['code' => 1, 'message' => 'query 为空'];
		}

		$alias = $this->resolveAnswerAlias($opts);
		$topK = max(1, (int)($opts['top_k'] ?? $this->config->attrTopK));
		$fetchK = max($topK * 3, (int)($opts['fetch_k'] ?? $topK * 3));

		$retOpts = $opts;
		$retOpts['alias'] = $alias;
		$retOpts['top_k'] = $fetchK;
		// 归因库优先向量（问句↔触发问）；仍可用 hybrid
		if (!isset($retOpts['mode'])) {
			$retOpts['mode'] = $this->config->attrRetrieveMode;
		}

		$raw = (new Retriever($this->config, $this->embedding))->retrieve($query, $retOpts);
		if (($raw['code'] ?? 1) !== 0) {
			return [
				'code' => (int)$raw['code'],
				'message' => (string)($raw['message'] ?? 'retrieve failed'),
			];
		}

		/** @var array<string,array{answer_id:string,answer_text:string,score:float,hit_count:int,source:?string,payload:array}> $agg */
		$agg = [];
		foreach ($raw['data'] ?? [] as $hit) {
			if (!is_array($hit)) {
				continue;
			}
			$payload = is_array($hit['payload'] ?? null) ? $hit['payload'] : [];
			if (($payload['kind'] ?? '') !== 'answer' && ($payload['answer_id'] ?? '') === '') {
				continue;
			}
			$aid = (string)($payload['answer_id'] ?? '');
			if ($aid === '') {
				continue;
			}
			$score = (float)($hit['score'] ?? 0);
			$text = (string)($payload['answer_text'] ?? $payload['text'] ?? '');
			if (!isset($agg[$aid])) {
				$agg[$aid] = [
					'answer_id' => $aid,
					'answer_text' => $text,
					'score' => $score,
					'hit_count' => 1,
					'source' => isset($payload['source']) ? (string)$payload['source'] : null,
					'payload' => $payload,
				];
			} else {
				// 多 trigger 命中：取 max，并按命中次数轻微加成（封顶 +0.15）
				$agg[$aid]['score'] = max($agg[$aid]['score'], $score);
				$agg[$aid]['hit_count']++;
				$agg[$aid]['score'] = min(1.0, $agg[$aid]['score'] + 0.03);
				if ($agg[$aid]['answer_text'] === '' && $text !== '') {
					$agg[$aid]['answer_text'] = $text;
				}
			}
		}

		$rows = array_values($agg);
		usort($rows, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
		$rows = array_slice($rows, 0, $topK);

		return ['code' => 0, 'message' => 'ok', 'data' => $rows];
	}

	/**
	 * @param array<string,mixed> $opts
	 * @return string
	 */
	private function resolveAnswerAlias(array $opts): string
	{
		if (isset($opts['alias']) && trim((string)$opts['alias']) !== '') {
			return (string)$opts['alias'];
		}
		if (isset($opts['answer_alias']) && trim((string)$opts['answer_alias']) !== '') {
			return (string)$opts['answer_alias'];
		}
		return $this->config->answerAlias;
	}
}
