<?php
/***
 * BM25 倒排索引
 *
 * 业务说明：内存倒排 + bm25.bin 持久化；配合 SimpleTokenizer
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-18 20:25:00 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

/**
 * Bm25Index
 */
final class Bm25Index
{
	public const MAGIC = 'CVB1';

	private float $k1;
	private float $b;
	private SimpleTokenizer $tokenizer;

	/** @var array<string, array{len:int, tf:array<string,int>}> */
	private array $docs = [];

	/** @var array<string,int> term => df */
	private array $df = [];

	private float $avgdl = 0.0;

	/**
	 * @param float $k1 TF 饱和
	 * @param float $b 长度归一化
	 * @param SimpleTokenizer|null $tokenizer 分词器
	 */
	public function __construct(float $k1 = 1.5, float $b = 0.75, ?SimpleTokenizer $tokenizer = null)
	{
		$this->k1 = max(0.1, $k1);
		$this->b = max(0.0, min(1.0, $b));
		$this->tokenizer = $tokenizer ?? new SimpleTokenizer();
	}

	/**
	 * 索引/更新文档正文
	 *
	 * @param string $id 文档 id
	 * @param string $text 正文
	 * @return void
	 */
	public function upsert(string $id, string $text): void
	{
		$this->remove($id);
		$tokens = $this->tokenizer->tokenize($text);
		if ($tokens === []) {
			return;
		}
		$tf = [];
		foreach ($tokens as $t) {
			$tf[$t] = ($tf[$t] ?? 0) + 1;
		}
		$len = count($tokens);
		$this->docs[$id] = ['len' => $len, 'tf' => $tf];
		foreach ($tf as $term => $_) {
			$this->df[$term] = ($this->df[$term] ?? 0) + 1;
		}
		$this->recomputeAvgdl();
	}

	/**
	 * 移除文档
	 *
	 * @param string $id
	 * @return void
	 */
	public function remove(string $id): void
	{
		if (!isset($this->docs[$id])) {
			return;
		}
		foreach ($this->docs[$id]['tf'] as $term => $_) {
			if (!isset($this->df[$term])) {
				continue;
			}
			$this->df[$term]--;
			if ($this->df[$term] <= 0) {
				unset($this->df[$term]);
			}
		}
		unset($this->docs[$id]);
		$this->recomputeAvgdl();
	}

	/**
	 * BM25 检索，返回 id => score 降序
	 *
	 * @param string $query 查询文本
	 * @param int $topK
	 * @return array<string,float>
	 */
	public function search(string $query, int $topK = 10): array
	{
		$tokens = $this->tokenizer->tokenize($query);
		if ($tokens === [] || $this->docs === []) {
			return [];
		}
		$N = count($this->docs);
		$scores = [];
		$qtf = [];
		foreach ($tokens as $t) {
			$qtf[$t] = ($qtf[$t] ?? 0) + 1;
		}

		foreach ($this->docs as $id => $doc) {
			$score = 0.0;
			$dl = max(1, $doc['len']);
			foreach ($qtf as $term => $_) {
				$tf = $doc['tf'][$term] ?? 0;
				if ($tf <= 0) {
					continue;
				}
				$df = $this->df[$term] ?? 0;
				if ($df <= 0) {
					continue;
				}
				$idf = log(1.0 + ($N - $df + 0.5) / ($df + 0.5));
				$den = $tf + $this->k1 * (1.0 - $this->b + $this->b * ($dl / max(1.0, $this->avgdl)));
				$score += $idf * (($tf * ($this->k1 + 1.0)) / max(1e-9, $den));
			}
			if ($score > 0.0) {
				$scores[$id] = $score;
			}
		}
		arsort($scores);
		return array_slice($scores, 0, max(1, $topK), true);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function export(): array
	{
		return [
			'k1' => $this->k1,
			'b' => $this->b,
			'docs' => $this->docs,
			'df' => $this->df,
			'avgdl' => $this->avgdl,
		];
	}

	/**
	 * @param array<string,mixed> $data
	 * @param SimpleTokenizer|null $tokenizer
	 * @return self
	 */
	public static function import(array $data, ?SimpleTokenizer $tokenizer = null): self
	{
		$idx = new self(
			(float)($data['k1'] ?? 1.5),
			(float)($data['b'] ?? 0.75),
			$tokenizer
		);
		$idx->docs = is_array($data['docs'] ?? null) ? $data['docs'] : [];
		$idx->df = is_array($data['df'] ?? null) ? $data['df'] : [];
		$idx->avgdl = (float)($data['avgdl'] ?? 0.0);
		if ($idx->avgdl <= 0.0) {
			$idx->recomputeAvgdl();
		}
		return $idx;
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function save(string $path): void
	{
		file_put_contents($path, self::MAGIC . serialize($this->export()));
	}

	/**
	 * @param string $path
	 * @param float $k1
	 * @param float $b
	 * @param SimpleTokenizer|null $tokenizer
	 * @return self
	 */
	public static function load(string $path, float $k1 = 1.5, float $b = 0.75, ?SimpleTokenizer $tokenizer = null): self
	{
		if (!is_file($path)) {
			return new self($k1, $b, $tokenizer);
		}
		$raw = file_get_contents($path);
		if ($raw === false || strlen($raw) < 4 || substr($raw, 0, 4) !== self::MAGIC) {
			return new self($k1, $b, $tokenizer);
		}
		$data = @unserialize(substr($raw, 4), ['allowed_classes' => false]);
		if (!is_array($data)) {
			return new self($k1, $b, $tokenizer);
		}
		return self::import($data, $tokenizer);
	}

	/**
	 * @return int
	 */
	public function documentCount(): int
	{
		return count($this->docs);
	}

	/**
	 * @return void
	 */
	private function recomputeAvgdl(): void
	{
		$n = count($this->docs);
		if ($n === 0) {
			$this->avgdl = 0.0;
			return;
		}
		$sum = 0;
		foreach ($this->docs as $doc) {
			$sum += (int)$doc['len'];
		}
		$this->avgdl = $sum / $n;
	}
}
