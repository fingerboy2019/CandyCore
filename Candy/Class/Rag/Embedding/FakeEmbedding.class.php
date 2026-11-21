<?php
/***
 * 确定性伪嵌入（冒烟/离线）
 *
 * 业务说明：基于文本哈希生成可复现单位向量，不调用外部 API；仅测试用途
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Embedding
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Embedding;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class FakeEmbedding implements EmbeddingInterface
{
	private int $dimensions;

	/**
	 * @param int $dimensions 维度（与向量库一致）
	 */
	public function __construct(int $dimensions = 768)
	{
		$this->dimensions = max(1, min(4096, $dimensions));
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
		return [
			'code' => 0,
			'message' => 'ok',
			'data' => $this->vectorOf($text),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function embedBatch(array $texts): array
	{
		$out = [];
		foreach ($texts as $t) {
			if (!is_string($t)) {
				return ['code' => 1, 'message' => 'embedBatch 元素必须为 string'];
			}
			$out[] = $this->vectorOf($t);
		}
		return ['code' => 0, 'message' => 'ok', 'data' => $out];
	}

	/**
	 * 由文本生成 L2 归一化伪向量
	 *
	 * @param string $text
	 * @return list<float>
	 */
	private function vectorOf(string $text): array
	{
		$dim = $this->dimensions;
		$seed = hash('sha256', $text, true);
		$vec = [];
		$norm = 0.0;
		$offset = 0;
		for ($i = 0; $i < $dim; $i++) {
			if ($offset + 4 > strlen($seed)) {
				$seed = hash('sha256', $seed . (string)$i, true);
				$offset = 0;
			}
			$u = unpack('N', substr($seed, $offset, 4));
			$offset += 4;
			$v = (($u[1] ?? 0) / 4294967295.0) * 2.0 - 1.0;
			$vec[] = $v;
			$norm += $v * $v;
		}
		$norm = sqrt($norm);
		if ($norm < 1e-12) {
			$vec[0] = 1.0;
			return $vec;
		}
		for ($i = 0; $i < $dim; $i++) {
			$vec[$i] /= $norm;
		}
		return $vec;
	}
}
