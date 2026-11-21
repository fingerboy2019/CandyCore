<?php
/**
 * 数据库迁移管理器
 *
 * 负责迁移目录扫描、批次记录、执行与回滚。
 *
 * @package Candy\Extend\Migration
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-07-15 07:15:00
 */

declare(strict_types=1);

namespace Candy\Extend\Migration;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Manager {

    /**
     * 迁移文件目录（相对 CANDYROOT）
     */
    public const DEFAULT_PATH = 'Data/migrations';

    /**
     * 迁移记录表名（不含前缀）
     */
    public const TABLE_NAME = 'migrations';

    /**
     * @var string 迁移文件目录绝对路径
     */
    private string $migrationsPath;

    /**
     * @var string|array|null 数据库连接配置
     */
    private string|array|null $connection;

    /** @var object|null 当前管理器复用的写连接 */
    private ?object $database = null;

    /**
     * 构造迁移管理器
     *
     * @param string|null $migrationsPath 迁移目录，默认 CANDYROOT/Data/migrations
     * @param string|array|null $connection 数据库配置别名或数组
     */
    public function __construct(?string $migrationsPath = null, string|array|null $connection = null)
    {
        $this->migrationsPath = $migrationsPath ?? (rtrim(C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_PATH);
        $this->connection = $connection;
    }

    /**
     * 创建迁移文件
     *
     * @param string $name 迁移名称（蛇形，如 create_users_table）
     * @return string 生成的文件绝对路径
     */
    public function make(string $name): string
    {
        $name = $this->normalizeMigrationName($name);
        if ($name === '') {
            throw new \InvalidArgumentException('迁移名称不能为空');
        }

        if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0755, true)) {
            throw new \RuntimeException('无法创建迁移目录：' . $this->migrationsPath);
        }

        $fileName = date('Ymd_His') . '_' . $name . '.php';
        $filePath = $this->migrationsPath . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($filePath)) {
            throw new \RuntimeException('迁移文件已存在：' . $fileName);
        }

        $stub = $this->buildStub($name);
        if (file_put_contents($filePath, $stub) === false) {
            throw new \RuntimeException('写入迁移文件失败：' . $filePath);
        }

        return $filePath;
    }

    /**
     * 创建带 up/down SQL 的迁移文件
     *
     * @param string $name 迁移名称（蛇形）
     * @param string $upSql up() 中执行的 SQL
     * @param string $downSql down() 中执行的 SQL，留空则生成 DROP TABLE 占位注释
     * @return string 生成的文件绝对路径
     */
    public function makeWithSql(string $name, string $upSql, string $downSql = ''): string
    {
        $name = $this->normalizeMigrationName($name);
        if ($name === '') {
            throw new \InvalidArgumentException('迁移名称不能为空');
        }
        $upSql = trim($upSql);
        if ($upSql === '') {
            throw new \InvalidArgumentException('up SQL 不能为空');
        }

        if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0755, true)) {
            throw new \RuntimeException('无法创建迁移目录：' . $this->migrationsPath);
        }

        $fileName = date('Ymd_His') . '_' . $name . '.php';
        $filePath = $this->migrationsPath . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($filePath)) {
            throw new \RuntimeException('迁移文件已存在：' . $fileName);
        }

        $stub = $this->buildStubWithSql($name, $upSql, $downSql);
        if (file_put_contents($filePath, $stub) === false) {
            throw new \RuntimeException('写入迁移文件失败：' . $filePath);
        }

        return $filePath;
    }

    /**
     * 执行待运行迁移
     *
     * @param int|null $step 最多执行条数，null 表示全部待执行
     * @return array<int, string> 已执行的迁移名列表
     */
    public function run(?int $step = null): array
    {
        $this->ensureMigrationsTable();
        $pending = $this->getPendingMigrations();
        if ($pending === []) {
            return [];
        }

        if ($step !== null && $step > 0) {
            $pending = array_slice($pending, 0, $step);
        }

        $batch = $this->getNextBatchNumber();
        $ran = [];
        $db = $this->db();

        foreach ($pending as $migrationName) {
            $instance = $this->resolveMigration($migrationName);
            $instance->setConnection($this->connection);
            $instance->setDatabase($db);

            $db->beginTransaction();
            try {
                $instance->up();
                $this->recordMigration($migrationName, $batch);
                $db->commit();
                $ran[] = $migrationName;
            } catch (\Throwable $e) {
                try {
                    $db->rollBack();
                } catch (\Throwable) {
                    // 某些 DDL 会由驱动隐式结束事务；保留原始迁移异常。
                }
                throw new \RuntimeException(
                    '迁移 ' . $migrationName . ' 执行失败：' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $ran;
    }

    /**
     * 回滚最近批次
     *
     * @param int $step 回滚批次数，默认 1
     * @return array<int, string> 已回滚的迁移名列表
     */
    public function rollback(int $step = 1): array
    {
        if ($step < 1) {
            throw new \InvalidArgumentException('回滚步数必须大于 0');
        }

        $this->ensureMigrationsTable();
        $batches = $this->getLastBatches($step);
        if ($batches === []) {
            return [];
        }

        $rolledBack = [];
        $db = $this->db();

        foreach ($batches as $batch) {
            $migrations = $this->getMigrationsByBatch($batch);
            foreach (array_reverse($migrations) as $migrationName) {
                $instance = $this->resolveMigration($migrationName);
                $instance->setConnection($this->connection);
                $instance->setDatabase($db);

                $db->beginTransaction();
                try {
                    $instance->down();
                    $this->removeMigrationRecord($migrationName);
                    $db->commit();
                    $rolledBack[] = $migrationName;
                } catch (\Throwable $e) {
                    try {
                        $db->rollBack();
                    } catch (\Throwable) {
                        // 某些 DDL 会由驱动隐式结束事务；保留原始回滚异常。
                    }
                    throw new \RuntimeException(
                        '迁移 ' . $migrationName . ' 回滚失败：' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        return $rolledBack;
    }

    /**
     * 获取待执行迁移列表
     *
     * @return array<int, string>
     */
    public function getPendingMigrations(): array
    {
        $all = $this->getAllMigrationFiles();
        $ran = $this->getRanMigrations();
        return array_values(array_diff($all, $ran));
    }

    /**
     * 获取迁移目录路径
     *
     * @return string
     */
    public function getMigrationsPath(): string
    {
        return $this->migrationsPath;
    }

    /**
     * 规范化迁移名称
     *
     * @param string $name 原始名称
     * @return string
     * @access private
     */
    private function normalizeMigrationName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? '';
        return trim($name, '_');
    }

    /**
     * 生成迁移文件模板
     *
     * @param string $name 迁移名称
     * @return string
     * @access private
     */
    private function buildStub(string $name): string
    {
        $classHint = str_replace('_', ' ', $name);
        return <<<PHP
<?php
/**
 * {$classHint}
 *
 * 使用 execute() 执行 SQL；表名请通过 \$this->prefix() 拼接前缀。
 */

use Candy\Extend\Migration\Migration;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

return new class extends Migration {
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up(): void
    {
        // 示例：\$this->execute('CREATE TABLE `{\$this->prefix()}{$name}` (...)');
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down(): void
    {
        // 示例：\$this->execute('DROP TABLE IF EXISTS `{\$this->prefix()}{$name}`');
    }
};

PHP;
    }

    /**
     * 生成含 execute SQL 的迁移文件模板
     *
     * @param string $name 迁移名称
     * @param string $upSql up SQL
     * @param string $downSql down SQL
     * @return string
     * @access private
     */
    private function buildStubWithSql(string $name, string $upSql, string $downSql): string
    {
        $classHint = str_replace('_', ' ', $name);
        $upExport = var_export($upSql, true);
        if ($downSql !== '') {
            $downBody = '$this->execute(' . var_export($downSql, true) . ');';
        } else {
            $downBody = "// 示例：\$this->execute('DROP TABLE IF EXISTS `{\$this->prefix()}...`');";
        }

        return <<<PHP
<?php
/**
 * {$classHint}
 *
 * 由代码生成器根据现有表结构自动写入 up SQL。
 */

use Candy\Extend\Migration\Migration;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

return new class extends Migration {
    /**
     * 执行迁移
     *
     * @return void
     */
    public function up(): void
    {
        \$this->execute({$upExport});
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down(): void
    {
        {$downBody}
    }
};

PHP;
    }

    /**
     * 确保迁移记录表存在
     *
     * @return void
     * @access private
     */
    private function ensureMigrationsTable(): void
    {
        $table = $this->quotedTableName();
        $id = $this->quoteIdent('id');
        $migration = $this->quoteIdent('migration');
        $batch = $this->quoteIdent('batch');
        $executedAt = $this->quoteIdent('executed_at');
        if ($this->isPgsql()) {
            $sql = "CREATE TABLE IF NOT EXISTS {$table} (
                {$id} bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                {$migration} varchar(255) NOT NULL UNIQUE,
                {$batch} integer NOT NULL,
                {$executedAt} timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            )";
        } else {
            $index = $this->quoteIdent('uk_migration');
            $sql = "CREATE TABLE IF NOT EXISTS {$table} (
                {$id} int unsigned NOT NULL AUTO_INCREMENT,
                {$migration} varchar(255) NOT NULL,
                {$batch} int unsigned NOT NULL,
                {$executedAt} datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY ({$id}),
                UNIQUE KEY {$index} ({$migration})
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库迁移记录'";
        }
        $this->db()->query($sql, 'other');
    }

    /**
     * 获取带前缀的迁移表名（已转义）
     *
     * @return string
     * @access private
     */
    private function quotedTableName(): string
    {
        return $this->quoteIdent($this->getTablePrefix() . self::TABLE_NAME);
    }

    /**
     * 按当前连接方言引用标识符
     *
     * @param string $ident 标识符
     * @return string
     * @access private
     */
    private function quoteIdent(string $ident): string
    {
        $dialect = $this->db()->dialectClass();
        return $dialect::quoteIdent($ident);
    }

    /**
     * 当前连接是否为 PostgreSQL
     *
     * @return bool
     * @access private
     */
    private function isPgsql(): bool
    {
        return $this->db()->dialectClass() === \Candy\Extend\DB\Dialect\Pgsql::class;
    }

    /**
     * 获取表前缀
     *
     * @return string
     * @access private
     */
    private function getTablePrefix(): string
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
     * 获取数据库驱动
     *
     * @return object
     * @access private
     */
    private function db(): object
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $this->bootDatabaseConfig();
        $this->database = D('_dbDriver', $this->connection ?? [])->useWrite();
        return $this->database;
    }

    /**
     * CLI 环境下加载数据库配置到 C()/G()
     *
     * Web 请求由 Bootstrap::boot() 完成，命令行走 bootRuntime
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
     * 扫描全部迁移文件名（不含扩展名）
     *
     * @return array<int, string>
     * @access private
     */
    private function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->migrationsPath) ?: [] as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }
            $files[] = substr($file, 0, -4);
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * 获取已执行迁移名列表
     *
     * @return array<int, string>
     * @access private
     */
    private function getRanMigrations(): array
    {
        if (!$this->migrationsTableExists()) {
            return [];
        }

        $table = $this->quotedTableName();
        $rows = $this->db()->query(
            'SELECT ' . $this->quoteIdent('migration') . ' FROM ' . $table
                . ' ORDER BY ' . $this->quoteIdent('id') . ' ASC',
            'select'
        );
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn(array $row): string => (string)$row['migration'], $rows);
    }

    /**
     * 迁移记录表是否已存在
     *
     * @return bool
     * @access private
     */
    private function migrationsTableExists(): bool
    {
        try {
            $table = $this->quotedTableName();
            $this->db()->query("SELECT 1 FROM {$table} LIMIT 1", 'find');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 加载迁移实例
     *
     * @param string $migrationName 迁移文件名（无扩展名）
     * @return Migration
     * @access private
     */
    private function resolveMigration(string $migrationName): Migration
    {
        $path = $this->migrationsPath . DIRECTORY_SEPARATOR . $migrationName . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('迁移文件不存在：' . $migrationName);
        }

        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new \RuntimeException('迁移文件必须返回 Migration 实例：' . $migrationName);
        }

        return $migration;
    }

    /**
     * 获取下一批次号
     *
     * @return int
     * @access private
     */
    private function getNextBatchNumber(): int
    {
        $table = $this->quotedTableName();
        $batch = $this->quoteIdent('batch');
        $maxBatch = $this->quoteIdent('max_batch');
        $row = $this->db()->query("SELECT MAX({$batch}) AS {$maxBatch} FROM {$table}", 'find');
        $max = is_array($row) ? (int)($row['max_batch'] ?? 0) : 0;
        return $max + 1;
    }

    /**
     * 写入迁移记录
     *
     * @param string $migrationName 迁移名
     * @param int $batch 批次号
     * @return void
     * @access private
     */
    private function recordMigration(string $migrationName, int $batch): void
    {
        $table = $this->quotedTableName();
        $this->db()->query(
            'INSERT INTO ' . $table . ' (' . $this->quoteIdent('migration') . ', '
                . $this->quoteIdent('batch') . ') VALUES (?, ?)',
            'insert',
            [$migrationName, $batch]
        );
    }

    /**
     * 删除迁移记录
     *
     * @param string $migrationName 迁移名
     * @return void
     * @access private
     */
    private function removeMigrationRecord(string $migrationName): void
    {
        $table = $this->quotedTableName();
        $this->db()->query(
            'DELETE FROM ' . $table . ' WHERE ' . $this->quoteIdent('migration') . ' = ?',
            'delete',
            [$migrationName]
        );
    }

    /**
     * 获取最近 N 个批次号（降序）
     *
     * @param int $step 批次数
     * @return array<int, int>
     * @access private
     */
    private function getLastBatches(int $step): array
    {
        $table = $this->quotedTableName();
        $rows = $this->db()->query(
            'SELECT DISTINCT ' . $this->quoteIdent('batch') . ' FROM ' . $table
                . ' ORDER BY ' . $this->quoteIdent('batch') . ' DESC LIMIT ' . (int)$step,
            'select'
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn(array $row): int => (int)$row['batch'], $rows);
    }

    /**
     * 按批次获取迁移名（升序）
     *
     * @param int $batch 批次号
     * @return array<int, string>
     * @access private
     */
    private function getMigrationsByBatch(int $batch): array
    {
        $table = $this->quotedTableName();
        $rows = $this->db()->query(
            'SELECT ' . $this->quoteIdent('migration') . ' FROM ' . $table
                . ' WHERE ' . $this->quoteIdent('batch') . ' = ? ORDER BY '
                . $this->quoteIdent('id') . ' ASC',
            'select',
            [$batch]
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn(array $row): string => (string)$row['migration'], $rows);
    }
}

