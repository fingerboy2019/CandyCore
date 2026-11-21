<?php
/***
 * HNSW 图（内存结构 + 序列化持久化）
 *
 * 业务说明：真多层邻接图构建与搜索；可选 heuristic 多样性选邻；距离由 Distance 度量
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-19 23:50:25 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class HnswGraph
{
	/** @var array<string, array{level:int, neighbors:array<int, list<string>>, slot:int, deleted:bool}> */
	public array $nodes = [];
	public ?string $entryPoint = null;
	public int $maxLevel = 0;

	public function __construct(
		private int $m,
		private int $efConstruction,
		private int $efSearch,
		private int $levelCap,
		private string $metric = Distance::COSINE,
		private bool $useHeuristic = true
	) {
		$this->metric = Distance::normalize($metric);
	}

	/**
	 * 插入或替换节点
	 *
	 * @param string $id
	 * @param int $slot
	 * @param callable(string):array $vectorOf id -> float[]
	 * @return void
	 */
	public function upsert(string $id, int $slot, callable $vectorOf): void
	{
		if (isset($this->nodes[$id])) {
			$this->delete($id);
		}

		$vector = $vectorOf($id);
		$level = $this->randomLevel();
		$node = [
			'level' => $level,
			'neighbors' => array_fill(0, $this->levelCap + 1, []),
			'slot' => $slot,
			'deleted' => false,
		];

		if ($this->entryPoint === null || $this->nodes === []) {
			$this->nodes[$id] = $node;
			$this->entryPoint = $id;
			$this->maxLevel = $level;
			return;
		}

		$curr = $this->entryPoint;
		for ($l = $this->maxLevel; $l > $level; $l--) {
			$curr = $this->searchLayer($curr, $vector, 1, $l, $vectorOf)[0] ?? $curr;
		}

		for ($l = min($level, $this->maxLevel); $l >= 0; $l--) {
			$candidates = $this->searchLayer($curr, $vector, $this->efConstruction, $l, $vectorOf);
			$selected = $this->selectNeighbors($id, $candidates, $vectorOf);
			$node['neighbors'][$l] = $selected;
			foreach ($selected as $nb) {
				if (!isset($this->nodes[$nb])) {
					continue;
				}
				$this->nodes[$nb]['neighbors'][$l][] = $id;
				$this->nodes[$nb]['neighbors'][$l] = array_values(array_unique($this->nodes[$nb]['neighbors'][$l]));
				if (count($this->nodes[$nb]['neighbors'][$l]) > $this->m) {
					$this->nodes[$nb]['neighbors'][$l] = $this->selectNeighbors(
						$nb,
						$this->nodes[$nb]['neighbors'][$l],
						$vectorOf
					);
				}
			}
			$curr = $candidates[0] ?? $curr;
		}

		$this->nodes[$id] = $node;
		if ($level > $this->maxLevel) {
			$this->maxLevel = $level;
			$this->entryPoint = $id;
		}
	}

	/**
	 * 软删节点（断开邻接）
	 *
	 * @param string $id
	 * @return void
	 */
	public function delete(string $id): void
	{
		if (!isset($this->nodes[$id])) {
			return;
		}
		foreach ($this->nodes[$id]['neighbors'] as $level => $neighbors) {
			foreach ($neighbors as $nb) {
				if (!isset($this->nodes[$nb]['neighbors'][$level])) {
					continue;
				}
				$this->nodes[$nb]['neighbors'][$level] = array_values(array_filter(
					$this->nodes[$nb]['neighbors'][$level],
					static fn($x) => $x !== $id
				));
			}
		}
		unset($this->nodes[$id]);
		if ($this->entryPoint === $id) {
			$this->entryPoint = array_key_first($this->nodes);
			$this->maxLevel = 0;
			foreach ($this->nodes as $n) {
				$this->maxLevel = max($this->maxLevel, $n['level']);
			}
		}
	}

	/**
	 * 近似搜索，返回 [id => score]（score 越大越好）
	 *
	 * @param array $query
	 * @param int $topK
	 * @param callable(string):array $vectorOf
	 * @return array<string,float>
	 */
	public function search(array $query, int $topK, callable $vectorOf): array
	{
		if ($this->entryPoint === null || $this->nodes === []) {
			return [];
		}

		$curr = $this->entryPoint;
		for ($l = $this->maxLevel; $l > 0; $l--) {
			$curr = $this->searchLayer($curr, $query, 1, $l, $vectorOf)[0] ?? $curr;
		}
		$candidates = $this->searchLayer($curr, $query, max($this->efSearch, $topK), 0, $vectorOf);

		$scores = [];
		foreach ($candidates as $id) {
			if (!isset($this->nodes[$id]) || ($this->nodes[$id]['deleted'] ?? false)) {
				continue;
			}
			$scores[$id] = Distance::score($query, $vectorOf($id), $this->metric);
		}
		arsort($scores);
		return array_slice($scores, 0, max(1, $topK), true);
	}

	/**
	 * 导出可序列化状态
	 *
	 * @return array
	 */
	public function export(): array
	{
		return [
			'nodes' => $this->nodes,
			'entry_point' => $this->entryPoint,
			'max_level' => $this->maxLevel,
			'm' => $this->m,
			'ef_construction' => $this->efConstruction,
			'ef_search' => $this->efSearch,
			'level_cap' => $this->levelCap,
			'metric' => $this->metric,
			'use_heuristic' => $this->useHeuristic,
		];
	}

	/**
	 * 从状态恢复
	 *
	 * @param array $data
	 * @return self
	 */
	public static function import(array $data): self
	{
		$g = new self(
			(int)($data['m'] ?? 16),
			(int)($data['ef_construction'] ?? 100),
			(int)($data['ef_search'] ?? 50),
			(int)($data['level_cap'] ?? 8),
			(string)($data['metric'] ?? Distance::COSINE),
			(bool)($data['use_heuristic'] ?? true)
		);
		$g->nodes = is_array($data['nodes'] ?? null) ? $data['nodes'] : [];
		$g->entryPoint = isset($data['entry_point']) ? (string)$data['entry_point'] : null;
		$g->maxLevel = (int)($data['max_level'] ?? 0);
		return $g;
	}

	/**
	 * 从候选中选出至多 M 个邻居
	 *
	 * useHeuristic=true：论文式多样性剪枝（离中心近且彼此不过分挤在一起）
	 * useHeuristic=false：仅按到中心距离取最近 M 个
	 *
	 * @param string $centerId 中心节点 id
	 * @param list<string> $candidates
	 * @param callable(string):array $vectorOf
	 * @return list<string>
	 */
	private function selectNeighbors(string $centerId, array $candidates, callable $vectorOf): array
	{
		$candidates = array_values(array_unique(array_filter(
			$candidates,
			fn(string $id): bool => $id !== $centerId && isset($this->nodes[$id])
		)));
		if ($candidates === []) {
			return [];
		}

		$center = $vectorOf($centerId);
		$scored = [];
		foreach ($candidates as $nb) {
			$scored[] = [$nb, $this->distance($center, $vectorOf($nb))];
		}
		usort($scored, static fn($a, $b) => $a[1] <=> $b[1]);

		if (!$this->useHeuristic) {
			return array_column(array_slice($scored, 0, $this->m), 0);
		}

		return $this->selectNeighborsHeuristic($center, $scored, $vectorOf);
	}

	/**
	 * HNSW selectNeighborsHeuristic（简化版）
	 *
	 * 按到中心距离升序扫描候选；仅当候选比已选集合中任一节点都更靠近中心时才接纳
	 * 从而避免邻居挤在同一方向，提高图连通性与召回
	 *
	 * @param list<float> $centerVec
	 * @param list<array{0:string,1:float}> $scored 已按 dist(center,*) 升序
	 * @param callable(string):array $vectorOf
	 * @return list<string>
	 */
	private function selectNeighborsHeuristic(array $centerVec, array $scored, callable $vectorOf): array
	{
		unset($centerVec); // 距离已在 $scored 中预计算
		$selected = [];
		/** @var array<string, list<float>> */
		$selectedVecs = [];

		foreach ($scored as [$candId, $distToCenter]) {
			if (count($selected) >= $this->m) {
				break;
			}
			$candVec = $vectorOf($candId);
			$good = true;
			foreach ($selected as $selId) {
				$distToSel = $this->distance($candVec, $selectedVecs[$selId]);
				// 若候选离已选邻居比离中心更近，说明方向重叠，跳过以保多样性
				if ($distToSel < $distToCenter) {
					$good = false;
					break;
				}
			}
			if ($good) {
				$selected[] = $candId;
				$selectedVecs[$candId] = $candVec;
			}
		}

		// 启发式筛得过严时，按距离补足到 M，避免度数过低
		if (count($selected) < $this->m) {
			foreach ($scored as [$candId]) {
				if (count($selected) >= $this->m) {
					break;
				}
				if (!in_array($candId, $selected, true)) {
					$selected[] = $candId;
				}
			}
		}

		return $selected;
	}

	/**
	 * @param string $entry
	 * @param array $query
	 * @param int $ef
	 * @param int $level
	 * @param callable(string):array $vectorOf
	 * @return list<string>
	 */
	private function searchLayer(string $entry, array $query, int $ef, int $level, callable $vectorOf): array
	{
		if (!isset($this->nodes[$entry])) {
			$entry = (string)array_key_first($this->nodes);
			if ($entry === '') {
				return [];
			}
		}

		$visited = [$entry => true];
		$candidates = [[$entry, $this->distance($query, $vectorOf($entry))]];
		$results = $candidates;

		while ($candidates !== []) {
			usort($candidates, static fn($a, $b) => $a[1] <=> $b[1]);
			[$currentId, $currentDist] = array_shift($candidates);
			usort($results, static fn($a, $b) => $a[1] <=> $b[1]);
			$worst = $results[count($results) - 1][1] ?? INF;
			if ($currentDist > $worst && count($results) >= $ef) {
				break;
			}

			foreach ($this->nodes[$currentId]['neighbors'][$level] ?? [] as $nb) {
				if (isset($visited[$nb]) || !isset($this->nodes[$nb])) {
					continue;
				}
				$visited[$nb] = true;
				$dist = $this->distance($query, $vectorOf($nb));
				$candidates[] = [$nb, $dist];
				$results[] = [$nb, $dist];
				usort($results, static fn($a, $b) => $a[1] <=> $b[1]);
				$results = array_slice($results, 0, $ef);
			}
		}

		usort($results, static fn($a, $b) => $a[1] <=> $b[1]);
		return array_column(array_slice($results, 0, $ef), 0);
	}

	private function distance(array $a, array $b): float
	{
		return Distance::distance($a, $b, $this->metric);
	}

	private function randomLevel(): int
	{
		$ml = 1.0 / log(max(2, $this->m));
		$r = (mt_rand() + 1) / (mt_getrandmax() + 1);
		$level = (int)floor(-log($r) * $ml);
		return min($level, $this->levelCap);
	}
}
