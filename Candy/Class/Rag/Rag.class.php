<?php
/***
 * RAG 框架门面
 *
 * 业务说明：静态入口 ingestText / retrieve / buildContext / ask；无业务表与 Admin UI
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:31:10 $
 *
 * @package Candy\Extend\Rag
 */

declare(strict_types=1);

namespace Candy\Extend\Rag;

use Candy\Extend\Rag\Embedding\EmbeddingFactory;
use Candy\Extend\Rag\Embedding\EmbeddingInterface;
use Candy\Extend\Rag\Llm\ChatClient;
use Candy\Extend\Rag\Pipeline\AnswerIndex;
use Candy\Extend\Rag\Pipeline\Asker;
use Candy\Extend\Rag\Pipeline\ContextBuilder;
use Candy\Extend\Rag\Pipeline\Ingestor;
use Candy\Extend\Rag\Pipeline\Retriever;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Rag
{
	/**
	 * 从配置构建（校验维度）
	 *
	 * @param array<string,mixed> $override
	 * @return array{code:int,message:string,data?:Config}
	 */
	public static function fromConfig(array $override = []): array
	{
		$cfg = Config::from($override);
		$aligned = $cfg->assertDimensionsAligned();
		if (!$aligned['ok']) {
			return ['code' => 1, 'message' => $aligned['message']];
		}
		return ['code' => 0, 'message' => 'ok', 'data' => $cfg];
	}

	/**
	 * 创建嵌入驱动（供业务单独调用）
	 *
	 * @param array<string,mixed> $override
	 * @return array{code:int,message:string,data?:EmbeddingInterface}
	 */
	public static function embedding(array $override = []): array
	{
		$cfgResult = self::fromConfig($override);
		if (($cfgResult['code'] ?? 1) !== 0 || !isset($cfgResult['data'])) {
			return ['code' => (int)($cfgResult['code'] ?? 1), 'message' => (string)($cfgResult['message'] ?? '')];
		}
		return EmbeddingFactory::make($cfgResult['data']);
	}

	/**
	 * 创建 Chat 客户端
	 *
	 * @param array<string,mixed> $override
	 * @return array{code:int,message:string,data?:ChatClient}
	 */
	public static function chatClient(array $override = []): array
	{
		$cfgResult = self::fromConfig($override);
		if (($cfgResult['code'] ?? 1) !== 0 || !isset($cfgResult['data'])) {
			return ['code' => (int)($cfgResult['code'] ?? 1), 'message' => (string)($cfgResult['message'] ?? '')];
		}
		return ChatClient::fromConfig($cfgResult['data']);
	}

	/**
	 * 纯文本 / Markdown 入库
	 *
	 * @param string $text
	 * @param array<string,mixed> $opts 含 doc_id；可含 embedding/chunk/vector 等覆盖
	 * @return array{code:int,message:string,data?:array}
	 */
	public static function ingestText(string $text, array $opts = []): array
	{
		$configOverride = self::extractConfigOverride($opts);
		$cfgResult = self::fromConfig($configOverride);
		if (($cfgResult['code'] ?? 1) !== 0 || !isset($cfgResult['data'])) {
			return ['code' => (int)($cfgResult['code'] ?? 1), 'message' => (string)($cfgResult['message'] ?? '')];
		}
		try {
			$ingestor = new Ingestor($cfgResult['data']);
			return $ingestor->ingestText($text, $opts);
		} catch (\Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 检索
	 *
	 * @param string $query
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:list}
	 */
	public static function retrieve(string $query, array $opts = []): array
	{
		$configOverride = self::extractConfigOverride($opts);
		$cfgResult = self::fromConfig($configOverride);
		if (($cfgResult['code'] ?? 1) !== 0 || !isset($cfgResult['data'])) {
			return ['code' => (int)($cfgResult['code'] ?? 1), 'message' => (string)($cfgResult['message'] ?? '')];
		}
		try {
			$retriever = new Retriever($cfgResult['data']);
			return $retriever->retrieve($query, $opts);
		} catch (\Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 拼装上下文字符串
	 *
	 * @param list<array>|array $hits retrieve 的 data
	 * @param array<string,mixed> $opts
	 * @return string
	 */
	public static function buildContext(array $hits, array $opts = []): string
	{
		return (new ContextBuilder())->build($hits, $opts);
	}

	/**
	 * 检索增强问答（retrieve → context → chat；支持归因润色）
	 *
	 * @param string $question
	 * @param array<string,mixed> $opts mode=auto|attr|doc
	 * @return array{code:int,message:string,data?:array}
	 */
	public static function ask(string $question, array $opts = []): array
	{
		$configOverride = self::extractConfigOverride($opts);
		$cfgResult = self::fromConfig($configOverride);
		if (($cfgResult['code'] ?? 1) !== 0 || !isset($cfgResult['data'])) {
			return ['code' => (int)($cfgResult['code'] ?? 1), 'message' => (string)($cfgResult['message'] ?? '')];
		}
		try {
			$asker = new Asker($cfgResult['data']);
			return $asker->ask($question, $opts);
		} catch (\Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 入库归因答案卡（标准答案 + 触发问法）
	 *
	 * @param string $answerText
	 * @param array<string,mixed> $opts answer_id、triggers 必填语义见 AnswerIndex
	 * @return array{code:int,message:string,data?:array}
	 */
	public static function ingestAnswer(string $answerText, array $opts = []): array
	{
		$configOverride = self::extractConfigOverride($opts);
		$cfgResult = self::fromConfig($configOverride);
		if (($cfgResult['code'] ?? 1) !== 0 || !isset($cfgResult['data'])) {
			return ['code' => (int)($cfgResult['code'] ?? 1), 'message' => (string)($cfgResult['message'] ?? '')];
		}
		try {
			return (new AnswerIndex($cfgResult['data']))->ingestAnswer($answerText, $opts);
		} catch (\Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 召回归因答案卡（按 answer_id 聚合）
	 *
	 * @param string $query
	 * @param array<string,mixed> $opts
	 * @return array{code:int,message:string,data?:list}
	 */
	public static function retrieveAnswer(string $query, array $opts = []): array
	{
		$configOverride = self::extractConfigOverride($opts);
		$cfgResult = self::fromConfig($configOverride);
		if (($cfgResult['code'] ?? 1) !== 0 || !isset($cfgResult['data'])) {
			return ['code' => (int)($cfgResult['code'] ?? 1), 'message' => (string)($cfgResult['message'] ?? '')];
		}
		try {
			return (new AnswerIndex($cfgResult['data']))->retrieveAnswer($query, $opts);
		} catch (\Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 从调用 opts 抽出可交给 Config::from 的键
	 *
	 * @param array<string,mixed> $opts
	 * @return array<string,mixed>
	 */
	private static function extractConfigOverride(array $opts): array
	{
		$out = [];
		foreach (['alias', 'embedding', 'chunk', 'retrieve', 'vector', 'llm', 'attribution'] as $key) {
			if (array_key_exists($key, $opts)) {
				$out[$key] = $opts[$key];
			}
		}
		return $out;
	}
}
