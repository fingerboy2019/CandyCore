<?php
/***
 * OpenAI 兼容语音转写（ASR）客户端
 *
 * 业务说明：对接 /v1/audio/transcriptions（Whisper 兼容）；multipart 上传音频；不落业务密钥
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-09-01 10:33:44 $
 *
 * @package Candy\Extend\Rag\Llm
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Llm;

use Candy\Extend\Network\Http;
use Candy\Extend\Rag\Config;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class AsrClient
{
	private string $baseUrl;
	private string $apiKey;
	private string $model;
	private int $timeout;

	/**
	 * @param Config $config
	 */
	public function __construct(Config $config)
	{
		$this->baseUrl = rtrim((string)$config->llmBaseUrl, '/');
		$this->apiKey = (string)$config->llmApiKey;
		$this->model = (string)$config->llmModel;
		$this->timeout = max(30, (int)$config->llmTimeout);
	}

	/**
	 * 从 Config 工厂
	 *
	 * @param Config $config
	 * @return array{code:int,message:string,data?:self}
	 */
	public static function fromConfig(Config $config): array
	{
		if (rtrim((string)$config->llmBaseUrl, '/') === '') {
			return ['code' => 1, 'message' => 'llm.base_url 未配置'];
		}
		if (trim((string)$config->llmModel) === '') {
			return ['code' => 1, 'message' => 'llm.model 未配置'];
		}
		return ['code' => 0, 'message' => 'ok', 'data' => new self($config)];
	}

	/**
	 * 转写本地音频文件
	 *
	 * 调用 OpenAI 兼容 transcriptions；成功返回 text，可选 duration/language/raw
	 *
	 * @param string $filePath 本地可读音频路径
	 * @param array<string,mixed> $opts language / filename / mime / model / prompt
	 * @return array{code:int,message:string,data?:array{text:string,language?:string,duration?:float,raw:array}}
	 */
	public function transcribe(string $filePath, array $opts = []): array
	{
		$filePath = (string)$filePath;
		if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
			return ['code' => 1, 'message' => '音频文件不可读'];
		}
		$size = (int)@filesize($filePath);
		if ($size <= 0) {
			return ['code' => 1, 'message' => '音频文件为空'];
		}
		if ($size > 25 * 1024 * 1024) {
			return ['code' => 1, 'message' => '音频超过 25MB 限制'];
		}
		if (!class_exists('CURLFile')) {
			return ['code' => 1, 'message' => 'CURLFile 不可用'];
		}

		$model = trim((string)($opts['model'] ?? $this->model));
		$language = trim((string)($opts['language'] ?? ''));
		$filename = trim((string)($opts['filename'] ?? ''));
		if ($filename === '') {
			$filename = basename($filePath);
		}
		$mime = trim((string)($opts['mime'] ?? ''));
		if ($mime === '') {
			$mime = 'application/octet-stream';
		}
		$prompt = trim((string)($opts['prompt'] ?? ''));

		$payload = [
			'model' => $model,
			'file' => new \CURLFile($filePath, $mime, $filename),
			'response_format' => 'json',
		];
		if ($language !== '') {
			$payload['language'] = $language;
		}
		if ($prompt !== '') {
			$payload['prompt'] = $prompt;
		}

		$headers = ['Accept: application/json'];
		if ($this->apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $this->apiKey;
		}

		$url = $this->baseUrl . '/audio/transcriptions';
		$resp = Http::post($url, $payload, [
			'timeout' => $this->timeout,
			'headers' => $headers,
		]);
		$code = (int)($resp['http_code'] ?? 0);
		$content = (string)($resp['content'] ?? '');
		if ($code < 200 || $code >= 300) {
			$msg = 'ASR HTTP ' . $code;
			$decodedErr = json_decode($content, true);
			if (is_array($decodedErr)) {
				$err = $decodedErr['error']['message'] ?? $decodedErr['message'] ?? '';
				if (is_string($err) && $err !== '') {
					$msg = $err;
				}
			}
			return ['code' => 1, 'message' => $msg, 'data' => ['raw' => ['http_code' => $code, 'body' => $content]]];
		}
		$decoded = json_decode($content, true);
		if (!is_array($decoded)) {
			return ['code' => 1, 'message' => 'ASR 响应非 JSON'];
		}
		$text = trim((string)($decoded['text'] ?? ''));
		if ($text === '') {
			return ['code' => 1, 'message' => 'ASR 未返回文本', 'data' => ['raw' => $decoded]];
		}
		$data = [
			'text' => $text,
			'raw' => $decoded,
		];
		if (isset($decoded['language'])) {
			$data['language'] = (string)$decoded['language'];
		}
		if (isset($decoded['duration'])) {
			$data['duration'] = (float)$decoded['duration'];
		}
		return ['code' => 0, 'message' => 'ok', 'data' => $data];
	}
}
