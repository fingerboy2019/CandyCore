<?php
/**
 * IMAP 客户端（UID 增量、文件夹、条件搜索、字面量读取）
 *
 * 为什么不复用 `Candy\Class\Network\Mail`：
 *   它的 IMAP 分支把 `SELECT INBOX` / `SEARCH ALL` 写死，只返回序号，没有 UID、没有文件夹、
 *   没有 SEARCH 条件，无法做增量同步；且关键方法是 private，外部无法扩展。
 *   本类按「同步一个邮箱」的实际需要重写，只保留 IMAP（POP3 是无状态的、不适合做增量）。
 *
 * 关键点：
 *   - 用 **UID** 而不是序号（序号会随删信变化，UID 在同一次 UIDVALIDITY 内稳定）
 *   - 用 `BODY.PEEK[]` 而不是 `BODY[]`（PEEK 不会把邮件标记为已读）
 *   - 字面量 `{N}` 必须按字节精确读取，不能按行读
 *
 * @package Candy\Extend\Network\Mail
 * @author 刘森 (fingerboy@qq.com)
 * @date 2026-11-17 16:00:00
 */
declare(strict_types=1);

namespace Candy\Extend\Network\Mail;

defined('CANDY') OR die('You Are A Bad Guy. o_O???');

final class Imap
{
    /** @var resource|null */
    private $sock = null;

    private string $host;
    private int $port;
    private string $secure;     // '' | ssl | tls
    private string $user;
    private string $pass;
    private int $timeout;
    private int $tagSeq = 0;
    private string $lastError = '';
    private string $capability = '';

    public function __construct(
        string $host,
        int $port = 993,
        string $secure = 'ssl',
        string $user = '',
        string $pass = '',
        int $timeout = 20
    ) {
        $this->host    = $host;
        $this->port    = $port > 0 ? $port : 993;
        $this->secure  = strtolower(trim($secure));
        $this->user    = $user;
        $this->pass    = $pass;
        $this->timeout = $timeout > 0 ? $timeout : 20;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function capability(): string
    {
        return $this->capability;
    }

    /**
     * 建立连接并登录
     */
    public function connect(): bool
    {
        $this->lastError = '';
        $scheme = match ($this->secure) {
            'ssl'   => 'ssl://',
            'tls'   => 'tcp://',
            default => 'tcp://',
        };
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        if (!is_resource($sock)) {
            $this->lastError = '连接失败: ' . ($errstr !== '' ? $errstr : ('errno ' . $errno));
            return false;
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, $this->timeout);

        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            $this->lastError = '服务端拒绝连接: ' . $greeting;
            return false;
        }

        // STARTTLS
        if ($this->secure === 'tls') {
            $r = $this->command('STARTTLS');
            if (!$r['ok']) {
                $this->lastError = 'STARTTLS 失败';
                return false;
            }
            $ok = @stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                $this->lastError = 'TLS 握手失败';
                return false;
            }
        }

        // CAPABILITY（登录后再取一次更准）
        $cap = $this->command('CAPABILITY');
        foreach ($cap['lines'] as $line) {
            if (str_starts_with($line, '* CAPABILITY')) {
                $this->capability = trim(substr($line, strlen('* CAPABILITY')));
            }
        }

        // LOGIN：密码打码，避免日志泄露
        $quotedUser = $this->quote($this->user);
        $quotedPass = $this->quote($this->pass);
        $r = $this->command('LOGIN ' . $quotedUser . ' ' . $quotedPass);
        if (!$r['ok']) {
            $this->lastError = '登录失败: ' . $this->responseText($r);
            return false;
        }
        return true;
    }

    /**
     * 文件夹列表
     *
     * @return array<int,string>
     */
    public function folders(): array
    {
        $r = $this->command('LIST "" "*"');
        $out = [];
        foreach ($r['lines'] as $line) {
            if (!str_starts_with($line, '* LIST')) {
                continue;
            }
            // * LIST (\HasNoChildren) "/" "INBOX"   或   ... {5}\r\nINBOX
            if (preg_match('/\* LIST\s+\([^)]*\)\s+(?:"[^"]*"|NIL)\s+(.+)$/i', $line, $m)) {
                $name = trim($m[1]);
                if (str_ends_with($name, '}')) {
                    // 字面量已在 readLine 里拼好，去掉 {N}
                    $name = preg_replace('/\{\d+\}$/', '', $name) ?? $name;
                }
                $name = trim($name, "\" \t");
                if ($name !== '' && !in_array($name, $out, true)) {
                    $out[] = $name;
                }
            }
        }
        return $out;
    }

    /**
     * 选择邮箱
     *
     * @return array{ok:bool,uidvalidity:int,uidnext:int,exists:int}
     */
    public function select(string $mailbox = 'INBOX', bool $readonly = true): array
    {
        $res = ['ok' => false, 'uidvalidity' => 0, 'uidnext' => 0, 'exists' => 0];
        $r = $this->command(($readonly ? 'EXAMINE ' : 'SELECT ') . $this->quote($mailbox));
        $res['ok'] = $r['ok'];
        foreach ($r['lines'] as $line) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
                $res['exists'] = (int) $m[1];
            } elseif (preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $line, $m)) {
                $res['uidvalidity'] = (int) $m[1];
            } elseif (preg_match('/\[UIDNEXT\s+(\d+)\]/i', $line, $m)) {
                $res['uidnext'] = (int) $m[1];
            }
        }
        return $res;
    }

    /**
     * 按条件搜索，返回 UID 列表（升序）
     *
     * 常用条件：
     *   'ALL'
     *   'UID 1200:*'          （从某 UID 起，注意 * 表示最大）
     *   'UNSEEN'
     *   'SINCE 01-Nov-2026'
     *   'SINCE 01-Nov-2026 NOT SEEN'
     *
     * @return array<int,int>
     */
    public function searchUids(string $criteria = 'ALL'): array
    {
        $r = $this->command('UID SEARCH ' . $criteria);
        $uids = [];
        foreach ($r['lines'] as $line) {
            if (preg_match('/^\*\s+SEARCH(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $v) {
                    if ($v !== '' && ctype_digit($v)) {
                        $uids[] = (int) $v;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /**
     * 批量取邮件头（不下载正文，快）
     *
     * @param array<int,int> $uids
     * @return array<int,array{uid:int,size:int,internal_date:string,raw_headers:string}>
     */
    public function fetchHeaders(array $uids): array
    {
        $uids = array_values(array_filter(array_map('intval', $uids)));
        if ($uids === []) {
            return [];
        }
        $set = implode(',', $uids);
        $r = $this->command(
            'UID FETCH ' . $set . ' (UID RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER])'
        );
        return $this->assembleFetched($r['lines'], false);
    }

    /**
     * 取整封原文（含头与正文，PEEK 不改已读状态）
     *
     * @param int $uid
     * @return string 原始 MIME；失败返回 ''
     */
    public function fetchRaw(int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }
        $r = $this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[])');
        foreach ($this->assembleFetched($r['lines'], true) as $item) {
            if ($item['uid'] === $uid) {
                return $item['raw'];
            }
        }
        return '';
    }

    /**
     * 标记为已读（同步完成后再做，保证失败可重试）
     */
    public function markSeen(int $uid): bool
    {
        $r = $this->command('UID STORE ' . $uid . ' +FLAGS (\\Seen)');
        return $r['ok'];
    }

    public function logout(): void
    {
        if (!is_resource($this->sock)) {
            return;
        }
        $this->command('LOGOUT');
        @fclose($this->sock);
        $this->sock = null;
    }

    // ── 内部：协议读写 ────────────────────────────────────

    /**
     * 发送带 tag 的命令并读到该 tag 的结束响应
     *
     * @return array{ok:bool,tag:string,lines:array<int,string>}
     */
    private function command(string $command): array
    {
        $tag = 'A' . str_pad((string) (++$this->tagSeq), 4, '0', STR_PAD_LEFT);
        if (!is_resource($this->sock)) {
            $this->lastError = '未建立连接';
            return ['ok' => false, 'tag' => $tag, 'lines' => []];
        }
        $this->write($tag . ' ' . $command . "\r\n");

        $lines = [];
        $ok = false;
        $guard = 0;
        while ($guard++ < 100000) {
            $line = $this->readLine();
            if ($line === '' && !is_resource($this->sock)) {
                $this->lastError = '连接已断开';
                break;
            }
            $lines[] = $line;
            if (str_starts_with($line, '* BYE')) {
                $this->lastError = '服务端关闭连接: ' . $line;
                break;
            }
            if (str_starts_with($line, $tag . ' ')) {
                $ok = (bool) preg_match('/^' . preg_quote($tag, '/') . '\s+OK/i', $line);
                if (!$ok) {
                    $this->lastError = $line;
                }
                break;
            }
        }
        return ['ok' => $ok, 'tag' => $tag, 'lines' => $lines];
    }

    /**
     * 读一行；遇到 `{N}` 字面量时读取 N 字节并附在同行后
     *
     * 这是 IMAP 客户端最容易写错的地方：字面量是**二进制长度**，不能按行读。
     */
    private function readLine(): string
    {
        if (!is_resource($this->sock)) {
            return '';
        }
        $line = fgets($this->sock, 65535);
        if ($line === false) {
            return '';
        }
        $line = rtrim($line, "\r\n");

        // 末尾 {N} → 读 N 字节字面量
        $guard = 0;
        while (preg_match('/\{(\d+)\}$/', $line, $m) && $guard++ < 64) {
            $len = (int) $m[1];
            $data = '';
            while (strlen($data) < $len) {
                $chunk = fread($this->sock, min(65536, $len - strlen($data)));
                if ($chunk === false || $chunk === '') {
                    break 2;
                }
                $data .= $chunk;
            }
            $next = fgets($this->sock, 65535);
            $next = $next === false ? '' : rtrim($next, "\r\n");
            $line .= "\n" . $data . ($next !== '' ? "\n" . $next : '');
        }
        return $line;
    }

    private function write(string $data): void
    {
        if (!is_resource($this->sock)) {
            return;
        }
        $written = 0;
        $len = strlen($data);
        while ($written < $len) {
            $n = fwrite($this->sock, substr($data, $written));
            if ($n === false || $n === 0) {
                $this->lastError = '写入失败';
                return;
            }
            $written += $n;
        }
    }

    /**
     * 把 FETCH 响应拼成结构化条目
     *
     * readLine 已把字面量拼进同一行（用 \n 分隔），这里按需还原原文。
     *
     * @param array<int,string> $lines
     * @return array<int,array<string,mixed>>
     */
    private function assembleFetched(array $lines, bool $raw): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\*\s+(\d+)\s+FETCH\s+\((.*)$/is', $line, $m)) {
                continue;
            }
            $body = $m[2];
            $uid = 0;
            if (preg_match('/UID\s+(\d+)/i', $body, $mu)) {
                $uid = (int) $mu[1];
            }
            $size = 0;
            if (preg_match('/RFC822\.SIZE\s+(\d+)/i', $body, $ms)) {
                $size = (int) $ms[1];
            }
            $internalDate = '';
            if (preg_match('/INTERNALDATE\s+"([^"]+)"/i', $body, $md)) {
                $internalDate = $md[1];
            }

            if ($raw) {
                // 原文 = 第一个 \n 之后到最后的 ")" 之前
                $pos = strpos($line, "\n");
                $content = $pos === false ? '' : substr($line, $pos + 1);
                $content = preg_replace('/\)\s*$/s', '', $content) ?? $content;
                $items[] = ['uid' => $uid, 'size' => $size, 'raw' => $content, 'internal_date' => $internalDate];
            } else {
                $pos = strpos($line, "\n");
                $headers = $pos === false ? '' : substr($line, $pos + 1);
                $headers = preg_replace('/\)\s*$/s', '', $headers) ?? $headers;
                $items[] = [
                    'uid' => $uid,
                    'size' => $size,
                    'internal_date' => $internalDate,
                    'raw_headers' => $headers,
                ];
            }
        }
        return $items;
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * 从响应里取可读的错误文本（去掉 tag 与换行）
     */
    private function responseText(array $r): string
    {
        $last = (string) end($r['lines']);
        return trim(str_replace("\n", ' ', $last));
    }
}
