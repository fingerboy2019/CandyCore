<?php
/**
 * 数据填充管理器
 *
 * 负责填充目录扫描、执行记录、按依赖顺序执行与强制重跑。
 * 结构对齐 Candy\Extend\Migration\Manager，便于维护。
 *
 * @package Candy\Extend\Seeder
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 20:45:00
 */

declare(strict_types=1);

namespace Candy\Extend\Seeder;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Manager {

    /**
     * 填充文件目录（相对 CANDYROOT）
     */
    public const DEFAULT_PATH = 'Data/seeders';

    /**
     * 填充记录表名（不含前缀）
     */
    public const TABLE_NAME = 'seeders';

    /**
     * @var string 填充文件目录绝对路径
     */
    private string $seedersPath;

    /**
     * @var string|array|null 数据库连接配置
     */
    private string|array|null $connection;

    /** @var object|null 当前管理器复用的写连接 */
    private ?object $database = null;

    /** @var int 当前批次号（callInline 复用） */
    private int $currentBatch = 0;

    /**
     * 构造填充管理器
     *
     * @param string|null $seedersPath 填充目录，默认 CANDYROOT/Data/seeders
     * @param string|array|null $connection 数据库配置别名或数组
     */
    public function __construct(?string $seedersPath = null, string|array|null $connection = null)
    {
        $this->seedersPath = $seedersPath ?? (rtrim((string) C('CANDYROOT'), '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_PATH);
        $this->connection = $connection;
    }

    /**
     * 创建填充文件
     *
     * @param string $name 填充名称（蛇形，如 users_seed）
     * @return string 生成的文件绝对路径
     */
    public function make(string $name): string
    {
        $name = $this->normalizeSeederName($name);
        if ($name === '') {
            throw new \InvalidArgumentException('填充名称不能为空');
        }

        if (!is_dir($this->seedersPath) && !mkdir($this->seedersPath, 0775, true) && !is_dir($this->seedersPath)) {
            throw new \RuntimeException('无法创建填充目录：' . $this->seedersPath);
        }

        $filePath = $this->seedersPath . DIRECTORY_SEPARATOR . $name . '.php';
        if (is_file($filePath)) {
            throw new \RuntimeException('填充文件已存在：' . $name);
        }

        $content = $this->buildStub($name);
        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException('写入填充文件失败：' . $name);
        }

        return $filePath;
    }

    /**
     * 执行填充
     *
     * @param string|array<int, string>|null $name  指定 Seeder（null = 全部待执行）
     * @param bool $force 为 true 时忽略执行记录强制重跑
     * @return array<int, string> 已执行的 Seeder 名列表
     */
    public function run(string|array|null $name = null, bool $force = false): array
    {
        $this->ensureSeedersTable();
        $targets = $this->resolveTargets($name, $force);
        if ($targets === []) {
            return [];
        }

        $batch = $this->getNextBatchNumber();
        $this->currentBatch = $batch;
        $ran = [];
        $db = $this->db();

        foreach ($targets as $seederName) {
            // 每轮重查：前一个 Seeder 的 call() 可能已经跑掉了本轮目标
            // （call() 走的是独立 Manager 实例，本地快照看不到它的写入）
            if (!$force && $this->hasRanSeeder($seederName)) {
                continue;
            }

            $instance = $this->resolveSeeder($seederName);
            $instance->setConnection($this->connection);
            $instance->setDatabase($db);
            $instance->setSeedersPath($this->seedersPath);
            $instance->setBatch($batch);

            $db->beginTransaction();
            try {
                $instance->run();
                if ($force) {
                    $this->removeSeederRecord($seederName);
                }
                $this->recordSeeder($seederName, $batch);
                $db->commit();
                $ran[] = $seederName;
            } catch (\Throwable $e) {
                try {
                    $db->rollBack();
                } catch (\Throwable) {
                    // 某些 DDL 会由驱动隐式结束事务；保留原始填充异常。
                }
                throw new \RuntimeException(
                    '填充 ' . $seederName . ' 执行失败：' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $ran;
    }

    /**
     * 在当前事务内执行一批 Seeder（供 Seeder::call() 使用）
     *
     * 不开新事务、不写独立批次号，直接复用调用方的连接与当前批次。
     *
     * @param array<int, string> $names Seeder 名列表
     * @return array<int, string> 已执行列表
     */
    public function callInline(array $names): array
    {
        $this->ensureSeedersTable();
        $ran = [];
        $db = $this->db();
        $batch = $this->currentBatch > 0 ? $this->currentBatch : $this->getNextBatchNumber();

        foreach ($names as $seederName) {
            $seederName = $this->normalizeSeederName($seederName);
            if ($seederName === '') {
                continue;
            }
            $instance = $this->resolveSeeder($seederName);
            $instance->setConnection($this->connection);
            $instance->setDatabase($db);
            $instance->setSeedersPath($this->seedersPath);
            $instance->setBatch($batch);
            $instance->run();
            $this->recordSeeder($seederName, $batch);
            $ran[] = $seederName;
        }

        return $ran;
    }

    /**
     * 设置当前批次号（Manager::run 内部使用）
     *
     * @param int $batch 批次号
     * @return void
     */
    public function setBatch(int $batch): void
    {
        $this->currentBatch = $batch;
    }

    /**
     * 注入外部连接（Seeder::call() 复用）
     *
     * @param object $database 数据库驱动
     * @return void
     */
    public function setDatabase(object $database): void
    {
        $this->database = $database;
    }

    /**
     * 获取待执行 Seeder 列表
     *
     * @return array<int, string>
     */
    public function getPendingSeeders(): array
    {
        $all = $this->getAllSeederFiles();
        $ran = $this->getRanSeeders();

        return array_values(array_diff($all, $ran));
    }

    /**
     * 获取全部 Seeder 名称
     *
     * @return array<int, string>
     */
    public function getAllSeeders(): array
    {
        return $this->getAllSeederFiles();
    }

    /**
     * 获取已执行 Seeder 名称
     *
     * @return array<int, string>
     */
    public function getRanSeeders(): array
    {
        if (!$this->seedersTableExists()) {
            return [];
        }

        $table = $this->quotedTableName();
        $rows = $this->db()->query(
            'SELECT ' . $this->quoteIdent('seeder') . ' FROM ' . $table
                . ' ORDER BY ' . $this->quoteIdent('id') . ' ASC',
            'select'
        );

        $names = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && isset($row['seeder'])) {
                $names[] = (string) $row['seeder'];
            }
        }

        return $names;
    }

    /**
     * 指定 Seeder 是否已执行过
     *
     * 单条查询，供 run() 每轮校验（call() 会在中途写入记录）
     *
     * @param string $seederName Seeder 名
     * @return bool
     */
    private function hasRanSeeder(string $seederName): bool
    {
        if (!$this->seedersTableExists()) {
            return false;
        }

        $table = $this->quotedTableName();
        $row = $this->db()->query(
            'SELECT ' . $this->quoteIdent('id') . ' AS ' . $this->quoteIdent('found')
            . ' FROM ' . $table
            . ' WHERE ' . $this->quoteIdent('seeder') . ' = ? LIMIT 1',
            'find',
            [$seederName]
        );

        return is_array($row) && $row !== [];
    }

    /**
     * 填充状态一览
     *
     * @return array<int, array{seeder: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $ranMap = [];
        if ($this->seedersTableExists()) {
            $table = $this->quotedTableName();
            $rows = $this->db()->query(
                'SELECT ' . $this->quoteIdent('seeder') . ',' . $this->quoteIdent('batch')
                . ' FROM ' . $table . ' ORDER BY ' . $this->quoteIdent('id') . ' ASC',
                'select'
            );
            foreach ((array) $rows as $row) {
                if (is_array($row) && isset($row['seeder'])) {
                    $ranMap[(string) $row['seeder']] = (int) ($row['batch'] ?? 0);
                }
            }
        }

        $out = [];
        foreach ($this->getAllSeederFiles() as $seederName) {
            $out[] = [
                'seeder' => $seederName,
                'ran'    => array_key_exists($seederName, $ranMap),
                'batch'  => $ranMap[$seederName] ?? null,
            ];
        }

        return $out;
    }

    /**
     * 获取填充目录路径
     *
     * @return string
     */
    public function getSeedersPath(): string
    {
        return $this->seedersPath;
    }

    /**
     * 规范化填充名称
     *
     * @param string $name 原始名称
     * @return string
     */
    private function normalizeSeederName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? '';

        return trim($name, '_');
    }

    /**
     * 解析本次要执行的目标
     *
     * @param string|array<int, string>|null $name  指定名称
     * @param bool $force 是否强制重跑
     * @return array<int, string>
     */
    private function resolveTargets(string|array|null $name, bool $force): array
    {
        if ($name === null) {
            return $force ? $this->getAllSeederFiles() : $this->getPendingSeeders();
        }

        $list = is_array($name) ? $name : [$name];
        $out = [];
        foreach ($list as $item) {
            $item = $this->normalizeSeederName((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * 生成填充文件模板
     *
     * @param string $name 填充名称
     * @return string
     */
    private function buildStub(string $name): string
    {
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));

        return "<?php\n"
            . "/**\n"
            . " * 数据填充：{$name}\n"
            . " *\n"
            . " * @package APP\Data\Seeders\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . "use Candy\\Extend\\Seeder\\Seeder;\n\n"
            . "return new class extends Seeder {\n\n"
            . "    /**\n"
            . "     * 执行填充\n"
            . "     *\n"
            . "     * @return void\n"
            . "     */\n"
            . "    public function run(): void\n"
            . "    {\n"
            . "        // \$this->truncate('your_table');\n"
            . "        // \$this->insert('YourModel', [\n"
            . "        //     ['name' => 'example'],\n"
            . "        // ]);\n"
            . "    }\n"
            . "};\n";
    }

    /**
     * 加载填充实例
     *
     * @param string $seederName 填充文件名（无扩展名）
     * @return Seeder
     */
    private function resolveSeeder(string $seederName): Seeder
    {
        $path = $this->seedersPath . DIRECTORY_SEPARATOR . $seederName . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('填充文件不存在：' . $seederName);
        }

        $seeder = require $path;
        if (!$seeder instanceof Seeder) {
            throw new \RuntimeException('填充文件必须返回 Seeder 实例：' . $seederName);
        }

        return $seeder;
    }

    /**
     * 扫描全部填充文件名（不含扩展名）
     *
     * @return array<int, string>
     */
    private function getAllSeederFiles(): array
    {
        if (!is_dir($this->seedersPath)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->seedersPath) ?: [] as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }
            $files[] = substr($file, 0, -4);
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * 确保填充记录表存在
     *
     * @return void
     */
    private function ensureSeedersTable(): void
    {
        if ($this->seedersTableExists()) {
            return;
        }

        if ($this->isPgsql()) {
            $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->quotedTableName() . ' ('
                . $this->quoteIdent('id') . ' SERIAL PRIMARY KEY, '
                . $this->quoteIdent('seeder') . ' VARCHAR(255) NOT NULL, '
                . $this->quoteIdent('batch') . ' INT NOT NULL, '
                . $this->quoteIdent('created_at') . ' TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)';
        } else {
            $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->quotedTableName() . ' ('
                . $this->quoteIdent('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . $this->quoteIdent('seeder') . ' VARCHAR(255) NOT NULL, '
                . $this->quoteIdent('batch') . ' INT NOT NULL, '
                . $this->quoteIdent('created_at') . ' DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)';
        }

        $this->db()->query($sql, 'other');
    }

    /**
     * 填充记录表是否存在
     *
     * @return bool
     */
    private function seedersTableExists(): bool
    {
        try {
            $prefix = $this->getTablePrefix();
            if ($this->isPgsql()) {
                $row = $this->db()->query(
                    "SELECT 1 AS ok FROM information_schema.tables WHERE table_name = '"
                    . $prefix . self::TABLE_NAME . "' LIMIT 1",
                    'find'
                );
            } else {
                $row = $this->db()->query(
                    "SHOW TABLES LIKE '" . $prefix . self::TABLE_NAME . "'",
                    'find'
                );
            }

            return is_array($row) && $row !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 带引号的记录表名
     *
     * @return string
     */
    private function quotedTableName(): string
    {
        return $this->quoteIdent($this->getTablePrefix() . self::TABLE_NAME);
    }

    /**
     * 标识符加引号
     *
     * @param string $ident 标识符
     * @return string
     */
    private function quoteIdent(string $ident): string
    {
        return $this->isPgsql() ? '"' . str_replace('"', '""', $ident) . '"' : '`' . str_replace('`', '``', $ident) . '`';
    }

    /**
     * 是否 PgSQL
     *
     * @return bool
     */
    private function isPgsql(): bool
    {
        return $this->db()->dialectClass() === \Candy\Extend\DB\Dialect\Pgsql::class;
    }

    /**
     * 获取表前缀
     *
     * @return string
     */
    private function getTablePrefix(): string
    {
        $config = loadConfig('Config');
        if (is_array($this->connection)) {
            return (string) ($this->connection['TABPREFIX'] ?? $config['TABPREFIX'] ?? '');
        }
        $alias = is_string($this->connection) && $this->connection !== '' ? $this->connection : 'default';
        $aliasConfig = $config['DB'][$alias] ?? [];
        if (is_array($aliasConfig) && $aliasConfig !== []) {
            return \Candy\Extend\DB\DBServer::resolveTablePrefixFromAliasConfig($aliasConfig);
        }

        return (string) ($config['TABPREFIX'] ?? '');
    }

    /**
     * 获取数据库驱动
     *
     * @return object
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

    /**
     * 获取下一批次号
     *
     * @return int
     */
    private function getNextBatchNumber(): int
    {
        $table = $this->quotedTableName();
        $batch = $this->quoteIdent('batch');
        $maxBatch = $this->quoteIdent('max_batch');
        $row = $this->db()->query("SELECT MAX({$batch}) AS {$maxBatch} FROM {$table}", 'find');
        $max = is_array($row) ? (int) ($row['max_batch'] ?? 0) : 0;

        return $max + 1;
    }

    /**
     * 写入填充记录
     *
     * @param string $seederName 填充名
     * @param int $batch 批次号
     * @return void
     */
    private function recordSeeder(string $seederName, int $batch): void
    {
        $table = $this->quotedTableName();
        $sql = 'INSERT INTO ' . $table
            . ' (' . $this->quoteIdent('seeder') . ',' . $this->quoteIdent('batch') . ')'
            . ' VALUES (?,?)';
        $this->db()->query($sql, 'insert', [$seederName, $batch]);
    }

    /**
     * 删除填充记录（强制重跑前清理）
     *
     * @param string $seederName 填充名
     * @return void
     */
    private function removeSeederRecord(string $seederName): void
    {
        if (!$this->seedersTableExists()) {
            return;
        }

        $table = $this->quotedTableName();
        $sql = 'DELETE FROM ' . $table . ' WHERE ' . $this->quoteIdent('seeder') . ' = ?';
        $this->db()->query($sql, 'delete', [$seederName]);
    }
}
