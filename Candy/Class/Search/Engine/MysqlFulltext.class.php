<?php
/**
 * MySQL FULLTEXT 搜索引擎驱动
 *
 * 使用 MySQL 内置 ngram 全文索引，支持中文分词。
 *
 * @package Candy\Extend\Search\Engine
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-30 17:32:00
 */
declare(strict_types=1);

namespace Candy\Extend\Search\Engine;

use Candy\Extend\Search\SearchEngine;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class MysqlFulltext implements SearchEngine
{
    private string $prefix;

    /** @var array<string,array{fields:list<string>,table:string,pri:string}> */
    private array $indexes = [];

    public function __construct()
    {
        $this->prefix = (string)(C('TABPREFIX') ?? G('TABPREFIX') ?? '');
    }

    /**
     * 注册索引
     *
     * @param string $name 索引名
     * @param string $table 物理表名（无前缀）
     * @param list<string> $fields 可搜索字段
     * @param string $pri 主键字段名
     * @return void
     */
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
        // MySQL FULLTEXT 不需要单独索引操作，数据已存在表中
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
        $matchFields = '`' . implode('`,`', $cfg['fields']) . '`';
        $table = $cfg['table'];
        $pri = $cfg['pri'];
        $mode = (string)($options['mode'] ?? 'NATURAL LANGUAGE MODE');

        // 安全校验搜索词
        $safeQuery = $this->sanitizeQuery($query);
        if ($safeQuery === '') {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'pages' => 1];
        }

        $whereMatch = "MATCH({$matchFields}) AGAINST(? IN {$mode})";
        $params = [$safeQuery];

        $filterClauses = '';
        if (!empty($options['filter'])) {
            foreach ($options['filter'] as $field => $value) {
                $filterClauses .= ' AND ' . $this->quoteIdent($field) . '=?';
                $params[] = (string)$value;
            }
        }

        $db = D('_dbDriver');
        $total = (int)$db->query(
            "SELECT COUNT(*) FROM {$table} WHERE {$whereMatch}{$filterClauses}", 'total', $params
        );
        $pages = max(1, (int)ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;

        $selectFields = '*';
        $highlight = '';
        if (!empty($options['highlight'])) {
            $hlOpen = '<mark class="cm-search-hl">';
            $hlClose = '</mark>';
            $highlight = ", {$whereMatch} AS _score";
        }

        $rows = $db->query(
            "SELECT {$selectFields}{$highlight} FROM {$table} WHERE {$whereMatch}{$filterClauses} ORDER BY {$whereMatch} DESC LIMIT {$offset},{$limit}",
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
        return true; // 数据在表中，删除由业务层处理
    }

    public function flush(string $index): bool
    {
        return true;
    }

    /**
     * 清理搜索词中的危险字符
     */
    private function sanitizeQuery(string $q): string
    {
        $q = trim(mb_substr($q, 0, 100));
        if ($q === '') return '';
        // 移除可能破坏语法的特殊字符
        $q = preg_replace('/[<>()~*@"]+/', ' ', $q);
        $q = preg_replace('/\s+/', ' ', $q);
        return trim($q);
    }

    private function quoteIdent(string $field): string
    {
        return '`' . str_replace('`', '``', $field) . '`';
    }
}
