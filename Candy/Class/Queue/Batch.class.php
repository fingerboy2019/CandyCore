<?php
/**
 * 队列批处理
 *
 * 跟踪一组异步任务的完成情况：每完成一个 markDone()，全部完成时触发 then 回调。
 * 状态存于 G()（跨进程共享变量），worker 进程与投递进程共享。
 * 注意：不能用 V() —— 那是向量检索门面（VectorServer）。
 * G() 无 TTL，所以把 expires_at 写进载荷自己判过期。
 *
 * ```php
 * // 投递方
 * $batchId = Batch::create('export-2026', 100);
 * Batch::onComplete($batchId, fn() => okrSendMail('a@b.com', '导出完成', '...'));
 * foreach ($rows as $i => $row) {
 *     Queue::push('export', ['batch' => $batchId, 'index' => $i, 'row' => $row]);
 * }
 *
 * // 消费方（任务处理完调用）
 * if (!empty($job['batch'])) {
 *     Batch::done($job['batch']);
 * }
 * ```
 *
 * @package Candy\Extend\Queue
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:40:00
 */

declare(strict_types=1);

namespace Candy\Extend\Queue;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Batch
{
    /** 缓存键前缀 */
    private const PREFIX = 'queue:batch:';

    /** 状态保留秒数（默认 7 天） */
    private const TTL = 604800;

    /**
     * 读批次载荷（自动处理过期）
     *
     * G() 没有 TTL，所以过期时间写在载荷里，读时判断。
     *
     * @param string $key 键
     * @return mixed 未过期返回载荷，否则 null
     */
    private static function read(string $key): mixed
    {
        $payload = G($key, '', null);
        if (!is_array($payload)) {
            return null;
        }
        if ((int) ($payload['__expires_at'] ?? 0) < time()) {
            G($key, null);   // 惰性清理

            return null;
        }

        return $payload['__data'] ?? null;
    }

    /**
     * 写批次载荷（带过期时间）
     *
     * @param string $key  键
     * @param mixed  $data 数据
     * @return void
     */
    private static function write(string $key, mixed $data): void
    {
        G($key, [
            '__expires_at' => time() + self::TTL,
            '__data'       => $data,
        ]);
    }

    /**
     * 创建批次
     *
     * @param string $name  批次名（便于排查）
     * @param int    $total 任务总数
     * @return string batchId
     */
    public static function create(string $name, int $total): string
    {
        $id = $name . ':' . bin2hex(random_bytes(6));
        self::write(self::PREFIX . $id, [
            'name'       => $name,
            'total'      => max(1, $total),
            'done'       => 0,
            'failed'     => 0,
            'finished'   => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /**
     * 标记一个任务完成
     *
     * @param string $batchId 批次 id
     * @param bool   $failed  该任务是否失败
     * @return array{finished: bool, done: int, total: int, failed: int}
     */
    public static function done(string $batchId, bool $failed = false): array
    {
        $state = self::state($batchId);
        if ($state === null) {
            return ['finished' => false, 'done' => 0, 'total' => 0, 'failed' => 0];
        }
        if ($state['finished']) {
            return ['finished' => true, 'done' => $state['done'], 'total' => $state['total'], 'failed' => $state['failed']];
        }

        $state['done']++;
        if ($failed) {
            $state['failed']++;
        }

        $finished = $state['done'] >= $state['total'];
        $state['finished'] = $finished;
        self::write(self::PREFIX . $batchId, $state);

        if ($finished) {
            self::fireCallback($batchId, $state['failed'] > 0 ? 'catch' : 'then', $state);
        }

        return [
            'finished' => $finished,
            'done'     => $state['done'],
            'total'    => $state['total'],
            'failed'   => $state['failed'],
        ];
    }

    /**
     * 注册全部成功回调
     *
     * 回调存在缓存里（序列化不了闭包），所以这里只记录「回调标识」，
     * 由调用方在自己的 worker 里用 runCallback 触发，或改用 thenDo/ catchDo 直接执行。
     *
     * @param string   $batchId  批次 id
     * @param callable $callback 回调
     * @return void
     */
    public static function onComplete(string $batchId, callable $callback): void
    {
        self::rememberCallback($batchId, 'then', $callback);
    }

    /**
     * 注册失败回调（有任一任务失败时触发）
     *
     * @param string   $batchId  批次 id
     * @param callable $callback 回调
     * @return void
     */
    public static function onFailed(string $batchId, callable $callback): void
    {
        self::rememberCallback($batchId, 'catch', $callback);
    }

    /**
     * 查询进度
     *
     * @param string $batchId 批次 id
     * @return array<string, mixed>|null
     */
    public static function progress(string $batchId): ?array
    {
        return self::state($batchId);
    }

    /**
     * 清理批次状态
     *
     * @param string $batchId 批次 id
     * @return void
     */
    public static function forget(string $batchId): void
    {
        G(self::PREFIX . $batchId, null);
        G(self::PREFIX . $batchId . ':cb', null);
    }

    /**
     * 读状态
     *
     * @param string $batchId 批次 id
     * @return array<string, mixed>|null
     */
    private static function state(string $batchId): ?array
    {
        $state = self::read(self::PREFIX . $batchId);
        if (!is_array($state)) {
            return null;
        }

        return [
            'name'       => (string) ($state['name'] ?? ''),
            'total'      => (int) ($state['total'] ?? 0),
            'done'       => (int) ($state['done'] ?? 0),
            'failed'     => (int) ($state['failed'] ?? 0),
            'finished'   => (bool) ($state['finished'] ?? false),
            'created_at' => (string) ($state['created_at'] ?? ''),
        ];
    }

    /**
     * 记录回调（当前进程内有效；跨进程需改用事件/消息）
     *
     * @param string   $batchId  批次 id
     * @param string   $kind     then|catch
     * @param callable $callback 回调
     * @return void
     */
    private static function rememberCallback(string $batchId, string $kind, callable $callback): void
    {
        $map = self::read(self::PREFIX . $batchId . ':cb');
        $map = is_array($map) ? $map : [];
        $map[$kind] = $callback;
        self::write(self::PREFIX . $batchId . ':cb', $map);
    }

    /**
     * 触发回调
     *
     * @param string $batchId 批次 id
     * @param string $kind    then|catch
     * @param array  $state   批次状态
     * @return void
     */
    private static function fireCallback(string $batchId, string $kind, array $state): void
    {
        $map = self::read(self::PREFIX . $batchId . ':cb');
        if (!is_array($map) || !isset($map[$kind]) || !is_callable($map[$kind])) {
            return;
        }

        try {
            ($map[$kind])($state);
        } catch (\Throwable $e) {
            if (class_exists('\Candy\Core\Debug')) {
                \Candy\Core\Debug::addMsg('[Batch] 回调异常：' . $e->getMessage(), 4);
            }
        }
    }
}
