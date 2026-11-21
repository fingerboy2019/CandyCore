<?php
/**
 * 全文检索服务门面
 *
 * 统一入口，根据配置自动选择搜索引擎驱动。
 *
 * @package Candy\Extend\Search
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-30 17:42:00
 */
declare(strict_types=1);

namespace Candy\Extend\Search;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class SearchService
{
    private static ?SearchEngine $engine = null;
    private static ?string $engineType = null;

    /**
     * 获取搜索引擎实例
     */
    public static function engine(): SearchEngine
    {
        $config = function_exists('readInc') ? readInc('CommunityConfig') : [];
        $engineType = is_array($config) ? (string)($config['search_engine'] ?? 'basic_like') : 'basic_like';

        if (self::$engine !== null && self::$engineType === $engineType) {
            return self::$engine;
        }

        self::$engineType = $engineType;
        self::$engine = self::createEngine($engineType);
        return self::$engine;
    }

    /**
     * 创建搜索引擎实例
     */
    private static function createEngine(string $type): SearchEngine
    {
        return match ($type) {
            'mysql_fulltext' => new Engine\MysqlFulltext(),
            'pgsql_fulltext' => new Engine\PgsqlFulltext(),
            'sqlite_fts' => new Engine\SqliteFts(),
            default => new Engine\BasicLike(),
        };
    }

    /**
     * 便捷搜索
     *
     * @param string $index 索引名
     * @param string $query 搜索词
     * @param array $options 选项
     * @return array
     */
    public static function search(string $index, string $query, array $options = []): array
    {
        return self::engine()->search($index, $query, $options);
    }

    /**
     * 便捷索引
     */
    public static function index(string $index, string $id, array $body): bool
    {
        return self::engine()->index($index, $id, $body);
    }

    /**
     * 便捷删除索引
     */
    public static function delete(string $index, string $id): bool
    {
        return self::engine()->delete($index, $id);
    }

    /**
     * 注册索引
     */
    public static function registerIndex(string $name, string $table, array $fields, string $pri = 'id'): void
    {
        $engine = self::engine();
        if (method_exists($engine, 'registerIndex')) {
            $engine->registerIndex($name, $table, $fields, $pri);
        }
    }

    /**
     * 清除引擎实例（配置变更后调用）
     */
    public static function clearCache(): void
    {
        self::$engine = null;
        self::$engineType = null;
    }
}

