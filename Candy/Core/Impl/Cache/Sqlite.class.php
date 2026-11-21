<?php
declare(strict_types=1);

/***
 * SQLite 缓存驱动
 *
 * 业务说明：单机持久化 KV 驱动，定位等同于 File 驱动，但底层交给成熟的 SQLite：
 *   - WAL 模式让「多读单写」并发不再靠 flock 排队
 *   - 崩溃安全由 SQLite 的 journal 保证，不需要自己实现 dump + WAL
 *   - B-tree 索引替代自研的 bitmap + meta 区
 *
 * 表结构：cache(k TEXT PRIMARY KEY, v BLOB, e INTEGER)
 *   k = 加过前缀的 key
 *   v = serialize 后的值
 *   e = 过期时间戳（0 表示永久）
 *
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2026-11-16 23:45:00 $
 *
 * @package Candy\Core\Impl\Cache
 */

namespace Candy\Core\Impl\Cache;

use Candy\Core\Cache;
use PDO;
use Throwable;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Sqlite extends Cache
{
    /** 驱动能力声明 */
    protected function driverCapabilities(): array
    {
        return [
            'atomic_mutation' => [
                'support'     => true,
                'mode'        => 'local',
                'distributed' => false,
                'durable'     => true,
            ],
        ];
    }

    use \Candy\Core\Trait\Cache\LocalHotCache;

    /** @var PDO|null 懒加载的连接 */
    private ?PDO $pdo = null;

    /** @var string 数据库文件路径 */
    private string $dbFile = '';

    /** @var string 表名 */
    private string $table = 'cache';

    /** @var int busy timeout（毫秒） */
    private int $busyTimeout = 5000;

    /** @var int 过期清理的触发概率分母（1/N） */
    private int $gcProbability = 100;

    /** @var array<string, mixed> 驱动配置 */
    private array $rawConfig = [];

    /**
     * 构造
     *
     * @param array<string, mixed> $config cacheDir / database / table / busyTimeout / gcProbability
     */
    public function __construct(#[ \SensitiveParameter ] array $config = [])
    {
        parent::__construct($config);
        $this->rawConfig = $config;

        $dir = $config['cacheDir'] ?? (
            is_null(C('RUNTIME'))
                ? rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'candy-sqlite-cache'
                : rtrim((string) C('RUNTIME'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'cache'
        );
        $dir = str_replace('\\', '/', (string) $dir);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->dbFile = str_replace('\\', '/', (string) ($config['database'] ?? $dir . '/cache.sqlite3'));
        $this->table = (string) ($config['table'] ?? 'cache');
        $this->busyTimeout = max(0, (int) ($config['busyTimeout'] ?? 5000));
        $this->gcProbability = max(1, (int) ($config['gcProbability'] ?? 100));
    }

    /**
     * 取连接（懒加载 + 建表 + WAL）
     *
     * @return PDO
     */
    private function db(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dir = dirname($this->dbFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $this->dbFile, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        if ($this->busyTimeout > 0) {
            $pdo->exec('PRAGMA busy_timeout = ' . $this->busyTimeout);
        }
        // WAL：读不阻塞写、写不阻塞读 —— 这是选 SQLite 而不是 File 的主要理由
        $pdo->exec('PRAGMA journal_mode = WAL');
        // NORMAL：崩溃时不丢已提交事务，只在断电时可能丢最后一个 checkpoint
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');

        $t = $this->table;
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (k TEXT PRIMARY KEY, v BLOB, e INTEGER NOT NULL DEFAULT 0)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$t}_e ON {$t} (e)");

        return $this->pdo = $pdo;
    }

    /**
     * 序列化（优先 igbinary，生产装了能快 2~3 倍）
     *
     * @param mixed $value
     * @return string
     */
    private function packValue(mixed $value): string
    {
        if (function_exists('igbinary_serialize')) {
            $bin = @igbinary_serialize($value);
            if (is_string($bin)) {
                return $bin;
            }
        }
        return serialize($value);
    }

    /**
     * 反序列化
     *
     * @param string|null $raw
     * @param mixed       $default
     * @return mixed
     */
    private function unpackValue(?string $raw, mixed $default = null): mixed
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (function_exists('igbinary_unserialize')) {
            $v = @igbinary_unserialize($raw);
            if ($v !== false || $raw === @igbinary_serialize(false)) {
                return $v;
            }
        }
        $v = @unserialize($raw);
        return $v === false && $raw !== serialize(false) ? $default : $v;
    }

    /**
     * 过期时间戳（0 = 永久）
     *
     * @param int $ttl
     * @return int
     */
    private function expireAt(int $ttl): int
    {
        return $ttl > 0 ? time() + $ttl : 0;
    }

    /**
     * 按概率清理过期行（低频，避免每次写都扫表）
     *
     * @return void
     */
    private function maybeGc(): void
    {
        if ($this->gcProbability > 1 && random_int(1, $this->gcProbability) !== 1) {
            return;
        }
        try {
            $st = $this->db()->prepare("DELETE FROM {$this->table} WHERE e > 0 AND e <= ?");
            $st->execute([time()]);
        } catch (Throwable) {
        }
    }

    /**
     * 写入
     *
     * @param string $key 已加前缀的完整 key
     * @param mixed  $value
     * @param int    $ttl
     * @return bool
     */
    public function __rawSet(string $key, mixed $value, int $ttl = 0): bool
    {
        try {
            $bin = $this->packValue($value);
            $st = $this->db()->prepare(
                "INSERT INTO {$this->table} (k, v, e) VALUES (?, ?, ?)
                 ON CONFLICT(k) DO UPDATE SET v = excluded.v, e = excluded.e"
            );
            $st->bindValue(1, $key, PDO::PARAM_STR);
            $st->bindValue(2, $bin, PDO::PARAM_LOB);
            $st->bindValue(3, $this->expireAt($ttl), PDO::PARAM_INT);
            $st->execute();
            $this->maybeGc();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 读取
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function __rawGet(string $key, mixed $default = null): mixed
    {
        try {
            $st = $this->db()->prepare("SELECT v, e FROM {$this->table} WHERE k = ?");
            $st->execute([$key]);
            $row = $st->fetch();
            if (!$row) {
                return $default;
            }
            $e = (int) $row['e'];
            if ($e > 0 && $e <= time()) {
                $this->__rawDelete($key);
                return $default;
            }
            return $this->unpackValue(is_string($row['v']) ? $row['v'] : null, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * 删除
     *
     * @param string $key
     * @return bool
     */
    public function __rawDelete(string $key): bool
    {
        try {
            $st = $this->db()->prepare("DELETE FROM {$this->table} WHERE k = ?");
            $st->execute([$key]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 存在性
     *
     * @param string $key
     * @return bool
     */
    public function __rawHas(string $key): bool
    {
        try {
            $st = $this->db()->prepare("SELECT e FROM {$this->table} WHERE k = ?");
            $st->execute([$key]);
            $row = $st->fetch();
            if (!$row) {
                return false;
            }
            $e = (int) $row['e'];
            if ($e > 0 && $e <= time()) {
                $this->__rawDelete($key);
                return false;
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 剩余 TTL（-1 不存在 / -2 无过期？按基类约定：0 表示无过期）
     *
     * @param string $key
     * @return int
     */
    public function __ttl(string $key): int
    {
        try {
            $st = $this->db()->prepare("SELECT e FROM {$this->table} WHERE k = ?");
            $st->execute([$key]);
            $row = $st->fetch();
            if (!$row) {
                return -1;
            }
            $e = (int) $row['e'];
            if ($e <= 0) {
                return 0;
            }
            $left = $e - time();
            if ($left <= 0) {
                $this->__rawDelete($key);
                return -1;
            }
            return $left;
        } catch (Throwable) {
            return -1;
        }
    }

    /**
     * 清空（按前缀隔离：只删本实例前缀的 key）
     *
     * @return bool
     */
    public function __clear(): bool
    {
        try {
            $prefix = $this->cachePrefix;
            if ($prefix === '') {
                $this->db()->exec("DELETE FROM {$this->table}");
                return true;
            }
            $st = $this->db()->prepare("DELETE FROM {$this->table} WHERE k LIKE ?");
            $st->execute([$prefix . '%']);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 批量存在性
     *
     * @param array<int, string> $keys
     * @return array<int, mixed>
     */
    public function __rawMHas(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->__rawHas((string) $k);
        }
        return $out;
    }

    /**
     * 批量写（单事务）
     *
     * @param array<string, mixed> $items
     * @param int                  $ttl
     * @return bool
     */
    public function __rawMSet(array $items, int $ttl = 0): bool
    {
        if ($items === []) {
            return true;
        }
        try {
            $pdo = $this->db();
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                "INSERT INTO {$this->table} (k, v, e) VALUES (?, ?, ?)
                 ON CONFLICT(k) DO UPDATE SET v = excluded.v, e = excluded.e"
            );
            $e = $this->expireAt($ttl);
            foreach ($items as $k => $value) {
                $st->bindValue(1, (string) $k, PDO::PARAM_STR);
                $st->bindValue(2, $this->packValue($value), PDO::PARAM_LOB);
                $st->bindValue(3, $e, PDO::PARAM_INT);
                $st->execute();
            }
            $pdo->commit();
            return true;
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    /**
     * 批量读
     *
     * @param array<int, string> $keys
     * @param mixed              $default
     * @return array<string, mixed>
     */
    public function __rawMGet(array $keys, mixed $default = null): array
    {
        $out = [];
        foreach ($keys as $k) {
            $k = (string) $k;
            $out[$k] = $this->__rawGet($k, $default);
        }
        return $out;
    }

    /**
     * 批量删
     *
     * @param array<int, string> $keys
     * @return bool
     */
    public function __rawMDelete(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }
        try {
            $pdo = $this->db();
            $pdo->beginTransaction();
            $st = $pdo->prepare("DELETE FROM {$this->table} WHERE k = ?");
            foreach ($keys as $k) {
                $st->execute([(string) $k]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    /**
     * 自增（$type: 0=int 1=float）
     *
     * @param string $key
     * @param int    $step
     * @param int    $type
     * @return int
     */
    public function __rawIncr(string $key, int $step, int $type): int
    {
        try {
            $pdo = $this->db();
            $pdo->beginTransaction();
            $cur = $this->__rawGet($key, 0);
            $cur = is_numeric($cur) ? ($type === 1 ? (float) $cur : (int) $cur) : 0;
            $next = $type === 1 ? $cur + $step : $cur + $step;
            $this->__rawSet($key, $next, 0);
            $pdo->commit();
            return (int) $next;
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return 0;
        }
    }

    /**
     * 入队（数组末尾/头部追加）
     *
     * @param string             $key
     * @param array<int, mixed>  $args
     * @param bool               $cover 覆盖还是追加
     * @param int                $head  1=头部 0=尾部
     * @return int 追加后的长度
     */
    public function __rawPush(string $key, array $args, bool $cover, int $head = 0): int
    {
        try {
            $list = $cover ? [] : (array) $this->__rawGet($key, []);
            if ($head === 1) {
                $list = array_merge(array_reverse($args), $list);
            } else {
                $list = array_merge($list, $args);
            }
            $this->__rawSet($key, $list, 0);
            return count($list);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * 出队
     *
     * @param string $key
     * @param int    $head 1=头部 0=尾部
     * @return mixed
     */
    public function __rawPop(string $key, int $head = 0): mixed
    {
        try {
            $list = (array) $this->__rawGet($key, []);
            if ($list === []) {
                return null;
            }
            $value = $head === 1 ? array_shift($list) : array_pop($list);
            $this->__rawSet($key, array_values($list), 0);
            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 区间查询（对 list 型值切片）
     *
     * @param string $key
     * @param int    $offset
     * @param int    $length
     * @param mixed  $sort
     * @param string $order
     * @return array<int, mixed>
     */
    public function __rawRange(string $key, int $offset, int $length = 0, $sort = '', string $order = 'asc'): array
    {
        $list = (array) $this->__rawGet($key, []);
        $list = array_values($list);
        if ($sort !== '' && $sort !== null) {
            $order = strtolower($order) === 'desc' ? 'desc' : 'asc';
            usort($list, static function ($a, $b) use ($order): int {
                $r = $a <=> $b;
                return $order === 'desc' ? -$r : $r;
            });
        }
        return $length > 0 ? array_slice($list, $offset, $length) : array_slice($list, $offset);
    }

    /**
     * 设置过期（不改值）
     *
     * @param string $key
     * @param int    $ttl
     * @return bool
     */
    public function __rawExpire(string $key, int $ttl): bool
    {
        try {
            $st = $this->db()->prepare("UPDATE {$this->table} SET e = ? WHERE k = ?");
            $st->execute([$this->expireAt($ttl), $key]);
            return $st->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 移除过期时间
     *
     * @param string $key
     * @return bool
     */
    public function __rawPersist(string $key): bool
    {
        return $this->__rawExpire($key, 0);
    }

    /**
     * 重命名
     *
     * @param string $oldKey
     * @param string $newKey
     * @return bool
     */
    public function __rawRename(string $oldKey, string $newKey): bool
    {
        try {
            $st = $this->db()->prepare("UPDATE {$this->table} SET k = ? WHERE k = ?");
            $st->execute([$newKey, $oldKey]);
            return $st->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 统计（排障用）
     *
     * @return array<string, mixed>
     */
    public function sqliteInfo(): array
    {
        try {
            $pdo = $this->db();
            $total = (int) $pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
            $expired = (int) $pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE e > 0 AND e <= " . time())->fetchColumn();
            $mode = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
            return [
                'file'     => $this->dbFile,
                'table'    => $this->table,
                'journal'  => $mode,
                'total'    => $total,
                'expired'  => $expired,
            ];
        } catch (Throwable $e) {
            return ['file' => $this->dbFile, 'error' => $e->getMessage()];
        }
    }


    // ================================================================
    // 以下为 Cache 基类强制要求、但 SQLite 驱动不实现的其余能力
    // ================================================================

    /** @var array<string, array{h: resource, count: int}> 已持有的锁 */
    private array $heldLocks = [];

    /**
     * 获取锁（用锁文件 + flock；不依赖 SQLite 事务隔离）
     *
     * @param string   $lockName
     * @param int|null $timeout 毫秒；-1=无限等待；null=用 busyTimeout
     * @return bool
     */
    public function __acquireLock(string $lockName, ?int $timeout = null): bool
    {
        if (isset($this->heldLocks[$lockName])) {
            $this->heldLocks[$lockName]['count']++;
            return true;
        }

        $file = $this->dbFile . '.lock.' . md5($lockName);
        $h = @fopen($file, 'c+b');
        if (!$h) {
            return false;
        }

        $deadline = $timeout === -1 ? null : (microtime(true) + max(0, $timeout ?? $this->busyTimeout) / 1000.0);
        do {
            if (@flock($h, LOCK_EX | LOCK_NB)) {
                $this->heldLocks[$lockName] = ['h' => $h, 'count' => 1];
                return true;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }
            usleep(2000);
        } while (true);

        @fclose($h);
        return false;
    }

    /**
     * 释放锁
     *
     * @param string $lockName
     * @return bool
     */
    public function __releaseLock(string $lockName): bool
    {
        if (!isset($this->heldLocks[$lockName])) {
            return true;
        }
        $this->heldLocks[$lockName]['count']--;
        if ($this->heldLocks[$lockName]['count'] > 0) {
            return true;
        }
        $h = $this->heldLocks[$lockName]['h'];
        if (is_resource($h)) {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
        unset($this->heldLocks[$lockName]);
        return true;
    }

    /**
     * Bitmap：SQLite 驱动不实现（用 Redis 或 Shmop）
     *
     * 返回空数组而不是抛异常 —— 调用方按"没取到"处理即可，不会中断业务。
     *
     * @param string      $key
     * @param int|array   $id
     * @param mixed       $options
     * @param int         $expire
     * @return array
     */
    public function __rawBit(string $key, int|array $id, mixed $options = null, int $expire = 0): array|int|bool
    {
        return [];
    }

    // ---- 以下 7 个为 Redis Stream 语义，SQLite 驱动不支持（返回失败值） ----

    /** @return string */
    public function __rawXAdd(string $key, array $fieldValues, ?string $id = '*', int $maxLen = 0): string
    {
        return '';
    }

    /** @return array|false */
    public function __rawXRead(string|array $keys, int $count = 0, int $blockMs = 0): array|false
    {
        return false;
    }

    /** @return string|false */
    public function __rawXGroup(string $key, string $group, string $startId = '0', bool $mkStream = false): bool
    {
        return false;
    }

    /** @return array|false */
    public function __rawXReadGroup(string $group, string $consumer, string $key, int $count = 0, int $blockMs = 0): array|false
    {
        return false;
    }

    /** @return int|false */
    public function __rawXAck(string $key, string $group, array $ids): int|false
    {
        return false;
    }

    /** @return array */
    public function __rawXRange(string $key, string $start = '-', string $end = '+', int $count = 0): array|false
    {
        return [];
    }

    /** @return array */
    public function __rawXRevRange(string $key, string $start = '-', string $end = '+', int $count = 0): array|false
    {
        return [];
    }

    /**
     * 析构：释放连接
     */
    public function __destruct()
    {
        $this->pdo = null;
    }
}
