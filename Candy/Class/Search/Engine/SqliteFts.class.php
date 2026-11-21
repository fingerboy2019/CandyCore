<?php
/**
 * SQLite FTS5 搜索引擎驱动
 *
 * 使用 SQLite FTS5 虚拟表实现全文检索。
 *
 * @package Candy\Extend\Search\Engine
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-30 17:38:00
 */
declare(strict_types=1);

namespace Candy\Extend\Search\Engine;

use Candy\Extend\Search\SearchEngine;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class SqliteFts implements SearchEngine
{
    private string $prefix;

    /** @var array<string,array{fts_table:string,content_table:string,fields:list<string>,pri:string}> */
    private array $indexes = [];

    public function __construct()
    {
        $this->prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
    }

    /**
     * 注册索引
     *
     * 首次注册时会自动创建 FTS5 虚拟表。
     */
    public function registerIndex(string $name, string $table, array $fields, string $pri = 'id'): void
    {
        $p = $this->prefix;
        $ftsTable = $p . $name . '_fts';
        $contentTable = $p . $table;

        $this->indexes[$name] = [
            'fts_table' => $ftsTable,
            'content_table' => $contentTable,
            'fields' => $fields,
            'pri' => $pri,
        ];

        // 自动创建 FTS5 表（幂等）
        $db = D('_dbDriver');
        try {
            $fieldList = implode(', ', $fields);
            $db->query(
                "CREATE VIRTUAL TABLE IF NOT EXISTS {$ftsTable} USING fts5({$fieldList}, content='{$contentTable}', content_rowid='{$pri}')",
                'update'
            );
            // 首次建表后同步数据
            $db->query("INSERT INTO {$ftsTable}({$ftsTable}) VALUES('rebuild')", 'update');
        } catch (\Throwable) {
        }
    }

    public function index(string $index, string $id, array $body): bool
    {
        $cfg = $this->indexes[$index] ?? null;
        if ($cfg === null) return false;
        try {
            $db = D('_dbDriver');
            $db->query(
                "INSERT INTO {$cfg['fts_table']}(rowid, {$cfg['fields'][0]}) VALUES(?, ?) ON CONFLICT(rowid) DO UPDATE SET {$cfg['fields'][0]}=excluded.{$cfg['fields'][0]}",
                'update',
                [(int)$id, implode(' ', $body)]
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function search(string $index, string $query, array $options = []): array
    {
        $cfg = $this->indexes[$index] ?? null;
        if ($cfg === null) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => 20, 'pages' => 1];
        }

        $page = max(1, (int)($options['page'] ?? 1));
        $limit = max(1, min(100, (int)($options['limit'] ?? 20)));
        $safeQuery = $this->sanitizeQuery($query);
        if ($safeQuery === '') {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'pages' => 1];
        }

        $db = D('_dbDriver');
        $ftsTable = $cfg['fts_table'];
        $contentTable = $cfg['content_table'];
        $fields = implode(', ', $cfg['fields']);

        // FTS5 查询语法：双引号精确短语
        $quoted = '"' . $safeQuery . '"';

        // Count
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM {$ftsTable} WHERE {$ftsTable} MATCH '{$quoted}'", 'total'
        );

        if ($total === 0) {
            // 降级：前缀匹配
            $total = (int)$db->query(
                "SELECT COUNT(*) FROM {$ftsTable} WHERE {$ftsTable} MATCH '{$safeQuery}*'", 'total'
            );
            $quoted = $safeQuery . '*';
        }

        $pages = max(1, (int)ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $snippet = '';
        if (!empty($options['highlight'])) {
            $snippet = ", snippet({$ftsTable}, 1, '<mark class=\"cm-search-hl\">', '</mark>', '...', 32) AS _snippet";
        }

        $rows = $db->query(
            "SELECT c.*{$snippet} FROM {$ftsTable} f JOIN {$contentTable} c ON c.rowid = f.rowid WHERE f.{$ftsTable} MATCH '{$quoted}' ORDER BY rank LIMIT {$limit} OFFSET {$offset}",
            'select'
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
        $cfg = $this->indexes[$index] ?? null;
        if ($cfg === null) return false;
        try {
            D('_dbDriver')->query(
                "DELETE FROM {$cfg['fts_table']} WHERE rowid = ?", 'delete', [(int)$id]
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function flush(string $index): bool
    {
        $cfg = $this->indexes[$index] ?? null;
        if ($cfg === null) return false;
        try {
            D('_dbDriver')->query("INSERT INTO {$cfg['fts_table']}({$cfg['fts_table']}) VALUES('rebuild')", 'update');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function sanitizeQuery(string $q): string
    {
        $q = trim(mb_substr($q, 0, 100));
        // FTS5 特殊字符需要转义
        $q = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $q);
        $q = preg_replace('/\s+/', ' ', $q);
        return trim($q);
    }
}
