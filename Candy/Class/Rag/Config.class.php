<?php
/***
 * RAG 框架配置解析
 *
 * 业务说明：读取 Rag.inc.php，合并覆盖项，并与 Vector.inc.php 维度对齐校验
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 17:37:11 $
 *
 * @package Candy\Extend\Rag
 */

declare(strict_types=1);

namespace Candy\Extend\Rag;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Config
{
	public string $alias = 'rag';

	public string $embeddingDriver = 'fake';
	public string $embeddingBaseUrl = '';
	public string $embeddingApiKey = '';
	public string $embeddingModel = '';
	public int $dimensions = 768;
	public int $embeddingTimeout = 60;

	public int $chunkSize = 800;
	public int $chunkOverlap = 120;
	public bool $splitByHeading = true;

	public int $topK = 8;
	public string $retrieveMode = 'hybrid';
	public string $hybridMode = 'rrf';
	public float $vectorWeight = 0.7;
	public float $textWeight = 0.3;

	/** @var array<string,mixed> 透传 V() */
	public array $vectorOptions = [];

	public string $llmBaseUrl = '';
	public string $llmApiKey = '';
	public string $llmModel = '';
	public int $llmTimeout = 120;
	public int $llmMaxTokens = 512;
	public float $llmTemperature = 0.2;
	public bool $llmEnableThinking = false;
	public int $llmContextMaxChars = 6000;
	public string $llmSystemPrompt = '你是知识库助手。只能根据用户提供的参考资料回答；资料不足时明确说明无法回答，不要编造。用简洁中文回复。';

	/** 归因答案向量别名（与文档 alias 分离） */
	public string $answerAlias = 'rag_answer';
	/** ask 默认路由：auto|doc|attr|attr_enrich */
	public string $askMode = 'auto';
	/** auto 下归因命中阈值（hybrid/向量 score，需按模型标定） */
	public float $attrThreshold = 0.02;
	public int $attrTopK = 5;
	/** 归因召回模式 */
	public string $attrRetrieveMode = 'hybrid';
	public string $attrSystemPrompt = '你是客服话术助手。用户会给出「标准答案」与「用户问题」。请在不改变标准答案事实的前提下，润色成自然、简洁的中文回复；禁止添加标准答案未出现的信息。';
	/**
	 * auto / attr 命中后是否用文档库完善（attr 默认 false；auto/attr_enrich 默认 true）
	 * 具体以 ask() 解析为准
	 */
	public bool $attrEnrichDocs = true;
	/** 完善时从文档库取的 chunk 数 */
	public int $attrEnrichTopK = 3;
	/** 是否额外用标准答案文本再查一轮文档 */
	public bool $attrEnrichByAnswer = true;
	public string $attrEnrichSystemPrompt = '你是客服话术助手。将给出「标准答案」与可选「补充资料」。标准答案是事实锚点，不可改写或否定；补充资料仅可用来补充细节，若与标准答案冲突则忽略补充资料。请生成自然、简洁的中文回复。';

	/** 子集抽不出字时是否启用多模 OCR */
	public bool $ocrEnable = true;
	/** OCR 最多页数（防打爆） */
	public int $ocrMaxPages = 8;
	/** Ghostscript 渲染 DPI */
	public int $ocrDpi = 144;
	/** gs 可执行文件名或绝对路径 */
	public string $ocrGsBin = 'gs';
	/** 子集最小有效字符数（低于则尝试 OCR） */
	public int $ocrLiteMinChars = 20;
	/** 单页 OCR max_tokens */
	public int $ocrMaxTokens = 1024;
	public string $ocrPrompt = '请只输出图片中的全部可见文字，保持原有换行；不要解释、不要翻译、不要添加未出现的内容。';

	/** OCR 专用端点（视觉默认模型）；空则回退 llm.* */
	public string $ocrLlmBaseUrl = '';
	public string $ocrLlmApiKey = '';
	public string $ocrLlmModel = '';
	public int $ocrLlmTimeout = 0;

	/**
	 * 从 Rag.inc.php、平台默认模型与覆盖项构建配置
	 *
	 * 优先级：显式 override > 环境变量端点/模型 > 平台 DB 默认（运维大模型管理）> Rag.inc.php；密钥环境变量仅补空值
	 *
	 * @param array<string,mixed> $override 覆盖（可含 embedding/chunk/retrieve/vector/llm/attribution 嵌套）
	 * @return self
	 */
	public static function from(array $override = []): self
	{
		$file = self::loadInc();
		$platform = self::platformSegments();
		$cfg = new self();

		$cfg->alias = self::sanitizeAlias((string)($override['alias'] ?? $file['alias'] ?? 'rag'));

		$emb = array_merge(
			is_array($file['embedding'] ?? null) ? $file['embedding'] : [],
			is_array($platform['embedding'] ?? null) ? $platform['embedding'] : [],
			is_array($override['embedding'] ?? null) ? $override['embedding'] : []
		);
		$cfg->embeddingDriver = strtolower((string)($emb['driver'] ?? 'fake'));
		$cfg->embeddingBaseUrl = rtrim((string)($emb['base_url'] ?? ''), '/');
		$embeddingBaseUrl = self::envValue('CANDY_RAG_EMBEDDING_BASE_URL');
		if ($embeddingBaseUrl !== null && !array_key_exists('base_url', (array)($override['embedding'] ?? []))) {
			$cfg->embeddingBaseUrl = rtrim($embeddingBaseUrl, '/');
		}
		$cfg->embeddingApiKey = trim((string)($emb['api_key'] ?? ''));
		$embeddingApiKey = self::envValue('CANDY_RAG_API_KEY');
		if ($cfg->embeddingApiKey === '' && $embeddingApiKey !== null) {
			$cfg->embeddingApiKey = $embeddingApiKey;
		}
		$cfg->embeddingModel = (string)($emb['model'] ?? 'text-embedding-3-small');
		$embeddingModel = self::envValue('CANDY_RAG_EMBEDDING_MODEL');
		if ($embeddingModel !== null && !array_key_exists('model', (array)($override['embedding'] ?? []))) {
			$cfg->embeddingModel = $embeddingModel;
		}
		$cfg->dimensions = max(1, min(4096, (int)($emb['dimensions'] ?? 768)));
		$cfg->embeddingTimeout = max(1, (int)($emb['timeout'] ?? 60));

		$chunk = array_merge(
			is_array($file['chunk'] ?? null) ? $file['chunk'] : [],
			is_array($override['chunk'] ?? null) ? $override['chunk'] : []
		);
		$cfg->chunkSize = max(32, (int)($chunk['size'] ?? 800));
		$cfg->chunkOverlap = max(0, min($cfg->chunkSize - 1, (int)($chunk['overlap'] ?? 120)));
		$cfg->splitByHeading = (bool)($chunk['split_by_heading'] ?? true);

		$ret = array_merge(
			is_array($file['retrieve'] ?? null) ? $file['retrieve'] : [],
			is_array($override['retrieve'] ?? null) ? $override['retrieve'] : []
		);
		$cfg->topK = max(1, (int)($ret['top_k'] ?? 8));
		$mode = strtolower((string)($ret['mode'] ?? 'hybrid'));
		$cfg->retrieveMode = in_array($mode, ['hybrid', 'vector', 'text'], true) ? $mode : 'hybrid';
		$cfg->hybridMode = (string)($ret['hybrid_mode'] ?? 'rrf');
		$cfg->vectorWeight = (float)($ret['vector_weight'] ?? 0.7);
		$cfg->textWeight = (float)($ret['text_weight'] ?? 0.3);

		$vec = array_merge(
			is_array($file['vector'] ?? null) ? $file['vector'] : [],
			is_array($override['vector'] ?? null) ? $override['vector'] : []
		);
		$cfg->vectorOptions = $vec;
		$cfg->vectorOptions['dimensions'] = $cfg->dimensions;

		$llm = array_merge(
			is_array($file['llm'] ?? null) ? $file['llm'] : [],
			is_array($platform['llm'] ?? null) ? $platform['llm'] : [],
			is_array($override['llm'] ?? null) ? $override['llm'] : []
		);
		$cfg->llmBaseUrl = rtrim((string)($llm['base_url'] ?? ''), '/');
		$llmBaseUrl = self::envValue('CANDY_RAG_LLM_BASE_URL');
		if ($llmBaseUrl !== null && !array_key_exists('base_url', (array)($override['llm'] ?? []))) {
			$cfg->llmBaseUrl = rtrim($llmBaseUrl, '/');
		}
		$cfg->llmApiKey = trim((string)($llm['api_key'] ?? ''));
		$llmApiKey = self::envValue('CANDY_RAG_LLM_API_KEY');
		if ($cfg->llmApiKey === '' && $llmApiKey !== null) {
			$cfg->llmApiKey = $llmApiKey;
		}
		$cfg->llmModel = (string)($llm['model'] ?? '');
		$llmModel = self::envValue('CANDY_RAG_LLM_MODEL');
		if ($llmModel !== null && !array_key_exists('model', (array)($override['llm'] ?? []))) {
			$cfg->llmModel = $llmModel;
		}
		$cfg->llmTimeout = max(1, (int)($llm['timeout'] ?? 120));
		$cfg->llmMaxTokens = max(1, (int)($llm['max_tokens'] ?? 512));
		$cfg->llmTemperature = (float)($llm['temperature'] ?? 0.2);
		$cfg->llmEnableThinking = (bool)($llm['enable_thinking'] ?? false);
		$cfg->llmContextMaxChars = max(0, (int)($llm['context_max_chars'] ?? 6000));
		if (isset($llm['system_prompt']) && is_string($llm['system_prompt']) && $llm['system_prompt'] !== '') {
			$cfg->llmSystemPrompt = $llm['system_prompt'];
		}

		$attr = array_merge(
			is_array($file['attribution'] ?? null) ? $file['attribution'] : [],
			is_array($override['attribution'] ?? null) ? $override['attribution'] : []
		);
		$cfg->answerAlias = self::sanitizeAlias((string)($attr['answer_alias'] ?? $cfg->answerAlias));
		$askMode = strtolower((string)($attr['ask_mode'] ?? $override['ask_mode'] ?? $cfg->askMode));
		$cfg->askMode = in_array($askMode, ['auto', 'doc', 'attr', 'attr_enrich'], true) ? $askMode : 'auto';
		$cfg->attrThreshold = (float)($attr['threshold'] ?? $cfg->attrThreshold);
		$cfg->attrTopK = max(1, (int)($attr['top_k'] ?? $cfg->attrTopK));
		$arm = strtolower((string)($attr['retrieve_mode'] ?? $cfg->attrRetrieveMode));
		$cfg->attrRetrieveMode = in_array($arm, ['hybrid', 'vector', 'text'], true) ? $arm : 'hybrid';
		if (isset($attr['system_prompt']) && is_string($attr['system_prompt']) && $attr['system_prompt'] !== '') {
			$cfg->attrSystemPrompt = $attr['system_prompt'];
		}
		$cfg->attrEnrichDocs = (bool)($attr['enrich_docs'] ?? $cfg->attrEnrichDocs);
		$cfg->attrEnrichTopK = max(1, (int)($attr['enrich_top_k'] ?? $cfg->attrEnrichTopK));
		$cfg->attrEnrichByAnswer = (bool)($attr['enrich_by_answer'] ?? $cfg->attrEnrichByAnswer);
		if (isset($attr['enrich_system_prompt']) && is_string($attr['enrich_system_prompt']) && $attr['enrich_system_prompt'] !== '') {
			$cfg->attrEnrichSystemPrompt = $attr['enrich_system_prompt'];
		}

		$ocr = array_merge(
			is_array($file['ocr'] ?? null) ? $file['ocr'] : [],
			is_array($platform['ocr'] ?? null) ? $platform['ocr'] : [],
			is_array($override['ocr'] ?? null) ? $override['ocr'] : []
		);
		$cfg->ocrEnable = (bool)($ocr['enable'] ?? $cfg->ocrEnable);
		$cfg->ocrMaxPages = max(1, min(50, (int)($ocr['max_pages'] ?? $cfg->ocrMaxPages)));
		$cfg->ocrDpi = max(72, min(300, (int)($ocr['dpi'] ?? $cfg->ocrDpi)));
		$gsBin = trim((string)($ocr['gs_bin'] ?? $cfg->ocrGsBin));
		$cfg->ocrGsBin = $gsBin !== '' ? $gsBin : 'gs';
		$cfg->ocrLiteMinChars = max(1, (int)($ocr['lite_min_chars'] ?? $cfg->ocrLiteMinChars));
		$cfg->ocrMaxTokens = max(64, (int)($ocr['max_tokens'] ?? $cfg->ocrMaxTokens));
		if (isset($ocr['prompt']) && is_string($ocr['prompt']) && trim($ocr['prompt']) !== '') {
			$cfg->ocrPrompt = trim($ocr['prompt']);
		}
		$cfg->ocrLlmBaseUrl = rtrim((string)($ocr['llm_base_url'] ?? ''), '/');
		$cfg->ocrLlmApiKey = (string)($ocr['llm_api_key'] ?? '');
		$cfg->ocrLlmModel = (string)($ocr['llm_model'] ?? '');
		$cfg->ocrLlmTimeout = max(0, (int)($ocr['llm_timeout'] ?? 0));

		return $cfg;
	}

	/**
	 * 平台默认模型片段（运维中心）；类不存在或表未装时返回空
	 *
	 * @return array<string,mixed>
	 * @access private
	 * @static
	 */
	private static function platformSegments(): array
	{
		if (!class_exists(\APP\Extend\Llm\Registry::class)) {
			return [];
		}
		try {
			$seg = \APP\Extend\Llm\Registry::ragSegments();
			return is_array($seg) ? $seg : [];
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * 校验与 Vector.inc.php 维度一致
	 *
	 * @return array{ok:bool,message:string,vector_dimensions?:int}
	 */
	public function assertDimensionsAligned(): array
	{
		$vectorDim = self::vectorIncDimensions();
		if ($vectorDim !== null && $vectorDim !== $this->dimensions) {
			return [
				'ok' => false,
				'message' => 'Rag.embedding.dimensions(' . $this->dimensions
					. ') 与 Vector.inc.php dimensions(' . $vectorDim . ') 不一致',
				'vector_dimensions' => $vectorDim,
			];
		}
		return ['ok' => true, 'message' => 'ok', 'vector_dimensions' => $vectorDim ?? $this->dimensions];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function loadInc(): array
	{
		$inc = (defined('INCPATH') ? INCPATH : '') . 'Rag.inc.php';
		if ($inc === 'Rag.inc.php' || !is_file($inc)) {
			return [];
		}
		$loaded = include $inc;
		return is_array($loaded) ? $loaded : [];
	}

	/**
	 * @return int|null
	 */
	private static function vectorIncDimensions(): ?int
	{
		$inc = (defined('INCPATH') ? INCPATH : '') . 'Vector.inc.php';
		if ($inc === 'Vector.inc.php' || !is_file($inc)) {
			return null;
		}
		$loaded = include $inc;
		if (!is_array($loaded) || !isset($loaded['dimensions'])) {
			return null;
		}
		return max(1, min(4096, (int)$loaded['dimensions']));
	}

	/**
	 * @param string $alias
	 * @return string
	 */
	private static function sanitizeAlias(string $alias): string
	{
		$alias = trim($alias);
		$clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $alias) ?: 'rag';
		return $clean !== '' ? $clean : 'rag';
	}

	/**
	 * 读取非空环境变量，避免空值覆盖文件或平台配置
	 *
	 * @param string $name 环境变量名
	 * @return string|null 非空值；未设置或为空时返回 null
	 */
	private static function envValue(string $name): ?string
	{
		$value = getenv($name);
		if ($value === false) {
			return null;
		}
		$value = trim((string)$value);
		return $value !== '' ? $value : null;
	}
}
