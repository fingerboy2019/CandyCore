<?php
/***
 * 推送业务回调（GatewayWorker BusinessWorker 的事件处理器）
 *
 * 客户端协议（JSON 帧）：
 *   {"act":"login","app":"home","uid":34,"tags":["dept-12"]}   → 绑定 uid + 加入分组 + 补发离线
 *   {"act":"join","tag":"dept-12"}                             → 加入分组
 *   {"act":"leave","tag":"dept-12"}                            → 退出分组
 *   {"act":"ping"}                                             → 心跳
 *
 * 服务端下发：
 *   {"act":"login","ok":true,"uid":"home:34","flushed":2}
 *   {"act":"message","msg_id":"...","title":"","body":"...","payload":{},"ts":...}
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 15:55:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

use GatewayWorker\Lib\Gateway;

final class Events
{
    /** @var array<string, array{0:int,1:int}> 每连接每秒帧计数 [窗口起点, 计数] */
    private static array $rate = [];

    /** @var array<string, bool> 订阅了 stats 的连接 */
    private static array $subs = [];
    /**
     * 连接建立
     *
     * @param string $clientId 连接 id
     * @return void
     */
    public static function onConnect(string $clientId): void
    {
        $max = (int) (Config::get()['limits']['conns_per_ip'] ?? 0);
        if ($max > 0) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if ($ip !== '') {
                $n = 0;
                try {
                    foreach ((array) Gateway::getAllClientSessions() as $info) {
                        $info = is_array($info) ? $info : [];
                        if ((string) ($info['client_ip'] ?? '') === $ip) {
                            $n++;
                        }
                    }
                } catch (\Throwable $e) {
                    $n = 0;
                }
                if ($n >= $max) {
                    Audit::close($clientId, 'per_ip_limit:' . $ip . ':' . $n);
                    self::reply($clientId, ['act' => 'error', 'message' => '同一 IP 连接数超限']);
                    Gateway::closeClient($clientId);
                    return;
                }
            }
        }
        self::reply($clientId, ['act' => 'welcome', 'client_id' => $clientId, 'ts' => time()]);
    }

    /**
     * 收到客户端消息
     *
     * @param string $clientId 连接 id
     * @param string $message  原始帧
     * @return void
     */
    public static function onMessage(string $clientId, string $message): void
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            self::reply($clientId, ['act' => 'error', 'message' => '消息必须是 JSON 对象']);
            return;
        }

        // 频率限流
        if (!self::allow($clientId)) {
            Audit::close($clientId, 'frame_rate');
            self::reply($clientId, ['act' => 'error', 'message' => '消息频率超限']);
            Gateway::closeClient($clientId);
            return;
        }

        $act = strtolower(trim((string) ($data['act'] ?? '')));
        switch ($act) {
            case 'pong':
            case 'heartbeat':
                return;
            case 'subscribe':
                // 面板订阅监控数据（服务端每 2 秒推一次）
                if ((string) ($data['topic'] ?? '') === 'stats') {
                    self::$subs[$clientId] = true;
                    self::reply($clientId, ['act' => 'subscribed', 'topic' => 'stats']);
                } else {
                    self::reply($clientId, ['act' => 'error', 'message' => '未知订阅主题']);
                }
                return;
            case 'unsubscribe':
                unset(self::$subs[$clientId]);
                self::reply($clientId, ['act' => 'unsubscribed']);
                return;
            case 'ack':
                Audit::ack((string) Gateway::getUidByClientId($clientId), (string) ($data['msg_id'] ?? ''));
                return;
            case 'login':
                self::login($clientId, $data);
                return;
            case 'join':
                self::join($clientId, $data);
                return;
            case 'leave':
                self::leave($clientId, $data);
                return;
            case 'ping':
                self::reply($clientId, ['act' => 'pong', 'ts' => time()]);
                return;
            default:
                self::reply($clientId, ['act' => 'error', 'message' => '未知动作：' . $act]);
        }
    }

    /**
     * 连接关闭（Gateway 自己会清理 uid/group，这里只留钩子）
     *
     * @param string $clientId 连接 id
     * @return void
     */
    public static function onClose(string $clientId): void
    {
        unset(self::$subs[$clientId]);
        Audit::close($clientId, 'disconnect');
    }

    /**
     * Worker 启动时注册定时器：每 2 秒给订阅了 stats 的面板推一次监控数据
     *
     * @param object $worker BusinessWorker 实例
     * @return void
     */
    public static function onWorkerStart(object $worker): void
    {
        \Workerman\Timer::add(2, static function (): void {
            if (self::$subs === []) {
                return;
            }
            try {
                $frame = (string) json_encode(['act' => 'stats'] + Stats::snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable $e) {
                return;
            }
            foreach (array_keys(self::$subs) as $cid) {
                try {
                    Gateway::sendToClient((string) $cid, $frame);
                } catch (\Throwable $e) {
                    unset(self::$subs[$cid]);
                }
            }
        });
    }

    /**
     * 登录：绑定 uid、加入分组、补发离线
     *
     * @param string               $clientId 连接 id
     * @param array<string, mixed> $data     帧
     * @return void
     */
    protected static function login(string $clientId, array $data): void
    {
        // 对外一律用 app_id（公开标识）；兼容老客户端传 app 名
        $app = '';
        $appId = (int) ($data['app_id'] ?? 0);
        if ($appId > 0) {
            $resolved = Registry::findByAppId($appId);
            if ($resolved === null) {
                self::reply($clientId, ['act' => 'login', 'ok' => false, 'message' => 'app_id 不存在：' . $appId]);
                return;
            }
            $app = $resolved;
        } else {
            $app = (string) ($data['app'] ?? 'default');
        }
        $uid = $data['uid'] ?? '';
        if ($uid === '' || $uid === null) {
            self::reply($clientId, ['act' => 'login', 'ok' => false, 'message' => 'uid 不能为空']);
            return;
        }

        Registry::ensure($app);
        $full = Registry::uid($app, $uid);
        Gateway::bindUid($clientId, $full);

        foreach ((array) ($data['tags'] ?? []) as $tag) {
            if (is_scalar($tag) && (string) $tag !== '') {
                Gateway::joinGroup($clientId, Registry::group($app, $tag));
            }
        }

        // 先把离线取出来（take 即清空），再回 login、最后补发 —— 保证 login 帧一定先到
        $pending = Offline::take($app, $uid);
        self::reply($clientId, ['act' => 'login', 'ok' => true, 'uid' => $full, 'flushed' => count($pending)]);
        foreach ($pending as $msg) {
            Gateway::sendToCurrentClient(Message::encode($msg));
        }
    }

    /**
     * 加入分组
     *
     * @param string               $clientId 连接 id
     * @param array<string, mixed> $data     帧
     * @return void
     */
    protected static function join(string $clientId, array $data): void
    {
        $app = (string) ($data['app'] ?? self::appOf($clientId));
        $tag = (string) ($data['tag'] ?? '');
        if ($tag === '') {
            self::reply($clientId, ['act' => 'join', 'ok' => false, 'message' => 'tag 不能为空']);
            return;
        }
        $group = Registry::group($app, $tag);
        Gateway::joinGroup($clientId, $group);
        self::reply($clientId, ['act' => 'join', 'ok' => true, 'group' => $group]);
    }

    /**
     * 退出分组
     *
     * @param string               $clientId 连接 id
     * @param array<string, mixed> $data     帧
     * @return void
     */
    protected static function leave(string $clientId, array $data): void
    {
        $app = (string) ($data['app'] ?? self::appOf($clientId));
        $tag = (string) ($data['tag'] ?? '');
        if ($tag === '') {
            self::reply($clientId, ['act' => 'leave', 'ok' => false, 'message' => 'tag 不能为空']);
            return;
        }
        $group = Registry::group($app, $tag);
        Gateway::leaveGroup($clientId, $group);
        self::reply($clientId, ['act' => 'leave', 'ok' => true, 'group' => $group]);
    }

    /**
     * 每连接每秒帧数限流
     *
     * @param string $clientId 连接 id
     * @return bool 是否放行
     */
    protected static function allow(string $clientId): bool
    {
        $max = (int) (Config::get()['limits']['frames_per_second'] ?? 0);
        if ($max <= 0) {
            return true;
        }
        $now = time();
        $row = self::$rate[$clientId] ?? [$now, 0];
        if ((int) $row[0] !== $now) {
            $row = [$now, 0];
        }
        $row[1] = (int) $row[1] + 1;
        self::$rate[$clientId] = $row;
        // 顺手清理过期的桶，避免无限增长
        if (count(self::$rate) > 2000) {
            foreach (self::$rate as $k => $v) {
                if ((int) $v[0] !== $now) {
                    unset(self::$rate[$k]);
                }
            }
        }
        return (int) $row[1] <= $max;
    }

    /**
     * 从已绑定的 uid 推断应用名
     *
     * @param string $clientId 连接 id
     * @return string
     */
    protected static function appOf(string $clientId): string
    {
        try {
            $uid = (string) Gateway::getUidByClientId($clientId);
        } catch (\Throwable $e) {
            return 'default';
        }
        $pos = strpos($uid, ':');
        return $pos === false ? 'default' : substr($uid, 0, $pos);
    }

    /**
     * 回一帧
     *
     * @param string               $clientId 连接 id
     * @param array<string, mixed> $payload  内容
     * @return void
     */
    protected static function reply(string $clientId, array $payload): void
    {
        try {
            Gateway::sendToClient($clientId, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // 连接可能已断，忽略
        }
    }
}
