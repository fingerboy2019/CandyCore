<?php
/**
 * 数据库迁移基类
 *
 * 迁移文件通过继承本类实现 up/down，使用 execute() 执行 SQL。
 *
 * @package Candy\Extend\Migration
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-15 07:15:00
 */

declare(strict_types=1);

namespace Candy\Extend\Migration;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class Migration {

    /**
     * 数据库连接配置别名或配置数组
     *
     * @var string|array|null
     */
    protected string|array|null $connection = null;

    /** @var object|null 管理器注入的同一写连接，保证事务不会因重复 D() 失效 */
    private ?object $database = null;

    /**
     * 设置数据库连接
     *
     * @param string|array|null $connection 配置别名或数组
     * @return void
     */
    public function setConnection(string|array|null $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * 注入迁移管理器当前使用的数据库连接
     *
     * @param object $database 数据库驱动
     * @return void
     */
    public function setDatabase(object $database): void
    {
        $this->database = $database;
    }

    /**
     * 执行迁移（向前）
     *
     * @return void
     */
    abstract public function up(): void;

    /**
     * 回滚迁移（向后）
     *
     * @return void
     */
    abstract public function down(): void;

    /**
     * 获取数据库驱动实例
     *
     * @return object
     */
    protected function db(): object
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $this->bootDatabaseConfig();
        return D('_dbDriver', $this->connection ?? [])->useWrite();
    }

    /**
     * CLI 环境下加载数据库配置
     *
     * @return void
     * @access private
     */
    private function bootDatabaseConfig(): void
    {
        if (C('HOST') !== null && C('HOST') !== '') {
            return;
        }

        \Candy\Core\Bootstrap::loadDbConfig();
        if (C('HOST') === null || C('HOST') === '') {
            throw new \RuntimeException('请先在 Source/Inc/Config.inc.php 配置数据库');
        }
    }

    /**
     * 获取当前连接的表前缀
     *
     * @return string
     */
    protected function prefix(): string
    {
        $config = loadConfig('Config');
        if (is_array($this->connection)) {
            return (string)($this->connection['TABPREFIX'] ?? $config['TABPREFIX'] ?? '');
        }
        $alias = is_string($this->connection) && $this->connection !== ''
            ? $this->connection
            : 'default';
        $aliasConfig = $config['DB'][$alias] ?? [];
        if (is_array($aliasConfig) && $aliasConfig !== []) {
            return \Candy\Extend\DB\DBServer::resolveTablePrefixFromAliasConfig($aliasConfig);
        }
        return (string)($config['TABPREFIX'] ?? '');
    }

    /**
     * 执行单条 SQL
     *
     * @param string $sql SQL 语句
     * @return mixed
     */
    protected function execute(string $sql): mixed
    {
        return $this->db()->query($sql, 'other');
    }
}

