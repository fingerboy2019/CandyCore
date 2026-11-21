<?php
/***
 * 推送网关监控数据（面板与 ?do=stats 共用）
 *
 * 一次算出：服务可用性、在线连接、各应用分布、离线队列、审计统计、限流配置。
 * 服务端每 2 秒广播给订阅了 `stats` 的面板连接（真实时）；连不上 WS 时前端退回轮询本数据。
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 16:30:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Stats
{
    /**
     * 汇总一次监控数据
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $cfg = Config::get();
        $gwOk = false;
        $uids = [];
        $clients = [];
        try {
            $gw = self::gw();
            if ($gw !== null) {
                $uids = array_values((array) $gw::getAllUidList());
                // sessions 拿不到 uid（那是业务 session），uid 要用 getUidByClientId 逐个反查
                $sess = (array) $gw::getAllClientSessions();
                foreach ((array) $gw::getAllClientIdList() as $cid) {
                    $cid = (string) $cid;
                    $info = is_array($sess[$cid] ?? null) ? $sess[$cid] : [];
                    $one = (string) '';
                    try {
                        $one = (string) $gw::getUidByClientId($cid);
                    } catch (\Throwable $e) {
                        $one = '';
                    }
                    $clients[] = [
                        'client_id' => $cid,
                        'uid'       => $one,
                        'ip'        => (string) ($info['client_ip'] ?? ''),
                        'port'      => (int) ($info['client_port'] ?? 0),
                        'online_at' => (int) ($info['connect_time'] ?? 0),
                    ];
                }
                $gwOk = true;
            }
        } catch (\Throwable $e) {
            $gwOk = false;
        }

        // 按应用聚合
        $apps = [];
        foreach (Registry::all() as $name => $one) {
            if ((string) $name === 'default') {
                continue;   // 兜底命名空间不参与应用分布
            }
            $apps[$name] = [
                'app'    => (string) $name,
                'app_id' => (int) ($one['app_id'] ?? 0),
                'label'  => (string) ($one['label'] ?? $name),
                'online' => 0,
                'offline' => 0,
                'persist' => (bool) ($one['offline']['persist'] ?? true),
            ];
        }
        foreach ($uids as $uid) {
            $app = strstr((string) $uid, ':', true);
            if ($app === false) {
                $app = 'default';
            }
            if (!isset($apps[$app])) {
                $apps[$app] = ['app' => $app, 'app_id' => 0, 'label' => $app, 'online' => 0, 'offline' => 0];
            }
            $apps[$app]['online']++;
        }

        // 离线队列
        $offline = [];
        foreach (Offline::index() as $key => $ts) {
            $target = str_replace(Offline::PREFIX, '', (string) $key);
            $app = strstr($target, ':', true) ?: 'default';
            if (isset($apps[$app])) {
                $apps[$app]['offline']++;
            }
            $offline[] = ['target' => $target, 'app' => $app, 'since' => (int) $ts];
        }

        return [
            'ts'          => time(),
            'available'   => $gwOk,
            'online_cnt'  => count(array_filter($uids, static fn ($u): bool => (string) $u !== '')),
            'client_cnt'  => count($clients),
            'clients'     => $clients,
            'online'      => $uids,
            'apps'        => array_values($apps),
            'offline'     => $offline,
            'offline_cnt' => count($offline),
            'audit'       => self::auditToday(),
            'limits'      => $cfg['limits'] ?? [],
            'gateway'     => [
                'listen'             => (string) ($cfg['gateway']['listen'] ?? ''),
                'ping_interval'      => 25,
                'not_response_limit' => 2,
            ],
            'server_time' => date('H:i:s'),
        ];
    }

    /**
     * 今天的审计统计（只读日志尾部，避免大文件）
     *
     * @return array<string, int>
     */
    public static function auditToday(): array
    {
        $out = ['send' => 0, 'ack' => 0, 'close' => 0, 'sent_ok' => 0, 'queued' => 0];
        $file = Audit::file();
        if (!is_file($file)) {
            return $out;
        }
        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return $out;
        }
        $tail = [];
        $max = 4000;
        while (($line = fgets($fp)) !== false) {
            $tail[] = $line;
            if (count($tail) > $max) {
                array_shift($tail);
            }
        }
        @fclose($fp);
        foreach ($tail as $line) {
            if (strpos($line, ' SEND ') !== false) {
                $out['send']++;
                if (strpos($line, 'ok=1') !== false) {
                    $out['sent_ok']++;
                }
                if (strpos($line, 'queued=0') === false) {
                    $out['queued']++;
                }
            } elseif (strpos($line, ' ACK ') !== false) {
                $out['ack']++;
            } elseif (strpos($line, ' CLOSE ') !== false) {
                $out['close']++;
            }
        }
        return $out;
    }

    /**
     * 取 Gateway 静态类（不可用返回 null）
     *
     * @return string|null
     */
    public static function gw(): ?string
    {
        $class = '\\GatewayWorker\\Lib\\Gateway';
        if (!class_exists($class, false)) {
            $base = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Vendor' . DIRECTORY_SEPARATOR
                . 'Workerman' . DIRECTORY_SEPARATOR . 'GatewayWorker' . DIRECTORY_SEPARATOR;
            foreach (['Protocols/GatewayProtocol.php', 'Lib/Context.php', 'Lib/Gateway.php'] as $rel) {
                $file = $base . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (is_file($file)) {
                    require_once $file;
                }
            }
        }
        return class_exists($class, false) ? $class : null;
    }
}
