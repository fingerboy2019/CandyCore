<?php
/**
 * LIKE 回退搜索引擎驱动
 *
 * 使用标准 SQL LIKE 查询，兼容所有数据库。作为无专用索引时的默认回退。
 *
 * @package Candy\Extend\Search\Engine
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-30 17:40:00
 */
declare(strict_types=1);

namespace Candy\Extend\Search\Engine;

use Candy\Extend\Search\SearchEngine;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class BasicLike implements SearchEngine
{
    private string $prefix;

    /** @var array<string,array{fields:list<string>,table:string,pri:string}> */
    private array $indexes = [];

    public function __construct()
    {
        $this->prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
    }

    public function registerIndex(string $name, string $table, array $fields, string $pri = 'id'): void
    {
        $this->indexes[$name] = [
            'table' => $this->prefix . $table,
            'fields' => $fields,
            'pri' => $pri,
        ];
    }

    public function index(string $index, string $id, array $body): bool
    {
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
        $safeQuery = $this->sanitizeQuery($query);
        if ($safeQuery === '') {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'pages' => 1];
        }

        $db = D('_dbDriver');
        $like = '%' . $safeQuery . '%';
        $where = [];
        $params = [];
        foreach ($cfg['fields'] as $field) {
            $where[] = $this->quoteIdent($field) . ' LIKE ?';
            $params[] = $like;
        }
        $whereStr = '(' . implode(' OR ', $where) . ')';

        $filterClauses = '';
        if (!empty($options['filter'])) {
            foreach ($options['filter'] as $field => $value) {
                $filterClauses .= ' AND ' . $this->quoteIdent($field) . '=?';
                $params[] = (string)$value;
            }
        }

        $table = $cfg['table'];
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM {$table} WHERE {$whereStr}{$filterClauses}", 'total', $params
        );

        $pages = max(1, (int)ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $limitSql = $this->limitSql($offset, $limit);
        $rows = $db->query(
            "SELECT * FROM {$table} WHERE {$whereStr}{$filterClauses} ORDER BY {$cfg['pri']} DESC {$limitSql}",
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
        if ($q === '') return '';
        // 转义 LIKE 通配符
        $q = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        return $q;
    }

    private function limitSql(int $offset, int $limit): string
    {
        $driver = \Candy\Extend\DB\DBServer::resolveDriverClass((string)(G('DRIVER') ?: C('DRIVER') ?: 'pdomysql'));
        $driver = strtolower($driver);
        if (str_contains($driver, 'pgsql') || str_contains($driver, 'sqlite')) {
            return "LIMIT {$limit} OFFSET {$offset}";
        }
        if (str_contains($driver, 'sqlsrv')) {
            return "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
        return "LIMIT {$offset},{$limit}";
    }

    private function quoteIdent(string $field): string
    {
        $driver = \Candy\Extend\DB\DBServer::resolveDriverClass((string)(G('DRIVER') ?: C('DRIVER') ?: 'pdomysql'));
        $driver = strtolower($driver);
        if (str_contains($driver, 'pgsql') || str_contains($driver, 'sqlite')) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return '`' . str_replace('`', '``', $field) . '`';
    }
}
