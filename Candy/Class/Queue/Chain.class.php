<?php
/**
 * 队列链式任务
 *
 * 一组任务串行执行：前一个成功后才投递下一个。
 * 任一环节失败则链中止（可通过 onFail 记录）。
 *
 * ```php
 * // 投递方
 * $chainId = Chain::dispatch([
 *     ['job' => 'export', 'data' => ['type' => 'csv']],
 *     ['job' => 'upload', 'data' => ['target' => 'oss']],
 *     ['job' => 'notify', 'data' => ['to' => 'a@b.com']],
 * ], 'default');
 *
 * // 消费方（任务处理成功后调用）
 * Chain::advance($chainId);
 * ```
 *
 * @package Candy\Extend\Queue
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-12 22:40:00
 */

declare(strict_types=1);

namespace Candy\Extend\Queue;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Chain
{
    /** 缓存键前缀 */
    private const PREFIX = 'queue:chain:';

    /** 状态保留秒数（默认 7 天） */
    private const TTL = 604800;

    /**
     * 读链载荷（自动处理过期）
     *
     * @param string $key 键
     * @return mixed
     */
    private static function read(string $key): mixed
    {
        $payload = G($key, '', null);
        if (!is_array($payload)) {
            return null;
        }
        if ((int) ($payload['__expires_at'] ?? 0) < time()) {
            G($key, null);

            return null;
        }

        return $payload['__data'] ?? null;
    }

    /**
     * 写链载荷（带过期）
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
     * 投递一条链（执行第一步）
     *
     * @param array<int, array{job: string, data?: array<string, mixed>}> $steps 步骤列表
     * @param string $queue 队列名
     * @return string chainId
     */
    public static function dispatch(array $steps, string $queue = 'default'): string
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('链式任务不能为空');
        }

        $id = bin2hex(random_bytes(8));
        self::write(self::PREFIX . $id, [
            'steps'      => array_values($steps),
            'index'      => 0,
            'queue'      => $queue,
            'failed'     => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::pushStep($id, 0);

        return $id;
    }

    /**
     * 推进到下一步（当前步骤成功后调用）
     *
     * @param string $chainId 链 id
     * @return bool true=已投递下一步；false=链已结束
     */
    public static function advance(string $chainId): bool
    {
        $state = self::read(self::PREFIX . $chainId);
        if (!is_array($state)) {
            return false;
        }

        $next = (int) $state['index'] + 1;
        if ($next >= count((array) $state['steps'])) {
            G(self::PREFIX . $chainId, null);

            return false;
        }

        $state['index'] = $next;
        self::write(self::PREFIX . $chainId, $state);
        self::pushStep($chainId, $next);

        return true;
    }

    /**
     * 标记链失败并中止
     *
     * @param string $chainId 链 id
     * @param string $reason  失败原因
     * @return void
     */
    public static function fail(string $chainId, string $reason = ''): void
    {
        $state = self::read(self::PREFIX . $chainId);
        if (!is_array($state)) {
            return;
        }
        $state['failed'] = $reason === '' ? 'unknown' : $reason;
        self::write(self::PREFIX . $chainId, $state);
    }

    /**
     * 查询链状态
     *
     * @param string $chainId 链 id
     * @return array<string, mixed>|null
     */
    public static function status(string $chainId): ?array
    {
        $state = self::read(self::PREFIX . $chainId);

        return is_array($state) ? $state : null;
    }

    /**
     * 投递指定步骤
     *
     * @param string $chainId 链 id
     * @param int    $index   步骤下标
     * @return void
     */
    private static function pushStep(string $chainId, int $index): void
    {
        $state = self::read(self::PREFIX . $chainId);
        if (!is_array($state)) {
            return;
        }

        $steps = (array) $state['steps'];
        $step = $steps[$index] ?? null;
        if (!is_array($step)) {
            return;
        }

        // 步骤数据里带上 chain 元数据，worker 处理完调 Chain::advance()
        Queue::push(
            (string) ($step['job'] ?? 'default'),
            array_merge(
                [
                    'chain' => $chainId,
                    'index' => $index,
                ],
                (array) ($step['data'] ?? [])
            ),
            0
        );
    }
}
