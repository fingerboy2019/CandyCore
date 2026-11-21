<?php
/**
 * 任务队列（Redis）
 *
 * 唯一实现：本类直接用 PHP redis 扩展实现，FPM 与常驻模式都可用。
 *
 * 历史：曾 vendored `Workerman\RedisQueue\Client`（workerman/redis-queue），但它缺依赖
 * `Workerman\Redis\Client`（异步客户端）→ 构造时落到 PHP 原生 `\Redis`，报
 * `ArgumentCountError: Redis::__construct() expects at most 1 argument, 2 given`。
 * 该残缺件已于 2026-11-17 删除（`Candy/Vendor/Workerman/RedisQueue/`），**不要再引入**。
 *
 * 特性：投递 / 延迟 / 重试（线性退避）/ 失败队列 / 消费。
 *
 * ```php
 * Queue::push('export', ['type' => 'csv']);            // 立即
 * Queue::push('export', ['type' => 'csv'], 300);       // 5 分钟后
 * ```
 *
 * 消费交给 Console 命令 `php candy queue:work`（见 Worker 类）。
 *
 * @package Candy\Extend\Queue
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:50:00
 */

declare(strict_types=1);

namespace Candy\Extend\Queue;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Queue
{
    /** 待消费队列前缀 */
    public const KEY_WAITING = 'queue:waiting:';

    /** 延迟队列（zset，score = 执行时间戳） */
    public const KEY_DELAYED = 'queue:delayed';

    /** 失败队列 */
    public const KEY_FAILED = 'queue:failed';

    /** @var \Redis|null 连接 */
    private static ?object $redis = null;

    /** @var array<string, mixed> 配置 */
    private static array $config = [
        'host'          => '127.0.0.1',
        'port'          => 6379,
        'password'      => '',
        'database'      => 0,
        'prefix'        => '',
        'retry_seconds' => 5,
        'max_attempts'  => 5,
    ];

    /**
     * 设置连接配置
     *
     * @param array<string, mixed> $config host/port/password/database/prefix/retry_seconds/max_attempts
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
        self::$redis = null;
    }

    /**
     * 取 Redis 连接（自动从框架配置推断）
     *
     * @return \Redis
     */
    public static function redis(): object
    {
        if (self::$redis !== null && self::$redis->isConnected()) {
            return self::$redis;
        }

        if (!extension_loaded('redis')) {
            throw new \RuntimeException('未安装 PHP redis 扩展，队列不可用');
        }

        // 未显式 configure 时从框架缓存配置推断
        $host = (string) (C('REDIS_HOST') ?: self::$config['host']);
        $port = (int) (C('REDIS_PORT') ?: self::$config['port']);
        $pass = (string) (C('REDIS_PASSWORD') ?: self::$config['password']);
        $db = (int) (C('REDIS_DATABASE') ?: self::$config['database']);

        $redis = new \Redis();
        if (!$redis->connect($host, $port, 3.0)) {
            throw new \RuntimeException('Redis 连接失败：' . $host . ':' . $port);
        }
        if ($pass !== '' && !$redis->auth($pass)) {
            throw new \RuntimeException('Redis 认证失败');
        }
        if ($db > 0) {
            $redis->select($db);
        }

        return self::$redis = $redis;
    }

    /**
     * 投递任务
     *
     * @param string $queue 队列名
     * @param array<string, mixed> $data 任务数据
     * @param int $delay 延迟秒数（>0 走延迟队列）
     * @return string 任务 id
     */
    public static function push(string $queue, array $data = [], int $delay = 0): string
    {
        $id = bin2hex(random_bytes(8));
        $package = json_encode([
            'id'       => $id,
            'queue'    => $queue,
            'data'     => $data,
            'attempts' => 0,
            'time'     => time(),
        ], JSON_UNESCAPED_UNICODE);

        $redis = self::redis();
        $prefix = (string) self::$config['prefix'];

        if ($delay > 0) {
            $redis->zAdd($prefix . self::KEY_DELAYED, time() + $delay, $package);
        } else {
            // 重复检测：同一任务内容若已在失败队列，允许重投
            $redis->lPush($prefix . self::KEY_WAITING . $queue, $package);
        }

        return $id;
    }

    /**
     * 把到期的延迟任务挪进待消费队列
     *
     * @param int $limit 单次最多搬运条数
     * @return int 搬运数量
     */
    public static function migrateDelayed(int $limit = 128): int
    {
        $redis = self::redis();
        $prefix = (string) self::$config['prefix'];
        $key = $prefix . self::KEY_DELAYED;
        $now = time();

        $items = $redis->zRangeByScore($key, '-inf', (string) $now, ['limit' => [0, $limit]]);
        if (!is_array($items) || $items === []) {
            return 0;
        }

        $moved = 0;
        foreach ($items as $package) {
            if (!$redis->zRem($key, $package)) {
                continue;
            }
            $decoded = json_decode((string) $package, true);
            $queue = is_array($decoded) ? (string) ($decoded['queue'] ?? 'default') : 'default';
            $redis->lPush($prefix . self::KEY_WAITING . $queue, $package);
            $moved++;
        }

        return $moved;
    }

    /**
     * 取一个任务（阻塞）
     *
     * @param array<int, string> $queues 队列名列表（按优先级）
     * @param int $timeout 阻塞秒数，0 表示永久
     * @return array<string, mixed>|null
     */
    public static function pop(array $queues, int $timeout = 0): ?array
    {
        $redis = self::redis();
        $prefix = (string) self::$config['prefix'];
        $keys = array_map(static fn ($q) => $prefix . self::KEY_WAITING . $q, $queues);

        $result = $redis->blPop($keys, $timeout);
        if (!is_array($result) || count($result) < 2) {
            return null;
        }

        $decoded = json_decode((string) $result[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 任务失败处理：未超次数则延迟重投，否则进失败队列
     *
     * @param array<string, mixed> $package 任务包
     * @param string $error 失败原因
     * @return bool true=已重投；false=已进失败队列
     */
    public static function retryOrFail(array $package, string $error = ''): bool
    {
        $redis = self::redis();
        $prefix = (string) self::$config['prefix'];

        $attempts = (int) ($package['attempts'] ?? 0) + 1;
        $max = max(1, (int) self::$config['max_attempts']);
        $package['attempts'] = $attempts;
        $package['error'] = $error;

        $encoded = json_encode($package, JSON_UNESCAPED_UNICODE);

        if ($attempts >= $max) {
            $redis->lPush($prefix . self::KEY_FAILED, $encoded);

            return false;
        }

        // 线性退避：retry_seconds * attempts
        $delay = max(1, (int) self::$config['retry_seconds']) * $attempts;
        $redis->zAdd($prefix . self::KEY_DELAYED, time() + $delay, $encoded);

        return true;
    }

    /**
     * 队列长度
     *
     * @param string $queue 队列名
     * @return int
     */
    public static function size(string $queue): int
    {
        return (int) self::redis()->lLen((string) self::$config['prefix'] . self::KEY_WAITING . $queue);
    }

    /**
     * 失败队列内容
     *
     * @param int $limit 条数
     * @return array<int, array<string, mixed>>
     */
    public static function failed(int $limit = 50): array
    {
        $items = self::redis()->lRange((string) self::$config['prefix'] . self::KEY_FAILED, 0, max(0, $limit - 1));
        $out = [];
        foreach ((array) $items as $item) {
            $decoded = json_decode((string) $item, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * 清空失败队列
     *
     * @return int 清除条数
     */
    public static function clearFailed(): int
    {
        $key = (string) self::$config['prefix'] . self::KEY_FAILED;
        $n = (int) self::redis()->lLen($key);
        self::redis()->del($key);

        return $n;
    }

    /**
     * 重置连接（测试用）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$redis = null;
    }
}
