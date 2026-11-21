<?php
/***
 * 嵌入式向量库配置
 *
 * 业务说明：解析 Data/vector 路径、维度与 HNSW 参数；根路径基于 CANDYROOT，有租户时落在 t/{id}/{alias}/
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-22 14:30:00 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Config
{
	public string $alias = 'default';
	public string $dataDir = '';
	public int $dimensions = 768;
	public int $m = 16;
	public int $efConstruction = 100;
	public int $efSearch = 50;
	public int $maxLevel = 8;
	/** @var string Distance::COSINE|… */
	public string $distance = Distance::COSINE;
	/** 构图选邻是否用 HNSW heuristic（多样性剪枝） */
	public bool $useHeuristic = true;
	public float $bm25K1 = 1.5;
	public float $bm25B = 0.75;

	/**
	 * 从 Vector.inc.php / 入参构建配置
	 *
	 * @param string $alias 库别名
	 * @param array $override 覆盖项
	 * @return self
	 */
	public static function from(string $alias = 'default', array $override = []): self
	{
		$cfg = new self();
		$file = [];
		$inc = (defined('INCPATH') ? INCPATH : '') . 'Vector.inc.php';
		if ($inc !== 'Vector.inc.php' && is_file($inc)) {
			$loaded = include $inc;
			if (is_array($loaded)) {
				$file = $loaded;
			}
		}

		// 空别名回退 default（与 V() / V(null) 语义一致）
		$alias = trim($alias) !== '' ? trim($alias) : (string)($file['alias'] ?? 'default');
		$cfg->alias = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $alias) ?: 'default';
		$cfg->dimensions = max(1, min(4096, (int)($override['dimensions'] ?? $file['dimensions'] ?? 768)));
		$cfg->m = max(4, (int)($override['m'] ?? $file['m'] ?? 16));
		$cfg->efConstruction = max(10, (int)($override['ef_construction'] ?? $file['ef_construction'] ?? 100));
		$cfg->efSearch = max(10, (int)($override['ef_search'] ?? $file['ef_search'] ?? 50));
		$cfg->maxLevel = max(1, min(16, (int)($override['max_level'] ?? $file['max_level'] ?? 8)));
		$cfg->distance = Distance::normalize((string)($override['distance'] ?? $file['distance'] ?? Distance::COSINE));
		$cfg->useHeuristic = (bool)($override['hnsw_heuristic'] ?? $file['hnsw_heuristic'] ?? true);
		$cfg->bm25K1 = (float)($override['bm25_k1'] ?? $file['bm25_k1'] ?? 1.5);
		$cfg->bm25B = (float)($override['bm25_b'] ?? $file['bm25_b'] ?? 0.75);

		$rel = (string)($override['path'] ?? $file['path'] ?? 'Data/vector');
		$rel = str_replace('\\', '/', $rel);
		if (str_contains($rel, 'backend/')) {
			// 编码禁止依赖仓库分层名；容忍误配时剥掉
			$rel = preg_replace('#(^|/)backend/#', '$1', $rel) ?? $rel;
		}
		// shared=true：强制平台默认空间（不注入 t/{id}/）
		$shared = (bool)($override['shared'] ?? $file['shared'] ?? false);
		$cfg->dataDir = \Candy\Core\Bootstrap::vectorDataDir($cfg->alias, $rel, $shared);

		return $cfg;
	}
}
