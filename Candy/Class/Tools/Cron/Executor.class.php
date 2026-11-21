<?php
/***
 * 任务执行器（端点 tick、手动执行、常驻 cron 服务共用）
 *
 * 抽出来的原因：`/sys/crontab?do=tick`、`?do=runonce` 和常驻 cron 服务必须执行**同一套**逻辑，
 * 否则两处实现漂移（改一处忘另一处）是迟早的事。
 *
 * 支持三种任务类型（`kind` 是视图层叫法，底层是 `handler`）：
 *   http    → `Handler\HttpCallback`   请求 URL
 *   push    → `Handler\Push`           交给推送网关投递
 *   shell   → `Handler\ShellCallback`  执行命令（默认关闭，见 CrontabScheduler.inc.php 的 shell 段）
 *
 * ## 每次执行统一做的事（2026-11-20 补齐）
 *   ① **登记运行态**（`RunState`）：执行前写 {pid, started_at, deadline}，执行后清除
 *   ② **防重叠**：已在运行且未超时 → 按任务的 `overlap` 策略处理（skip / kill）
 *   ③ **真实耗时**：handler 返回 `duration_ms`，没有则由这里计时兜底
 *   ④ **结果落库**：`Task::markRun()` 把 message / 耗时 / 输出尾部写进任务行，
 *      否则「上次结果」只能显示一行状态，出问题无从查起
 *
 * ## 为什么防重叠要独立做一遍
 * `Workerman\Crontab\Manager` 自带 `singleton` 与 `acquireLock`，但它只管**常驻引擎自己调度的
 * 那一次**。页面「立即执行」、tick 端点、外部 cron 打的 tick、FPM 多进程并发，全都绕开它。
 * 没有独立运行态，一次卡死就让这个任务永远「看起来在跑」。
 *
 * @package Candy\Extend\Tools\Cron
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 14:00:00
 * @modified 2026-11-20 11:40:00 加运行态/防重叠/耗时/输出落库
 */
namespace Candy\Extend\Tools\Cron;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Executor
{
    /** 重叠策略：跳过本次 */
    public const OVERLAP_SKIP = 'skip';

    /** 重叠策略：杀掉上一次再跑 */
    public const OVERLAP_KILL = 'kill';

    /** 写进 message 的输出尾部长度 */
    public const TAIL_CHARS = 600;

    /**
     * 执行一条任务并记录结果
     *
     * @param array<string, mixed> $task 任务视图
     * @param int|null             $now  执行时刻
     * @return array{ok:bool, message:string, duration_ms:float, status:string}
     */
    public static function run(array $task, ?int $now = null): array
    {
        $now = $now ?? time();
        $id = (string) ($task['id'] ?? '');
        $kind = (string) ($task['kind'] ?? 'http');
        $timeout = max(0, (int) ($task['timeout'] ?? 0));
        $overlap = (string) ($task['overlap'] ?? self::OVERLAP_SKIP);

        // ── ① 防重叠：已在跑怎么办 ────────────────────────────────
        if ($id !== '') {
            $state = RunState::status($id);
            if ($state['running']) {
                if ($overlap === self::OVERLAP_KILL) {
                    RunState::kill($id);
                    RunState::clear($id);
                } else {
                    $note = '已跳过：' . $state['reason'];
                    Task::markRun($id, false, $note, $now, 0, 'skipped');
                    return ['ok' => false, 'message' => $note, 'duration_ms' => 0.0, 'status' => 'skipped'];
                }
            }
            RunState::begin($id, $timeout);
        }

        $t0 = microtime(true);
        $status = 'failed';

        try {
            $res = self::dispatch($kind, $task, $now);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'message' => '执行异常：' . $e->getMessage(), 'tail' => ''];
        } finally {
            if ($id !== '') {
                RunState::end($id);
            }
        }

        $elapsed = round((microtime(true) - $t0) * 1000, 1);
        $durationMs = (float) ($res['duration_ms'] ?? $elapsed);
        $ok = !empty($res['ok']);
        $status = $ok ? 'ok' : 'failed';

        $message = (string) ($res['message'] ?? '');
        $tail = trim((string) ($res['tail'] ?? ''));
        if ($tail !== '') {
            $message .= ($message === '' ? '' : ' | ') . self::clip($tail);
        }

        if ($id !== '') {
            Task::markRun($id, $ok, $message, $now, $durationMs, $status);
        }

        return ['ok' => $ok, 'message' => $message, 'duration_ms' => $durationMs, 'status' => $status];
    }

    /**
     * 按类型分派到 handler
     *
     * @param string               $kind 任务类型
     * @param array<string, mixed> $task 任务
     * @param int                  $now  执行时刻
     * @return array{ok:bool, message:string, duration_ms?:float, tail?:string}
     */
    protected static function dispatch(string $kind, array $task, int $now): array
    {
        if ($kind === 'http') {
            return self::runHttp($task);
        }

        if ($kind === 'shell') {
            return self::runShell($task);
        }

        return self::runPush($task);
    }

    /**
     * 执行 HTTP 回调任务（统一走框架内置 handler）
     *
     * 以前这里直接调 `Server::request()`，只拿到 bool，耗时/状态码/响应体全丢，
     * 和 `Handler\HttpCallback` 是两套逻辑。现在统一走 handler，一处实现两处受益。
     *
     * @param array<string, mixed> $task 任务
     * @return array{ok:bool, message:string, duration_ms?:float, tail?:string}
     */
    protected static function runHttp(array $task): array
    {
        $url = trim((string) ($task['url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'message' => '回调地址为空'];
        }

        $post = $task['post'] ?? [];
        if (is_string($post) && $post !== '') {
            $decoded = json_decode($post, true);
            $post = is_array($decoded) ? $decoded : [];
        }

        $params = [
            'url'    => $url,
            'method' => (string) ($task['method'] ?? 'get'),
            'body'   => is_array($post) ? $post : [],
        ];
        // 超时：任务上的优先，没填就用 handler 默认
        $timeout = max(0, (int) ($task['timeout'] ?? 0));
        if ($timeout > 0) {
            $params['timeout'] = $timeout;
        }

        $handlerClass = '\Candy\Extend\Tools\Cron\Handler\HttpCallback';
        if (!class_exists($handlerClass)) {
            // 极端兜底：handler 不在，退回旧实现
            $ok = Server::request($url, (string) ($task['method'] ?? 'get'), (array) $params['body']);
            return ['ok' => $ok, 'message' => ($ok ? '回调成功' : '回调失败') . '：' . $url];
        }

        try {
            $res = (new $handlerClass())->run($params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '回调异常：' . $e->getMessage()];
        }

        $ok = ((int) ($res['code'] ?? 1)) === 0;
        $message = (string) ($res['message'] ?? '');
        $durationMs = (float) ($res['duration_ms'] ?? 0);

        // 失败时把目标地址也带上，便于一眼看出是哪个任务
        if (!$ok && strpos($message, $url) === false) {
            $message .= '（' . $url . '）';
        }

        return [
            'ok'          => $ok,
            'message'     => $message,
            'duration_ms' => $durationMs,
            'tail'        => '',
        ];
    }

    /**
     * 执行 shell 命令任务
     *
     * @param array<string, mixed> $task 任务
     * @return array{ok:bool, message:string, duration_ms?:float, tail?:string}
     */
    protected static function runShell(array $task): array
    {
        $params = [
            'command' => (string) ($task['command'] ?? ''),
            'cwd'     => (string) ($task['cwd'] ?? ''),
            'timeout' => (int) ($task['timeout'] ?? 0),
        ];
        if ($params['command'] === '') {
            return ['ok' => false, 'message' => '命令为空'];
        }

        $handlerClass = '\Candy\Extend\Tools\Cron\Handler\ShellCallback';
        if (!class_exists($handlerClass)) {
            return ['ok' => false, 'message' => '缺少 ShellCallback handler'];
        }

        $result = (new $handlerClass())->run($params);
        $ok = ((int) ($result['code'] ?? 1)) === 0;
        return [
            'ok'          => $ok,
            'message'     => (string) ($result['message'] ?? ''),
            'duration_ms' => (float) ($result['duration_ms'] ?? 0),
            'tail'        => '',
        ];
    }

    /**
     * 执行推送任务
     *
     * @param array<string, mixed> $task 任务
     * @return array{ok:bool, message:string, tail?:string}
     */
    protected static function runPush(array $task): array
    {
        $target = trim((string) ($task['target'] ?? 'all'));
        if ($target === '') {
            $target = 'all';
        }
        $msg = (string) ($task['msg'] ?? '');
        $app = trim((string) ($task['app'] ?? 'default'));
        if ($app === '') {
            $app = 'default';
        }

        try {
            $pusher = \Candy\Extend\Tools\Push\Target::app($app);
            if ($target === 'all') {
                $res = $pusher->all($msg);
            } elseif (str_starts_with($target, '#')) {
                $res = $pusher->toGroup(substr($target, 1), $msg);
            } else {
                $res = $pusher->toUid($target, $msg);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '推送异常：' . $e->getMessage()];
        }

        $note = (string) ($res['message'] ?? '');
        if (!empty($res['ok'])) {
            return ['ok' => true, 'message' => '已推送：' . $note];
        }
        if ((int) ($res['queued'] ?? 0) > 0) {
            return ['ok' => true, 'message' => '目标离线，已存补发队列：' . $note];
        }
        return ['ok' => false, 'message' => '推送失败：' . $note];
    }

    /**
     * 执行所有到期任务
     *
     * @param int|null $now 基准时间
     * @return array<int, array<string, mixed>>
     */
    public static function runDue(?int $now = null): array
    {
        $now = $now ?? time();
        $results = [];
        foreach (Task::due($now) as $task) {
            $res = self::run($task, $now);
            $results[] = [
                'id'      => $task['id'],
                'name'    => $task['name'],
                'ok'      => $res['ok'],
                'message' => $res['message'],
            ];
        }
        return $results;
    }

    /**
     * 执行指定 id 的任务（立即执行，不改变原计划）
     *
     * @param string   $id  任务 id
     * @param int|null $now 时刻
     * @return array{ok:bool, message:string, duration_ms:float, status:string}
     */
    public static function runId(string $id, ?int $now = null): array
    {
        $task = Task::find($id);
        if ($task === null) {
            return ['ok' => false, 'message' => '任务不存在：' . $id, 'duration_ms' => 0.0, 'status' => 'failed'];
        }
        // 立即执行不改 at：先记下原值，执行后复位
        $at = (int) ($task['at'] ?? 0);
        $res = self::run($task, $now);
        $cur = Task::find($id);
        if ($cur !== null && (int) ($cur['at'] ?? 0) !== $at) {
            $rows = Task::all();
            foreach ($rows as $i => $row) {
                if ((string) $row['id'] === $id) {
                    $rows[$i]['at'] = $at;
                    break;
                }
            }
            if (method_exists(Task::class, 'replaceAll')) {
                Task::replaceAll($rows);
            }
        }
        return $res;
    }

    /**
     * 截断输出尾部
     *
     * @param string $text 文本
     * @return string
     */
    protected static function clip(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        if (strlen($text) > self::TAIL_CHARS) {
            return substr($text, -self::TAIL_CHARS);
        }
        return $text;
    }
}
