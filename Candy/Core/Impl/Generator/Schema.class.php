<?php
/***
 * 代码生成器库表元数据读取
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core\Impl\Generator
 */
declare(strict_types=1);

namespace Candy\Core\Impl\Generator;

use Candy\Extend\DB\SchemaMeta;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Schema
{
    /**
     * 列出数据表
     *
     * @param string|null $keyword 表名关键字
     * @return array<int, array{name:string,comment:string}>
     */
    public static function listTables(?string $keyword = null): array
    {
        return SchemaMeta::listTables($keyword);
    }

    /**
     * 表是否存在
     *
     * @param string $table
     * @return bool
     */
    public static function tableExists(string $table): bool
    {
        return SchemaMeta::tableExists($table);
    }

    /**
     * 读取表字段
     *
     * @param string $table
     * @return array<int, array<string, mixed>>
     */
    public static function listColumns(string $table): array
    {
        return SchemaMeta::listColumns($table);
    }

    /**
     * 获取建表 SQL（SHOW CREATE TABLE）
     *
     * @param string $table 物理表名
     * @return string 失败时返回空字符串
     */
    public static function getCreateTableSql(string $table): string
    {
        return SchemaMeta::getCreateTableSql($table);
    }
}
