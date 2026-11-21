<?php
/***
 * OpenAI 兼容 Embeddings HTTP 驱动
 *
 * 业务说明：请求 {base_url}/embeddings；支持智谱/DeepSeek 等兼容端点
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Embedding
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Embedding;

use Candy\Extend\Network\Http;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class OpenAICompatibleEmbedding implements EmbeddingInterface
{
	private string $baseUrl;
	private string $apiKey;
	private string $model;
	private int $dimensions;
	private int $timeout;

	/**
	 * @param string $baseUrl 如 https://api.openai.com/v1
	 * @param string $apiKey Bearer Token
	 * @param string $model 模型名
	 * @param int $dimensions 期望维度（用于校验返回长度）
	 * @param int $timeout 秒
	 */
	public function __construct(
		string $baseUrl,
		string $apiKey,
		string $model,
		int $dimensions = 768,
		int $timeout = 60
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->apiKey = $apiKey;
		$this->model = $model;
		$this->dimensions = max(1, min(4096, $dimensions));
		$this->timeout = max(1, $timeout);
	}

	/**
	 * {@inheritdoc}
	 */
	public function dimensions(): int
	{
		return $this->dimensions;
	}

	/**
	 * {@inheritdoc}
	 */
	public function embed(string $text): array
	{
		$batch = $this->embedBatch([$text]);
		if (($batch['code'] ?? 1) !== 0) {
			return ['code' => (int)$batch['code'], 'message' => (string)($batch['message'] ?? 'embed failed')];
		}
		$first = $batch['data'][0] ?? null;
		if (!is_array($first)) {
			return ['code' => 1, 'message' => '嵌入结果为空'];
		}
		return ['code' => 0, 'message' => 'ok', 'data' => $first];
	}

	/**
	 * {@inheritdoc}
	 */
	public function embedBatch(array $texts): array
	{
		if ($texts === []) {
			return ['code' => 0, 'message' => 'ok', 'data' => []];
		}
		// 本地 llama.cpp / 内网网关常无密钥；有密钥才带 Authorization
		if ($this->baseUrl === '') {
			return ['code' => 1, 'message' => 'embedding.base_url 未配置'];
		}
		foreach ($texts as $t) {
			if (!is_string($t)) {
				return ['code' => 1, 'message' => 'embedBatch 元素必须为 string'];
			}
		}

		$url = $this->baseUrl . '/embeddings';
		$body = json_encode([
			'model' => $this->model,
			'input' => count($texts) === 1 ? $texts[0] : array_values($texts),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($body === false) {
			return ['code' => 1, 'message' => '请求体 JSON 编码失败'];
		}

		$headers = [
			'Content-Type: application/json; charset=utf-8',
			'Accept: application/json',
		];
		if ($this->apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $this->apiKey;
		}

		$resp = Http::post($url, $body, [
			'timeout' => $this->timeout,
			'headers' => $headers,
		]);
		$code = (int)($resp['http_code'] ?? 0);
		$content = (string)($resp['content'] ?? '');
		if ($code < 200 || $code >= 300) {
			return [
				'code' => 1,
				'message' => 'embeddings HTTP ' . $code . ': ' . mb_substr($content, 0, 200),
			];
		}
		$json = json_decode($content, true);
		if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
			return ['code' => 1, 'message' => 'embeddings 响应缺少 data'];
		}

		// 按 index 排序，保证与 input 对齐
		$rows = $json['data'];
		usort($rows, static function ($a, $b): int {
			return ((int)($a['index'] ?? 0)) <=> ((int)($b['index'] ?? 0));
		});

		$out = [];
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['embedding']) || !is_array($row['embedding'])) {
				return ['code' => 1, 'message' => 'embeddings 项缺少 embedding'];
			}
			$vec = [];
			foreach ($row['embedding'] as $n) {
				$vec[] = (float)$n;
			}
			if (count($vec) !== $this->dimensions) {
				return [
					'code' => 1,
					'message' => '返回维度 ' . count($vec) . ' 与配置 dimensions ' . $this->dimensions . ' 不一致',
				];
			}
			$out[] = $vec;
		}
		if (count($out) !== count($texts)) {
			return [
				'code' => 1,
				'message' => '嵌入条数 ' . count($out) . ' 与输入 ' . count($texts) . ' 不一致',
			];
		}
		return ['code' => 0, 'message' => 'ok', 'data' => $out];
	}
}
