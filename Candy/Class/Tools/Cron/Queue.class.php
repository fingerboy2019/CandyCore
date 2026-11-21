<?php
/***
 * 时间戳队列（计划任务数据面）
 *
 * 业务说明：把「某个时间点要做的事」按时间戳归档，供 tick 服务到点取出执行。
 * 这是旧 `ws_start.php` 里 `$globaldata->cron` 那套结构的类化版本。
 *
 * 存储：**Cache 的 zset**（`zAdd` 入队、`zPopDue` 原子取出）。
 * - 用 zset 而不是 `G()` 整包：整包是「读全量 → 改 → 写全量」，非原子且随条目数线性放大；
 *   zset 的 score 就是执行时间戳，天然按时间索引，`zPopDue` 在单个锁区间内完成「取到期 + 移除」。
 * - 模拟驱动（File/Memory）与 Redis 都走同一套 API，跨进程一致性由驱动层保证。
 *
 * 队列结构（对外的视图形状保持不变）：
 * ```
 * [
 *   1763000000 => [
 *     'push'  => ['<target>' => ['msg' => '...', 'url' => '']],   // 到点推送
 *     'sever' => ['<md5(url)>' => ['url'=>..., 'type'=>'get', 'post'=>[], 'interval'=>0]],
 *   ],
 * ]
 * ```
 * 同一个时间戳 + 同一个目标（`push` 看 target，`sever` 看 md5(url)）重复入队会**覆盖**，
 * 与旧的整包数组语义一致。
 *
 * 用法：
 * ```php
 * use Candy\Extend\Tools\Cron\Queue;
 * Queue::push(time() + 60, 'all', '五分钟后提醒');                 // 60 秒后推送
 * Queue::sever(time() + 60, 'https://example.com/hook', 'post', ['a'=>1]);  // 60 秒后回调
 * Queue::sever(time() + 60, 'https://example.com/heart', 'get', [], 300);   // 每 300 秒
 * ```
 *
 * @package Candy\Extend\Tools\Cron
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 12:15:00
 * @modified 2026-11-17 存储层改为 Cache zset（原子取出 + 不再整包读写）
 */
namespace Candy\Extend\Tools\Cron;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Queue
{
    /** zset 键（score = 执行时间戳） */
    public const KEY = 'cron.queue';

    /** 旧版整包键（仅用于升级时一次性迁移） */
    public const LEGACY_KEY = 'cron.queue';

    /** 离线待补推消息键（低频小数据，继续走 G()） */
    public const PENDING_KEY = 'cron.pending';

    /** 单次 take 最多取出的条数（防止一次拉太多把 tick 拖住） */
    public const TAKE_LIMIT = 200;

    /**
     * 读取整条队列（视图形状与旧版一致，供管理界面/统计使用）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        self::migrateLegacy();

        $cache = self::cache();
        $rows  = $cache->zRange(self::KEY, 0, -1, true);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $queue = [];
        foreach ($rows as $member => $score) {
            $item = self::decode((string) $member);
            if ($item === null) {
                continue;
            }
            self::merge($queue, $item, (int) $score);
        }

        ksort($queue, SORT_NUMERIC);
        return $queue;
    }

    /**
     * 入队一条推送任务
     *
     * @param int    $timestamp 执行时间戳
     * @param string $target    目标（'all' 表示广播，其余为业务侧用户标识）
     * @param string $message   消息内容
     * @param string $url       离线回调地址（空 = 只入离线队列）
     * @return bool
     */
    public static function push(int $timestamp, string $target, string $message, string $url = ''): bool
    {
        return self::enqueue($timestamp, self::encode('push', $timestamp, [
            't'   => $target,
            'msg' => $message,
            'url' => $url,
        ]));
    }

    /**
     * 入队一条 HTTP 回调任务
     *
     * @param int    $timestamp 首次执行时间戳
     * @param string $url       回调地址
     * @param string $type      get / post
     * @param array  $post      POST 数据
     * @param int    $interval  周期秒数，0 = 只执行一次
     * @return bool
     */
    public static function sever(int $timestamp, string $url, string $type = 'get', array $post = [], int $interval = 0): bool
    {
        return self::enqueue($timestamp, self::encode('sever', $timestamp, [
            'u'  => $url,
            'ty' => strtolower($type) === 'post' ? 'post' : 'get',
            'p'  => $post,
            'iv' => max(0, $interval),
        ]));
    }

    /**
     * 列出所有已到期条目（**不删除**，只读视图，给管理界面看）
     *
     * @param int|null $now 基准时间，默认当前
     * @return array<int, array<string, mixed>> 按时间戳升序
     */
    public static function due(?int $now = null): array
    {
        $now   = $now ?? time();
        $queue = self::all();

        $due = [];
        foreach ($queue as $ts => $item) {
            if ((int) $ts > $now) {
                break;
            }
            $due[(int) $ts] = $item;
        }
        return $due;
    }

    /**
     * 原子取出并移除所有已到期条目（供 tick 执行）
     *
     * 走 `Cache::zPopDue()`：模拟驱动在单个锁区间内完成「读到期 + 移除 + 写回」，
     * Redis 靠单命令 `ZREM` 的返回值裁决，多进程不会重复取到同一批。
     *
     * @param int|null $now   基准时间，默认当前
     * @param int      $limit 本次最多取出多少条
     * @return array<int, array<string, mixed>>
     */
    public static function take(?int $now = null, int $limit = self::TAKE_LIMIT): array
    {
        $now = $now ?? time();
        self::migrateLegacy();

        $members = self::cache()->zPopDue(self::KEY, (float) $now, $limit);
        if (!is_array($members) || $members === []) {
            return [];
        }

        $taken = [];
        foreach ($members as $member) {
            $item = self::decode((string) $member);
            if ($item === null) {
                continue;
            }
            self::merge($taken, $item, (int) $item['ts']);
        }

        ksort($taken, SORT_NUMERIC);
        return $taken;
    }

    /**
     * 清空队列
     *
     * @return bool
     */
    public static function clear(): bool
    {
        $cache  = self::cache();
        $rows   = $cache->zRange(self::KEY, 0, -1);
        if (is_array($rows) && $rows !== []) {
            $cache->zRem(self::KEY, ...array_map('strval', $rows));
        }

        // 旧版整包键一并清掉
        $legacy = G(self::LEGACY_KEY);
        if (is_array($legacy) && $legacy !== []) {
            G(self::LEGACY_KEY, []);
        }

        return true;
    }

    /**
     * 队列规模摘要
     *
     * @return array<string, mixed>
     */
    public static function size(): array
    {
        $queue = self::all();
        $push  = 0;
        $sever = 0;
        $next  = null;
        foreach ($queue as $ts => $item) {
            $push  += isset($item['push']) ? count((array) $item['push']) : 0;
            $sever += isset($item['sever']) ? count((array) $item['sever']) : 0;
            if ($next === null || (int) $ts < $next) {
                $next = (int) $ts;
            }
        }

        return [
            'timestamps' => count($queue),
            'push'       => $push,
            'sever'      => $sever,
            'next_at'    => $next,
            'next_time'  => $next === null ? '' : date('Y-m-d H:i:s', $next),
        ];
    }

    /**
     * 追加离线待补推消息
     *
     * @param string $target  目标
     * @param string $message 消息
     * @return bool
     */
    public static function fail(string $target, string $message): bool
    {
        $pending = G(self::PENDING_KEY);
        if (!is_array($pending)) {
            $pending = [];
        }
        $pending[$target][] = $message;
        G(self::PENDING_KEY, $pending);
        return true;
    }

    /**
     * 取出并清空某目标的离线消息
     *
     * @param string $target 目标
     * @return array<int, string>
     */
    public static function flushFailed(string $target): array
    {
        $pending = G(self::PENDING_KEY);
        if (!is_array($pending) || empty($pending[$target])) {
            return [];
        }
        $messages = (array) $pending[$target];
        unset($pending[$target]);
        G(self::PENDING_KEY, $pending);
        return array_values($messages);
    }

    /**
     * 入队：先删掉同一秒里「同类型 + 同唯一键」的旧条目（保持覆盖语义），再 zAdd
     *
     * @param int    $timestamp 时间戳
     * @param string $member    成员内容（JSON）
     * @return bool
     */
    protected static function enqueue(int $timestamp, string $member): bool
    {
        if ($member === '') {
            return false;
        }

        self::migrateLegacy();
        $cache = self::cache();

        $item = json_decode($member, true);
        $kind = is_array($item) ? (string) ($item['k'] ?? '') : '';
        $uniq = self::uniqueKey(is_array($item) ? $item : []);

        if ($kind !== '' && $uniq !== '') {
            $same = $cache->zRangeByScore(self::KEY, (float) $timestamp, (float) $timestamp, 0, -1);
            if (is_array($same) && $same !== []) {
                foreach ($same as $old) {
                    if ((string) $old === $member) {
                        continue;
                    }
                    $oldItem = self::decode((string) $old);
                    if ($oldItem === null || (string) ($oldItem['k'] ?? '') !== $kind) {
                        continue;
                    }
                    if (self::uniqueKey($oldItem) !== $uniq) {
                        continue;
                    }
                    $cache->zRem(self::KEY, (string) $old);
                }
            }
        }

        return $cache->zAdd(self::KEY, (float) $timestamp, $member);
    }

    /**
     * 同秒同类型的唯一键（push 用 target，sever 用 md5(url)）
     *
     * @param array<string, mixed> $item 成员数据
     * @return string
     */
    protected static function uniqueKey(array $item): string
    {
        $data = (array) ($item['d'] ?? []);
        $kind = (string) ($item['k'] ?? '');

        if ($kind === 'push') {
            return 'push|' . (string) ($data['t'] ?? '');
        }
        if ($kind === 'sever') {
            return 'sever|' . md5((string) ($data['u'] ?? ''));
        }
        return '';
    }

    /**
     * 编码成员
     *
     * @param string $kind    类型 push / sever
     * @param int    $timestamp 时间戳
     * @param array  $payload 负载
     * @return string
     */
    protected static function encode(string $kind, int $timestamp, array $payload): string
    {
        return (string) json_encode(
            ['k' => $kind, 'ts' => $timestamp, 'd' => $payload],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 解码成员
     *
     * @param string $member 成员内容
     * @return array<string, mixed>|null
     */
    protected static function decode(string $member): ?array
    {
        $item = json_decode($member, true);
        if (!is_array($item) || !isset($item['k'], $item['ts'])) {
            return null;
        }
        $item['ts'] = (int) $item['ts'];
        return $item;
    }

    /**
     * 把单条成员合并进「按时间戳分组」的视图结构
     *
     * @param array<int, array<string, mixed>> $queue 目标结构
     * @param array<string, mixed>             $item  成员
     * @param int                              $ts    时间戳
     * @return void
     */
    protected static function merge(array &$queue, array $item, int $ts): void
    {
        $data = (array) ($item['d'] ?? []);

        if ($item['k'] === 'push') {
            $queue[$ts]['push'][(string) ($data['t'] ?? '')] = [
                'msg' => (string) ($data['msg'] ?? ''),
                'url' => (string) ($data['url'] ?? ''),
            ];
            return;
        }

        if ($item['k'] === 'sever') {
            $url = (string) ($data['u'] ?? '');
            $queue[$ts]['sever'][md5($url)] = [
                'url'      => $url,
                'type'     => (string) ($data['ty'] ?? 'get'),
                'post'     => (array) ($data['p'] ?? []),
                'interval' => max(0, (int) ($data['iv'] ?? 0)),
            ];
        }
    }

    /**
     * 升级迁移：zset 为空且旧版整包键有数据时，搬一次并清掉旧键
     *
     * @return void
     */
    protected static function migrateLegacy(): void
    {
        $cache = self::cache();
        if ($cache->zCard(self::KEY) > 0) {
            return;
        }

        $legacy = G(self::LEGACY_KEY);
        if (!is_array($legacy) || $legacy === []) {
            return;
        }

        foreach ($legacy as $ts => $item) {
            $ts = (int) $ts;
            foreach ((array) ($item['push'] ?? []) as $target => $payload) {
                $payload = is_array($payload) ? $payload : ['msg' => (string) $payload];
                $cache->zAdd(self::KEY, (float) $ts, self::encode('push', $ts, [
                    't'   => (string) $target,
                    'msg' => (string) ($payload['msg'] ?? ''),
                    'url' => (string) ($payload['url'] ?? ''),
                ]));
            }
            foreach ((array) ($item['sever'] ?? []) as $job) {
                $cache->zAdd(self::KEY, (float) $ts, self::encode('sever', $ts, [
                    'u'  => (string) ($job['url'] ?? ''),
                    'ty' => (string) ($job['type'] ?? 'get'),
                    'p'  => (array) ($job['post'] ?? []),
                    'iv' => max(0, (int) ($job['interval'] ?? 0)),
                ]));
            }
        }

        G(self::LEGACY_KEY, []);
    }

    /**
     * 当前 Cache 实例
     *
     * @return \Candy\Core\Cache
     */
    protected static function cache(): \Candy\Core\Cache
    {
        return \Candy\Core\Cache::store();
    }
}
