<?php
/**
 * PostgreSQL 全文检索引擎驱动
 *
 * 使用 tsvector + tsquery 实现全文检索，pg_trgm 加速模糊匹配。
 *
 * @package Candy\Extend\Search\Engine
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-30 17:35:00
 */
declare(strict_types=1);

namespace Candy\Extend\Search\Engine;

use Candy\Extend\Search\SearchEngine;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class PgsqlFulltext implements SearchEngine
{
    private string $prefix;

    /** @var array<string,array{fields:list<string>,table:string,pri:string,weight:array<string,string>}> */
    private array $indexes = [];

    /** @var string 分词配置（'simple' 适合英文和代码，'pg_catalog.english' 英语分词） */
    private string $config = 'simple';

    public function __construct(string $config = 'simple')
    {
        $this->prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
        $this->config = $config;
    }

    public function registerIndex(string $name, string $table, array $fields, string $pri = 'id', array $weight = []): void
    {
        $this->indexes[$name] = [
            'table' => $this->prefix . $table,
            'fields' => $fields,
            'pri' => $pri,
            'weight' => $weight,
        ];
    }

    public function index(string $index, string $id, array $body): bool
    {
        // 数据已在表中，PostgreSQL 通过函数索引进行全文检索
        return true;
    }

    public function search(string $index, string $query, array $options = []): array
    {
        $cfg = $this->indexes[$index] ?? null;
        if ($cfg === null) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => 20, 'pages' => 1];
        }

        $page = max(1, (int)($options['page'] ?? 1));
        $limit = max(1, min(100, (int)($options['limit'] ?? 20)));
        $table = $cfg['table'];
        $pri = $cfg['pri'];

        $safeQuery = $this->sanitizeQuery($query);
        if ($safeQuery === '') {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'pages' => 1];
        }

        $db = D('_dbDriver');

        // 构造 tsquery：plainto_tsquery 适合用户输入
        $tsQuery = "plainto_tsquery('{$this->config}', ?)";
        $params = [$safeQuery];

        // 构造 tsvector：多字段加权
        $vecParts = [];
        foreach ($cfg['fields'] as $i => $field) {
            $w = $cfg['weight'][$field] ?? 'D';
            $vecParts[] = "setweight(to_tsvector('{$this->config}', COALESCE({$this->quoteIdent($field)},'')), '{$w}')";
        }
        $tsVec = implode(' || ', $vecParts);

        $where = "{$tsVec} @@ {$tsQuery}";

        // 可选 ILIKE 补充（pg_trgm 加速）
        $likeWhere = '';
        if (mb_strlen($safeQuery) > 1) {
            $likeClauses = [];
            foreach ($cfg['fields'] as $field) {
                $likeClauses[] = $this->quoteIdent($field) . ' ILIKE ?';
                $params[] = '%' . $safeQuery . '%';
            }
            $likeWhere = ' OR (' . implode(' OR ', $likeClauses) . ')';
        }

        $finalWhere = $where . ($likeWhere !== '' ? " OR ({$likeWhere})" : '');

        // 可选过滤
        $filterClauses = '';
        if (!empty($options['filter'])) {
            foreach ($options['filter'] as $field => $value) {
                $filterClauses .= ' AND ' . $this->quoteIdent($field) . '=?';
                $params[] = (string)$value;
            }
        }

        // Count
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM {$table} WHERE ({$finalWhere}){$filterClauses}", 'total', $params
        );

        if ($total === 0) {
            // 降级：仅 ILIKE
            $likeParams = array_slice($params, 1); // 去掉 tsquery 参数
            if ($likeWhere !== '') {
                $total = (int)$db->query(
                    "SELECT COUNT(*) FROM {$table} WHERE ({$likeWhere}){$filterClauses}", 'total', $likeParams
                );
                $finalWhere = $likeWhere;
            }
        }

        $pages = max(1, (int)ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $rank = "ts_rank({$tsVec}, {$tsQuery})";
        $selectFields = '*';
        $highlight = '';

        if (!empty($options['highlight'])) {
            $headlineParts = [];
            foreach ($cfg['fields'] as $field) {
                $headlineParts[] = "ts_headline('{$this->config}', {$this->quoteIdent($field)}, {$tsQuery}, 'StartSel=<mark class=\"cm-search-hl\">,StopSel=</mark>,MaxWords=50,MinWords=20') AS {$this->quoteIdent($field)}_hl";
            }
            $highlight = ', ' . implode(', ', $headlineParts) . ", {$rank} AS _score";
            $selectFields = "{$this->quoteIdent($pri)}, {$this->quoteIdent(implode(',', $cfg['fields']))}";
        }

        $rows = $db->query(
            "SELECT {$selectFields}{$highlight} FROM {$table} WHERE ({$finalWhere}){$filterClauses} ORDER BY {$rank} DESC, {$this->quoteIdent($pri)} DESC LIMIT {$limit} OFFSET {$offset}",
            'select', $params
        );

        return [
            'rows' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
        ];
    }

    public function delete(string $index, string $id): bool
    {
        return true;
    }

    public function flush(string $index): bool
    {
        return true;
    }

    private function sanitizeQuery(string $q): string
    {
        $q = trim(mb_substr($q, 0, 100));
        return $q;
    }

    private function quoteIdent(string $field): string
    {
        return '"' . str_replace('"', '""', $field) . '"';
    }
}
