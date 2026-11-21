<?php
/***
 * 框架内置端点：计划任务管理（/sys/crontab）
 *
 * 一个端点两种形态：
 *   浏览器（Accept: text/html）或 ?do=page  → 可视化管理页（增删改查 / 启停 / 立即执行）
 *   其余                                   → JSON 接口
 *
 * 动作（?do=）：
 *   page     管理页 HTML
 *   tasks    任务列表 JSON
 *   save     新增/更新（带 id 为更新）：name/kind/every/at/url/method/post/target/msg/enabled
 *   delete   删除：id
 *   toggle   启停：id + enabled=1|0
 *   runonce  立即执行一次（不影响原有计划）：id
 *   tick     执行所有到期任务（给系统 cron 打）
 *   due      列出到期任务（只读）
 *   status   运行环境自省
 *   queue    旧时间戳队列视图
 *
 * 所有动作都要求令牌（`Guard::token`）；浏览器未认证会 302 到 `/sys/auth`。
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 12:55:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

class Crontab
{
    /**
     * 入口
     *
     * @return string JSON 或 HTML
     */
    public static function handle(): string
    {
        Guard::token('sys/crontab');

        $do = strtolower(trim((string) ($_GET['do'] ?? '')));
        if ($do === '') {
            $do = self::wantsHtml() ? 'page' : 'tasks';
        }

        try {
            switch ($do) {
                case 'page':
                    return self::page();
                case 'echo':
                    $raw = function_exists('R') ? (string) R()->rawBody() : (string) @file_get_contents('php://input');
                    return self::json(['ok' => true, 'data' => [
                        'bag'      => self::bag(),
                        'post'     => array_keys((array) $_POST),
                        'get'      => array_keys((array) $_GET),
                        'put'      => function_exists('I') ? I('put') : null,
                        'raw_head' => substr($raw, 0, 300),
                        'ctype'    => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
                    ]]);
                case 'manager':
                    // 调度引擎内部状态（排障用）：queue/version/run 摘要
                    $m = \Candy\Extend\Tools\Cron\Task::manager();
                    if ($m === null) {
                        return self::json(['ok' => false, 'message' => 'Manager 不可用']);
                    }
                    $out = ['connected' => false, 'version' => 0, 'jobs' => 0, 'status' => [], 'summaries' => []];
                    try {
                        $out['connected'] = (bool) $m->isConnected();
                        $out['version']   = (int) $m->getVersion();
                        $out['jobs']      = count((array) $m->getJobs());
                        $out['status']    = (array) $m->status();
                        $out['summaries'] = array_keys((array) $m->getAllRunSummaries());
                        // 原始队列/键名：看槽位时间戳是否在未来、是否被 drain
                        $gd = \Candy\Extend\Tools\Service\GlobalData::make();
                        if ($gd !== null) {
                            $prefix = (string) (\Candy\Extend\Tools\Cron\Config::snapshot()['prefix'] ?? 'candy_crontab_');
                            $out['prefix'] = $prefix;
                            $out['queue']  = $gd->{$prefix . 'queue'} ?? [];
                            $out['now']    = time();
                            $out['summary_keys'] = array_keys((array) ($gd->{$prefix . 'run_summary'} ?? []));
                        }
                    } catch (\Throwable $e) {
                        return self::json(['ok' => false, 'message' => $e->getMessage()]);
                    }
                    return self::json(['ok' => true, 'data' => $out]);
                case 'tasks':
                    $payload = self::actTasks();
                    break;
                case 'save':
                    $payload = self::actSave();
                    break;
                case 'delete':
                    $payload = self::actDelete();
                    break;
                case 'toggle':
                    $payload = self::actToggle();
                    break;
                case 'runonce':
                    $payload = self::actRunOnce();
                    break;
                case 'tick':
                    $payload = self::actTick();
                    break;
                case 'due':
                    $payload = self::actDue();
                    break;
                case 'status':
                    $payload = self::actStatus();
                    break;
                case 'queue':
                    $payload = self::actQueue();
                    break;
                default:
                    return self::json(['ok' => false, 'message' => '不支持的动作：' . $do]);
            }
        } catch (\Throwable $e) {
            return self::json(['ok' => false, 'message' => $e->getMessage(), 'type' => get_class($e)]);
        }

        // 注意：不能用 ['ok'=>true] + $payload（左侧优先会把失败掩成成功）
        return self::json(array_merge(['ok' => true], $payload));
    }

    /**
     * 是否要 HTML
     *
     * @return bool
     */
    protected static function wantsHtml(): bool
    {
        $view = strtolower(trim((string) ($_GET['view'] ?? '')));
        if ($view === 'json') {
            return false;
        }
        if ($view === 'html') {
            return true;
        }
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    /**
     * 任务列表
     *
     * @return array<string, mixed>
     */
    protected static function actTasks(): array
    {
        return ['data' => [
            'now'   => time(),
            'tasks' => !empty(\I('get.include_disabled'))
                ? \Candy\Extend\Tools\Cron\Task::allWithDisabled()
                : \Candy\Extend\Tools\Cron\Task::all(),
            'gate'  => self::gateInfo(),
        ]];
    }

    /**
     * 保存（新增 / 更新）
     *
     * @return array<string, mixed>
     */
    protected static function actSave(): array
    {
        $input = self::input();
        $res = \Candy\Extend\Tools\Cron\Task::save($input);
        return ['ok' => $res['ok'], 'message' => $res['message'], 'task' => $res['task']];
    }

    /**
     * 删除
     *
     * @return array<string, mixed>
     */
    protected static function actDelete(): array
    {
        $id = self::param('id');
        if ($id === '') {
            return ['ok' => false, 'message' => '缺少 id'];
        }
        $res = \Candy\Extend\Tools\Cron\Task::remove($id);
        return ['ok' => $res['ok'], 'message' => $res['message']];
    }

    /**
     * 启停
     *
     * @return array<string, mixed>
     */
    protected static function actToggle(): array
    {
        $id = self::param('id');
        if ($id === '') {
            return ['ok' => false, 'message' => '缺少 id'];
        }
        $res = \Candy\Extend\Tools\Cron\Task::toggle($id, self::bool(self::param('enabled', '0')));
        return ['ok' => $res['ok'], 'message' => $res['message']];
    }

    /**
     * 立即执行一次（不改变原有计划时间）
     *
     * @return array<string, mixed>
     */
    protected static function actRunOnce(): array
    {
        $id = self::param('id');
        $task = $id === '' ? null : \Candy\Extend\Tools\Cron\Task::find($id);
        if ($task === null) {
            return ['ok' => false, 'message' => '任务不存在：' . $id];
        }
        $res = self::execute($task);
        return ['ok' => $res['ok'], 'message' => $res['message'], 'id' => $id, 'manual' => true];
    }

    /**
     * 执行所有到期任务
     *
     * @return array<string, mixed>
     */
    protected static function actTick(): array
    {
        $now = time();
        $results = [];
        foreach (\Candy\Extend\Tools\Cron\Task::due($now) as $task) {
            $res = self::execute($task, $now);
            $results[] = ['id' => $task['id'], 'name' => $task['name']] + $res;
        }

        // 旧时间戳队列也顺带处理（保持向后兼容）
        $legacy = [];
        if (class_exists('\Candy\Extend\Tools\Cron\Server')) {
            try {
                $legacy = \Candy\Extend\Tools\Cron\Server::tick($now);
            } catch (\Throwable $e) {
                $legacy = ['error' => $e->getMessage()];
            }
        }

        return ['data' => ['at' => date('Y-m-d H:i:s', $now), 'ran' => count($results), 'results' => $results, 'legacy' => $legacy]];
    }

    /**
     * 到期任务（只读）
     *
     * @return array<string, mixed>
     */
    protected static function actDue(): array
    {
        $now = time();
        return ['data' => ['now' => $now, 'due' => \Candy\Extend\Tools\Cron\Task::due($now)]];
    }

    /**
     * 旧队列视图
     *
     * @return array<string, mixed>
     */
    protected static function actQueue(): array
    {
        $queue = \Candy\Extend\Tools\Cron\Queue;
        if (!class_exists($queue)) {
            return ['ok' => false, 'message' => 'Candy\\Extend\\Tools\\Cron\\Queue 未加载'];
        }
        $now = time();
        if (self::bool(self::param('demo', '0'))) {
            $queue::push($now + 1, 'all', 'demo message @ ' . date('H:i:s'));
        }
        $tick = self::bool(self::param('tick', '0')) ? \Candy\Extend\Tools\Cron\Server::tick($now) : null;
        return ['data' => ['size' => $queue::size(), 'due' => $queue::due($now), 'tick' => $tick]];
    }

    /**
     * 运行环境自省
     *
     * @return array<string, mixed>
     */
    protected static function actStatus(): array
    {
        $gate = '\\Candy\\Extend\\Process\\Gate';
        $php = '\\Candy\\Extend\\Process\\PhpBinary';
        $runner = '\\Candy\\Extend\\Process\\Runner';

        return ['data' => [
            'php_version'   => PHP_VERSION,
            'sapi'          => PHP_SAPI,
            'process_gate'  => class_exists($gate) ? $gate::info() : ['enabled' => null, 'source' => '类不存在'],
            'php_binary'    => class_exists($php) ? $php::info() : null,
            'proc_open'     => class_exists($runner) ? $runner::available() : null,
            'proc_open_why' => class_exists($runner) ? $runner::unavailableReason() : null,
            'task_total'    => count(\Candy\Extend\Tools\Cron\Task::all()),
            'task_due'      => count(\Candy\Extend\Tools\Cron\Task::due()),
            'task_engine'   => \Candy\Extend\Tools\Cron\Task::engineReady() ? 'Workerman\Crontab\Manager（GlobalData + Server）' : 'Store 兜底（不会自动执行）',
            'task_handlers' => array_keys(\Candy\Extend\Tools\Cron\Config::builtinHandlers()),
            'queue'         => class_exists('\Candy\Extend\Tools\Cron\Queue') ? \Candy\Extend\Tools\Cron\Queue::size() : null,
        ]];
    }

    /**
     * 执行一条任务并记录结果（委托 Executor，端点和常驻服务共用）
     *
     * @param array<string, mixed> $task 任务
     * @param int|null             $now  执行时刻
     * @return array{ok:bool, message:string}
     */
    protected static function execute(array $task, ?int $now = null): array
    {
        // 执行逻辑统一放在 Executor，端点 tick 与常驻 cron 服务共用同一份，避免两处漂移
        return \Candy\Extend\Tools\Cron\Executor::run($task, $now);
    }

    protected static function gateInfo(): array
    {
        $gate = '\\Candy\\Extend\\Process\\Gate';
        return class_exists($gate) ? $gate::info() : ['enabled' => null, 'source' => '类不存在'];
    }

    /**
     * 取参数（先 POST 后 GET）
     *
     * @param string $key     键
     * @param string $default 默认
     * @return string
     */
    protected static function param(string $key, string $default = ''): string
    {
        $bag = self::bag();
        if (isset($bag[$key]) && is_scalar($bag[$key])) {
            return trim((string) $bag[$key]);
        }
        return $default;
    }

    /**
     * 合并三处输入：框架 I('put')（JSON 或 urlencoded 请求体）> $_POST > $_GET
     *
     * 必须走 I('put')：本框架会把请求体先交给它解析，单看 $_POST 可能拿不到值。
     *
     * @return array<string, mixed>
     */
    protected static function bag(): array
    {
        $bag = [];
        if (function_exists('I')) {
            try {
                $put = I('put');
                if (is_array($put)) {
                    $bag = $put;
                }
            } catch (\Throwable $e) {
                $bag = [];
            }
        }
        foreach ((array) $_POST as $k => $v) {
            $bag[$k] = $v;
        }
        foreach ((array) $_GET as $k => $v) {
            $bag[$k] = $v;
        }
        return $bag;
    }

    /**
     * 取整份输入
     *
     * @return array<string, mixed>
     */
    protected static function input(): array
    {
        $data = [];
        foreach (self::bag() as $k => $v) {
            if ($k === 'do' || $k === 'token' || $k === 'view') {
                continue;
            }
            $data[$k] = $v;
        }
        return $data;
    }

    /**
     * 宽松布尔
     *
     * @param mixed $value 原值
     * @return bool
     */
    protected static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * 统一 JSON
     *
     * @param array<string, mixed> $data 数据
     * @return string
     */
    protected static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * 管理页
     *
     * @return string
     */
    protected static function page(): string
    {
        $tpl = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>计划任务管理 · /sys/crontab</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:26px 30px 70px;background:#f1f5f9;color:#0f172a;
 font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
h1{margin:0 0 4px;font-size:20px;font-weight:650}
.sub{color:#64748b;font-size:12.5px;margin-bottom:16px}
.sub code{background:#e2e8f0;padding:1px 5px;border-radius:4px;font-size:11.5px}
.bar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.btn{padding:7px 14px;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:8px;
 font-size:13px;font-weight:600;cursor:pointer}
.btn:hover{background:#1d4ed8}
.btn.ghost{background:#fff;color:#334155;border-color:#cbd5e1}
.btn.ghost:hover{background:#f8fafc}
.btn.mini{padding:3px 9px;font-size:12px;font-weight:500;border-radius:6px}
.btn.red{background:#fff;color:#b91c1c;border-color:#fecaca}
.btn.red:hover{background:#fef2f2}
.btn:disabled{opacity:.5;cursor:not-allowed}
.sp{flex:1}
.hint{color:#94a3b8;font-size:12px}
.wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:auto;
 box-shadow:0 1px 2px rgba(15,23,42,.04)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f8fafc;color:#64748b;font-weight:600;text-align:left;padding:10px 12px;
 border-bottom:1px solid #e2e8f0;white-space:nowrap;font-size:12px}
td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:#f5f8ff}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}
.muted{color:#64748b}
.c{text-align:center;white-space:nowrap}
.badge{display:inline-block;border-radius:999px;padding:1px 9px;font-size:11px;font-weight:600;white-space:nowrap}
.b-on{background:#ecfdf5;color:#059669}
.b-off{background:#f1f5f9;color:#64748b}
.b-http{background:#eff6ff;color:#2563eb}
.b-push{background:#f5f3ff;color:#7c3aed}
.b-shell{background:#fff7ed;color:#c2410c}
.ok{color:#059669;font-weight:600}
.no{color:#dc2626;font-weight:600}
.empty{padding:44px;text-align:center;color:#94a3b8}
.mask{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;
 justify-content:center;padding:20px;z-index:50}
.mask.show{display:flex}
.dlg{width:100%;max-width:560px;background:#fff;border-radius:14px;padding:22px 22px 18px;
 box-shadow:0 20px 50px rgba(15,23,42,.25);max-height:90vh;overflow:auto}
.dlg h2{margin:0 0 14px;font-size:16px;font-weight:650}
.f{margin-bottom:12px}
.f label{display:block;font-size:12.5px;color:#475569;margin-bottom:4px;font-weight:600}
.f input,.f select,.f textarea{width:100%;padding:8px 11px;border:1px solid #cbd5e1;border-radius:8px;
 font-size:13px;outline:none;background:#fff;font-family:inherit}
.f input:focus,.f select:focus,.f textarea:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.14)}
.f textarea{min-height:64px;resize:vertical;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}
.f .tip{color:#94a3b8;font-size:11.5px;margin-top:3px}
.row2{display:flex;gap:10px}
.row2>*{flex:1}
.acts{display:flex;gap:6px;justify-content:flex-end;margin-top:16px}
.svcbox{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.svc{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;min-width:260px;
 box-shadow:0 1px 2px rgba(15,23,42,.04);flex:1 1 280px}
.svc .t{display:flex;align-items:center;gap:8px;font-weight:650;font-size:13.5px}
.svc .d{color:#64748b;font-size:11.5px;margin-top:3px;line-height:1.5}
.svc .a{display:flex;gap:6px;margin-top:9px;flex-wrap:wrap}
.svc .b{display:inline-block;border-radius:999px;padding:1px 9px;font-size:11px;font-weight:600}
.svc .b.on{background:#ecfdf5;color:#059669}
.svc .b.off{background:#f1f5f9;color:#64748b}
.svc .pid{color:#94a3b8;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px}
#toast{position:fixed;right:22px;bottom:22px;padding:10px 16px;border-radius:9px;font-size:13px;
 background:#0f172a;color:#fff;opacity:0;transition:opacity .25s;pointer-events:none;z-index:60}
#toast.show{opacity:.95}

.dlg{width:calc(100% - 40px);max-width:660px;max-height:88vh;overflow-y:auto}
.grp{border:1px solid #e2e8f0;border-radius:10px;padding:13px 15px 15px;margin-bottom:12px;background:#fff}
.grp-t{font-size:11.5px;font-weight:700;color:#64748b;letter-spacing:.06em;margin-bottom:11px}
.grp .f:last-child{margin-bottom:0}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}
.row2 .f{margin-bottom:0}
.row2 + .f,.f + .row2,.row2 + .row2{margin-top:12px}
.cad-hint{color:#64748b;font-size:12px;margin-top:8px;min-height:16px}
.cad-hint b{color:#2563eb;font-weight:600}
.expr-mono{font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.02em}
@media(max-width:560px){.row2{grid-template-columns:1fr}}
__GUIDE_CSS__
</style>
</head>
<body>
<h1>计划任务管理</h1>
<div class="sub">任务到点自动执行，执行结果可查（由「计划任务调度」服务驱动） ·
 生成于 __TIME__ · <a href="?do=tasks&view=json" style="color:#2563eb;text-decoration:none">查看 JSON</a></div>

<div id="svcspawn"></div>
<div id="guide"></div>
<div class="svcbox" id="svcbox"></div>
<div class="bar">
  <button class="btn" id="btnAdd">＋ 新建任务</button>
  <button class="btn ghost" id="btnTick">立即执行到期任务</button>
  <button class="btn ghost" id="btnRefresh">刷新</button>
  <span class="sp"></span>
  <span class="hint" id="stat">加载中…</span>
</div>

<div class="wrap">
<table>
<thead><tr>
<th>名称</th><th>类型</th><th>执行周期</th><th>cron 表达式</th><th class="c">状态</th><th>上次结果</th><th class="c">耗时</th><th class="c">操作</th>
</tr></thead>
<tbody id="tb"></tbody>
</table>
</div>

<div class="mask" id="mask">
  <div class="dlg">
    <h2 id="dlgTitle">新建任务</h2>
    <input type="hidden" id="f_id">
    <section class="grp">
      <div class="grp-t">基本信息</div>
      <div class="row2">
        <div class="f"><label>任务名称</label><input id="f_name" placeholder="例如：每日经营数据汇总"></div>
        <div class="f"><label>任务类型</label>
          <select id="f_kind"><option value="http">HTTP 回调</option><option value="push">推送消息</option><option value="shell">执行命令</option></select>
        </div>
      </div>
    </section>

    <section class="grp">
      <div class="grp-t">执行周期</div>
      <div class="row2">
        <div class="f"><label>周期类型</label>
          <select id="f_cadence" onchange="cadenceChanged()">
            <option value="minute">每分钟</option>
            <option value="nmin">每 N 分钟</option>
            <option value="hour">每小时</option>
            <option value="day">每天</option>
            <option value="week">每周</option>
            <option value="month">每月</option>
            <option value="once">一次性（指定日期时刻）</option>
            <option value="expr">自定义表达式</option>
          </select>
        </div>
        <div class="f" id="rowNmin" style="display:none"><label>间隔分钟数</label><input id="f_nmin" type="number" min="1" max="59" value="5" oninput="cadenceChanged()"></div>
        <div class="f" id="rowTime" style="display:none"><label>时刻（时:分）</label><input id="f_time" type="time" value="09:00" oninput="cadenceChanged()"></div>
        <div class="f" id="rowWeek" style="display:none"><label>星期几</label><select id="f_week" onchange="cadenceChanged()"><option value="1">周一</option><option value="2">周二</option><option value="3">周三</option><option value="4">周四</option><option value="5">周五</option><option value="6">周六</option><option value="0">周日</option></select></div>
        <div class="f" id="rowMonthDay" style="display:none"><label>每月几号</label><input id="f_mday" type="number" min="1" max="31" value="1" oninput="cadenceChanged()"></div>
        <div class="f" id="rowOnce" style="display:none"><label>执行时刻</label><input id="f_once" type="datetime-local" oninput="cadenceChanged()"></div>
      </div>
      <div class="f" style="margin-top:12px">
        <label>cron 表达式（秒 分 时 日 月 周）</label>
        <input id="f_expr" class="expr-mono" placeholder="0 0 9 * * *" oninput="cadenceHintOnly()">
        <div class="cad-hint" id="cadHint">选择周期类型会自动生成，也可以直接手填</div>
      </div>
    </section>

    <section class="grp">
      <div class="grp-t">执行控制</div>
      <div class="row2">
        <div class="f"><label>超时（秒）</label>
          <input id="f_timeout" type="number" min="0" placeholder="0 = 不限制">
          <div class="tip">超过后按下面的策略处理；命令任务另受配置里的 max_timeout 封顶</div></div>
        <div class="f"><label>上次没跑完时</label>
          <select id="f_overlap">
            <option value="skip">跳过本次（默认）</option>
            <option value="kill">终止上一次再执行</option>
          </select>
          <div class="tip">防止同一条任务被叠着执行多次</div></div>
      </div>
    </section>

    <section class="grp" id="grpHttp">
      <div class="grp-t">HTTP 回调</div>
      <div class="f"><label>回调地址</label><input id="f_url" placeholder="https://example.com/hook"></div>
      <div class="row2" style="margin-top:12px">
        <div class="f"><label>请求方法</label>
          <select id="f_method"><option value="get">GET</option><option value="post">POST</option></select>
        </div>
        <div class="f"><label>POST 数据（JSON，可空）</label><input id="f_post" placeholder='{"a":1}'></div>
      </div>
    </section>

    <section class="grp" id="grpPush" style="display:none">
      <div class="grp-t">推送内容</div>
      <div class="f"><label>推送目标</label><input id="f_target" value="all" placeholder="all 表示所有人，或填用户标识"></div>
      <div class="f" style="margin-top:12px"><label>消息内容</label><textarea id="f_msg" placeholder="要推送的消息内容"></textarea></div>
    </section>

    <section class="grp" id="grpShell" style="display:none">
      <div class="grp-t">执行命令</div>
      <div class="f"><label>命令</label>
        <input id="f_command" class="expr-mono" placeholder="例如：/usr/bin/php /www/wwwroot/xxx/script.php">
        <div class="tip" id="shellTip">经由 /bin/sh -c 执行，支持管道与重定向。
          <b>此能力默认关闭</b>，需在源配置 CrontabScheduler.inc.php 打开 shell.enable 并建议配置 allow_prefix 白名单。</div>
      </div>
      <div class="f" style="margin-top:12px"><label>工作目录（可空）</label>
        <input id="f_cwd" placeholder="留空 = 项目根目录"></div>
    </section>

    <section class="grp">
      <div class="f" style="margin-bottom:0"><label style="font-weight:500"><input type="checkbox" id="f_enabled" checked style="width:auto;margin-right:8px;vertical-align:-2px">创建后立即启用</label></div>
    </section>
    <div class="acts">
      <button class="btn ghost" id="btnCancel">取消</button>
      <button class="btn" id="btnSave">保存</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>__GUIDE_JS__</script>
<script>
(function(){
  var API='';
  var tasks=[];
  // 用 ?token=xxx 直连时，页面内所有 AJAX 必须自己带上同一个 token；
  // 走 cookie 认证时由 credentials:'same-origin' 自动携带，这里为空不影响
  var _TK=(function(){try{return (new URLSearchParams(location.search)).get('token')||'';}catch(e){return '';}})();
  function api(qs){return API+'?'+qs+(_TK?'&token='+encodeURIComponent(_TK):'');}

  function toast(m){var t=document.getElementById('toast');t.textContent=m;t.className='show';
    clearTimeout(t._h);t._h=setTimeout(function(){t.className=''},2200);}

  function fmt(ts){if(!ts)return '-';var d=new Date(ts*1000);
    function p(n){return n<10?'0'+n:''+n;}
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());}

  // 耗时显示：<1s 用 ms，>=1s 用秒（保留一位）
  function cost(t){
    if(!t||!t.last_at){return '-';}
    var ms=Number(t.last_ms||0);
    if(!ms){return t.last_status==='skipped'?'跳过':'<1ms';}
    if(ms<1000){return ms+'ms';}
    return (ms/1000).toFixed(1)+'s';
  }

  function plan(t){
    var e=(t&&t.expr)?String(t.expr).trim():'';
    if(!e){return '—';}
    var f=e.split(/\s+/);
    if(f.length!==6){return e;}
    // fixed: 写死为具体值（不是 * 也不是 */N）
    function fixed(x){return x!=='*'&&String(x).indexOf('*/')!==0;}
    // step: 取 */N 里的 N
    function step(x){var m=String(x).match(/^\*\/(\d+)$/);return m?parseInt(m[1],10):0;}
    var s=f[0],mi=f[1],h=f[2],d=f[3],mo=f[4],w=f[5];

    if(fixed(d)&&fixed(mo)){return '一次性 '+mo+'/'+d+' '+pad2(h)+':'+pad2(mi);}
    if(fixed(d)){return '每月 '+d+' 日 '+pad2(h)+':'+pad2(mi);}
    if(fixed(w)){return '每周'+['日','一','二','三','四','五','六'][parseInt(w,10)%7]+' '+pad2(h)+':'+pad2(mi);}
    if(fixed(h)){return '每天 '+pad2(h)+':'+pad2(mi);}

    var ss=step(s), sm=step(mi);
    if(ss>0&&mi==='*'&&h==='*'){return ss===1?'每秒':'每 '+ss+' 秒';}
    if(sm>0&&h==='*'){return sm===1?'每分钟':'每 '+sm+' 分钟';}
    // 秒写死 0、分与时通配 = 每分钟整点秒（这是最常见的「每分钟」写法）
    if(s==='0'&&mi==='*'&&h==='*'){return '每分钟';}
    if(fixed(mi)&&h==='*'){return '每小时第 '+mi+' 分';}
    return e;
  }
  function pad2(n){return (n<10?'0':'')+n;}
  var CAD_KEYS=['rowNmin','rowTime','rowWeek','rowMonthDay','rowOnce'];
  function cadenceChanged(){
    var c=document.getElementById('f_cadence').value;
    var tv=document.getElementById('f_time').value||'09:00';
    var tp=tv.split(':'); var hh=parseInt(tp[0]||'9',10); var mm=parseInt(tp[1]||'0',10);
    var exp=''; var hint='';
    if(c==='minute'){exp='0 * * * * *';hint='每分钟的第 0 秒';}
    else if(c==='nmin'){var n=parseInt(document.getElementById('f_nmin').value||'5',10);n=Math.max(1,Math.min(59,n));
      exp='0 */'+n+' * * * *';hint='每 '+n+' 分钟';}
    else if(c==='hour'){exp='0 '+mm+' * * * *';hint='每小时的 '+pad2(mm)+' 分';}
    else if(c==='day'){exp='0 '+mm+' '+hh+' * * *';hint='每天 '+pad2(hh)+':'+pad2(mm);}
    else if(c==='week'){var w=document.getElementById('f_week').value;var wn=['周日','周一','周二','周三','周四','周五','周六'];
      exp='0 '+mm+' '+hh+' * * '+w;hint='每周'+wn[parseInt(w,10)]+' '+pad2(hh)+':'+pad2(mm);}
    else if(c==='month'){var d=parseInt(document.getElementById('f_mday').value||'1',10);d=Math.max(1,Math.min(31,d));
      exp='0 '+mm+' '+hh+' '+d+' * *';hint='每月 '+d+' 日 '+pad2(hh)+':'+pad2(mm);}
    else if(c==='once'){var v=document.getElementById('f_once').value;
      if(v){var dt=new Date(v);
        exp=dt.getSeconds()+' '+dt.getMinutes()+' '+dt.getHours()+' '+dt.getDate()+' '+(dt.getMonth()+1)+' *';
        hint='只执行一次：'+v.replace('T',' ');}else{hint='请选择执行时刻';}}
    else{return;}
    for(var i=0;i<CAD_KEYS.length;i++){var e1=document.getElementById(CAD_KEYS[i]);if(e1){e1.style.display='none';}}
    if(c==='nmin'){document.getElementById('rowNmin').style.display='';}
    if(c==='hour'||c==='day'||c==='week'||c==='month'){document.getElementById('rowTime').style.display='';}
    if(c==='week'){document.getElementById('rowWeek').style.display='';}
    if(c==='month'){document.getElementById('rowMonthDay').style.display='';}
    if(c==='once'){document.getElementById('rowOnce').style.display='';}
    if(exp){document.getElementById('f_expr').value=exp;}
    var ch=document.getElementById('cadHint');ch.textContent=hint;ch.style.display=hint?'':'none';
  }
  function cadenceHintOnly(){
    var v=document.getElementById('f_expr').value.trim();
    var ch=document.getElementById('cadHint');
    if(v){ch.textContent='表达式：'+v;ch.style.display='';}else{ch.textContent='';ch.style.display='none';}
  }
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}

  function render(){
    var tb=document.getElementById('tb');
    if(!tasks.length){tb.innerHTML='<tr><td colspan="8" class="empty">还没有任务，点「＋ 新建任务」开始</td></tr>';
      document.getElementById('stat').textContent='共 0 个任务';return;}
    var h='';
    tasks.forEach(function(t){
      var on=!!t.enabled;
      var kindCls=t.kind==='push'?'b-push':(t.kind==='shell'?'b-shell':'b-http');
      var kindTxt=t.kind==='push'?'推送':(t.kind==='shell'?'命令':'HTTP');
      var detail=t.kind==='push'?(t.target||'all')+' → '+esc(t.msg||'')
        :(t.kind==='shell'?'$ '+esc(t.command||'')
        :(t.method||'get').toUpperCase()+' '+esc(t.url||''));
      var last='-';
      if(t.last_at){last='<span class="'+(t.last_ok?'ok':'no')+'">'+(t.last_ok?'成功':'失败')+'</span> '+fmt(t.last_at)
        +' <span class="muted">'+(t.runs||0)+' 次</span>';}
      h+='<tr>'
        +'<td><b>'+esc(t.name)+'</b><div class="mono muted" style="font-size:11px">'+detail+'</div></td>'
        +'<td><span class="badge '+kindCls+'">'+kindTxt+'</span></td>'
        +'<td class="mono">'+plan(t)+'</td>'
        +'<td class="mono">'+esc(t.expr||'-')+'</td>'
        +'<td class="c"><span class="badge '+(on?'b-on':'b-off')+'">'+(on?'运行中':'已停止')+'</span></td>'
        +'<td class="mono muted">'+last+'</td>'
        +'<td class="c mono">'+cost(t)+'</td>'
        +'<td class="c">'
          +'<button class="btn mini ghost" data-act="toggle" data-id="'+t.id+'">'+(on?'停止':'启动')+'</button> '
          +'<button class="btn mini ghost" data-act="runonce" data-id="'+t.id+'">执行</button> '
          +'<button class="btn mini ghost" data-act="edit" data-id="'+t.id+'">编辑</button> '
          +'<button class="btn mini red" data-act="del" data-id="'+t.id+'">删除</button>'
        +'</td></tr>';
    });
    tb.innerHTML=h;
    document.getElementById('stat').textContent='共 '+tasks.length+' 个任务';
  }

  function load(){
    fetch(api('do=tasks&view=json'),{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.ok){toast(j.message||'加载失败');return;}
        tasks=(j.data&&j.data.tasks)||[];
        render();
      }).catch(function(e){toast('加载失败：'+e.message);});
  }

  function post(qs,body){
    return fetch(api('do='+qs),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
      body:body}).then(function(r){return r.json();});
  }

  var mask=document.getElementById('mask');
  function openDlg(t){
    document.getElementById('dlgTitle').textContent=t?'编辑任务':'新建任务';
    document.getElementById('f_id').value=t?t.id:'';
    document.getElementById('f_name').value=t?t.name:'';
    document.getElementById('f_kind').value=t?t.kind:'http';
    if(t){document.getElementById('f_cadence').value='expr';
      document.getElementById('f_expr').value=t.expr||'';cadenceHintOnly();}
    else{document.getElementById('f_cadence').value='day';
      document.getElementById('f_time').value='09:00';cadenceChanged();}
    document.getElementById('f_enabled').checked=t?!!t.enabled:true;
    document.getElementById('f_timeout').value=t&&t.timeout?(t.timeout):'';
    document.getElementById('f_overlap').value=t&&t.overlap?t.overlap:'skip';
    document.getElementById('f_command').value=t?(t.command||''):'';
    document.getElementById('f_cwd').value=t?(t.cwd||''):'';
    if(t&&t.kind==='push'){
      document.getElementById('f_target').value=t.target||'all';
      document.getElementById('f_msg').value=t.msg||'';
    }else{
      document.getElementById('f_url').value=t?(t.url||''):'';
      document.getElementById('f_method').value=t?(t.method||'get'):'get';
      document.getElementById('f_post').value=t&&t.post&&Object.keys(t.post).length?JSON.stringify(t.post):'';
      document.getElementById('f_target').value='all';
      document.getElementById('f_msg').value='';
    }
    if(t){document.getElementById('f_url').value=t.url||'';}
    syncKind();
    mask.classList.add('show');
  }
  function closeDlg(){mask.classList.remove('show');}
  function syncKind(){
    var k=document.getElementById('f_kind').value;
    document.getElementById('grpHttp').style.display=k==='http'?'':'none';
    document.getElementById('grpPush').style.display=k==='push'?'':'none';
    document.getElementById('grpShell').style.display=k==='shell'?'':'none';
  }

  document.getElementById('btnAdd').addEventListener('click',function(){openDlg(null);});
  document.getElementById('btnRefresh').addEventListener('click',load);
  document.getElementById('btnCancel').addEventListener('click',closeDlg);
  document.getElementById('f_kind').addEventListener('change',syncKind);
  mask.addEventListener('click',function(e){if(e.target===mask){closeDlg();}});

  document.getElementById('btnTick').addEventListener('click',function(){
    var b=this;b.disabled=true;b.textContent='执行中…';
    fetch(api('do=tick&view=json'),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.ok){toast(j.message||'执行失败');}else{
          var d=j.data||{};toast('已执行 '+(d.ran||0)+' 个到期任务');}
        load();
      }).catch(function(e){toast('执行失败：'+e.message);})
      .finally(function(){b.disabled=false;b.textContent='立即执行到期任务';});
  });

  document.getElementById('btnSave').addEventListener('click',function(){
    var k=document.getElementById('f_kind').value;
    var p=new URLSearchParams();
    p.set('id',document.getElementById('f_id').value);
    p.set('name',document.getElementById('f_name').value);
    p.set('kind',k);
    var expr=document.getElementById('f_expr').value.trim();
    if(!expr){toast('请先生成或填写 cron 表达式');return;}
    p.set('expr',expr);
    p.set('enabled',document.getElementById('f_enabled').checked?'1':'0');
    // 执行控制（所有类型通用）
    p.set('timeout',document.getElementById('f_timeout').value||'0');
    p.set('overlap',document.getElementById('f_overlap').value||'skip');
    if(k==='http'){
      p.set('url',document.getElementById('f_url').value);
      p.set('method',document.getElementById('f_method').value);
      p.set('post',document.getElementById('f_post').value||'');
    }else if(k==='shell'){
      var cmd=(document.getElementById('f_command').value||'').trim();
      if(!cmd){toast('请填写要执行的命令');return;}
      p.set('command',cmd);
      p.set('cwd',document.getElementById('f_cwd').value||'');
    }else{
      p.set('target',document.getElementById('f_target').value||'all');
      p.set('msg',document.getElementById('f_msg').value||'');
    }
    post('save',p.toString()).then(function(j){
      if(!j.ok){toast(j.message||'保存失败');return;}
      toast('已保存');closeDlg();load();
    }).catch(function(e){toast('保存失败：'+e.message);});
  });

  document.getElementById('tb').addEventListener('click',function(e){
    var btn=e.target.closest('button[data-act]');
    if(!btn){return;}
    var act=btn.getAttribute('data-act'),id=btn.getAttribute('data-id');
    var t=tasks.filter(function(x){return x.id===id;})[0];
    if(act==='edit'){if(t){openDlg(t);}return;}
    if(act==='del'){
      if(!confirm('确定删除任务「'+(t?t.name:id)+'」？')){return;}
      var p=new URLSearchParams();p.set('id',id);
      post('delete',p.toString()).then(function(j){toast(j.message||'');load();});
      return;
    }
    if(act==='toggle'){
      var p2=new URLSearchParams();p2.set('id',id);p2.set('enabled',t&&t.enabled?'0':'1');
      post('toggle',p2.toString()).then(function(j){toast(j.message||'');load();});
      return;
    }
    if(act==='runonce'){
      var p3=new URLSearchParams();p3.set('id',id);
      btn.disabled=true;
      post('runonce',p3.toString()).then(function(j){
        toast((j.ok?'执行成功：':'执行失败：')+(j.message||''));load();
      }).finally(function(){btn.disabled=false;});
    }
  });

  // ── 调度依赖服务（GlobalData / WebSocket）：直接在本页开启关闭 ──────────────
  function svcPost(action, name){
    var body=new URLSearchParams(); body.set('name', name);
    return fetch('/sys/service?do='+action+'&view=json'+(_TK?'&token='+encodeURIComponent(_TK):''),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
      body:body.toString()}).then(function(r){return r.json();});
  }

  function renderSvc(list){
    var box=document.getElementById('svcbox');
    if(!box){return;}
    if(!list||!list.length){box.innerHTML='';return;}
    var h='';
    list.forEach(function(s){
      h+='<div class="svc">'
        +'<div class="t">'+esc(s.label)
        +' <span class="b '+(s.running?'on':'off')+'">'+(s.running?'运行中':'已停止')+'</span>'
        +(s.running?' <span class="pid">pid '+s.pid+'</span>':'')
        +'</div>'
        +(s.desc?'<div class="d">'+esc(s.desc)+'</div>':'')
        +'<div class="a">'
          +'<button class="btn mini'+(s.running?' ghost':'')+'" data-svc="start" data-name="'+esc(s.name)+'"'
            +(s.running||!s.exists?' disabled':'')+'>开启</button>'
          +'<button class="btn mini red" data-svc="stop" data-name="'+esc(s.name)+'"'
            +(s.running?'':' disabled')+'>关闭</button>'
          +'<button class="btn mini ghost" data-svc="force" data-name="'+esc(s.name)+'">清残留</button>'
        +'</div></div>';
    });
    box.innerHTML=h;
  }

  function loadSvc(){
    fetch('/sys/service?do=status&view=json',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j||!j.data){return;}
        // 计划任务页只显示调度依赖（process）；推送服务归 /sys/push 与 /sys/service
        var only=(j.data.services||[]).filter(function(s){return s.name!=='push';});
        renderSvc(only);
        // 起不了子进程时先提示，别等点了「开启」才报错
        var p=j.data.process||{}, w=document.getElementById('svcspawn');
        if(w){
          w.innerHTML=(p.available===false)
            ? '<div class="warn" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;'
              +'border-radius:10px;padding:10px 14px;font-size:12.5px;line-height:1.7;margin-bottom:12px">'
              +'<b>当前环境起不了子进程</b>（proc_open 被 disable_functions 禁了）—— 点「开启」会失败，'
              +'失败后会给出手动命令；或直接去 <a href="/sys/service" style="color:#b45309">/sys/service</a> 看完整原因。</div>'
            : '';
        }
      })
      .catch(function(){});
  }

  document.addEventListener('click',function(e){
    var btn=e.target.closest('button[data-svc]');
    if(!btn||btn.disabled||btn.getAttribute('data-svc')==='__none'){return;}
    var act=btn.getAttribute('data-svc'),name=btn.getAttribute('data-name');
    btn.disabled=true;
    svcPost(act,name).then(function(j){
      if(j&&j.ok){okrSvcHideGuide();toast(j.message||'完成');}
      else{
        var verb=(act==='start'?'启动':(act==='stop'?'停止':(act==='force'?'清理残留':'操作')));
        okrSvcFail('没能'+verb+'「'+name+'」服务',j,toast);
      }
      loadSvc();
    }).catch(function(err){toast('操作失败：'+err.message);});
  });

  loadSvc();


  // 内联 oninput/onchange 在全局作用域求值，IIFE 内的函数必须显式挂出去，
  // 否则改周期/改表达式会报 "xxx is not defined"（既有缺陷）
  window.cadenceChanged=cadenceChanged;
  window.cadenceHintOnly=cadenceHintOnly;

  load();
})();
</script>
</body>
</html>
HTML;

        return str_replace(
            ['__TIME__', '__GUIDE_CSS__', '__GUIDE_JS__'],
            [date('Y-m-d H:i:s'), \Candy\Extend\Tools\Endpoint\Service::guideCss(), \Candy\Extend\Tools\Endpoint\Service::guideJs()],
            $tpl
        );
    }
}
