<?php
/***
 * 向量统一路由（pgvector 优先 / PHP 嵌入式回退）
 *
 * 业务说明：探测 PostgreSQL+pgvector 可用则走 DB 旁路，否则回退 Data/vector；DEBUG 下注入提醒
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-19 12:31:02 $
 *
 * @package Candy\Extend\DB
 */

declare(strict_types=1);

namespace Candy\Extend\DB;

use Candy\Core\Debug;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class VectorRouter
{
	public const BACKEND_PG = 'pgvector';
	public const BACKEND_PHP = 'php';

	/** @var array<string, string> alias|force 缓存解析结果 */
	private static array $resolved = [];

	/**
	 * 解析后端：pgvector 优先，条件不符回退 php
	 *
	 * options:
	 * - force: pgvector|php 强制后端
	 * - table: pg 表名（默认 doc_embedding）
	 * - dimensions / path / alias 等同 Vector.inc
	 * - distance: cosine|l2|ip（仅 pg）
	 * - ensure_table: bool 首次写 pg 时自动 ensureDocTable（默认 true）
	 *
	 * @param string|null $alias
	 * @param array $options
	 * @return array{backend:string,reason:string}
	 */
	public static function resolve(?string $alias = null, array $options = []): array
	{
		$force = strtolower((string)($options['force'] ?? ''));
		$cacheKey = ($alias ?? '') . '|' . $force . '|' . (string)($options['table'] ?? '');

		if ($force === self::BACKEND_PHP || $force === 'embedded') {
			self::$resolved[$cacheKey] = self::BACKEND_PHP;
			return ['backend' => self::BACKEND_PHP, 'reason' => 'force=php'];
		}
		if ($force === self::BACKEND_PG || $force === 'pg') {
			$ok = self::probePgvector($alias);
			if ($ok['ok']) {
				self::$resolved[$cacheKey] = self::BACKEND_PG;
				return ['backend' => self::BACKEND_PG, 'reason' => 'force=pgvector'];
			}
			self::debugFallback('强制 pgvector 但不可用：' . $ok['reason'] . '，已回退 PHP 嵌入式向量');
			self::$resolved[$cacheKey] = self::BACKEND_PHP;
			return ['backend' => self::BACKEND_PHP, 'reason' => $ok['reason']];
		}

		if (isset(self::$resolved[$cacheKey])) {
			$b = self::$resolved[$cacheKey];
			return ['backend' => $b, 'reason' => 'cached'];
		}

		$inc = self::incConfig();
		$preferPg = (bool)($options['prefer_pgvector'] ?? $inc['prefer_pgvector'] ?? true);
		if (!$preferPg) {
			self::$resolved[$cacheKey] = self::BACKEND_PHP;
			self::debugFallback('配置 prefer_pgvector=false，使用 PHP 嵌入式向量');
			return ['backend' => self::BACKEND_PHP, 'reason' => 'prefer_pgvector=false'];
		}

		$ok = self::probePgvector($alias);
		if ($ok['ok']) {
			self::$resolved[$cacheKey] = self::BACKEND_PG;
			if (\isDebug()) {
				Debug::addMsg('<font color="green">[Vector] 使用 pgvector 后端</font>', 0);
			}
			return ['backend' => self::BACKEND_PG, 'reason' => 'pgvector_ok'];
		}

		self::debugFallback('pgvector 不可用（' . $ok['reason'] . '），已回退 PHP 嵌入式向量（Data/vector）');
		self::$resolved[$cacheKey] = self::BACKEND_PHP;
		return ['backend' => self::BACKEND_PHP, 'reason' => $ok['reason']];
	}

	/**
	 * 探测当前后端
	 *
	 * @param string|null $alias
	 * @param array $options
	 * @return array{code:int,message:string,data?:array}
	 */
	public static function ping(?string $alias = null, array $options = []): array
	{
		$r = self::resolve($alias, $options);
		if ($r['backend'] === self::BACKEND_PG) {
			$p = (new Pgvector($alias))->ping();
			$p['data'] = array_merge(is_array($p['data'] ?? null) ? $p['data'] : [], [
				'backend' => self::BACKEND_PG,
				'resolve_reason' => $r['reason'],
			]);
			return $p;
		}
		$p = (new Vector($alias, $options))->ping();
		$p['data'] = array_merge(is_array($p['data'] ?? null) ? $p['data'] : [], [
			'backend' => self::BACKEND_PHP,
			'resolve_reason' => $r['reason'],
		]);
		return $p;
	}

	/**
	 * 统一写入
	 *
	 * @param string $id doc_id
	 * @param array $vector
	 * @param array|string|null $payloadOrContent 数组=PHP payload / 字符串=pg content
	 * @param string|null $alias
	 * @param array $options
	 * @return array{code:int,message:string,data?:array,backend?:string}
	 */
	public static function upsert(
		string $id,
		array $vector,
		array|string|null $payloadOrContent = null,
		?string $alias = null,
		array $options = []
	): array {
		$r = self::resolve($alias, $options);
		if ($r['backend'] === self::BACKEND_PG) {
			$table = (string)($options['table'] ?? 'doc_embedding');
			$dims = (int)($options['dimensions'] ?? count($vector));
			$ensure = (bool)($options['ensure_table'] ?? true);
			$pg = new Pgvector($alias);
			if ($ensure) {
				$pg->ensureDocTable($table, max(1, $dims), true);
			}
			$content = null;
			if (is_string($payloadOrContent)) {
				$content = $payloadOrContent;
			} elseif (is_array($payloadOrContent)) {
				$content = isset($payloadOrContent['content'])
					? (string)$payloadOrContent['content']
					: json_encode($payloadOrContent, JSON_UNESCAPED_UNICODE);
			}
			$out = $pg->upsert($table, $id, $vector, $content);
			$out['backend'] = self::BACKEND_PG;
			return $out;
		}

		$payload = null;
		$text = null;
		if (is_array($payloadOrContent)) {
			$payload = $payloadOrContent;
			if (isset($payload['text']) && is_string($payload['text'])) {
				$text = $payload['text'];
			} elseif (isset($payload['content']) && is_string($payload['content'])) {
				$text = $payload['content'];
			}
		} elseif (is_string($payloadOrContent)) {
			$payload = ['content' => $payloadOrContent, 'text' => $payloadOrContent];
			$text = $payloadOrContent;
		}
		$phpOpts = $options;
		if (!isset($phpOpts['dimensions'])) {
			$phpOpts['dimensions'] = count($vector);
		}
		$out = (new Vector($alias, $phpOpts))->open($alias, $phpOpts)->upsert($id, $vector, $payload, $text);
		$out['backend'] = self::BACKEND_PHP;
		return $out;
	}

	/**
	 * 统一检索（归一化为 id/score/payload 列表）
	 *
	 * options: metric / filter / distance(pg)
	 *
	 * @param array $query
	 * @param int $topK
	 * @param string|null $alias
	 * @param array $options
	 * @return array{code:int,message:string,data?:list,backend?:string}
	 */
	public static function search(
		array $query,
		int $topK = 10,
		?string $alias = null,
		array $options = []
	): array {
		$r = self::resolve($alias, $options);
		if ($r['backend'] === self::BACKEND_PG) {
			$table = (string)($options['table'] ?? 'doc_embedding');
			$distance = (string)($options['distance'] ?? $options['metric'] ?? Pgvector::DIST_COSINE);
			$out = (new Pgvector($alias))->search($table, $query, $topK, $distance);
			$rows = [];
			$filter = is_array($options['filter'] ?? null) ? $options['filter'] : null;
			foreach ($out['data'] ?? [] as $row) {
				if (!is_array($row)) {
					continue;
				}
				$dist = (float)($row['distance'] ?? 0);
				$score = $distance === Pgvector::DIST_COSINE
					? (1.0 - $dist)
					: (1.0 / (1.0 + max(0.0, $dist)));
				$payload = null;
				if (isset($row['content']) && $row['content'] !== null && $row['content'] !== '') {
					$payload = ['content' => (string)$row['content'], 'text' => (string)$row['content']];
				}
				if ($filter !== null && !Vector\MetadataFilter::match($payload, $filter)) {
					continue;
				}
				$rows[] = [
					'id' => (string)($row['doc_id'] ?? $row['id'] ?? ''),
					'score' => $score,
					'payload' => $payload,
					'distance' => $dist,
				];
				if (count($rows) >= $topK) {
					break;
				}
			}
			return [
				'code' => (int)($out['code'] ?? 1),
				'message' => (string)($out['message'] ?? ''),
				'data' => $rows,
				'backend' => self::BACKEND_PG,
			];
		}

		$phpOpts = $options;
		if (!isset($phpOpts['dimensions'])) {
			$phpOpts['dimensions'] = count($query);
		}
		$metric = isset($options['metric']) ? (string)$options['metric'] : null;
		$filter = is_array($options['filter'] ?? null) ? $options['filter'] : null;
		$out = (new Vector($alias, $phpOpts))->open($alias, $phpOpts)->search($query, $topK, $metric, $filter);
		$out['backend'] = self::BACKEND_PHP;
		return $out;
	}

	/**
	 * BM25 全文检索（强制 PHP 嵌入式）
	 *
	 * @param string $query
	 * @param int $topK
	 * @param string|null $alias
	 * @param array $options
	 * @return array{code:int,message:string,data?:list,backend?:string}
	 */
	public static function textSearch(
		string $query,
		int $topK = 10,
		?string $alias = null,
		array $options = []
	): array {
		$phpOpts = array_merge($options, ['force' => self::BACKEND_PHP]);
		$filter = is_array($options['filter'] ?? null) ? $options['filter'] : null;
		$out = (new Vector($alias, $phpOpts))->open($alias, $phpOpts)->textSearch($query, $topK, $filter);
		$out['backend'] = self::BACKEND_PHP;
		return $out;
	}

	/**
	 * 混合检索（强制 PHP 嵌入式）
	 *
	 * @param array $vector
	 * @param string $text
	 * @param int $topK
	 * @param string|null $alias
	 * @param array $options
	 * @return array{code:int,message:string,data?:list,backend?:string}
	 */
	public static function hybridSearch(
		array $vector,
		string $text,
		int $topK = 10,
		?string $alias = null,
		array $options = []
	): array {
		$phpOpts = array_merge($options, ['force' => self::BACKEND_PHP]);
		if (!isset($phpOpts['dimensions'])) {
			$phpOpts['dimensions'] = count($vector);
		}
		$mode = (string)($options['hybrid_mode'] ?? 'rrf');
		$vw = (float)($options['vector_weight'] ?? 0.7);
		$tw = (float)($options['text_weight'] ?? 0.3);
		$metric = isset($options['metric']) ? (string)$options['metric'] : null;
		$filter = is_array($options['filter'] ?? null) ? $options['filter'] : null;
		$out = (new Vector($alias, $phpOpts))->open($alias, $phpOpts)->hybridSearch(
			$vector,
			$text,
			$topK,
			$mode,
			$vw,
			$tw,
			$metric,
			$filter
		);
		$out['backend'] = self::BACKEND_PHP;
		return $out;
	}

	/**
	 * 统一删除
	 *
	 * @param string $id
	 * @param string|null $alias
	 * @param array $options
	 * @return array{code:int,message:string,backend?:string}
	 */
	public static function delete(string $id, ?string $alias = null, array $options = []): array
	{
		$r = self::resolve($alias, $options);
		if ($r['backend'] === self::BACKEND_PG) {
			$table = (string)($options['table'] ?? 'doc_embedding');
			$out = (new Pgvector($alias))->delete($table, $id);
			$out['backend'] = self::BACKEND_PG;
			return $out;
		}
		$out = (new Vector($alias, $options))->open($alias, $options)->delete($id);
		$out['backend'] = self::BACKEND_PHP;
		return $out;
	}

	/**
	 * optimize：仅 PHP 后端有意义；pg 返回提示
	 *
	 * @param string|null $alias
	 * @param array $options
	 * @return array
	 */
	public static function optimize(?string $alias = null, array $options = []): array
	{
		$r = self::resolve($alias, $options);
		if ($r['backend'] === self::BACKEND_PG) {
			return [
				'code' => 0,
				'message' => 'pgvector 由数据库维护索引，无需 optimize',
				'backend' => self::BACKEND_PG,
			];
		}
		$out = (new Vector($alias, $options))->open($alias, $options)->optimize();
		$out['backend'] = self::BACKEND_PHP;
		return $out;
	}

	/**
	 * 清除解析缓存（测试或配置变更后）
	 *
	 * @return void
	 */
	public static function clearResolveCache(): void
	{
		self::$resolved = [];
	}

	/**
	 * @param string|null $alias
	 * @return array{ok:bool,reason:string}
	 */
	private static function probePgvector(?string $alias): array
	{
		if (!self::hasConfiguredConnectionAlias($alias)) {
			$aliasName = trim((string)($alias ?? ''));
			$aliasName = $aliasName !== '' ? $aliasName : 'default';
			return [
				'ok' => false,
				'reason' => "数据库配置别名 '{$aliasName}' 不存在，使用 PHP 嵌入式向量",
			];
		}

		try {
			$pg = new Pgvector($alias);
			$ping = $pg->ping();
			if (($ping['code'] ?? 1) !== 0) {
				return ['ok' => false, 'reason' => (string)($ping['message'] ?? 'ping 失败')];
			}
			if (empty($ping['data']['extension'])) {
				return ['ok' => false, 'reason' => '未启用 vector 扩展'];
			}
			return ['ok' => true, 'reason' => 'ok'];
		} catch (Throwable $e) {
			return ['ok' => false, 'reason' => $e->getMessage()];
		}
	}

	/**
	 * @param string $msg
	 * @return void
	 */
	private static function debugFallback(string $msg): void
	{
		if (\isDebug()) {
			Debug::addMsg('<font color="orange">[Vector] ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</font>', 0);
		}
	}

	/**
	 * 判断别名是否为已登记的数据库连接。
	 *
	 * 向量库逻辑别名（例如 rag、rag_answer）与 pgvector 数据库连接别名不是同一概念；
	 * 未登记的逻辑别名必须在探测数据库前回退到 PHP 嵌入式后端，避免触发数据库连接层的终止提示。
	 *
	 * @param string|null $alias 待检查的数据库连接别名
	 * @return bool 是否存在有效连接配置
	 */
	private static function hasConfiguredConnectionAlias(?string $alias): bool
	{
		$alias = trim((string)($alias ?? ''));
		$alias = $alias !== '' ? $alias : 'default';

		$globalList = G('DBLIST');
		if (is_array($globalList) && isset($globalList[$alias]) && is_array($globalList[$alias])) {
			return true;
		}

		$configList = C('DBLIST');
		if (is_array($configList) && isset($configList[$alias]) && is_array($configList[$alias])) {
			return true;
		}

		$config = loadConfig('Config');
		$dbList = is_array($config['DB'] ?? null) ? $config['DB'] : [];
		return isset($dbList[$alias]) && is_array($dbList[$alias]);
	}

	/**
	 * @return array
	 */
	private static function incConfig(): array
	{
		$inc = (defined('INCPATH') ? INCPATH : '') . 'Vector.inc.php';
		if ($inc !== 'Vector.inc.php' && is_file($inc)) {
			$loaded = include $inc;
			if (is_array($loaded)) {
				return $loaded;
			}
		}
		return [];
	}
}

