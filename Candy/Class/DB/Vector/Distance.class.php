<?php
/***
 * 向量距离度量
 *
 * 业务说明：HNSW 用「越小越近」距离；对外结果用「越大越好」score
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-21 10:16:44 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

use Candy\Core\Impl\Pack\Vector\VectorService;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

// Impl/Pack 被 Autoload 静默跳过，需显式加载
if (!class_exists(VectorService::class, false)) {
	require_once CANDYPATH . 'Candy/Core/Impl/Pack/Vector/VectorService.php';
}

/**
 * 距离度量工具
 */
final class Distance
{
	public const COSINE = 'cosine';
	public const EUCLIDEAN = 'euclidean';
	public const DOT = 'dot';
	public const MANHATTAN = 'manhattan';

	/**
	 * 规范化度量名
	 *
	 * @param string $metric 入参
	 * @return string
	 */
	public static function normalize(string $metric): string
	{
		$m = strtolower(trim($metric));
		return match ($m) {
			'l2', 'euclid', 'euclidean' => self::EUCLIDEAN,
			'ip', 'inner_product', 'dot', 'dotproduct' => self::DOT,
			'l1', 'manhattan' => self::MANHATTAN,
			default => self::COSINE,
		};
	}

	/**
	 * HNSW 用距离：越小越相似
	 *
	 * @param array<int,float|int> $a
	 * @param array<int,float|int> $b
	 * @param string $metric
	 * @return float
	 */
	public static function distance(array $a, array $b, string $metric = self::COSINE): float
	{
		$metric = self::normalize($metric);
		return match ($metric) {
			self::EUCLIDEAN => VectorService::euclideanDistance($a, $b),
			self::DOT => -VectorService::dotProduct($a, $b),
			self::MANHATTAN => self::manhattan($a, $b),
			default => 1.0 - VectorService::cosineSimilarity($a, $b),
		};
	}

	/**
	 * 对外相似度分数：越大越好
	 *
	 * @param array<int,float|int> $a
	 * @param array<int,float|int> $b
	 * @param string $metric
	 * @return float
	 */
	public static function score(array $a, array $b, string $metric = self::COSINE): float
	{
		$metric = self::normalize($metric);
		return match ($metric) {
			self::EUCLIDEAN => 1.0 / (1.0 + VectorService::euclideanDistance($a, $b)),
			self::DOT => VectorService::dotProduct($a, $b),
			self::MANHATTAN => 1.0 / (1.0 + self::manhattan($a, $b)),
			default => VectorService::cosineSimilarity($a, $b),
		};
	}

	/**
	 * @param array<int,float|int> $a
	 * @param array<int,float|int> $b
	 * @return float
	 */
	public static function manhattan(array $a, array $b): float
	{
		$sum = 0.0;
		$n = min(count($a), count($b));
		for ($i = 0; $i < $n; $i++) {
			$sum += abs((float)$a[$i] - (float)$b[$i]);
		}
		return $sum;
	}
}
