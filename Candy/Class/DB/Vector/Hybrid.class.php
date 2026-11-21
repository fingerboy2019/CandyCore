<?php
/***
 * 混合检索融合
 *
 * 业务说明：RRF 倒数排名融合与加权分数融合
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 23:25:52 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Hybrid 融合
 */
final class Hybrid
{
	public const RRF = 'rrf';
	public const WEIGHTED = 'weighted';

	/**
	 * @param array<string,float> $vectorScores id=>score
	 * @param array<string,float> $textScores id=>score
	 * @param string $mode rrf|weighted
	 * @param int $topK
	 * @param float $vectorWeight
	 * @param float $textWeight
	 * @param int $rrfK RRF 常数，常用 60
	 * @return array<string,float>
	 */
	public static function fuse(
		array $vectorScores,
		array $textScores,
		string $mode = self::RRF,
		int $topK = 10,
		float $vectorWeight = 0.7,
		float $textWeight = 0.3,
		int $rrfK = 60
	): array {
		$mode = strtolower(trim($mode));
		if ($mode === self::WEIGHTED) {
			return self::weighted($vectorScores, $textScores, $topK, $vectorWeight, $textWeight);
		}
		return self::rrf($vectorScores, $textScores, $topK, $rrfK);
	}

	/**
	 * @param array<string,float> $a
	 * @param array<string,float> $b
	 * @param int $topK
	 * @param int $k
	 * @return array<string,float>
	 */
	public static function rrf(array $a, array $b, int $topK = 10, int $k = 60): array
	{
		$k = max(1, $k);
		$ranksA = self::ranks($a);
		$ranksB = self::ranks($b);
		$ids = array_unique(array_merge(array_keys($ranksA), array_keys($ranksB)));
		$scores = [];
		foreach ($ids as $id) {
			$s = 0.0;
			if (isset($ranksA[$id])) {
				$s += 1.0 / ($k + $ranksA[$id]);
			}
			if (isset($ranksB[$id])) {
				$s += 1.0 / ($k + $ranksB[$id]);
			}
			$scores[$id] = $s;
		}
		arsort($scores);
		return array_slice($scores, 0, max(1, $topK), true);
	}

	/**
	 * @param array<string,float> $a
	 * @param array<string,float> $b
	 * @param int $topK
	 * @param float $wa
	 * @param float $wb
	 * @return array<string,float>
	 */
	public static function weighted(
		array $a,
		array $b,
		int $topK = 10,
		float $wa = 0.7,
		float $wb = 0.3
	): array {
		$na = self::normalize($a);
		$nb = self::normalize($b);
		$ids = array_unique(array_merge(array_keys($na), array_keys($nb)));
		$scores = [];
		foreach ($ids as $id) {
			$scores[$id] = $wa * ($na[$id] ?? 0.0) + $wb * ($nb[$id] ?? 0.0);
		}
		arsort($scores);
		return array_slice($scores, 0, max(1, $topK), true);
	}

	/**
	 * @param array<string,float> $scores
	 * @return array<string,int> id => 1-based rank
	 */
	private static function ranks(array $scores): array
	{
		arsort($scores);
		$rank = 1;
		$out = [];
		foreach ($scores as $id => $_) {
			$out[$id] = $rank++;
		}
		return $out;
	}

	/**
	 * min-max 归一化到 [0,1]
	 *
	 * @param array<string,float> $scores
	 * @return array<string,float>
	 */
	private static function normalize(array $scores): array
	{
		if ($scores === []) {
			return [];
		}
		$min = min($scores);
		$max = max($scores);
		$span = $max - $min;
		if ($span < 1e-12) {
			$out = [];
			foreach ($scores as $id => $_) {
				$out[$id] = 1.0;
			}
			return $out;
		}
		$out = [];
		foreach ($scores as $id => $s) {
			$out[$id] = ($s - $min) / $span;
		}
		return $out;
	}
}
