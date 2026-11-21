<?php
/***
 * 按配置创建嵌入驱动
 *
 * 业务说明：fake / openai_compatible 工厂；供 Pipeline 与门面复用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Embedding
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Embedding;

use Candy\Extend\Rag\Config;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class EmbeddingFactory
{
	/**
	 * 根据 Config 创建驱动
	 *
	 * @param Config $config
	 * @return array{code:int,message:string,data?:EmbeddingInterface}
	 */
	public static function make(Config $config): array
	{
		$driver = $config->embeddingDriver;
		if ($driver === 'fake') {
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => new FakeEmbedding($config->dimensions),
			];
		}
		if ($driver === 'openai_compatible' || $driver === 'openai') {
			return [
				'code' => 0,
				'message' => 'ok',
				'data' => new OpenAICompatibleEmbedding(
					$config->embeddingBaseUrl,
					$config->embeddingApiKey,
					$config->embeddingModel,
					$config->dimensions,
					$config->embeddingTimeout
				),
			];
		}
		return ['code' => 1, 'message' => '不支持的 embedding.driver: ' . $driver];
	}
}
