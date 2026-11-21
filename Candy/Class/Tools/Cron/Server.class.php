<?php
/***
 * 时间戳队列的执行器（tick 服务）
 *
 * 业务说明：一次性把「已到期的任务」取出来执行完，**不含任何 Workerman 依赖**，
 * 因此可以被三种驱动方式复用：
 *   ① 常驻进程：在 `Source/Inc/Process.inc.php` 注册一个 worker，`onWorkerStart` 里
 *      `Timer::add(1, [Server::class, 'tick'])`
 *   ② 系统 cron：`* * * * * php candy schedule:tick`（或用 `/sys/crontab?do=queue&tick=1`）
 *   ③ 业务请求里手动触发（不推荐，但排障时好用）
 *
 * 分工：
 *   - `push` 类任务：本类**只负责计数与落离线队列**，真正的在线推送由 WebSocket worker 消费
 *     （连接表在 worker 进程里，本类拿不到，硬塞会写出半成品）
 *   - `sever` 类任务：本类直接发 HTTP 回调；`interval > 0` 会自动重排到下一个时间点
 *
 * @package Candy\Extend\Tools\Cron
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 12:15:00
 */
namespace Candy\Extend\Tools\Cron;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Server
{
    /** 单次 tick 最多处理的条目数（防一次回调太多把进程拖住） */
    public const MAX_PER_TICK = 200;

    /** HTTP 回调连接超时（秒） */
    public const CONNECT_TIMEOUT = 2;

    /** HTTP 回调总超时（秒） */
    public const TIMEOUT = 5;

    /**
     * 挂载时间戳队列（推送 / HTTP 回调）的 tick 定时器
     *
     * 只在 Workerman 常驻进程里可用；FPM 与 CLI 下调用会安全返回 false。
     * 开关与间隔来自 `CrontabScheduler.inc.php` → `queue.auto_tick` / `queue.tick_interval`。
     *
     * @return bool 是否成功挂载
     */
    public static function attach(): bool
    {
        if (!class_exists('\Workerman\Timer')) {
            return false;
        }

        $config = function_exists('loadConfig') ? loadConfig('CrontabScheduler') : [];
        $queue  = is_array($config) && isset($config['queue']) ? (array) $config['queue'] : [];

        if (empty($queue['auto_tick'])) {
            return false;
        }

        // File 兜底驱动下每个 tick 都要整包读写，间隔可调大（分钟级任务无影响）
        $interval = max(1, (int) ($queue['tick_interval'] ?? 1));

        try {
            \Workerman\Timer::add($interval, static function (): void {
                try {
                    self::tick();
                } catch (\Throwable) {
                    // tick 内部已兜异常；这里再兜一层，避免定时器被单个任务打断
                }
            });
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * 执行一次 tick
     *
     * @param int|null $now 基准时间戳，默认当前（测试时可传固定值）
     * @return array<string, mixed> 执行统计
     */
    public static function tick(?int $now = null): array
    {
        $now = $now ?? time();
        $due = Queue::take($now);

        $stat = [
            'at'      => date('Y-m-d H:i:s', $now),
            'batches' => count($due),
            'push'    => 0,
            'sever'   => 0,
            'failed'  => 0,
            'skipped' => 0,
            'errors'  => [],
        ];

        $handled = 0;
        foreach ($due as $timestamp => $item) {
            if ($handled >= self::MAX_PER_TICK) {
                $stat['skipped']++;
                // 超限的条目放回队列，留到下个 tick
                self::requeue((int) $timestamp, $item);
                continue;
            }

            // ── 推送任务：本进程没有连接表，落离线队列交给 WebSocket worker ──
            foreach ((array) ($item['push'] ?? []) as $target => $payload) {
                $handled++;
                $stat['push']++;
                $message = is_array($payload) ? (string) ($payload['msg'] ?? '') : (string) $payload;
                Queue::fail((string) $target, $message);
            }

            // ── HTTP 回调任务：直接执行，周期任务重排 ──
            foreach ((array) ($item['sever'] ?? []) as $job) {
                $handled++;
                $stat['sever']++;

                $url = (string) ($job['url'] ?? '');
                $type = (string) ($job['type'] ?? 'get');
                $post = (array) ($job['post'] ?? []);
                $interval = max(0, (int) ($job['interval'] ?? 0));

                if ($url === '') {
                    $stat['failed']++;
                    $stat['errors'][] = '空的回调地址';
                    continue;
                }

                if (!self::request($url, $type, $post)) {
                    $stat['failed']++;
                    $stat['errors'][] = '回调失败：' . $url;
                }

                if ($interval > 0) {
                    $next = (int) $timestamp + $interval;
                    while ($next <= $now) {
                        $next += $interval;
                    }
                    Queue::sever($next, $url, $type, $post, $interval);
                }
            }
        }

        return $stat;
    }

    /**
     * 把没处理完的条目放回队列
     *
     * @param int                  $timestamp 时间戳
     * @param array<string, mixed> $item      条目
     * @return void
     */
    protected static function requeue(int $timestamp, array $item): void
    {
        foreach ((array) ($item['push'] ?? []) as $target => $payload) {
            $message = is_array($payload) ? (string) ($payload['msg'] ?? '') : (string) $payload;
            $url = is_array($payload) ? (string) ($payload['url'] ?? '') : '';
            Queue::push($timestamp, (string) $target, $message, $url);
        }
        foreach ((array) ($item['sever'] ?? []) as $job) {
            Queue::sever(
                $timestamp,
                (string) ($job['url'] ?? ''),
                (string) ($job['type'] ?? 'get'),
                (array) ($job['post'] ?? []),
                (int) ($job['interval'] ?? 0)
            );
        }
    }

    /**
     * 发一个 HTTP 回调
     *
     * 与原 `ws_start.php` 的 `httpsRequest()` 行为对齐：忽略证书校验、连接 2 秒、总 5 秒。
     *
     * @param string $url  地址
     * @param string $type get / post
     * @param array  $post POST 数据
     * @return bool 是否成功
     */
    public static function request(string $url, string $type = 'get', array $post = []): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
            if (strtolower($type) === 'post') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            }
            $result = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            return $result !== false && $code > 0 && $code < 400;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => strtolower($type) === 'post' ? 'POST' : 'GET',
                'timeout'       => self::TIMEOUT,
                'content'       => strtolower($type) === 'post' ? http_build_query($post) : '',
                'ignore_errors' => true,
            ],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }
}
