<?php
/***
 * 代码生成器门面
 *
 * 框架内置 default 模板；扩展风格由 Source/Generator 与 Generator.inc.php 注册。
 * 默认推荐 admin-layui（Source/Generator/admin-layui/）。
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-07-14 20:35:33 $
 *
 * @package Candy\Core
 */
declare(strict_types=1);

namespace Candy\Core;

use Candy\Core\Impl\Generator\Crud as CrudGenerator;
use Candy\Core\Impl\Generator\GenerateResult;
use Candy\Core\Impl\Generator\Schema;
use Candy\Core\Impl\Generator\Scaffold;
use Candy\Core\Trait\Generator\Naming;
use Candy\Core\Trait\Generator\PathLayout;
use Candy\Core\Trait\Generator\TemplateResolver;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Generator
{
    use Naming;
    use PathLayout;
    use TemplateResolver;

    /**
     * 创建应用骨架
     *
     * @param string $app 应用名
     * @param string $type 空|addon|plug
     * @return GenerateResult
     */
    public static function scaffold(string $app, string $type = ''): GenerateResult
    {
        return Scaffold::run($app, $type);
    }

    /**
     * 按表生成 CRUD 相关代码
     *
     * @param array<string, mixed> $options 见 Impl\Generator\Crud::run
     * @return GenerateResult
     */
    public static function crud(array $options): GenerateResult
    {
        return CrudGenerator::run($options);
    }

    /**
     * 列出数据表
     *
     * @param string|null $keyword
     * @return array<int, array{name:string,comment:string}>
     */
    public static function tables(?string $keyword = null): array
    {
        return Schema::listTables($keyword);
    }

    /**
     * 列出可用模板风格
     *
     * default + Source/Generator 扩展 + Generator.inc.php 注册项
     *
     * @return array<string, array<string, string>>
     */
    public static function styles(): array
    {
        return self::listGeneratorStyles();
    }

    /**
     * 列出已安装的应用目录名
     *
     * @return array<int, string>
     */
    public static function applications(): array
    {
        return self::listApplications();
    }

    /**
     * 由表名推导 Model/Control 类名（自动剥离 TABPREFIX）
     *
     * @param string $table 物理表名，如 sa_article
     * @return string 如 Article
     */
    public static function suggestClassName(string $table): string
    {
        return self::tableToClassName($table);
    }

    /**
     * 当前配置的数据库表前缀
     *
     * @return string
     */
    public static function tablePrefix(): string
    {
        return self::resolveTablePrefix();
    }

    /**
     * 导出数据表建表 SQL（SHOW CREATE TABLE）
     *
     * @param string $table 物理表名
     * @return string 失败时返回空字符串
     */
    public static function exportCreateTableSql(string $table): string
    {
        return Schema::getCreateTableSql($table);
    }
}
