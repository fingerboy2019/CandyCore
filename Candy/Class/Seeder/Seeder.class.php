<?php
/**
 * 数据填充基类
 *
 * 填充文件通过继承本类实现 run()，使用 db() 拿到与管理器同一个写连接，
 * 保证 call() 嵌套调用与事务不会被重复 D() 打断。
 *
 * 与 Migration 的差异：
 * - 只有 run()，没有 down()（数据种子通常不做回滚）；
 * - 支持 call() 串起多个 Seeder，便于按依赖顺序编排；
 * - Manager::run($name, true) 可强制重跑（默认按执行记录跳过）。
 *
 * @package Candy\Extend\Seeder
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 20:45:00
 */

declare(strict_types=1);

namespace Candy\Extend\Seeder;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

abstract class Seeder {

    /**
     * 数据库连接配置别名或配置数组
     *
     * @var string|array|null
     */
    protected string|array|null $connection = null;

    /** @var object|null 管理器注入的同一写连接，保证事务不会因重复 D() 失效 */
    private ?object $database = null;

    /** @var string|null 管理器注入的填充目录，供 call() 复用 */
    private ?string $seedersPath = null;

    /** @var int 管理器注入的当前批次号，call() 沿用同一批次 */
    private int $batch = 0;

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
     * 注入管理器当前使用的数据库连接
     *
     * @param object $database 数据库驱动
     * @return void
     */
    public function setDatabase(object $database): void
    {
        $this->database = $database;
    }

    /**
     * 注入填充目录（call() 需要与调用方在同一目录查找子 Seeder）
     *
     * @param string|null $seedersPath 填充目录绝对路径
     * @return void
     */
    public function setSeedersPath(?string $seedersPath): void
    {
        $this->seedersPath = $seedersPath;
    }

    /**
     * 注入当前批次号（call() 内的子 Seeder 沿用同一批次）
     *
     * @param int $batch 批次号
     * @return void
     */
    public function setBatch(int $batch): void
    {
        $this->batch = $batch;
    }

    /**
     * 执行填充
     *
     * @return void
     */
    abstract public function run(): void;

    /**
     * 依次调用其它 Seeder
     *
     * 与 Manager 共用同一连接与同一批次号，任一子 Seeder 抛错会向上冒泡，
     * 由 Manager 统一回滚本次事务。
     *
     * @param string ...$seeders Seeder 名称（文件名去扩展名）或完整类名
     * @return void
     */
    protected function call(string ...$seeders): void
    {
        $manager = new \Candy\Extend\Seeder\Manager($this->seedersPath, $this->connection);
        $manager->setDatabase($this->db());
        $manager->setBatch($this->batch);
        $manager->callInline($seeders);
    }

    /**
     * 清空指定表（可选是否重置自增）
     *
     * @param string $table     表名（不含前缀）
     * @param bool   $restart   是否重置自增序列
     * @param bool   $cascade   是否级联
     * @return void
     */
    protected function truncate(string $table, bool $restart = true, bool $cascade = false): void
    {
        $this->db()->truncate($table, $restart, $cascade);
    }

    /**
     * 批量插入数据（走 Model 层，能触发模型事件与时间戳）
     *
     * @param string $model 模型短名（如 'Users'）或完整类名
     * @param array<int, array<string, mixed>> $rows 行数据
     * @return int 成功插入条数
     */
    protected function insert(string $model, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $instance = str_contains($model, '\\') ? new $model() : M($model);
        $count = 0;
        foreach ($rows as $row) {
            if (is_array($row) && $row !== []) {
                $instance->resetQueryState()->add($row);
                $count++;
            }
        }

        return $count;
    }

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
     * CLI 环境下加载数据库配置到 C()/G()
     *
     * Web 请求由 Bootstrap::boot() 完成，命令行走这里补齐
     *
     * @return void
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
}
