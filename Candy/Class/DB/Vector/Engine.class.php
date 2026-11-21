<?php
/***
 * 嵌入式向量库引擎
 *
 * 业务说明：Data/vector 下 vector/meta/graph/payload/bm25；HNSW+BM25+混合检索
 * 线格式：graph.bin=CVG2；payload.bin=CVP1；bm25.bin=CVB1
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-08-26 15:16:42 $
 *
 * @package Candy\Extend\DB\Vector
 */

declare(strict_types=1);

namespace Candy\Extend\DB\Vector;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Engine
{
	private const VEC_MAGIC = 'CVV1';
	private const META_MAGIC = 'CVM2';
	/** payload 中用于 BM25 的正文键 */
	public const TEXT_KEY = 'text';

	private Config $config;
	private HnswGraph $graph;
	private Bm25Index $bm25;
	/** @var array<string, array{slot:int, deleted:bool, payload_off:int, payload_len:int}> */
	private array $meta = [];
	/** @var list<int> 可复用 slot */
	private array $freeSlots = [];
	private int $nextSlot = 0;
	private bool $opened = false;

	public function __construct(Config $config)
	{
		$this->config = $config;
		$this->graph = $this->newGraph();
		$this->bm25 = new Bm25Index($config->bm25K1, $config->bm25B);
	}

	/**
	 * 按当前配置构造空 HNSW 图
	 *
	 * @return HnswGraph
	 */
	private function newGraph(): HnswGraph
	{
		return new HnswGraph(
			$this->config->m,
			$this->config->efConstruction,
			$this->config->efSearch,
			$this->config->maxLevel,
			$this->config->distance,
			$this->config->useHeuristic
		);
	}

	public function config(): Config
	{
		return $this->config;
	}

	/**
	 * 打开或初始化库目录
	 *
	 * @return array{code:int,message:string}
	 */
	public function open(): array
	{
		$dir = $this->config->dataDir;
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return ['code' => 1, 'message' => '无法创建向量目录: ' . $dir];
		}
		if (!is_writable($dir)) {
			return ['code' => 1, 'message' => '向量目录不可写: ' . $dir];
		}

		$lock = new Lock($dir . '/.lock');
		if (!$lock->acquire(false)) {
			return ['code' => 1, 'message' => '获取读锁失败'];
		}
		try {
			$this->loadMeta();
			$this->loadGraph();
			$this->loadBm25();
			$this->ensureVectorFile();
			PayloadBin::ensure($this->payloadPath());
			$this->opened = true;
			return ['code' => 0, 'message' => 'ok', 'data' => ['dir' => $dir, 'dimensions' => $this->config->dimensions]];
		} finally {
			$lock->release();
		}
	}

	/**
	 * 写入/更新向量（可选 text 供 BM25；也可写在 payload['text']）
	 *
	 * @param string $id
	 * @param array $vector
	 * @param array|null $payload 元数据（可含 text）
	 * @param string|null $text 正文；优先于 payload['text']
	 * @return array{code:int,message:string}
	 */
	public function upsert(string $id, array $vector, ?array $payload = null, ?string $text = null): array
	{
		$id = trim($id);
		if ($id === '') {
			return ['code' => 1, 'message' => 'id 不能为空'];
		}
		if (count($vector) !== $this->config->dimensions) {
			return ['code' => 1, 'message' => '维度不匹配，期望 ' . $this->config->dimensions];
		}

		$lock = new Lock($this->config->dataDir . '/.lock');
		if (!$lock->acquire(true)) {
			return ['code' => 1, 'message' => '获取写锁失败'];
		}
		try {
			if (!$this->opened) {
				$o = $this->openUnlocked();
				if ($o['code'] !== 0) {
					return $o;
				}
			}

			$slot = $this->meta[$id]['slot'] ?? null;
			if ($slot === null) {
				$slot = $this->freeSlots !== [] ? array_pop($this->freeSlots) : $this->nextSlot++;
			}

			$this->writeVectorSlot((int)$slot, $vector);

			$resolvedText = $text;
			if ($resolvedText === null && is_array($payload) && isset($payload[self::TEXT_KEY])) {
				$resolvedText = (string)$payload[self::TEXT_KEY];
			}
			if ($resolvedText !== null && $resolvedText !== '') {
				$payload = is_array($payload) ? $payload : [];
				$payload[self::TEXT_KEY] = $resolvedText;
			}

			$payloadOff = -1;
			$payloadLen = 0;
			if ($payload !== null) {
				$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
				if ($json === false) {
					return ['code' => 1, 'message' => 'payload JSON 编码失败'];
				}
				PayloadBin::ensure($this->payloadPath());
				[$payloadOff, $payloadLen] = PayloadBin::append($this->payloadPath(), $json);
			}
			$this->meta[$id] = [
				'slot' => (int)$slot,
				'deleted' => false,
				'payload_off' => $payloadOff,
				'payload_len' => $payloadLen,
			];

			$vectorOf = fn(string $nid): array => $this->readVectorById($nid);
			$this->graph->upsert($id, (int)$slot, $vectorOf);

			if ($resolvedText !== null && $resolvedText !== '') {
				$this->bm25->upsert($id, $resolvedText);
			} else {
				$this->bm25->remove($id);
			}

			$this->saveMeta();
			$this->saveGraph();
			$this->saveBm25();
			return ['code' => 0, 'message' => 'ok', 'data' => ['id' => $id, 'slot' => $slot]];
		} finally {
			$lock->release();
		}
	}

	/**
	 * 删除（软删 + 从图移除）
	 *
	 * @param string $id
	 * @return array{code:int,message:string}
	 */
	public function delete(string $id): array
	{
		$lock = new Lock($this->config->dataDir . '/.lock');
		if (!$lock->acquire(true)) {
			return ['code' => 1, 'message' => '获取写锁失败'];
		}
		try {
			if (!$this->opened) {
				$o = $this->openUnlocked();
				if ($o['code'] !== 0) {
					return $o;
				}
			}
			if (!isset($this->meta[$id])) {
				return ['code' => 1, 'message' => 'id 不存在'];
			}
			$slot = $this->meta[$id]['slot'];
			$this->graph->delete($id);
			$this->bm25->remove($id);
			unset($this->meta[$id]);
			$this->freeSlots[] = $slot;
			$this->saveMeta();
			$this->saveGraph();
			$this->saveBm25();
			return ['code' => 0, 'message' => 'ok'];
		} finally {
			$lock->release();
		}
	}

	/**
	 * ANN 搜索
	 *
	 * @param array $query
	 * @param int $topK
	 * @param string|null $metric 覆盖库默认度量
	 * @param array<string,mixed>|null $filter metadata 预过滤
	 * @return array{code:int,message:string,data?:list<array{id:string,score:float,payload:?array}>}
	 */
	public function search(
		array $query,
		int $topK = 10,
		?string $metric = null,
		?array $filter = null
	): array {
		if (count($query) !== $this->config->dimensions) {
			return ['code' => 1, 'message' => '查询维度不匹配'];
		}
		$lock = new Lock($this->config->dataDir . '/.lock');
		if (!$lock->acquire(false)) {
			return ['code' => 1, 'message' => '获取读锁失败'];
		}
		try {
			if (!$this->opened) {
				$o = $this->openUnlocked();
				if ($o['code'] !== 0) {
					return $o;
				}
			}
			$useMetric = Distance::normalize($metric ?? $this->config->distance);
			$needFilter = $filter !== null && $filter !== [];
			$fetchK = $needFilter ? max($topK * 5, $topK, 50) : $topK;

			// 度量与构图不一致时：扩大候选后按目标度量重打分
			$scores = $this->graph->search($query, $fetchK, fn(string $id): array => $this->readVectorById($id));
			if ($useMetric !== Distance::normalize($this->config->distance)) {
				$rescored = [];
				foreach (array_keys($scores) as $id) {
					$rescored[$id] = Distance::score($query, $this->readVectorById($id), $useMetric);
				}
				arsort($rescored);
				$scores = $rescored;
			}

			$rows = [];
			foreach ($scores as $id => $score) {
				$payload = $this->readPayload($id);
				if (!MetadataFilter::match($payload, $filter)) {
					continue;
				}
				$rows[] = [
					'id' => $id,
					'score' => (float)$score,
					'payload' => $payload,
				];
				if (count($rows) >= $topK) {
					break;
				}
			}
			return ['code' => 0, 'message' => 'ok', 'data' => $rows];
		} finally {
			$lock->release();
		}
	}

	/**
	 * BM25 全文检索
	 *
	 * @param string $query
	 * @param int $topK
	 * @param array<string,mixed>|null $filter
     * @return array{code:int,message:string,data?:list<array{id:string,score:float,vector_score:float,text_score:float,text_match:bool,payload:?array}>}
	 */
	public function textSearch(string $query, int $topK = 10, ?array $filter = null): array
	{
		$lock = new Lock($this->config->dataDir . '/.lock');
		if (!$lock->acquire(false)) {
			return ['code' => 1, 'message' => '获取读锁失败'];
		}
		try {
			if (!$this->opened) {
				$o = $this->openUnlocked();
				if ($o['code'] !== 0) {
					return $o;
				}
			}
			$needFilter = $filter !== null && $filter !== [];
			$fetchK = $needFilter ? max($topK * 5, $topK, 50) : $topK;
			$scores = $this->bm25->search($query, $fetchK);
			$rows = [];
			foreach ($scores as $id => $score) {
				$payload = $this->readPayload($id);
				if (!MetadataFilter::match($payload, $filter)) {
					continue;
				}
				$rows[] = [
					'id' => $id,
					'score' => (float)$score,
					'payload' => $payload,
				];
				if (count($rows) >= $topK) {
					break;
				}
			}
			return ['code' => 0, 'message' => 'ok', 'data' => $rows];
		} finally {
			$lock->release();
		}
	}

	/**
	 * 混合检索（向量 + BM25）
	 *
	 * @param array $vector 查询向量
	 * @param string $text 查询文本
	 * @param int $topK
	 * @param string $mode rrf|weighted
	 * @param float $vectorWeight
	 * @param float $textWeight
	 * @param string|null $metric
	 * @param array<string,mixed>|null $filter
	 * @return array{code:int,message:string,data?:list}
	 */
	public function hybridSearch(
		array $vector,
		string $text,
		int $topK = 10,
		string $mode = Hybrid::RRF,
		float $vectorWeight = 0.7,
		float $textWeight = 0.3,
		?string $metric = null,
		?array $filter = null
	): array {
		if (count($vector) !== $this->config->dimensions) {
			return ['code' => 1, 'message' => '查询维度不匹配'];
		}
		$lock = new Lock($this->config->dataDir . '/.lock');
		if (!$lock->acquire(false)) {
			return ['code' => 1, 'message' => '获取读锁失败'];
		}
		try {
			if (!$this->opened) {
				$o = $this->openUnlocked();
				if ($o['code'] !== 0) {
					return $o;
				}
			}
			$pool = max($topK * 3, 30);
			$vRes = $this->searchUnlocked($vector, $pool, $metric, $filter);
			$tRes = $this->textSearchUnlocked($text, $pool, $filter);
			$vScores = [];
			foreach ($vRes as $row) {
				$vScores[$row['id']] = $row['score'];
			}
			$tScores = [];
			foreach ($tRes as $row) {
				$tScores[$row['id']] = $row['score'];
			}
			$fused = Hybrid::fuse($vScores, $tScores, $mode, $topK, $vectorWeight, $textWeight);
			$rows = [];
			foreach ($fused as $id => $score) {
				$rows[] = [
					'id' => $id,
					'score' => (float)$score,
					'vector_score' => (float)($vScores[$id] ?? 0),
					'text_score' => (float)($tScores[$id] ?? 0),
					'text_match' => isset($tScores[$id]),
					'payload' => $this->readPayload($id),
				];
			}
			return ['code' => 0, 'message' => 'ok', 'data' => $rows];
		} finally {
			$lock->release();
		}
	}

	/**
	 * 统计信息
	 *
	 * @return array{code:int,message:string,data?:array}
	 */
	public function stats(): array
	{
		return [
			'code' => 0,
			'message' => 'ok',
			'data' => [
				'dir' => $this->config->dataDir,
				'alias' => $this->config->alias,
				'dimensions' => $this->config->dimensions,
				'count' => count($this->meta),
				'bm25_docs' => $this->bm25->documentCount(),
				'distance' => $this->config->distance,
				'graph_nodes' => count($this->graph->nodes),
				'next_slot' => $this->nextSlot,
			],
		];
	}

	/**
	 * 重建 HNSW（optimize）
	 *
	 * @return array{code:int,message:string}
	 */
	public function optimize(): array
	{
		$lock = new Lock($this->config->dataDir . '/.lock');
		if (!$lock->acquire(true)) {
			return ['code' => 1, 'message' => '获取写锁失败'];
		}
		try {
			if (!$this->opened) {
				$o = $this->openUnlocked();
				if ($o['code'] !== 0) {
					return $o;
				}
			}
			$this->graph = $this->newGraph();
			$this->bm25 = new Bm25Index($this->config->bm25K1, $this->config->bm25B);
			foreach ($this->meta as $id => $row) {
				if ($row['deleted'] ?? false) {
					continue;
				}
				$this->graph->upsert($id, (int)$row['slot'], fn(string $nid): array => $this->readVectorById($nid));
				$payload = $this->readPayload($id);
				$text = is_array($payload) ? (string)($payload[self::TEXT_KEY] ?? '') : '';
				if ($text !== '') {
					$this->bm25->upsert($id, $text);
				}
			}
			// 紧凑 payload.bin，去掉已删/覆盖留下的空洞
			$refs = [];
			foreach ($this->meta as $id => $row) {
				if (($row['payload_off'] ?? -1) >= 0 && ($row['payload_len'] ?? 0) > 0) {
					$refs[$id] = [
						'payload_off' => (int)$row['payload_off'],
						'payload_len' => (int)$row['payload_len'],
					];
				}
			}
			if ($refs !== [] && is_file($this->payloadPath())) {
				$map = PayloadBin::compact($this->payloadPath(), $refs);
				foreach ($map as $id => [$off, $len]) {
					if (isset($this->meta[$id])) {
						$this->meta[$id]['payload_off'] = $off;
						$this->meta[$id]['payload_len'] = $len;
					}
				}
			}
			$this->saveMeta();
			$this->saveGraph();
			$this->saveBm25();
			return ['code' => 0, 'message' => 'ok', 'data' => ['nodes' => count($this->graph->nodes)]];
		} finally {
			$lock->release();
		}
	}

	/**
	 * 无外层锁的打开（调用方已持锁）
	 *
	 * @return array{code:int,message:string}
	 */
	private function openUnlocked(): array
	{
		$dir = $this->config->dataDir;
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return ['code' => 1, 'message' => '无法创建向量目录: ' . $dir];
		}
		$this->loadMeta();
		$this->loadGraph();
		$this->loadBm25();
		$this->ensureVectorFile();
		PayloadBin::ensure($this->payloadPath());
		$this->opened = true;
		return ['code' => 0, 'message' => 'ok'];
	}

	/**
	 * 已持读锁的向量检索
	 *
	 * @param array $query
	 * @param int $topK
	 * @param string|null $metric
	 * @param array<string,mixed>|null $filter
	 * @return list<array{id:string,score:float,payload:?array}>
	 */
	private function searchUnlocked(array $query, int $topK, ?string $metric, ?array $filter): array
	{
		$useMetric = Distance::normalize($metric ?? $this->config->distance);
		$needFilter = $filter !== null && $filter !== [];
		$fetchK = $needFilter ? max($topK * 5, $topK, 50) : $topK;
		$scores = $this->graph->search($query, $fetchK, fn(string $id): array => $this->readVectorById($id));
		if ($useMetric !== Distance::normalize($this->config->distance)) {
			$rescored = [];
			foreach (array_keys($scores) as $id) {
				$rescored[$id] = Distance::score($query, $this->readVectorById($id), $useMetric);
			}
			arsort($rescored);
			$scores = $rescored;
		}
		$rows = [];
		foreach ($scores as $id => $score) {
			$payload = $this->readPayload($id);
			if (!MetadataFilter::match($payload, $filter)) {
				continue;
			}
			$rows[] = ['id' => $id, 'score' => (float)$score, 'payload' => $payload];
			if (count($rows) >= $topK) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * 已持读锁的全文检索
	 *
	 * @param string $query
	 * @param int $topK
	 * @param array<string,mixed>|null $filter
	 * @return list<array{id:string,score:float,payload:?array}>
	 */
	private function textSearchUnlocked(string $query, int $topK, ?array $filter): array
	{
		$needFilter = $filter !== null && $filter !== [];
		$fetchK = $needFilter ? max($topK * 5, $topK, 50) : $topK;
		$scores = $this->bm25->search($query, $fetchK);
		$rows = [];
		foreach ($scores as $id => $score) {
			$payload = $this->readPayload($id);
			if (!MetadataFilter::match($payload, $filter)) {
				continue;
			}
			$rows[] = ['id' => $id, 'score' => (float)$score, 'payload' => $payload];
			if (count($rows) >= $topK) {
				break;
			}
		}
		return $rows;
	}

	private function vectorPath(): string
	{
		return $this->config->dataDir . '/vector.bin';
	}

	private function metaPath(): string
	{
		return $this->config->dataDir . '/meta.bin';
	}

	private function graphPath(): string
	{
		return $this->config->dataDir . '/graph.bin';
	}

	private function payloadPath(): string
	{
		return $this->config->dataDir . '/payload.bin';
	}

	private function bm25Path(): string
	{
		return $this->config->dataDir . '/bm25.bin';
	}

	/**
	 * @return void
	 */
	private function loadBm25(): void
	{
		$this->bm25 = Bm25Index::load(
			$this->bm25Path(),
			$this->config->bm25K1,
			$this->config->bm25B
		);
	}

	/**
	 * @return void
	 */
	private function saveBm25(): void
	{
		$this->bm25->save($this->bm25Path());
	}

	private function ensureVectorFile(): void
	{
		$path = $this->vectorPath();
		if (is_file($path)) {
			return;
		}
		$header = self::VEC_MAGIC . pack('N', $this->config->dimensions);
		file_put_contents($path, $header);
	}

	private function writeVectorSlot(int $slot, array $vector): void
	{
		$path = $this->vectorPath();
		$this->ensureVectorFile();
		$fp = fopen($path, 'c+b');
		if ($fp === false) {
			throw new \RuntimeException('无法打开 vector.bin');
		}
		$headerSize = 4 + 4;
		$recordSize = $this->config->dimensions * 4;
		$offset = $headerSize + $slot * $recordSize;
		fseek($fp, $offset);
		fwrite($fp, Float32::pack($vector, $this->config->dimensions));
		fclose($fp);
	}

	/**
	 * @param string $id
	 * @return array<int,float>
	 */
	private function readVectorById(string $id): array
	{
		if (!isset($this->meta[$id])) {
			return array_fill(0, $this->config->dimensions, 0.0);
		}
		return $this->readVectorSlot((int)$this->meta[$id]['slot']);
	}

	/**
	 * @param int $slot
	 * @return array<int,float>
	 */
	private function readVectorSlot(int $slot): array
	{
		$path = $this->vectorPath();
		if (!is_file($path)) {
			return array_fill(0, $this->config->dimensions, 0.0);
		}
		$headerSize = 8;
		$recordSize = $this->config->dimensions * 4;
		$offset = $headerSize + $slot * $recordSize;
		$fp = fopen($path, 'rb');
		if ($fp === false) {
			return array_fill(0, $this->config->dimensions, 0.0);
		}
		fseek($fp, $offset);
		$bin = fread($fp, $recordSize);
		fclose($fp);
		return Float32::unpack(is_string($bin) ? $bin : '', $this->config->dimensions);
	}

	private function loadMeta(): void
	{
		$path = $this->metaPath();
		$this->meta = [];
		$this->freeSlots = [];
		$this->nextSlot = 0;
		if (!is_file($path)) {
			return;
		}
		$raw = file_get_contents($path);
		if ($raw === false || strlen($raw) < 8) {
			return;
		}
		$magic = substr($raw, 0, 4);
		// CVM1/CVM2 均为 serialize 体；CVM2 使用 payload_off/len
		if ($magic !== self::META_MAGIC && $magic !== 'CVM1') {
			return;
		}
		$data = @unserialize(substr($raw, 4), ['allowed_classes' => false]);
		if (!is_array($data)) {
			return;
		}
		$meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
		$normalized = [];
		foreach ($meta as $id => $row) {
			if (!is_array($row)) {
				continue;
			}
			$off = (int)($row['payload_off'] ?? -1);
			$len = (int)($row['payload_len'] ?? 0);
			// 兼容旧版内嵌 payload 字符串：迁移时写入 payload.bin
			if ($off < 0 && isset($row['payload']) && is_string($row['payload']) && $row['payload'] !== '') {
				PayloadBin::ensure($this->payloadPath());
				[$off, $len] = PayloadBin::append($this->payloadPath(), $row['payload']);
			}
			$normalized[(string)$id] = [
				'slot' => (int)($row['slot'] ?? 0),
				'deleted' => !empty($row['deleted']),
				'payload_off' => $off,
				'payload_len' => $len,
			];
		}
		$this->meta = $normalized;
		$this->freeSlots = is_array($data['free'] ?? null) ? array_values($data['free']) : [];
		$this->nextSlot = (int)($data['next_slot'] ?? 0);
		if (isset($data['dimensions']) && (int)$data['dimensions'] > 0) {
			$this->config->dimensions = (int)$data['dimensions'];
		}
	}

	private function saveMeta(): void
	{
		$payload = self::META_MAGIC . serialize([
			'dimensions' => $this->config->dimensions,
			'meta' => $this->meta,
			'free' => $this->freeSlots,
			'next_slot' => $this->nextSlot,
		]);
		file_put_contents($this->metaPath(), $payload, LOCK_EX);
	}

	private function loadGraph(): void
	{
		$path = $this->graphPath();
		if (!is_file($path)) {
			$this->graph = $this->newGraph();
			return;
		}
		$raw = file_get_contents($path);
		if ($raw === false) {
			return;
		}
		$decoded = GraphBinary::decode($raw);
		if ($decoded instanceof HnswGraph) {
			$this->graph = $decoded;
		}
	}

	private function saveGraph(): void
	{
		file_put_contents($this->graphPath(), GraphBinary::encode($this->graph), LOCK_EX);
	}

	/**
	 * 读取 id 对应 payload（JSON → array）
	 *
	 * @param string $id
	 * @return array|null
	 */
	private function readPayload(string $id): ?array
	{
		$row = $this->meta[$id] ?? null;
		if ($row === null) {
			return null;
		}
		$off = (int)($row['payload_off'] ?? -1);
		$len = (int)($row['payload_len'] ?? 0);
		if ($off < 0 || $len <= 0) {
			return null;
		}
		$raw = PayloadBin::read($this->payloadPath(), $off, $len);
		if ($raw === null || $raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}
}
