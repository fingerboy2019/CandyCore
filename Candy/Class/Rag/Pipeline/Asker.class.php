<?php
/***
 * RAG 问答编排（文档 RAG + 归因润色 + 文档完善）
 *
 * 业务说明：mode=doc|attr|attr_enrich|auto；归因命中后可拉文档库补充再润色，标准答案优先
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:49:48 $
 *
 * @package Candy\Extend\Rag\Pipeline
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Pipeline;

use Candy\Extend\Rag\Config;
use Candy\Extend\Rag\Llm\ChatClient;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Asker
{
	private Config $config;
	private Retriever $retriever;
	private AnswerIndex $answerIndex;
	private ContextBuilder $contextBuilder;
	private ChatClient $chat;

	/**
	 * @param Config $config
	 * @param ChatClient|null $chat
	 * @param Retriever|null $retriever
	 * @param AnswerIndex|null $answerIndex
	 */
	public function __construct(
		Config $config,
		?ChatClient $chat = null,
		?Retriever $retriever = null,
		?AnswerIndex $answerIndex = null
	) {
		$this->config = $config;
		$this->retriever = $retriever ?? new Retriever($config);
		$this->answerIndex = $answerIndex ?? new AnswerIndex($config);
		$this->contextBuilder = new ContextBuilder();
		if ($chat !== null) {
			$this->chat = $chat;
		} else {
			$made = ChatClient::fromConfig($config);
			if (($made['code'] ?? 1) !== 0 || !isset($made['data'])) {
				throw new \InvalidArgumentException((string)($made['message'] ?? 'llm 初始化失败'));
			}
			$this->chat = $made['data'];
		}
	}

	/**
	 * 基于知识库问答
	 *
	 * opts：
	 * - mode：doc|attr|attr_enrich|auto
	 * - enrich_docs：归因命中后是否拉文档完善（覆盖配置）
	 * - doc_alias：文档库别名（完善/文档路径用；默认 Config.alias）
	 * - answer_alias：答案卡别名
	 * - attr_threshold / system_prompt / …
	 *
	 * @param string $question
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:array}
	 */
	public function ask(string $question, array $opts = []): array
	{
		$question = trim($question);
		if ($question === '') {
			return ['code' => 1, 'message' => 'question 为空'];
		}

		$mode = strtolower((string)($opts['mode'] ?? $this->config->askMode));
		if (!in_array($mode, ['doc', 'attr', 'attr_enrich', 'auto'], true)) {
			$mode = 'auto';
		}

		if ($mode === 'doc') {
			return $this->askDoc($question, $opts);
		}
		if ($mode === 'attr') {
			$opts['enrich_docs'] = $opts['enrich_docs'] ?? false;
			return $this->askAttr($question, $opts, true);
		}
		if ($mode === 'attr_enrich') {
			$opts['enrich_docs'] = true;
			return $this->askAttr($question, $opts, true);
		}

		// auto：归因优先（可完善），未达阈值回退文档
		$probeOpts = $opts;
		$probeOpts['enrich_docs'] = $opts['enrich_docs'] ?? $this->config->attrEnrichDocs;
		$attr = $this->askAttr($question, $probeOpts, false);
		if (($attr['code'] ?? 1) !== 0) {
			return $attr;
		}
		$score = (float)($attr['data']['attr_score'] ?? 0);
		$threshold = (float)($opts['attr_threshold'] ?? $this->config->attrThreshold);
		if (!empty($attr['data']['attr_hit']) && $score >= $threshold) {
			return $attr;
		}
		$doc = $this->askDoc($question, $opts);
		if (($doc['code'] ?? 1) === 0 && is_array($doc['data'] ?? null)) {
			$doc['data']['route'] = 'doc_fallback';
			$doc['data']['attr_score'] = $score;
			$doc['data']['attr_threshold'] = $threshold;
		}
		return $doc;
	}

	/**
	 * 常规文档 RAG
	 *
	 * @param string $question
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:array}
	 */
	private function askDoc(string $question, array $opts): array
	{
		$retrieved = $this->retriever->retrieve($question, $opts);
		if (($retrieved['code'] ?? 1) !== 0) {
			return [
				'code' => (int)$retrieved['code'],
				'message' => (string)($retrieved['message'] ?? 'retrieve failed'),
			];
		}
		/** @var list<array{id:string,score:float,text:string,payload:array}> $hits */
		$hits = $retrieved['data'] ?? [];
		$allowEmpty = (bool)($opts['allow_empty_context'] ?? false);

		if ($hits === [] && !$allowEmpty) {
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => [
					'answer' => '知识库中未找到相关内容，无法依据现有资料回答。',
					'citations' => [],
					'hits' => [],
					'context' => '',
					'usage' => null,
					'covered' => false,
					'route' => 'doc',
					'attr_hit' => false,
				],
			];
		}

		$context = $this->buildContextFromHits($hits, $opts);
		$system = (string)($opts['system_prompt'] ?? $this->config->llmSystemPrompt);
		$user = "参考资料：\n" . $context . "\n\n用户问题：" . $question
			. "\n\n请仅依据参考资料作答；若资料不足请明确说明。";

		return $this->finishChat($question, $user, $system, $opts, [
			'hits' => $hits,
			'context' => $context,
			'citations' => $this->citations($hits),
			'covered' => $hits !== [],
			'route' => 'doc',
			'attr_hit' => false,
			'canonical_answer' => null,
		]);
	}

	/**
	 * 归因路径：命中答案卡后润色；可选拉文档库完善
	 *
	 * @param string $question
	 * @param array<string,mixed> $opts
	 * @param bool $strict 无命中是否直接未覆盖（attr/attr_enrich 为 true；auto 探测为 false）
	 * @return array{code:int,message:string,data?:array}
	 */
	private function askAttr(string $question, array $opts, bool $strict): array
	{
		$ansOpts = $opts;
		unset($ansOpts['alias']);
		if (isset($opts['answer_alias']) && trim((string)$opts['answer_alias']) !== '') {
			$ansOpts['alias'] = (string)$opts['answer_alias'];
		} else {
			$ansOpts['alias'] = $this->config->answerAlias;
		}

		$got = $this->answerIndex->retrieveAnswer($question, $ansOpts);
		if (($got['code'] ?? 1) !== 0) {
			return [
				'code' => (int)$got['code'],
				'message' => (string)($got['message'] ?? 'retrieveAnswer failed'),
			];
		}
		$rows = $got['data'] ?? [];
		$top = is_array($rows[0] ?? null) ? $rows[0] : null;
		$score = $top !== null ? (float)($top['score'] ?? 0) : 0.0;
		$threshold = (float)($opts['attr_threshold'] ?? $this->config->attrThreshold);

		if ($top === null || ($strict && $score < $threshold)) {
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => [
					'answer' => '未匹配到归因答案，无法作答。',
					'citations' => [],
					'hits' => [],
					'doc_hits' => [],
					'context' => '',
					'usage' => null,
					'covered' => false,
					'route' => 'attr',
					'attr_hit' => false,
					'attr_score' => $score,
					'attr_threshold' => $threshold,
					'canonical_answer' => null,
					'enriched' => false,
				],
			];
		}

		if (!$strict && $score < $threshold) {
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => [
					'attr_hit' => false,
					'attr_score' => $score,
					'attr_threshold' => $threshold,
					'canonical_answer' => null,
					'answer' => '',
					'covered' => false,
					'route' => 'attr_miss',
					'enriched' => false,
				],
			];
		}

		$canonical = (string)($top['answer_text'] ?? '');
		$answerId = (string)($top['answer_id'] ?? '');
		$enrich = (bool)($opts['enrich_docs'] ?? false);

		$docHits = [];
		if ($enrich) {
			$docHits = $this->fetchEnrichDocHits($question, $canonical, $opts);
		}

		$citations = [[
			'id' => $answerId,
			'score' => $score,
			'snippet' => mb_substr($canonical, 0, 160, 'UTF-8'),
			'kind' => 'answer',
		]];
		foreach ($docHits as $dh) {
			$citations[] = [
				'id' => (string)($dh['id'] ?? ''),
				'score' => (float)($dh['score'] ?? 0),
				'snippet' => mb_substr((string)($dh['text'] ?? ''), 0, 160, 'UTF-8'),
				'kind' => 'doc',
			];
		}

		$pseudoHits = [[
			'id' => $answerId,
			'score' => $score,
			'text' => $canonical,
			'payload' => is_array($top['payload'] ?? null) ? $top['payload'] : [],
		]];

		if ($enrich && $docHits !== []) {
			$docCtx = $this->buildContextFromHits($docHits, array_merge($opts, [
				'context_max_chars' => (int)($opts['enrich_max_chars'] ?? 2400),
			]));
			$context = "【标准答案】\n" . $canonical
				. "\n\n【答案编号】" . $answerId
				. ($top['source'] ? "\n【来源】" . $top['source'] : '')
				. "\n\n【补充资料】\n" . $docCtx;
			$system = (string)($opts['attr_enrich_system_prompt']
				?? $opts['attr_system_prompt']
				?? $this->config->attrEnrichSystemPrompt);
			$user = "标准答案（事实锚点，不可改写或否定）：\n" . $canonical
				. "\n\n补充资料（可选用，冲突则忽略）：\n" . $docCtx
				. "\n\n用户问题：" . $question
				. "\n\n请生成自然、简洁的中文回复：以标准答案为核心，合理融入补充资料中的细节。";
			$route = 'attr_enrich';
		} else {
			$context = "【标准答案】\n" . $canonical
				. "\n\n【答案编号】" . $answerId
				. ($top['source'] ? "\n【来源】" . $top['source'] : '');
			$system = (string)($opts['attr_system_prompt'] ?? $this->config->attrSystemPrompt);
			$user = "标准答案（必须保持事实不变，可润色语气与措辞）：\n" . $canonical
				. "\n\n用户问题：" . $question
				. "\n\n请基于标准答案生成一句自然、简洁的中文回复；不要添加标准答案未包含的事实。";
			$route = $enrich ? 'attr_enrich_empty_docs' : 'attr';
		}

		return $this->finishChat($question, $user, $system, $opts, [
			'hits' => array_merge($pseudoHits, $docHits),
			'doc_hits' => $docHits,
			'context' => $context,
			'citations' => $citations,
			'covered' => true,
			'route' => $route,
			'attr_hit' => true,
			'attr_score' => $score,
			'attr_threshold' => $threshold,
			'canonical_answer' => $canonical,
			'answer_id' => $answerId,
			'enriched' => $enrich && $docHits !== [],
		]);
	}

	/**
	 * 从文档库取补充 chunk（问句 + 可选标准答案再查）
	 *
	 * @param string $question
	 * @param string $canonical
	 * @param array<string,mixed> $opts
	 * @return list<array{id:string,score:float,text:string,payload:array}>
	 */
	private function fetchEnrichDocHits(string $question, string $canonical, array $opts): array
	{
		$topK = max(1, (int)($opts['enrich_top_k'] ?? $this->config->attrEnrichTopK));
		$docOpts = $opts;
		// 文档库别名：优先 doc_alias，否则框架文档 alias（禁止误用答案卡 alias）
		if (isset($opts['doc_alias']) && trim((string)$opts['doc_alias']) !== '') {
			$docOpts['alias'] = (string)$opts['doc_alias'];
		} else {
			$docOpts['alias'] = $this->config->alias;
		}
		unset($docOpts['answer_alias']);
		$docOpts['top_k'] = $topK;

		$merged = [];
		$seen = [];

		$qRet = $this->retriever->retrieve($question, $docOpts);
		if (($qRet['code'] ?? 1) === 0) {
			foreach ($qRet['data'] ?? [] as $h) {
				if (!is_array($h)) {
					continue;
				}
				$id = (string)($h['id'] ?? '');
				if ($id === '' || isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;
				$merged[] = $h;
			}
		}

		$byAnswer = (bool)($opts['enrich_by_answer'] ?? $this->config->attrEnrichByAnswer);
		if ($byAnswer && $canonical !== '') {
			$aRet = $this->retriever->retrieve($canonical, $docOpts);
			if (($aRet['code'] ?? 1) === 0) {
				foreach ($aRet['data'] ?? [] as $h) {
					if (!is_array($h)) {
						continue;
					}
					$id = (string)($h['id'] ?? '');
					if ($id === '' || isset($seen[$id])) {
						continue;
					}
					$seen[$id] = true;
					$merged[] = $h;
				}
			}
		}

		usort($merged, static function (array $a, array $b): int {
			return ((float)($b['score'] ?? 0)) <=> ((float)($a['score'] ?? 0));
		});
		return array_slice($merged, 0, $topK);
	}

	/**
	 * @param list<array{id?:string,score?:float,text?:string,payload?:array}> $hits
	 * @param array<string,mixed> $opts
	 * @return string
	 */
	private function buildContextFromHits(array $hits, array $opts): string
	{
		$ctxOpts = [];
		if (isset($opts['context_max_chars'])) {
			$ctxOpts['max_chars'] = (int)$opts['context_max_chars'];
		} elseif ($this->config->llmContextMaxChars > 0) {
			$ctxOpts['max_chars'] = $this->config->llmContextMaxChars;
		}
		return $this->contextBuilder->build($hits, $ctxOpts);
	}

	/**
	 * @param string $question
	 * @param string $user
	 * @param string $system
	 * @param array<string,mixed> $opts
	 * @param array<string,mixed> $baseData
	 * @return array{code:int,message:string,data?:array}
	 */
	private function finishChat(
		string $question,
		string $user,
		string $system,
		array $opts,
		array $baseData
	): array {
		unset($question);
		$chatOpts = [];
		foreach (['max_tokens', 'temperature', 'enable_thinking', 'model'] as $k) {
			if (array_key_exists($k, $opts)) {
				$chatOpts[$k] = $opts[$k];
			}
		}
		if (isset($opts['llm']) && is_array($opts['llm'])) {
			$chatOpts = array_merge($chatOpts, $opts['llm']);
		}

		$chat = $this->chat->chat([
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => $user],
		], $chatOpts);
		if (($chat['code'] ?? 1) !== 0) {
			return [
				'code' => (int)$chat['code'],
				'message' => (string)($chat['message'] ?? 'chat failed'),
				'data' => $baseData,
			];
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'data' => array_merge($baseData, [
				'answer' => (string)($chat['data']['content'] ?? ''),
				'usage' => $chat['data']['usage'] ?? null,
			]),
		];
	}

	/**
	 * @param list<array{id?:string,score?:float,text?:string,payload?:array}> $hits
	 * @return list<array{id:string,score:float,snippet:string}>
	 */
	private function citations(array $hits): array
	{
		$out = [];
		foreach ($hits as $h) {
			if (!is_array($h)) {
				continue;
			}
			$text = (string)($h['text'] ?? '');
			$out[] = [
				'id' => (string)($h['id'] ?? ''),
				'score' => (float)($h['score'] ?? 0),
				'snippet' => mb_substr($text, 0, 160, 'UTF-8'),
			];
		}
		return $out;
	}
}
