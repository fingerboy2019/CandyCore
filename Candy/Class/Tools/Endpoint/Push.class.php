<?php
/***
 * 消息推送端点（sys/push）—— 独立于计划任务的推送服务管理面
 *
 * 为什么单独成端点：
 *   计划任务 = 「什么时候触发什么动作」的调度；消息推送 = 「把消息发给在线连接」的投递服务。
 *   两者生命周期、状态、故障域都不同（WS 挂了不影响调度，调度挂了不影响推送），
 *   混在一个页面里会让「任务管理」和「服务运维」互相干扰。
 *
 * 能力：
 *   page    管理页（服务状态 / 在线连接 / 推送测试 / 离线队列）
 *   status  JSON：GlobalData 可用性 + 在线连接 + 待投递队列 + 离线积压
 *   online  JSON：在线连接明细
 *   queue   JSON：离线待补发队列
 *   send    POST target/msg/url → 写 GlobalData，由 WS 的 1 秒定时器投递
 *
 * 服务启停不在这里做 —— 统一走 /sys/service（避免两处逻辑漂移）。
 *
 * @package Candy\Extend\Tools\Endpoint
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:00:00
 */
namespace Candy\Extend\Tools\Endpoint;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use Candy\Extend\Tools\Service\GlobalData;

final class Push
{
    /**
     * 入口
     *
     * @return string
     */
    public static function handle(): string
    {
        $do = strtolower(self::param('do', 'page'));
        if ($do === 'api') {
            // 第三方接入只认 app_key，不需要 endpoint token
            return self::json(self::apiSend());
        }

        Guard::token('sys/push');
        switch ($do) {
            case 'status':
                return self::json(['ok' => true, 'data' => self::snapshot()]);
            case 'online':
                $s = self::snapshot();
                return self::json(['ok' => true, 'data' => ['online' => $s['online'], 'available' => $s['available']]]);
            case 'queue':
                return self::json(['ok' => true, 'data' => ['failed' => self::failedQueue()]]);
            case 'api':
                // 第三方接入：只认 app_key，不走 endpoint token
                return self::json(self::apiSend());
            case 'apps':
                \Candy\Extend\Tools\Push\Registry::load();
                $list = [];
                foreach (\Candy\Extend\Tools\Push\Registry::all() as $n => $c) {
                    if ((string) $n === 'default') {
                        continue;   // 兜底命名空间，不算「已注册应用」
                    }
                    $list[] = [
                        'app'     => $n,
                        'app_id'  => (int) ($c['app_id'] ?? 0),
                        'label'   => (string) ($c['label'] ?? $n),
                        'key'     => (string) ($c['key'] ?? ''),
                        'offline' => $c['offline'] ?? [],
                        'persist' => (bool) ($c['offline']['persist'] ?? true),
                    ];
                }
                return self::json(['ok' => true, 'data' => ['apps' => $list]]);
            case 'register':
                $rApp = strtolower(trim((string) self::param('app', '')));
                if ($rApp === '' || !preg_match('/^[a-z][a-z0-9_]{1,23}$/', $rApp)) {
                    return self::json(['ok' => false, 'message' => '应用名需为 2-24 位小写字母/数字/下划线，且以字母开头']);
                }
                $rLabel = trim((string) self::param('label', $rApp));
                $rTtl = (int) self::param('ttl', '604800');
                $rMax = (int) self::param('max', '200');
                $rPersist = (string) self::param('persist', '1') !== '0';
                \Candy\Extend\Tools\Push\Registry::load();
                $exist = \Candy\Extend\Tools\Push\Registry::all()[$rApp] ?? null;
                \Candy\Extend\Tools\Push\Registry::register($rApp, [
                    'label'   => $rLabel !== '' ? $rLabel : $rApp,
                    'app_id'  => (int) ($exist['app_id'] ?? 0) ?: \Candy\Extend\Tools\Push\Registry::nextAppId(),
                    'key'     => (string) ($exist['key'] ?? '') ?: \Candy\Extend\Tools\Push\Registry::newKey(),
                    'offline' => ['ttl' => max(60, $rTtl), 'max' => max(1, $rMax), 'persist' => $rPersist],
                ]);
                $saved = \Candy\Extend\Tools\Push\Registry::persist();
                $one = \Candy\Extend\Tools\Push\Registry::all()[$rApp];
                return self::json(['ok' => true, 'message' => $saved ? '已保存' : '已注册（写配置失败，重启后丢失）', 'data' => $one]);
            case 'remove':
                $dApp = strtolower(trim((string) self::param('app', '')));
                if ($dApp === '') {
                    return self::json(['ok' => false, 'message' => 'app 不能为空']);
                }
                \Candy\Extend\Tools\Push\Registry::load();
                $all = \Candy\Extend\Tools\Push\Registry::all();
                if (!isset($all[$dApp])) {
                    return self::json(['ok' => false, 'message' => '应用不存在：' . $dApp]);
                }
                unset($all[$dApp]);
                \Candy\Extend\Tools\Push\Registry::replaceAll($all);
                return self::json(['ok' => true, 'message' => '已移除', 'saved' => \Candy\Extend\Tools\Push\Registry::persist()]);
            case 'rotate':
                $kApp = strtolower(trim((string) self::param('app', '')));
                if ($kApp === '') {
                    return self::json(['ok' => false, 'message' => 'app 不能为空']);
                }
                \Candy\Extend\Tools\Push\Registry::load();
                $all = \Candy\Extend\Tools\Push\Registry::all();
                if (!isset($all[$kApp])) {
                    return self::json(['ok' => false, 'message' => '应用不存在：' . $kApp]);
                }
                $all[$kApp]['key'] = \Candy\Extend\Tools\Push\Registry::newKey();
                \Candy\Extend\Tools\Push\Registry::replaceAll($all);
                $saved = \Candy\Extend\Tools\Push\Registry::persist();
                return self::json(['ok' => true, 'message' => '已重置 key', 'key' => $all[$kApp]['key'], 'saved' => $saved]);
            case 'logs':
                $lgDay = trim((string) self::param('day', ''));
                $lgTag = trim((string) self::param('tag', ''));
                $lgApp = trim((string) self::param('app', ''));
                $lgLimit = (int) self::param('lines', '200');
                return self::json(['ok' => true, 'data' => [
                    'days'  => \Candy\Extend\Tools\Push\Audit::days(),
                    'day'   => $lgDay !== '' ? $lgDay : date('Y-m-d'),
                    'tag'   => $lgTag,
                    'app'   => $lgApp,
                    'rows'  => \Candy\Extend\Tools\Push\Audit::read($lgDay, $lgTag, $lgApp, $lgLimit),
                ]]);
                throw new \RuntimeException('syntax error, unexpected token "," 测试用');
            case 'stats':
                return self::json(['ok' => true, 'data' => \Candy\Extend\Tools\Push\Stats::snapshot()]);
            case 'offline':
                return self::json(['ok' => true, 'data' => ['index' => \Candy\Extend\Tools\Push\Offline::index()]]);
            case 'clear':
                $cApp = trim((string) self::param('app', 'default')) ?: 'default';
                $cUid = trim((string) self::param('uid', ''));
                if ($cUid === '') {
                    return self::json(['ok' => false, 'message' => 'uid 不能为空']);
                }
                \Candy\Extend\Tools\Push\Offline::clear($cApp, $cUid);
                return self::json(['ok' => true, 'message' => '已清空 ' . $cApp . ':' . $cUid . ' 的离线队列']);
            case 'send':
                return self::json(self::send());
            case 'selftest':
                return self::json(self::selftest());
            case 'page':
            default:
                return self::page();
        }
    }

    /**
     * 当前推送状态快照
     *
     * @return array<string, mixed>
     */
    protected static function snapshot(): array
    {
        $uids = [];
        $gwOk = false;
        $gwErr = '';
        try {
            $gwClass = 'GatewayWorker\Lib\Gateway';
            // 框架根 = Candy/（CANDYPATH 是项目根，语义不一致，用 __DIR__ 反推）
            $base = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR . 'Workerman' . DIRECTORY_SEPARATOR . 'GatewayWorker' . DIRECTORY_SEPARATOR;
            $dbg = ['candypath' => defined('CANDYPATH') ? CANDYPATH : '(undefined)', 'base' => $base, 'probe' => []];
            if (!class_exists($gwClass, false)) {
                foreach (['Protocols/GatewayProtocol.php', 'Lib/Context.php', 'Lib/Gateway.php'] as $one) {
                    $f = $base . $one;
                    $dbg['probe'][$one] = is_file($f) ? 'exists' : 'MISSING';
                    if (is_file($f)) {
                        require_once $f;
                    }
                }
            }
            $dbg['loaded'] = class_exists($gwClass, false) ? 'yes' : 'no';
            $gwErr = json_encode($dbg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (class_exists($gwClass, false)) {
                $uids = array_values((array) $gwClass::getAllUidList());
                $gwOk = true;
            }
        } catch (\Throwable $e) {
            $gwOk = false;
            $gwErr = get_class($e) . ': ' . $e->getMessage();
        }
        return [
            'available'  => $gwOk,
            'error'      => $gwOk ? '' : ($gwErr !== '' ? $gwErr : '推送服务未启动或不可用'),
            'apps'       => array_keys(\Candy\Extend\Tools\Push\Registry::all()),
            'online'     => $uids,
            'online_cnt' => count($uids),
            'cron_cnt'   => 0,
            'failed_cnt' => count(self::failedQueue()),
        ];
    }

    /**
     * 离线待补发队列
     *
     * @return array<int|string, mixed>
     */
    protected static function failedQueue(): array
    {
        $raw = \Candy\Extend\Tools\Push\Offline::index();
        return is_array($raw) ? $raw : [];
    }

    /**
     * 第三方接入：用 app_key 发消息（不需要 endpoint token）
     *
     * @return array<string, mixed>
     */
    protected static function apiSend(): array
    {
        $appId = self::param('app_id', '');
        $key = trim((string) self::param('app_key', ''));
        if ($appId === '' || $key === '') {
            return ['ok' => false, 'message' => '缺少 app_id 或 app_key'];
        }
        $app = \Candy\Extend\Tools\Push\Registry::verify($appId, $key);
        if ($app === null) {
            return ['ok' => false, 'message' => 'app_id 与 app_key 不匹配'];
        }
        $target = trim((string) self::param('target', 'all'));
        if ($target === '') {
            $target = 'all';
        }
        $msg = (string) self::param('msg', '');
        if ($msg === '') {
            return ['ok' => false, 'message' => 'msg 不能为空'];
        }
        $title = (string) self::param('title', '');
        try {
            \Candy\Extend\Tools\Push\Target::via('api');
            $pusher = \Candy\Extend\Tools\Push\Target::app($app);
            if ($target === 'all') {
                $res = $pusher->all($msg, [], $title);
            } elseif (str_starts_with($target, '#')) {
                $res = $pusher->toGroup(substr($target, 1), $msg, [], $title);
            } else {
                $res = $pusher->toUid($target, $msg, [], $title);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '推送异常：' . $e->getMessage()];
        }
        return array_merge(['ok' => (bool) ($res['ok'] ?? false), 'app' => $app, 'target' => $target], $res);
    }

    /**
     * 发送一条推送
     *
     * @return array<string, mixed>
     */
    protected static function send(): array
    {
        $target = trim((string) self::param('target', 'all'));
        $msg = (string) self::param('msg', '');
        $url = (string) self::param('url', '');
        if ($target === '') {
            $target = 'all';
        }
        if ($msg === '') {
            return ['ok' => false, 'message' => '消息内容不能为空'];
        }
        $app = trim((string) self::param('app', 'default'));
        if ($app === '') {
            $app = 'default';
        }
        $pusher = \Candy\Extend\Tools\Push\Target::app($app);
        if ($target === 'all' || $target === '') {
            $res = $pusher->all($msg, [], '');
        } elseif (str_starts_with($target, '#')) {
            $res = $pusher->toGroup(substr($target, 1), $msg, [], '');
        } else {
            $res = $pusher->toUid($target, $msg, [], '');
        }
        return array_merge(['ok' => (bool) ($res['ok'] ?? false), 'app' => $app, 'target' => $target], $res);
    }

    /**
     * 输出 JSON
     *
     * @param array<string, mixed> $data 数据
     * @return string
     */
    protected static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 读参数（GET / POST 都认）
     *
     * @param string $key     键
     * @param string $default 默认值
     * @return string
     */
    protected static function param(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $_POST[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        return is_scalar($v) ? trim((string) $v) : $default;
    }


    /**
     * 自检：验证 Push 基础类能加载且行为正确
     *
     * @return array<string, mixed>
     */
    protected static function selftest(): array
    {
        $cfg = '\Candy\Extend\Tools\Push\Config';
        $reg = '\Candy\Extend\Tools\Push\Registry';
        $msg = '\Candy\Extend\Tools\Push\Message';
        $checks = [];
        $check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        try {
            $defaults = $cfg::defaults();
            $need = ['enable', 'gateway', 'register', 'worker', 'offline', 'auth', 'message', 'apps', 'limits', 'audit'];
            $miss = array_diff($need, array_keys($defaults));
            $check('Config::defaults 关键键齐全', $miss === [], 'count=' . count($defaults) . ' miss=' . implode(',', $miss));
            $check('默认 gateway 监听 4020', str_contains((string) ($defaults['gateway']['listen'] ?? ''), '4020'), (string) ($defaults['gateway']['listen'] ?? ''));

            $reg::reset();
            $home = $reg::register('Home', ['label' => '门户']);
            $check('Registry::register 规范化小写', isset($reg::all()['home']), 'apps=' . implode(',', array_keys($reg::all())));
            $check('register 保留 label', ($home['label'] ?? '') === '门户', (string) ($home['label'] ?? ''));

            $auto = $reg::ensure('someapp');
            $check('未注册应用自动注册', isset($reg::all()['someapp']) && ($auto['enable'] ?? false) === true, 'label=' . (string) ($auto['label'] ?? ''));

            $check('uid 加应用前缀', $reg::uid('home', 34) === 'home:34', $reg::uid('home', 34));
            $check('group 加应用前缀', $reg::group('Admin', 'ops') === 'admin:ops', $reg::group('Admin', 'ops'));
            $check('应用隔离（home:34 ≠ admin:34）', $reg::uid('home', 34) !== $reg::uid('admin', 34), $reg::uid('home', 34) . ' vs ' . $reg::uid('admin', 34));

            $m = $msg::make('你好', ['a' => 1], '标题');
            $check('Message::make 字段齐全', isset($m['msg_id'], $m['title'], $m['body'], $m['payload'], $m['ts']), 'msg_id=' . substr((string) $m['msg_id'], 0, 8));
            $check('Message::normalize 字符串转消息', ($msg::normalize('纯文本')['body'] ?? '') === '纯文本', $msg::normalize('纯文本')['body'] ?? '');
            $frame = $msg::encode($m);
            $check('Message::encode 带 act=message', str_contains($frame, '"act":"message"'), substr($frame, 0, 60));

            $big = $msg::make(str_repeat('x', 9000));
            $check('Message::tooLarge 拦住超长', $msg::tooLarge($big) === true, 'bytes=' . $msg::bytes($big));

            $off = '\Candy\Extend\Tools\Push\Offline';
            $tgt = '\Candy\Extend\Tools\Push\Target';

            $off::clear('home', 34);
            $check('Offline::key 结构', $off::key('home', 34) === 'push.offline.home:34', $off::key('home', 34));
            $check('Offline::push 第 1 条', $off::push('home', 34, '第一条') === 1, 'count=' . $off::count('home', 34));
            $check('Offline::push 第 2 条', $off::push('home', 34, '第二条') === 2, 'count=' . $off::count('home', 34));
            $taken = $off::take('home', 34);
            $check('Offline::take 取出 2 条', count($taken) === 2, 'n=' . count($taken));
            $check('Offline::take 后清空', $off::count('home', 34) === 0, 'count=' . $off::count('home', 34));

            $off::clear('home', 99);
            $r1 = $tgt::app('home')->toUid(99, '离线测试');
            $check('Target 降级不抛异常', is_array($r1) && isset($r1['ok'], $r1['sent'], $r1['queued']), json_encode($r1, JSON_UNESCAPED_UNICODE));
            $check('Target 离线入队', ($r1['queued'] ?? 0) === 1 && ($r1['ok'] ?? true) === false, 'queued=' . (int) ($r1['queued'] ?? 0));
            $check('Target 离线队列可读', $off::count('home', 99) === 1, 'count=' . $off::count('home', 99));

            $r2 = $tgt::app('home')->toUid(99, str_repeat('y', 9000));
            $check('Target 拦住超长', ($r2['ok'] ?? true) === false && str_contains((string) $r2['message'], '超过'), (string) ($r2['message'] ?? ''));

            $r3 = $tgt::broadcast('广播');
            $check('Target 广播降级返回结构', is_array($r3) && isset($r3['message']), (string) ($r3['message'] ?? ''));

            $r4 = $tgt::app(1001)->toUid(88, 'app_id 调用');
            $check('Target::app(app_id) 可解析', is_array($r4) && isset($r4['message']), json_encode($r4, JSON_UNESCAPED_UNICODE));
            $r5 = $tgt::app(9999)->toUid(88, '不存在的 app_id');
            $check('Target::app(不存在) 不炸', is_array($r5) && isset($r5['message']), (string) ($r5['message'] ?? ''));
            $off::clear('demo', 88);

            $boot = '\Candy\Extend\Tools\Push\Boot';
            $evt = '\Candy\Extend\Tools\Push\Events';
            $check('Boot 类可加载', class_exists($boot), $boot);
            $check('Events 类可加载', class_exists($evt), $evt);
            $check('Events::onMessage 存在', method_exists($evt, 'onMessage'), 'onMessage');
            $check('Events::onConnect 存在', method_exists($evt, 'onConnect'), 'onConnect');
            $check('Events::onClose 存在', method_exists($evt, 'onClose'), 'onClose');
            $inc = function_exists('C') ? (string) C('INCPATH') : '';
            $svcMgr = '\\Candy\\Extend\\Tools\\Service\\Manager';
            $svcAll = class_exists($svcMgr) ? $svcMgr::all() : [];
            $check('服务清单含 push（框架内置，无需 Service.inc.php）', isset($svcAll['push']), (string) implode(',', array_keys((array) $svcAll)));
        } catch (\Throwable $e) {
            $check('异常', false, get_class($e) . ': ' . $e->getMessage());
        }

        $pass = count(array_filter($checks, static fn (array $c): bool => $c['ok'] === true));
        return ['ok' => $pass === count($checks), 'data' => ['pass' => $pass, 'total' => count($checks), 'checks' => $checks]];
    }

    /**
     * 管理页
     *
     * @return string
     */
    protected static function page(): string
    {
        // 面板前端放在 Candy/Tpl/push/（框架模板目录），页面与脚本分离便于改样式
        $base = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Tpl' . DIRECTORY_SEPARATOR . 'push' . DIRECTORY_SEPARATOR;
        $html = is_file($base . 'panel.html') ? (string) file_get_contents($base . 'panel.html') : '';
        $js = is_file($base . 'app.js') ? (string) file_get_contents($base . 'app.js') : '';
        if ($html === '') {
            return '<!doctype html><meta charset="utf-8"><h1>面板模板缺失</h1><p>Candy/Tpl/push/panel.html 不存在</p>';
        }
        return str_replace('__APP_JS__', $js, $html);
    }
}
