<?php
/***
 * 推送审计日志（不落数据库，按天写 Runtime/Logs/push-YYYY-MM-DD.log）
 *
 * 记录三类事件：
 *   SEND  一次推送的结果（谁发给谁、成功还是进离线队列、消息大小）
 *   ACK   客户端回执（浅显版：只留痕，不做重发）
 *   CLOSE 连接被限流/超时断开
 *
 * @package Candy\Extend\Tools\Push
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-16 16:25:00
 */
namespace Candy\Extend\Tools\Push;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Audit
{
    /**
     * 是否开启审计
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return (bool) (Config::get()['audit']['enable'] ?? false);
    }

    /**
     * 当前日志文件路径（按天）
     *
     * @return string
     */
    public static function file(): string
    {
        $base = (defined('CANDYPATH') ? CANDYPATH : sys_get_temp_dir() . '/');
        $dir = $base . 'Runtime' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . 'push-' . date('Y-m-d') . '.log';
    }

    /**
     * 记一次推送
     *
     * @param string               $app    应用
     * @param mixed                $target 目标
     * @param array<string, mixed> $res    结果
     * @param array<string, mixed> $msg    消息
     * @return void
     */
    public static function send(string $app, mixed $target, array $res, array $msg): void
    {
        self::line('SEND', [
            'app'     => $app,
            'target'  => (string) $target,
            'ok'      => !empty($res['ok']) ? 1 : 0,
            'sent'    => (int) ($res['sent'] ?? 0),
            'queued'  => (int) ($res['queued'] ?? 0),
            'msg_id'  => (string) ($msg['msg_id'] ?? ''),
            'bytes'   => Message::bytes($msg),
            'note'    => (string) ($res['message'] ?? ''),
            'via'     => (string) (\Candy\Extend\Tools\Push\Target::viaName()),
        ]);
    }

    /**
     * 记一次客户端回执
     *
     * @param string $uid   内部 uid
     * @param string $msgId 消息 id
     * @return void
     */
    public static function ack(string $uid, string $msgId): void
    {
        self::line('ACK', ['uid' => $uid, 'msg_id' => $msgId]);
    }

    /**
     * 记一次连接被拒/断开
     *
     * @param string $clientId 连接 id
     * @param string $reason   原因
     * @return void
     */
    public static function close(string $clientId, string $reason): void
    {
        self::line('CLOSE', ['client_id' => $clientId, 'reason' => $reason]);
    }


    /**
     * 有日志的日期列表（新的在前）
     *
     * @param int $keep 最多返回几天
     * @return array<int, string>
     */
    public static function days(int $keep = 14): array
    {
        $base = self::dir();
        $out = [];
        foreach ((array) glob($base . 'push-*.log') as $file) {
            if (preg_match('/push-(\d{4}-\d{2}-\d{2})\.log$/', (string) $file, $m)) {
                $out[] = $m[1];
            }
        }
        rsort($out);
        return array_slice($out, 0, max(1, $keep));
    }

    /**
     * 读日志并解析成结构化记录（新的在前）
     *
     * 日志格式：[2026-11-16 16:31:15] SEND app=demo target=88 ok=0 ...
     *
     * @param string $day   日期（空 = 今天）
     * @param string $tag   类型过滤（SEND/ACK/CLOSE/MISS）
     * @param string $app   应用过滤
     * @param int    $limit 最多条数
     * @return array<int, array<string, mixed>>
     */
    public static function read(string $day = '', string $tag = '', string $app = '', int $limit = 200): array
    {
        $day = $day !== '' ? $day : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = date('Y-m-d');
        }
        $file = self::dir() . 'push-' . $day . '.log';
        if (!is_file($file)) {
            return [];
        }
        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return [];
        }
        $tail = [];
        $cap = max(200, min(4000, $limit * 8));
        while (($line = fgets($fp)) !== false) {
            $tail[] = $line;
            if (count($tail) > $cap) {
                array_shift($tail);
            }
        }
        @fclose($fp);

        $tag = strtoupper(trim($tag));
        $app = trim($app);
        $rows = [];
        foreach (array_reverse($tail) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // [时间] TAG k=v k=v
            if (!preg_match('/^\[([^\]]+)\]\s+([A-Z]+)\s*(.*)$/', $line, $m)) {
                continue;
            }
            $t = $m[1];
            $k = $m[2];
            if ($tag !== '' && $k !== $tag) {
                continue;
            }
            $fields = [];
            if ($m[3] !== '') {
                foreach (preg_split('/\s+/', trim($m[3])) as $kv) {
                    $pos = strpos($kv, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $fields[substr($kv, 0, $pos)] = substr($kv, $pos + 1);
                }
            }
            if ($app !== '' && (string) ($fields['app'] ?? '') !== $app && strpos((string) ($fields['target'] ?? ''), $app . ':') !== 0) {
                continue;
            }
            $ok = null;
            if (isset($fields['ok'])) {
                $ok = (string) $fields['ok'] === '1';
            }
            $rows[] = [
                'time'    => $t,
                'clock'   => substr($t, 11),
                'tag'     => $k,
                'app'     => (string) ($fields['app'] ?? ''),
                'target'  => (string) ($fields['target'] ?? ($fields['uid'] ?? '')),
                'ok'      => $ok,
                'sent'    => (int) ($fields['sent'] ?? 0),
                'queued'  => (int) ($fields['queued'] ?? 0),
                'msg_id'  => (string) ($fields['msg_id'] ?? ''),
                'bytes'   => (int) ($fields['bytes'] ?? 0),
                'via'     => (string) ($fields['via'] ?? ''),
                'reason'  => (string) ($fields['reason'] ?? ''),
                'client'  => (string) ($fields['client_id'] ?? ''),
                'note'    => (string) ($fields['note'] ?? ''),
            ];
            if (count($rows) >= max(1, min(2000, $limit))) {
                break;
            }
        }
        return $rows;
    }

    /**
     * 日志目录
     *
     * @return string
     */
    protected static function dir(): string
    {
        $base = (defined('CANDYPATH') ? CANDYPATH : sys_get_temp_dir() . '/');
        $dir = $base . 'Runtime' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * 写一行
     *
     * @param string               $tag  事件
     * @param array<string, mixed> $data 字段
     * @return void
     */
    public static function line(string $tag, array $data = []): void
    {
        if (!self::enabled()) {
            return;
        }
        $parts = [];
        foreach ($data as $k => $v) {
            $parts[] = $k . '=' . str_replace(["\n", "\r", ' '], '_', (string) $v);
        }
        $text = '[' . date('Y-m-d H:i:s') . '] ' . $tag . ' ' . implode(' ', $parts) . PHP_EOL;
        @file_put_contents(self::file(), $text, FILE_APPEND | LOCK_EX);
    }
}
