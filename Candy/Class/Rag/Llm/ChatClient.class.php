<?php
/***
 * OpenAI 兼容 Chat Completions 客户端
 *
 * 业务说明：对接 llama.cpp /v1/chat/completions；默认关闭 Qwen 思考链；空 content 时回退 reasoning_content
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-09-18 04:10:03 $
 *
 * @package Candy\Extend\Rag\Llm
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Llm;

use Candy\Extend\Network\Http;
use Candy\Extend\Rag\Config;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class ChatClient
{
	private string $baseUrl;
	private string $apiKey;
	private string $model;
	private int $timeout;
	private int $maxTokens;
	private float $temperature;
	private bool $enableThinking;

	/**
	 * @param Config $config
	 */
	public function __construct(Config $config)
	{
		$this->baseUrl = $config->llmBaseUrl;
		$this->apiKey = $config->llmApiKey;
		$this->model = $config->llmModel;
		$this->timeout = $config->llmTimeout;
		$this->maxTokens = $config->llmMaxTokens;
		$this->temperature = $config->llmTemperature;
		$this->enableThinking = $config->llmEnableThinking;
	}

	/**
	 * 从 Config 工厂
	 *
	 * @param Config $config
	 * @return array{code:int,message:string,data?:self}
	 */
	public static function fromConfig(Config $config): array
	{
		if ($config->llmBaseUrl === '') {
			return ['code' => 1, 'message' => 'llm.base_url 未配置'];
		}
		if ($config->llmModel === '') {
			return ['code' => 1, 'message' => 'llm.model 未配置'];
		}
		return ['code' => 0, 'message' => 'ok', 'data' => new self($config)];
	}

	/**
	 * 发送对话
	 *
	 * messages[].content 可为 string，或 OpenAI 多模态数组（text / image_url）
	 * 传入 $opts['tools'] 时启用 Function Calling；有 tool_calls 时允许 content 为空
	 *
	 * @param list<array{role:string,content?:mixed}> $messages
     * @param array<string,mixed> $opts timeout / max_tokens / temperature / enable_thinking / model / tools / tool_choice
	 * @return array{code:int,message:string,data?:array{content:string,tool_calls?:list,reasoning?:string,raw:array,usage?:array}}
	 */
	public function chat(array $messages, array $opts = []): array
	{
		if ($messages === []) {
			return ['code' => 1, 'message' => 'messages 为空'];
		}
        $model = (string)($opts['model'] ?? $this->model);
        $timeout = max(1, (int)($opts['timeout'] ?? $this->timeout));
		$maxTokens = max(1, (int)($opts['max_tokens'] ?? $this->maxTokens));
		$temperature = (float)($opts['temperature'] ?? $this->temperature);
		$enableThinking = array_key_exists('enable_thinking', $opts)
			? (bool)$opts['enable_thinking']
			: $this->enableThinking;

		$payload = [
			'model' => $model,
			'messages' => array_values($messages),
			'max_tokens' => $maxTokens,
			'temperature' => $temperature,
			// Qwen3.x / llama.cpp：关闭思考链，避免 content 为空且耗尽 max_tokens
			'chat_template_kwargs' => [
				'enable_thinking' => $enableThinking,
			],
		];
		if (isset($opts['tools']) && is_array($opts['tools']) && $opts['tools'] !== []) {
			$payload['tools'] = array_values($opts['tools']);
			if (array_key_exists('tool_choice', $opts)) {
				$payload['tool_choice'] = $opts['tool_choice'];
			}
		}
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

		$url = $this->baseUrl . '/chat/completions';
		$resp = Http::post($url, $body, [
            'timeout' => $timeout,
			'headers' => $headers,
		]);
		$code = (int)($resp['http_code'] ?? 0);
		$content = (string)($resp['content'] ?? '');
		if ($code < 200 || $code >= 300) {
			return [
				'code' => 1,
				'message' => 'chat HTTP ' . $code . ': ' . mb_substr($content, 0, 240),
			];
		}
		$json = json_decode($content, true);
		if (!is_array($json)) {
			return ['code' => 1, 'message' => 'chat 响应非 JSON'];
		}
		$choice = $json['choices'][0] ?? null;
		if (!is_array($choice)) {
			return ['code' => 1, 'message' => 'chat 响应缺少 choices'];
		}
		$msg = is_array($choice['message'] ?? null) ? $choice['message'] : [];
		$text = trim((string)($msg['content'] ?? ''));
		$reasoning = trim((string)($msg['reasoning_content'] ?? ''));
		if ($text === '' && $reasoning !== '') {
			// 未关思考或 max_tokens 耗尽时的兜底
			$text = $reasoning;
		}

		$toolCalls = [];
		if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
			foreach ($msg['tool_calls'] as $tc) {
				if (!is_array($tc)) {
					continue;
				}
				$toolCalls[] = $tc;
			}
		}

		// Function Calling：有 tool_calls 时 content 可为空，仍视为成功
		if ($text === '' && $toolCalls === []) {
			return [
				'code' => 1,
				'message' => '模型返回空 content（可检查 enable_thinking / max_tokens）',
				'data' => [
					'content' => '',
					'reasoning' => $reasoning,
					'raw' => $json,
					'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
				],
			];
		}

		$data = [
			'content' => $text,
			'reasoning' => $reasoning !== '' ? $reasoning : null,
			'finish_reason' => isset($choice['finish_reason']) ? (string)$choice['finish_reason'] : null,
			'raw' => $json,
			'usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null,
		];
		if ($toolCalls !== []) {
			$data['tool_calls'] = $toolCalls;
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'data' => $data,
		];
	}

	/**
	 * 发送 Provider-neutral SSE 对话流。
	 *
	 * 只有收到完整的 SSE [DONE] 帧，且正文或工具调用至少存在一项时才返回成功。
	 * 回调收到 delta、reasoning_delta、tool_delta、usage、done 五类请求内事件；
	 * 不会把原始 Provider 帧或工具参数写入持久化层。
	 *
	 * @param list<array{role:string,content?:mixed}> $messages OpenAI 对话消息
     * @param array<string,mixed> $opts timeout / max_tokens / temperature / enable_thinking / model / tools / tool_choice
	 * @param callable|null $onEvent 请求内事件回调，返回 false 时主动停止流
	 * @return array{code:int,message:string,data?:array{content:string,tool_calls:list,reasoning:?string,finish_reason:?string,usage:?array}}
	 */
	public function stream(
		array $messages,
		array $opts = [],
		?callable $onEvent = null
	): array {
		if ($messages === []) {
			return ['code' => 1, 'message' => 'messages 为空'];
		}
        $model = (string)($opts['model'] ?? $this->model);
        $timeout = max(1, (int)($opts['timeout'] ?? $this->timeout));
		$maxTokens = max(1, (int)($opts['max_tokens'] ?? $this->maxTokens));
		$temperature = (float)($opts['temperature'] ?? $this->temperature);
		$enableThinking = array_key_exists('enable_thinking', $opts)
			? (bool)$opts['enable_thinking']
			: $this->enableThinking;
		$payload = [
			'model' => $model,
			'messages' => array_values($messages),
			'max_tokens' => $maxTokens,
			'temperature' => $temperature,
			'stream' => true,
			'stream_options' => ['include_usage' => true],
			'chat_template_kwargs' => [
				'enable_thinking' => $enableThinking,
			],
		];
		if (isset($opts['stream_options']) && is_array($opts['stream_options'])) {
			$payload['stream_options'] = array_merge(
				$opts['stream_options'],
				['include_usage' => true]
			);
		}
		if (isset($opts['tools']) && is_array($opts['tools']) && $opts['tools'] !== []) {
			$payload['tools'] = array_values($opts['tools']);
			if (array_key_exists('tool_choice', $opts)) {
				$payload['tool_choice'] = $opts['tool_choice'];
			}
		}
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($body === false) {
			return ['code' => 1, 'message' => '请求体 JSON 编码失败'];
		}

		$headers = [
			'Content-Type: application/json; charset=utf-8',
			'Accept: text/event-stream',
			'Cache-Control: no-cache',
		];
		if ($this->apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $this->apiKey;
		}

		$content = '';
		$reasoning = '';
		$usage = null;
		$finishReason = null;
		$doneReceived = false;
		$toolParts = [];
		$decoder = new SseFrameDecoder();
		$emit = static function (array $event) use ($onEvent): bool {
			return $onEvent === null || $onEvent($event) !== false;
		};
		$onFrame = function (array $frame) use (
			&$content,
			&$reasoning,
			&$usage,
			&$finishReason,
			&$doneReceived,
			&$toolParts,
			$emit
		): bool {
			if (!empty($frame['done'])) {
				$doneReceived = true;

				return $emit([
					'type' => 'done',
					'finish_reason' => $finishReason,
				]);
			}

			$data = is_array($frame['data'] ?? null) ? $frame['data'] : [];
			if (is_array($data['usage'] ?? null)) {
				$usage = $data['usage'];
				if (!$emit(['type' => 'usage', 'usage' => $usage])) {
					return false;
				}
			}
			$choice = is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
			$delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
			if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
				$finishReason = (string)$choice['finish_reason'];
			}

			$text = (string)($delta['content'] ?? '');
			if ($text !== '') {
				$content .= $text;
				if (!$emit(['type' => 'delta', 'text' => $text])) {
					return false;
				}
			}
			$reasoningText = (string)($delta['reasoning_content'] ?? '');
			if ($reasoningText !== '') {
				$reasoning .= $reasoningText;
				if (!$emit(['type' => 'reasoning_delta', 'text' => $reasoningText])) {
					return false;
				}
			}

			$toolDeltas = is_array($delta['tool_calls'] ?? null)
				? $delta['tool_calls'] : [];
			if (is_array($delta['function_call'] ?? null)) {
				$toolDeltas[] = [
					'index' => 0,
					'function' => $delta['function_call'],
				];
			}
			foreach ($toolDeltas as $position => $toolDelta) {
				if (!is_array($toolDelta)) {
					continue;
				}
				$index = (int)($toolDelta['index'] ?? $position);
				if (!isset($toolParts[$index])) {
					$toolParts[$index] = [
						'id' => '',
						'name' => '',
						'arguments' => '',
					];
				}
				$idPart = (string)($toolDelta['id'] ?? '');
				if ($idPart !== '') {
					$toolParts[$index]['id'] = $idPart;
				}
				$function = is_array($toolDelta['function'] ?? null)
					? $toolDelta['function'] : [];
				$namePart = (string)($function['name'] ?? '');
				if ($namePart !== '') {
					$toolParts[$index]['name'] = $namePart;
				}
				$argumentsDelta = (string)($function['arguments'] ?? '');
				$toolParts[$index]['arguments'] .= $argumentsDelta;
				if (!$emit([
					'type' => 'tool_delta',
					'index' => $index,
					'id' => $toolParts[$index]['id'],
					'name' => $toolParts[$index]['name'],
					'arguments_delta' => $argumentsDelta,
				])) {
					return false;
				}
			}

			return true;
		};

		$http = Http::stream(
			'POST',
			$this->baseUrl . '/chat/completions',
			[
                'timeout' => $timeout,
				'headers' => $headers,
				'data' => $body,
			],
			static function (string $chunk) use ($decoder, $onFrame): bool {
				return $decoder->push($chunk, $onFrame);
			}
		);
		if ((int)($http['http_code'] ?? 0) < 200 || (int)($http['http_code'] ?? 0) >= 300) {
			return [
				'code' => 1,
				'message' => 'stream HTTP ' . (int)($http['http_code'] ?? 0)
					. ': ' . (string)($http['error'] ?? ''),
			];
		}
		if ((string)($http['error'] ?? '') !== '') {
			return [
				'code' => 1,
				'message' => (string)$http['error'],
			];
		}
		if (!$decoder->finish($onFrame) || !$doneReceived || !$decoder->isDone()) {
			return ['code' => 1, 'message' => 'stream 未收到完整 [DONE] 终态'];
		}

		$toolCalls = [];
		ksort($toolParts);
		foreach ($toolParts as $index => $toolPart) {
			$toolCalls[] = [
				'index' => (int)$index,
				'id' => (string)$toolPart['id'],
				'type' => 'function',
				'function' => [
					'name' => (string)$toolPart['name'],
					'arguments' => (string)$toolPart['arguments'],
				],
			];
		}
		if (trim($content) === '' && $toolCalls === []) {
			return ['code' => 1, 'message' => '模型流返回空 content'];
		}

		return [
			'code' => 0,
			'message' => 'ok',
			'data' => [
				'content' => trim($content),
				'reasoning' => $reasoning !== '' ? trim($reasoning) : null,
				'finish_reason' => $finishReason,
				'usage' => $usage,
				'tool_calls' => $toolCalls,
			],
		];
	}
}
