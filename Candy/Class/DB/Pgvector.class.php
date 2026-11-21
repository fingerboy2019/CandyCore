<?php
/***
 * PostgreSQL pgvector 旁路客户端
 *
 * 业务说明：在现有 Pdopgsql 连接上封装扩展探测、向量写入与相似度检索；不注册为默认 DRIVER
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-18 21:43:36 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use Candy\Extend\DB\Dialect\Pgsql as PgsqlDialect;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Pgvector
{
	/** 余弦距离 */
	public const DIST_COSINE = 'cosine';

	/** L2 欧氏距离 */
	public const DIST_L2 = 'l2';

	/** 内积（越大越相似，ORDER BY 用 DESC） */
	public const DIST_IP = 'ip';

	/** @var string 库隔离别名（空=默认连接） */
	protected string $alias = '';

	/**
	 * @param string|null $alias 隔离别名；null 用默认库（须为 PostgreSQL）
	 */
	public function __construct(?string $alias = null)
	{
		$this->alias = $alias !== null ? trim($alias) : '';
	}

	/**
	 * 获取 PostgreSQL 驱动
	 *
	 * @return object
	 */
	public function db(): object
	{
		$driver = $this->alias !== ''
			? D('_dbDriver', $this->alias, 'write', 'pdopgsql')
			: D('_dbDriver', [], 'write', 'pdopgsql');

		$short = strtolower(DBServer::resolveDriverClass(
			method_exists($driver, 'getResolvedDriver')
				? (string) $driver->getResolvedDriver()
				: 'pdopgsql'
		));
		if ($short !== 'pdopgsql' && $short !== 'pgsql') {
			// 第四参已强制 pdopgsql；若仍非预期则告警式继续
		}

		return $driver;
	}

	/**
	 * 是否已安装并启用 vector 扩展
	 *
	 * @return bool
	 */
	public function hasExtension(): bool
	{
		try {
			$row = $this->db()->query(
				"SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector' LIMIT 1",
				'find'
			);
			return is_array($row) && $row !== [];
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * 尝试 CREATE EXTENSION IF NOT EXISTS vector
	 *
	 * 无权限或未安装控制文件时返回失败，不抛给业务
	 *
	 * @return array{code:int,message:string}
	 */
	public function ensureExtension(): array
	{
		if ($this->hasExtension()) {
			return ['code' => 0, 'message' => 'vector extension already enabled'];
		}
		try {
			$ok = $this->db()->query('CREATE EXTENSION IF NOT EXISTS vector', 'other');
			if ($ok === false) {
				return [
					'code' => 1,
					'message' => 'CREATE EXTENSION vector 失败：请确认已安装 postgresql-*-pgvector 且当前用户有权限',
				];
			}
			if (!$this->hasExtension()) {
				return [
					'code' => 1,
					'message' => '扩展仍未启用：请检查 PostgreSQL 是否安装 pgvector 包',
				];
			}
			return ['code' => 0, 'message' => 'vector extension enabled'];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 连通与扩展探测
	 *
	 * @return array{code:int,message:string,data?:array{extension:bool,version?:string}}
	 */
	public function ping(): array
	{
		try {
			$verRow = $this->db()->query('SELECT version() AS v', 'find');
			$ver = is_array($verRow) ? (string) ($verRow['v'] ?? $verRow['V'] ?? '') : '';
			$has = $this->hasExtension();
			return [
				'code' => $has ? 0 : 1,
				'message' => $has ? 'ok' : 'pgvector 扩展未启用（可调用 ensureExtension 或安装 postgresql-*-pgvector）',
				'data' => [
					'extension' => $has,
					'version' => $ver,
				],
			];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 将 float 数组格式化为 pgvector 字面量
	 *
	 * @param array<int,float|int|string> $vector 向量
	 * @return string 如 [0.1,0.2,0.3]
	 */
	public static function formatVector(array $vector): string
	{
		$parts = [];
		foreach ($vector as $n) {
			if (!is_numeric($n)) {
				throw new \InvalidArgumentException('vector must contain only numbers');
			}
			$parts[] = (string) (0 + $n);
		}
		if ($parts === []) {
			throw new \InvalidArgumentException('vector must not be empty');
		}

		return '[' . implode(',', $parts) . ']';
	}

	/**
	 * 创建简易文档向量表（幂等）
	 *
	 * 默认表：{prefix}doc_embedding（id, doc_id, content, embedding, created_at）
	 *
	 * @param string $table 逻辑表名（不含前缀也可；将按 TABPREFIX 拼）
	 * @param int $dimensions 向量维度
	 * @param bool $withHnsw 是否创建余弦 HNSW 索引
	 * @return array{code:int,message:string,data?:array{table:string}}
	 */
	public function ensureDocTable(string $table = 'doc_embedding', int $dimensions = 1536, bool $withHnsw = true): array
	{
		$ext = $this->ensureExtension();
		if (($ext['code'] ?? 1) !== 0) {
			return $ext;
		}

		$dimensions = max(1, min(16000, $dimensions));
		$physical = $this->physicalTable($table);
		$qt = PgsqlDialect::quoteIdent($physical);

		$sql = 'CREATE TABLE IF NOT EXISTS ' . $qt . ' ('
			. 'id BIGSERIAL PRIMARY KEY,'
			. 'doc_id VARCHAR(64) NOT NULL,'
			. 'content TEXT NULL,'
			. 'embedding vector(' . $dimensions . ') NOT NULL,'
			. 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
			. 'UNIQUE (doc_id)'
			. ')';

		try {
			if ($this->db()->query($sql, 'other') === false) {
				return ['code' => 1, 'message' => '创建向量表失败'];
			}
			if ($withHnsw) {
				$idx = $this->ensureHnswIndex($physical, 'embedding', self::DIST_COSINE);
				if (($idx['code'] ?? 1) !== 0) {
					return [
						'code' => 0,
						'message' => '表已就绪，但 HNSW 索引创建失败：' . ($idx['message'] ?? ''),
						'data' => ['table' => $physical],
					];
				}
			}
			return ['code' => 0, 'message' => 'ok', 'data' => ['table' => $physical]];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 创建 HNSW 索引（幂等：若已存在则跳过）
	 *
	 * @param string $table 物理表名
	 * @param string $column 向量列
	 * @param string $distance cosine|l2|ip
	 * @return array{code:int,message:string}
	 */
	public function ensureHnswIndex(string $table, string $column = 'embedding', string $distance = self::DIST_COSINE): array
	{
		if (!$this->hasExtension()) {
			return ['code' => 1, 'message' => 'vector 扩展未启用'];
		}
		$table = $this->safeIdent($table);
		$column = $this->safeIdent($column);
		$ops = match ($distance) {
			self::DIST_L2 => 'vector_l2_ops',
			self::DIST_IP => 'vector_ip_ops',
			default => 'vector_cosine_ops',
		};
		$indexName = 'idx_' . $table . '_' . $column . '_hnsw';
		$qi = PgsqlDialect::quoteIdent($indexName);
		$qt = PgsqlDialect::quoteIdent($table);
		$qc = PgsqlDialect::quoteIdent($column);

		$sql = 'CREATE INDEX IF NOT EXISTS ' . $qi
			. ' ON ' . $qt . ' USING hnsw (' . $qc . ' ' . $ops . ')';

		try {
			if ($this->db()->query($sql, 'other') === false) {
				return ['code' => 1, 'message' => '创建 HNSW 索引失败'];
			}
			return ['code' => 0, 'message' => 'ok'];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 按 doc_id 写入或更新向量行
	 *
	 * @param string $table 逻辑/物理表名
	 * @param string $docId 业务文档 ID
	 * @param array<int,float|int|string> $vector 向量
	 * @param string|null $content 可选正文摘要
	 * @param string $vectorColumn 向量列名
	 * @return array{code:int,message:string}
	 */
	public function upsert(
		string $table,
		string $docId,
		array $vector,
		?string $content = null,
		string $vectorColumn = 'embedding'
	): array {
		if (!$this->hasExtension()) {
			return ['code' => 1, 'message' => 'vector 扩展未启用'];
		}
		$docId = trim($docId);
		if ($docId === '') {
			return ['code' => 1, 'message' => 'doc_id 不能为空'];
		}
		if ($vector === []) {
			return ['code' => 1, 'message' => 'vector 不能为空'];
		}
		if (count($vector) > 16000) {
			return ['code' => 1, 'message' => 'vector 维度超过 16000'];
		}

		try {
			$literal = self::formatVector($vector);
		} catch (\InvalidArgumentException $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}

		$physical = $this->physicalTable($table);
		$qt = PgsqlDialect::quoteIdent($physical);
		$qc = PgsqlDialect::quoteIdent($this->safeIdent($vectorColumn));

		$sql = 'INSERT INTO ' . $qt . ' (doc_id, content, ' . $qc . ')'
			. ' VALUES (?, ?, ?::vector)'
			. ' ON CONFLICT (doc_id) DO UPDATE SET'
			. ' content = EXCLUDED.content,'
			. ' ' . $qc . ' = EXCLUDED.' . $qc;

		try {
			$ok = $this->db()->query($sql, 'insert', [$docId, $content, $literal]);
			if ($ok === false) {
				return ['code' => 1, 'message' => 'upsert 失败'];
			}
			return ['code' => 0, 'message' => 'ok'];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 相似度检索
	 *
	 * @param string $table 表名
	 * @param array<int,float|int|string> $query 查询向量
	 * @param int $limit TopK
	 * @param string $distance cosine|l2|ip
	 * @param string $vectorColumn 向量列
	 * @param string|null $extraWhere 额外 WHERE（禁止分号；仅受信内部调用）
	 * @param array<int,mixed> $extraBind 额外绑定
	 * @return array{code:int,message:string,data?:array<int,array<string,mixed>>}
	 */
	public function search(
		string $table,
		array $query,
		int $limit = 10,
		string $distance = self::DIST_COSINE,
		string $vectorColumn = 'embedding',
		?string $extraWhere = null,
		array $extraBind = []
	): array {
		if (!$this->hasExtension()) {
			return ['code' => 1, 'message' => 'vector 扩展未启用', 'data' => []];
		}
		$limit = max(1, min(200, $limit));
		if ($query === []) {
			return ['code' => 1, 'message' => 'query 不能为空', 'data' => []];
		}
		if (count($query) > 16000) {
			return ['code' => 1, 'message' => 'query 维度超过 16000', 'data' => []];
		}
		try {
			$literal = self::formatVector($query);
		} catch (\InvalidArgumentException $e) {
			return ['code' => 1, 'message' => $e->getMessage(), 'data' => []];
		}

		$op = match ($distance) {
			self::DIST_L2 => '<->',
			self::DIST_IP => '<#>',
			default => '<=>',
		};
		$orderDir = $distance === self::DIST_IP ? 'DESC' : 'ASC';

		$physical = $this->physicalTable($table);
		$qt = PgsqlDialect::quoteIdent($physical);
		$qc = PgsqlDialect::quoteIdent($this->safeIdent($vectorColumn));

		$whereSql = '';
		$bind = [];
		if ($extraWhere !== null && trim($extraWhere) !== '') {
			if (str_contains($extraWhere, ';')) {
				return ['code' => 1, 'message' => 'extraWhere 不允许分号', 'data' => []];
			}
			$whereSql = ' WHERE (' . $extraWhere . ')';
		}

		// SELECT 与 ORDER BY 各引用一次查询向量占位符
		$distExpr = $qc . ' ' . $op . ' ?::vector';
		$sql = 'SELECT *, (' . $distExpr . ') AS distance FROM ' . $qt
			. $whereSql
			. ' ORDER BY (' . $distExpr . ') ' . $orderDir
			. ' LIMIT ' . $limit;

		$bind[] = $literal;
		if ($extraWhere !== null && trim($extraWhere) !== '') {
			foreach ($extraBind as $b) {
				$bind[] = $b;
			}
		}
		$bind[] = $literal;

		try {
			$rows = $this->db()->query($sql, 'select', $bind);
			if (!is_array($rows)) {
				$rows = [];
			}
			return ['code' => 0, 'message' => 'ok', 'data' => $rows];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage(), 'data' => []];
		}
	}

	/**
	 * 按 doc_id 删除向量行
	 *
	 * @param string $table 表名
	 * @param string $docId 文档 ID
	 * @return array{code:int,message:string}
	 */
	public function delete(string $table, string $docId): array
	{
		if (!$this->hasExtension()) {
			return ['code' => 1, 'message' => 'vector 扩展未启用'];
		}
		$docId = trim($docId);
		if ($docId === '') {
			return ['code' => 1, 'message' => 'doc_id 不能为空'];
		}
		$physical = $this->physicalTable($table);
		$qt = PgsqlDialect::quoteIdent($physical);
		try {
			$ok = $this->db()->query('DELETE FROM ' . $qt . ' WHERE doc_id = ?', 'delete', [$docId]);
			if ($ok === false) {
				return ['code' => 1, 'message' => 'delete 失败'];
			}
			return ['code' => 0, 'message' => 'ok'];
		} catch (Throwable $e) {
			return ['code' => 1, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 拼物理表名（带 TABPREFIX）
	 *
	 * @param string $table 逻辑或已含前缀的表名
	 * @return string
	 */
	public function physicalTable(string $table): string
	{
		$table = $this->safeIdent($table);
		$prefix = (string) (C('TABPREFIX') ?? G('TABPREFIX') ?? '');
		if ($prefix !== '' && str_starts_with($table, $prefix)) {
			return $table;
		}
		return $prefix . $table;
	}

	/**
	 * @param string $ident 标识符
	 * @return string
	 */
	private function safeIdent(string $ident): string
	{
		$ident = trim($ident);
		if ($ident === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $ident)) {
			throw new \InvalidArgumentException('invalid identifier: ' . $ident);
		}
		return $ident;
	}
}

