<?php
/***
 * 计划任务定义（框架能力对外唯一 API）
 *
 * 页面 `/sys/crontab` 与所有接口只用这个类，**不直接碰底层引擎**。
 *
 * 底层用框架自带的 `Workerman\Crontab\Manager`（`Candy/Vendor/Workerman/Crontab`）：
 *   - jobs 存 GlobalData（`candy_crontab_*` 前缀），读即最新
 *   - 秒级调度由 `Workerman\Crontab\Server` 常驻执行
 *   - 自带分布式锁 `acquireLock`、运行日志 `recordRun/listRunLogs`（保留 7 天）、`singleton` 语义
 *
 * 任务模型（对外视图，字段尽量保持与旧版一致，页面不用改）：
 *   id / name / kind(http|push) / expr / enabled / runs / last_at / last_ok / last_msg
 *   + kind=http 时的 url / method / post
 *   + kind=push 时的 target / msg
 *
 * 存储与引擎都不可用时（裸框架、没起 GlobalData）：
 *   自动退回 `Cron\Store`（GD→Cache 兜底的键值存储），保证页面仍可增删改查，
 *   只是**不会自动执行**——这时页面/接口会如实返回存储来源。
 *
 * @package Candy\Extend\Tools\Cron
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 14:30:00
 */
namespace Candy\Extend\Tools\Cron;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Task
{
    /** 兼容键（Store 回落时用） */
    public const KEY = Store::CACHE_KEY;

    /** 镜像回填重入标记：防止「引擎连不上 + 镜像非空」时无限自递归 */
    private static bool $refilling = false;

    /** 允许的任务类型 → 对应框架内置 handler */
    public const KINDS = [
        'http'  => 'http_callback',
        'push'  => 'push',
        'shell' => 'shell_callback',
    ];

    /**
     * 取 Manager（不存在或不可用返回 null）
     *
     * @return object|null
     */
    public static function manager(): ?object
    {
        Config::ensure();
        $class = '\Workerman\Crontab\Manager';
        if (!class_exists($class)) {
            return null;
        }
        try {
            return $class::instance();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 是否走真引擎（Manager 可用）
     *
     * @return bool
     */
    public static function engineReady(): bool
    {
        $manager = self::manager();
        if ($manager === null) {
            return false;
        }
        try {
            return (bool) $manager->isConnected();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 读全部任务
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $manager = self::manager();
        if ($manager !== null) {
            try {
                $jobs = $manager->getJobs();
                if (is_array($jobs)) {
                    $rows = [];
                    foreach ($jobs as $job) {
                        if (is_array($job)) {
                            $rows[] = self::toView($job);
                        }
                    }
                    // 合并运行摘要（Manager 自己记 runs/last_*，视图层拼上去）
                    try {
                        $summaries = $manager->getAllRunSummaries();
                        if (is_array($summaries)) {
                            foreach ($rows as $i => $row) {
                                $sum = $summaries[$row['id']] ?? null;
                                if (is_array($sum)) {
                                    // recordRun 的摘要结构：run_at / run_at_fmt / status(0 成功,1 失败) / message / duration_ms / channel
                                    $rows[$i]['last_at'] = (int) ($sum['run_at'] ?? 0);
                                    $rows[$i]['last_ok'] = ((int) ($sum['status'] ?? 1)) === 0;
                                    $rows[$i]['last_msg'] = (string) ($sum['message'] ?? '');
                                    $rows[$i]['last_ms'] = (int) ($sum['duration_ms'] ?? 0);
                                    $rows[$i]['runs'] = (int) ($sum['runs'] ?? 0);
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // 摘要拿不到不影响列表
                    }
                    // Manager 的 jobs 在 GlobalData 里（纯内存），服务重启会清空 →
                    // 用 Store 里的镜像自动回填，避免任务定义丢失。
                    if ($rows === []) {
                        $mirror = Store::get();
                        if ($mirror !== []) {
                            try {
                                // 镜像回填前先处理一次性任务：从表达式反算「今年那次」，
                                // 已过 → 停用（视为已完成），不补跑、也不让它在明年重跑。
                                // 周期性任务（含通配符）反算得 0，直接跳过；下次时间由 cron_expr + now 现算。
                                $nowTs = time();
                                $patched = false;
                                foreach ($mirror as $k => $mj) {
                                    if (!is_array($mj)) {
                                        continue;
                                    }
                                    if (empty($mj['enabled'])) {
                                        continue;
                                    }
                                    // 只有「年度级」（日+月都写死）才需要判过期；其余维度总有下一次
                                    $meta = self::classifyExpr((string) ($mj['cron_expr'] ?? ''));
                                    if (empty($meta['expire_check'])) {
                                        continue;
                                    }
                                    $onceAt = self::onceAtFromExpr((string) ($mj['cron_expr'] ?? ''));
                                    if ($onceAt > 0 && $onceAt < $nowTs) {
                                        $mirror[$k]['enabled'] = false;
                                        $patched = true;
                                    }
                                }
                                if ($patched) {
                                    Store::put($mirror);
                                }

                                // 回填只做一次：publish 失败时绝不能再递归调 all()，
                                // 否则「引擎连不上 + 镜像非空」= 无限自递归（曾把 FPM 子进程打满）
                                $jobs = [];
                                foreach ($mirror as $job) {
                                    if (is_array($job)) {
                                        $jobs[] = self::toView($job);
                                    }
                                }

                                if (self::$refilling) {
                                    return $jobs;
                                }
                                self::$refilling = true;
                                try {
                                    $res = $manager->publish($mirror, false, []);
                                    $ok = is_array($res) && !empty($res['ok']);
                                } catch (\Throwable $e) {
                                    $ok = false;
                                } finally {
                                    self::$refilling = false;
                                }

                                // 回填成功才重新读一次（这次不会为空）；失败就把镜像当视图返回，到此为止
                                if ($ok) {
                                    try {
                                        $fresh = $manager->getJobs();
                                        if (is_array($fresh) && $fresh !== []) {
                                            $out = [];
                                            foreach ($fresh as $job) {
                                                if (is_array($job)) {
                                                    $out[] = self::toView($job);
                                                }
                                            }
                                            return $out;
                                        }
                                    } catch (\Throwable $e) {
                                        // 读不到就用镜像
                                    }
                                }
                                return $jobs;
                            } catch (\Throwable $e) {
                                // 回填失败就先把镜像当视图返回
                                $out = [];
                                foreach ($mirror as $job) {
                                    if (is_array($job)) {
                                        $out[] = self::toView($job);
                                    }
                                }
                                return $out;
                            }
                        }
                    }
                    usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
                    return $rows;
                }
            } catch (\Throwable $e) {
                // 落到 Store 兜底
            }
        }

        // 兜底：旧键值存储（把旧结构也转成视图）
        $rows = [];
        foreach (Store::get() as $item) {
            if (is_array($item) && isset($item['id'])) {
                $rows[] = self::toView($item);
            }
        }
        return $rows;
    }

    /**
     * 读全部任务，**包含已停用**（仅存在于镜像里、不在调度队列中的条目）
     *
     * 用途：页面要能看到「已完成/已停用」的历史任务，也便于按 id 删除它们
     *       （否则这些条目只能留在 Cache 镜像里，用户看不到也删不掉）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allWithDisabled(): array
    {
        $rows = self::all();
        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) $row['id']] = true;
        }
        foreach (Store::get() as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $id = (string) $item['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $row = self::toView($item);
            $row['enabled'] = false;
            $row['disabled_reason'] = '已停用（不在调度队列）';
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * 按 id 找任务
     *
     * @param string $id 任务 id
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $item) {
            if ((string) $item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 新增或更新任务
     *
     * @param array<string, mixed> $input 表单数据
     * @return array{ok:bool, message:string, task:array<string,mixed>|null}
     */
    public static function save(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => '任务名称不能为空', 'task' => null];
        }

        $kind = strtolower(trim((string) ($input['kind'] ?? 'http')));
        if (!isset(self::KINDS[$kind])) {
            return ['ok' => false, 'message' => '任务类型只能是 http、push 或 shell', 'task' => null];
        }
        // shell 是高风险能力：开关关着时直接拒绝保存，不要让它先存进去再「悄悄不执行」
        if ($kind === 'shell' && !self::shellEnabled()) {
            return [
                'ok'      => false,
                'message' => '执行命令类型未启用。开启方式：Source/Inc/CrontabScheduler.inc.php 里 '
                    . "\"shell\" => ['enable' => true]（建议同时配 allow_prefix 白名单）",
                'task'    => null,
            ];
        }

        // 表达式：优先用页面传的 expr；否则由 every/at 折算
        $expr = trim((string) ($input['expr'] ?? ''));
        $every = max(0, (int) ($input['every'] ?? 0));
        $at = (int) ($input['at'] ?? 0);
        $translate = '';
        if ($expr === '') {
            [$expr, $translate] = self::exprFromSchedule($every, $at);
            if ($expr === '') {
                return ['ok' => false, 'message' => $translate ?: '无法从周期/时间折算出 cron 表达式', 'task' => null];
            }
        }
        if (!self::isValidExpr($expr)) {
            return ['ok' => false, 'message' => 'cron 表达式无效：' . $expr . '（五段或六段，如 */5 * * * * *）', 'task' => null];
        }

        $id = trim((string) ($input['id'] ?? ''));
        $enabled = !empty($input['enabled']) || (string) ($input['enabled'] ?? '') === '1';

        // 超时与重叠策略：对所有类型都生效（0 = 不设上限，但对 shell 会被 handler 封顶）
        $timeout = max(0, (int) ($input['timeout'] ?? 0));
        $overlap = strtolower(trim((string) ($input['overlap'] ?? 'skip')));
        if (!in_array($overlap, ['skip', 'kill'], true)) {
            $overlap = 'skip';
        }

        $params = [];
        if ($kind === 'http') {
            $url = trim((string) ($input['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return ['ok' => false, 'message' => '回调地址必须是 http:// 或 https:// 开头', 'task' => null];
            }
            $post = $input['post'] ?? [];
            if (is_string($post) && $post !== '') {
                $decoded = json_decode($post, true);
                $post = is_array($decoded) ? $decoded : [];
            }
            $params = [
                'url'    => $url,
                'method' => strtolower(trim((string) ($input['method'] ?? 'get'))) === 'post' ? 'post' : 'get',
                'body'   => is_array($post) ? $post : [],
            ];
        } else {
            $msg = trim((string) ($input['msg'] ?? ''));
            if ($msg === '') {
                return ['ok' => false, 'message' => '推送内容不能为空', 'task' => null];
            }
            $params = [
                'target' => trim((string) ($input['target'] ?? 'all')) ?: 'all',
                'msg'    => $msg,
                'url'    => trim((string) ($input['url'] ?? '')),
            ];
        }

        // 超时 / 重叠策略统一塞进 params（Manager 只认 params 里的自定义字段）
        $params['timeout'] = $timeout;
        $params['overlap'] = $overlap;

        $job = [
            'id'        => $id !== '' ? $id : (string) (time() . rand(100, 999)),
            'name'      => $name,
            'handler'   => self::KINDS[$kind],
            'cron_expr' => $expr,
            'params'    => $params,
            'enabled'   => $enabled,
            'singleton' => true,
        ];

        // ① 真引擎
        $manager = self::manager();
        if ($manager !== null) {
            try {
                $res = $manager->publish([$job], false, []);
                if (!empty($res['ok'])) {
                    self::mirrorPut($job);
                    $saved = self::find((string) $job['id']);
                    $msg = '已保存并交给调度器';
                    if ($translate !== '') {
                        $msg .= '（周期折算为 ' . $expr . '）';
                    }
                    return ['ok' => true, 'message' => $msg, 'task' => $saved ?? self::toView($job)];
                }
                return ['ok' => false, 'message' => '保存失败：' . (string) ($res['message'] ?? ''), 'task' => null];
            } catch (\Throwable $e) {
                // 落到 Store
            }
        }

        // ② 兜底：键值存储（能增删改查，但不会自动执行）
        $rows = Store::get();
        $found = false;
        foreach ($rows as $i => $row) {
            if ((string) $row['id'] === (string) $job['id']) {
                $rows[$i] = $job;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $rows[] = $job;
        }
        if (!Store::put($rows)) {
            return ['ok' => false, 'message' => '保存失败：存储与引擎都不可用', 'task' => null];
        }
        return ['ok' => true, 'message' => '已保存（调度引擎不可用，任务暂不会自动执行）', 'task' => self::toView($job)];
    }

    /**
     * 删除任务
     *
     * @param string $id 任务 id
     * @return array{ok:bool, message:string}
     */
    public static function remove(string $id): array
    {
        $manager = self::manager();
        if ($manager !== null) {
            try {
                $res = $manager->publish([], false, [$id]);
                if (!empty($res['ok'])) {
                    self::mirrorRemove($id);
                    return ['ok' => true, 'message' => '已删除'];
                }
            } catch (\Throwable $e) {
                // 落 Store
            }
        }

        $kept = [];
        $found = false;
        foreach (Store::get() as $row) {
            if ((string) $row['id'] === $id) {
                $found = true;
                continue;
            }
            $kept[] = $row;
        }
        if (!$found) {
            return ['ok' => false, 'message' => '任务不存在：' . $id];
        }
        return Store::put($kept) ? ['ok' => true, 'message' => '已删除'] : ['ok' => false, 'message' => '写入失败'];
    }

    /**
     * 启动 / 停止
     *
     * @param string $id      任务 id
     * @param bool   $enabled 目标状态
     * @return array{ok:bool, message:string}
     */
    public static function toggle(string $id, bool $enabled): array
    {
        $task = self::find($id);
        if ($task === null) {
            return ['ok' => false, 'message' => '任务不存在：' . $id];
        }
        $input = [
            'id'      => $id,
            'name'    => $task['name'],
            'kind'    => $task['kind'],
            'expr'    => $task['expr'],
            'enabled' => $enabled ? '1' : '0',
        ] + $task;
        $res = self::save($input);
        return ['ok' => $res['ok'], 'message' => $res['ok'] ? ($enabled ? '已启动' : '已停止') : $res['message']];
    }

    /**
     * 立即执行（交给 Manager 的锁+日志）
     *
     * @param string $id 任务 id
     * @return array{ok:bool, message:string}
     */
    public static function runOnce(string $id): array
    {
        $manager = self::manager();
        if ($manager === null) {
            return ['ok' => false, 'message' => '调度引擎不可用'];
        }
        $job = null;
        try {
            $job = $manager->findJob($id);
        } catch (\Throwable $e) {
            $job = null;
        }
        if (!is_array($job)) {
            return ['ok' => false, 'message' => '任务不存在：' . $id];
        }
        try {
            $res = $manager->runJob($job, 'manual');
            return [
                'ok'      => ((int) ($res['code'] ?? 1)) === 0,
                'message' => (string) ($res['message'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 到期任务（兼容旧接口）
     *
     * 调度已交给 `Workerman\Crontab\Server`，由 Manager 的队列驱动，
     * 这里返回空数组；保留方法是为了不破坏既有的 tick / status 调用点。
     *
     * @param int|null $now 基准时间
     * @return array<int, array<string, mixed>>
     */
    public static function due(?int $now = null): array
    {
        $now = $now ?? time();
        $due = [];
        foreach (self::all() as $task) {
            if (empty($task['enabled'])) {
                continue;
            }
            if (self::matchesNow((string) ($task['expr'] ?? ''), $now)) {
                $due[] = $task;
            }
        }
        return $due;
    }

    /**
     * 表达式在 $now 这一秒是否命中
     *
     * 与 `Parser::nextRunTimestamp($expr, $now - 1) === $now` 等价，但**不迭代**：
     * 六段表达式命中秒级判定，`* * * * * *` 这种全通配也不会退化成"扫一年"。
     * 五段表达式固定在秒 = 0 触发（与 Workerman 的语义一致）。
     *
     * @param string $expr cron 表达式
     * @param int    $now  时刻
     * @return bool
     */
    public static function matchesNow(string $expr, int $now): bool
    {
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        $n = count($parts);

        if ($n === 6) {
            $sec = $parts[0];
            $min = $parts[1];
            $hour = $parts[2];
            $day = $parts[3];
            $mon = $parts[4];
            $week = $parts[5];
        } elseif ($n === 5) {
            // 五段没有秒字段：等价于六段第 0 位写死 "0"（整分触发）
            $sec = '0';
            $min = $parts[0];
            $hour = $parts[1];
            $day = $parts[2];
            $mon = $parts[3];
            $week = $parts[4];
        } else {
            return false;
        }

        return self::segMatch((string) $sec, (int) date('s', $now), 0, 59)
            && self::segMatch((string) $min, (int) date('i', $now), 0, 59)
            && self::segMatch((string) $hour, (int) date('G', $now), 0, 23)
            && self::segMatch((string) $day, (int) date('j', $now), 1, 31)
            && self::segMatch((string) $mon, (int) date('n', $now), 1, 12)
            && self::segMatch((string) $week, (int) date('w', $now), 0, 6);
    }

    /**
     * 单段（秒/分/时/日/月/周）是否命中
     *
     * 支持：`*`、`N`、`a-b`、`a-b/s`、`*\/s`、`N\/s`、`a,b,c`（含以上各形式的组合）
     *
     * @param string $seg   分段
     * @param int    $value 当前值
     * @param int    $min   该段最小值
     * @param int    $max   该段最大值
     * @return bool
     */
    public static function segMatch(string $seg, int $value, int $min, int $max): bool
    {
        $seg = trim($seg);
        if ($seg === '') {
            return false;
        }

        foreach (explode(',', $seg) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            // 先拆步长：`*/7`、`10-20/5`、`30/10`
            $step = 1;
            $slash = strrpos($item, '/');
            if ($slash !== false) {
                $step = (int) substr($item, $slash + 1);
                if ($step <= 0) {
                    $step = 1;
                }
                $item = trim(substr($item, 0, $slash));
            }

            // 再定区间：`*` / `a-b` / `a`
            if ($item === '*') {
                $from = $min;
                $to = $max;
            } elseif (strpos($item, '-') !== false) {
                $pair = explode('-', $item, 2);
                $from = (int) $pair[0];
                $to = (int) $pair[1];
            } else {
                $from = (int) $item;
                // 单值不带步长就是精确匹配；带步长（如 30/10）表示「从 30 起每 10 一步到上限」
                $to = $step > 1 ? $max : $from;
            }

            if ($value < $from || $value > $to) {
                continue;
            }

            // 区间起点是 $from，因此步长从 $from 起算
            if ($step > 1 && (($value - $from) % $step) !== 0) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * 周期秒 / 指定时间 → cron 表达式（六段优先，精确到秒）
     *
     * @param int $every 周期秒（0 表示一次性）
     * @param int $at    一次性任务的执行时间戳
     * @return array{0:string, 1:string} [表达式, 说明]
     */
    public static function exprFromSchedule(int $every, int $at): array
    {
        if ($every > 0) {
            if ($every < 60) {
                return ['*/' . max(1, $every) . ' * * * * *', '按秒'];
            }
            if ($every % 3600 === 0 && $every / 3600 <= 23) {
                return ['0 0 */' . (int) ($every / 3600) . ' * * *', '按小时'];
            }
            if ($every % 60 === 0 && $every / 60 <= 59) {
                return ['0 */' . (int) ($every / 60) . ' * * * *', '按分钟'];
            }
            return ['', '周期 ' . $every . ' 秒无法折算为表达式，请直接填 cron 表达式'];
        }

        if ($at > 0) {
            $t = getdate($at);
            return [
                $t['seconds'] . ' ' . $t['minutes'] . ' ' . $t['hours'] . ' ' . $t['mday'] . ' ' . $t['mon'] . ' *',
                '一次性（同月同日同时刻，年维度会重复，建议配合停用）',
            ];
        }

        return ['', '请填写 cron 表达式，或给出周期/执行时间'];
    }

    /**
     * 判定表达式的周期维度（六段：秒 分 时 日 月 周）
     *
     * 维度决定「重启后要不要做过期判定」——只有年度级才需要：
     *   年度级（日、月都写死）   → 一年只跑一次，要判「今年那次过没过」
     *   月度（日写死、月通配）   → 每月都有下一次，不用判
     *   周度（周写死）           → 每周都有下一次，不用判
     *   日/时/分钟/秒级          → 天天/时时有下一次，不用判
     *
     * `next_run_at` 一律交给 `Workerman\Crontab\Parser::nextRunTimestamp()` 算，
     * 不要为每个维度各写一套「下一次」算法。
     *
     * @param string $expr cron 表达式
     * @return array{kind:string, expire_check:bool}
     */
    public static function classifyExpr(string $expr): array
    {
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($parts) !== 6) {
            return ['kind' => 'invalid', 'expire_check' => false];
        }
        $fixed = static function (string $seg): bool {
            return $seg !== '*' && strpos($seg, '*/') !== 0;
        };
        [$sec, $min, $hour, $day, $mon, $week] = array_pad($parts, 6, '*');

        $d = $fixed((string) $day);
        $m = $fixed((string) $mon);
        $w = $fixed((string) $week);

        if ($d && $m) {
            return ['kind' => 'once_yearly', 'expire_check' => true];      // 日+月都写死 = 一年一次
        }
        if ($d && !$m) {
            return ['kind' => 'monthly', 'expire_check' => false];         // 每月某日
        }
        if (!$d && $w) {
            return ['kind' => 'weekly', 'expire_check' => false];          // 每周某天
        }
        if (!$d && !$m && $fixed((string) $hour)) {
            return ['kind' => 'daily', 'expire_check' => false];           // 每天某时
        }
        if (!$d && !$m && !$fixed((string) $hour)) {
            return ['kind' => 'interval', 'expire_check' => false];        // 分钟/秒级
        }
        return ['kind' => 'custom', 'expire_check' => false];
    }

    /**
     * 从「指定日期时刻」的表达式反算**今年那一次**的时间戳
     *
     * 只认「秒 分 时 日 月 都写死数字」的形状（即 `at` 折算出的一次性任务形状），
     * 其余（含通配符）一律返回 0 表示「这是周期性任务，不该按一次性处理」。
     *
     * 用途：判断一次性任务「今年那次」过没过——
     *   过了  → 调用方把它停用（已完成），而不是傻等明年；
     *   没过  → 什么都不用做，Crontab 的 nextRunTimestamp 自然会等到那个时刻。
     *
     * ⚠️ 闰年 2 月 29 日：今年没有这天时 mktime 会滚到 3 月 1 日，必须回读校验后返回 0。
     *
     * @param string $expr cron 表达式
     * @return int 时间戳；不是「一次性形状」返回 0
     */
    public static function onceAtFromExpr(string $expr): int
    {
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($parts) !== 6) {
            return 0;
        }
        // 前五段（秒 分 时 日 月）必须都是纯数字，第六段（周）允许 *
        for ($i = 0; $i < 5; $i++) {
            if (!ctype_digit((string) $parts[$i])) {
                return 0;
            }
        }

        [$sec, $min, $hour, $day, $mon] = array_map('intval', array_slice($parts, 0, 5));
        if ($mon < 1 || $mon > 12 || $day < 1 || $day > 31) {
            return 0;
        }

        $ts = mktime($hour, $min, $sec, $mon, $day, (int) date('Y'));
        if ($ts === false) {
            return 0;
        }
        // 回读校验：把「2/29 → 3/1」这类滚动挡掉
        if ((int) date('n', $ts) !== $mon || (int) date('j', $ts) !== $day) {
            return 0;
        }
        return (int) $ts;
    }

    /**
     * 表达式是否合法
     *
     * @param string $expr cron 表达式
     * @return bool
     */
    public static function isValidExpr(string $expr): bool
    {
        $class = '\Workerman\Crontab\Parser';
        if (!class_exists($class)) {
            // 没装 Parser 时做最宽松的形状检查（5 或 6 段）
            $n = count(preg_split('/\s+/', trim($expr)) ?: []);
            return $n === 5 || $n === 6;
        }
        try {
            return (bool) (new $class())->isValid($expr);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 写一份 jobs 到 Store（GlobalData 丢失后的恢复来源）
     *
     * @param array<string, mixed> $job job
     * @return void
     */
    protected static function mirrorPut(array $job): void
    {
        try {
            $mirror = [];
            foreach (Store::get() as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $mirror[(string) $item['id']] = $item;
                }
            }
            $mirror[(string) $job['id']] = $job;
            Store::put(array_values($mirror));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 从 Store 镜像里删
     *
     * @param string $id 任务 id
     * @return void
     */
    protected static function mirrorRemove(string $id): void
    {
        try {
            $kept = [];
            foreach (Store::get() as $item) {
                if (is_array($item) && (string) ($item['id'] ?? '') === $id) {
                    continue;
                }
                $kept[] = $item;
            }
            Store::put($kept);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 引擎/存储内部结构 → 对外视图
     *
     * @param array<string, mixed> $job job 或旧结构
     * @return array<string, mixed>
     */
    public static function toView(array $job): array
    {
        // 已经是旧视图（有 kind 字段）→ 原样补齐默认值
        if (isset($job['kind'])) {
            return array_merge([
                'id'        => (string) ($job['id'] ?? ''),
                'name'      => (string) ($job['name'] ?? ''),
                'kind'      => (string) $job['kind'],
                'expr'      => (string) ($job['expr'] ?? ''),
                'enabled'   => (bool) ($job['enabled'] ?? false),
                'url'       => (string) ($job['url'] ?? ''),
                'method'    => (string) ($job['method'] ?? 'get'),
                'post'      => (array) ($job['post'] ?? []),
                'target'    => (string) ($job['target'] ?? 'all'),
                'msg'       => (string) ($job['msg'] ?? ''),
                'runs'        => (int) ($job['runs'] ?? 0),
                'last_at'     => (int) ($job['last_at'] ?? 0),
                'last_ok'     => $job['last_ok'] ?? null,
                'last_msg'    => (string) ($job['last_msg'] ?? ''),
                'last_ms'     => (int) ($job['last_ms'] ?? 0),
                'last_status' => (string) ($job['last_status'] ?? ''),
                'timeout'     => (int) ($job['timeout'] ?? 0),
                'overlap'     => (string) ($job['overlap'] ?? 'skip'),
                'command'     => (string) ($job['command'] ?? ''),
                'cwd'         => (string) ($job['cwd'] ?? ''),
            ], $job);
        }

        $handler = (string) ($job['handler'] ?? '');
        $kind = 'http';
        foreach (self::KINDS as $k => $h) {
            if ($h === $handler) {
                $kind = $k;
                break;
            }
        }
        $params = $job['params'] ?? [];
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($params)) {
            $params = [];
        }

        return [
            'id'          => (string) ($job['id'] ?? ''),
            'name'        => (string) ($job['name'] ?? ''),
            'kind'        => $kind,
            'expr'        => (string) ($job['cron_expr'] ?? ''),
            'enabled'     => (bool) ($job['enabled'] ?? false),
            'url'         => (string) ($params['url'] ?? ''),
            'method'      => (string) ($params['method'] ?? 'get'),
            'post'        => (array) ($params['body'] ?? []),
            'target'      => (string) ($params['target'] ?? 'all'),
            'msg'         => (string) ($params['msg'] ?? ''),
            'command'     => (string) ($params['command'] ?? ''),
            'cwd'         => (string) ($params['cwd'] ?? ''),
            'timeout'     => (int) ($params['timeout'] ?? 0),
            'overlap'     => (string) ($params['overlap'] ?? 'skip'),
            'shell_ready' => self::shellEnabled(),
            'runs'        => (int) ($job['runs'] ?? 0),
            'last_at'     => (int) ($job['last_at'] ?? 0),
            'last_ok'     => $job['last_ok'] ?? null,
            'last_msg'    => (string) ($job['last_msg'] ?? ''),
            'last_ms'     => (int) ($job['last_ms'] ?? 0),
            'last_status' => (string) ($job['last_status'] ?? ''),
        ];
    }

    /**
     * 兼容旧调用（Manager 自己记运行日志，这里不再单独写状态）
     *
     * @param string   $id  任务 id
     * @param bool     $ok  是否成功
     * @param string   $msg 说明
     * @param int|null $now 时刻
     * @return void
     */
    public static function markRun(
        string $id,
        bool $ok,
        string $msg = '',
        ?int $now = null,
        float|int $durationMs = 0,
        string $status = ''
    ): void {
        if ($id === '') {
            return;
        }
        // 以前这里在 engineReady() 时直接 return，理由是「运行统计交给 Manager 的 recordRun」。
        // 但 recordRun 记的是 GlobalData 里的 run_summary，而 tick / 手动执行 / 外部 cron
        // 这几条路径根本不经过 Manager → 页面上永远显示「从未运行」。
        // 所以改成两边都写：Manager 有记录时它自己写；这里写镜像，保证任何路径都有据可查。
        $rows = Store::get();
        $found = false;
        foreach ($rows as $i => $row) {
            if ((string) ($row['id'] ?? '') !== $id) {
                continue;
            }
            $rows[$i]['runs'] = (int) ($row['runs'] ?? 0) + 1;
            $rows[$i]['last_at'] = $now ?? time();
            $rows[$i]['last_ok'] = $ok;
            $rows[$i]['last_msg'] = self::clipMsg($msg);
            $rows[$i]['last_ms'] = (int) round((float) $durationMs);
            $rows[$i]['last_status'] = $status !== '' ? $status : ($ok ? 'ok' : 'failed');
            $found = true;
            break;
        }
        if ($found) {
            Store::put($rows);
        }
    }

    /**
     * 截断消息（超长输出不塞进存储）
     *
     * @param string $msg 消息
     * @return string
     */
    protected static function clipMsg(string $msg): string
    {
        if (strlen($msg) > 800) {
            return substr($msg, 0, 800) . '…';
        }
        return $msg;
    }

    /**
     * shell 类型是否已启用（供保存校验与视图判断）
     *
     * @return bool
     */
    public static function shellEnabled(): bool
    {
        $class = '\Candy\Extend\Tools\Cron\Handler\ShellCallback';
        if (!class_exists($class)) {
            return false;
        }
        try {
            return (bool) $class::enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
