<?php
/**
 * 全文检索引擎接口
 *
 * 定义统一搜索契约，所有驱动（MySQL FULLTEXT / PostgreSQL tsvector / SQLite FTS / LIKE）必须实现。
 *
 * @package Candy\Extend\Search
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-30 17:30:00
 */
declare(strict_types=1);

namespace Candy\Extend\Search;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

interface SearchEngine
{
    /**
     * 索引一篇文档
     *
     * @param string $index 索引名（如 community_question）
     * @param string $id 文档 ID
     * @param array $body 文档内容（含所有可搜索字段和存储字段）
     * @return bool
     */
    public function index(string $index, string $id, array $body): bool;

    /**
     * 搜索
     *
     * @param string $index 索引名
     * @param string $query 搜索关键词
     * @param array $options page/limit/fields/highlight/filter
     * @return array{rows:list<array>,total:int,page:int,limit:int,pages:int,hits?:array}
     */
    public function search(string $index, string $query, array $options = []): array;

    /**
     * 删除文档
     *
     * @param string $index 索引名
     * @param string $id 文档 ID
     * @return bool
     */
    public function delete(string $index, string $id): bool;

    /**
     * 删除全部文档（重建索引用）
     *
     * @param string $index 索引名
     * @return bool
     */
    public function flush(string $index): bool;
}

