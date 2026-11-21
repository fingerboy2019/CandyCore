<?php
/***
 * 文本嵌入驱动契约
 *
 * 业务说明：将文本转为 float 向量；批量接口供入库流水线复用
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 06:17:15 $
 *
 * @package Candy\Extend\Rag\Embedding
 */

declare(strict_types=1);

namespace Candy\Extend\Rag\Embedding;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface EmbeddingInterface
{
	/**
	 * 当前向量维度
	 *
	 * @return int
	 */
	public function dimensions(): int;

	/**
	 * 单条文本嵌入
	 *
	 * @param string $text 原文
	 * @return array{code:int,message:string,data?:list<float>}
	 */
	public function embed(string $text): array;

	/**
	 * 批量嵌入（顺序与入参一致）
	 *
	 * @param list<string> $texts
	 * @return array{code:int,message:string,data?:list<list<float>>}
	 */
	public function embedBatch(array $texts): array;
}
